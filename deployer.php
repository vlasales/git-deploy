<?php
$content = file_get_contents("php://input");
$json    = json_decode($content, true);
$time    = time();
$token   = false;
$sha     = false;
$DIR    = defined('DIR') ? (preg_match("/\/$/", DIR) ? DIR : DIR . "/") : "./";
$branch = defined('BRANCH') ? BRANCH : "refs/heads/master";

class ForbidException extends Exception {
}

class DirectoryException extends Exception {
}

class Logger {
    function __construct($logfile) {
        $this->log = fopen($logfile, "a");
    }

    function write($text) {
        fputs($this->log, $text . PHP_EOL);
        return $text . PHP_EOL;
    }

    function __destruct() {
        fputs($this->log, PHP_EOL . PHP_EOL);
        fclose($this->log);
    }
}
$log = new Logger(defined('LOGFILE') ? LOGFILE : '/dev/null');

// retrieve the token
if (!$token && isset($_SERVER["HTTP_X_HUB_SIGNATURE"])) {
    list($algo, $token) = explode("=", $_SERVER["HTTP_X_HUB_SIGNATURE"], 2) + array("", "");
} elseif (isset($_SERVER["HTTP_X_GITLAB_TOKEN"])) {
    $token = $_SERVER["HTTP_X_GITLAB_TOKEN"];
} elseif (isset($_GET["token"])) {
    $token = $_GET["token"];
}

// retrieve the checkout_sha
if (isset($json["checkout_sha"])) {
    $sha = $json["checkout_sha"];
} elseif (isset($_SERVER["checkout_sha"])) {
    $sha = $_SERVER["checkout_sha"];
} elseif (isset($_GET["sha"])) {
    $sha = $_GET["sha"];
}

// write the time to the log
date_default_timezone_set("UTC");
$log->write(date("d-m-Y (H:i:s)", $time));

header("Content-Type: text/plain");

if (defined('MAX_EXECUTION_TIME') && !empty(MAX_EXECUTION_TIME)) {
    ini_set("max_execution_time", MAX_EXECUTION_TIME);
}

try {
    // Check for a GitHub signature
    if (defined('TOKEN') && isset($_SERVER["HTTP_X_HUB_SIGNATURE"]) &&
        $token !== hash_hmac($algo, $content, TOKEN)) {
        throw new ForbidException("X-Hub-Signature does not match TOKEN", 403);
    }
    // Check for a GitLab token
    if (defined('TOKEN') && isset($_SERVER["HTTP_X_GITLAB_TOKEN"]) && $token !== TOKEN) {
        throw new ForbidException("X-GitLab-Token does not match TOKEN", 403);
    }
    // Check for a $_GET token
    if (defined('TOKEN') && isset($_GET["token"]) && $token !== TOKEN) {
        throw new ForbidException("\$_GET[\"token\"] does not match TOKEN", 403);
    }
    // if none of the above match, but a token exists, exit
    if (defined('TOKEN') && !isset($_SERVER["HTTP_X_HUB_SIGNATURE"]) &&
        !isset($_SERVER["HTTP_X_GITLAB_TOKEN"]) && !isset($_GET["token"])) {
        throw new ForbidException("No token detected", 403);
    }
    if ($json["ref"] !== $branch) {
        throw new ForbidException($error = "=== ERROR: Pushed branch `" . $json["ref"] . "` does not match BRANCH `$branch` ===", 400);
    }
} catch (ForbidException $e) {
    $reason = $e->getMessage();
    $code = $e->getCode();
    $error = "=== ERROR: $reason ===\n*** ACCESS DENIED ***\n";
    if ($code != 0) {
        http_response_code($code);
    }
    $log->write($error);
    echo $error;
    exit;
}

$log->write($content);

// ensure directory is a repository
try {
    if (!file_exists($DIR)) {
        throw new DirectoryException("`$DIR` does not exist", 400);
    }
    if (!is_dir($DIR)) {
        throw new DirectoryException("`$DIR` is not a directory", 400);
    }
    if (!file_exists($DIR . ".git")) {
        throw new DirectoryException("`$DIR` is not a repository", 400);
    }
} catch (DirectoryException $e) {
    $error = "=== ERROR: DIR " . $e->getMessage() . " ===\n";
    $code = $e->getCode();
    http_response_code($code);
    $log->write($error);
    echo $error;
    exit;
}
// change directory to the repository
chdir($DIR);

// write to the log
$log->write("*** AUTO PULL INITIATED ***");

/**
 * Attempt to reset specific hash if specified
 */
if (!empty($_GET["reset"]) && $_GET["reset"] === "true") {
    // write to the log
    $log->write("*** RESET TO HEAD INITIATED ***");
    exec(GIT . " reset --hard HEAD 2>&1", $output, $exit);
    // reformat the output as a string
    $output = (!empty($output) ? implode("\n", $output) : "[no output]") . "\n";
    // if an error occurred, return 500 and log the error
    if ($exit !== 0) {
        http_response_code(500);
        $output = "=== ERROR: Reset to head failed using GIT `" . GIT . "` ===\n" . $output;
    }
    // write the output to the log and the body
    $log->write($output);
    echo $output;
}

/**
 * Attempt to execute BEFORE_PULL if specified
 */
if (!empty(BEFORE_PULL)) {
    // write to the log
    $log->write("*** BEFORE_PULL INITIATED ***");
    // execute the command, returning the output and exit code
    exec(BEFORE_PULL . " 2>&1", $output, $exit);
    // reformat the output as a string
    $output = (!empty($output) ? implode("\n", $output) : "[no output]") . "\n";
    // if an error occurred, return 500 and log the error
    if ($exit !== 0) {
        http_response_code(500);
        $output = "=== ERROR: BEFORE_PULL `" . BEFORE_PULL . "` failed ===\n" . $output;
    }
    // write the output to the log and the body
    $log->write($output);
    echo $output;
}

/**
 * Attempt to pull, returing the output and exit code
 */
exec(GIT . " pull 2>&1", $output, $exit);
// reformat the output as a string
$output = (!empty($output) ? implode("\n", $output) : "[no output]") . "\n";
// if an error occurred, return 500 and log the error
if ($exit !== 0) {
    http_response_code(500);
    $output = "=== ERROR: Pull failed using GIT `" . GIT . "` and DIR `" . $DIR . "` ===\n" . $output;
}
// write the output to the log and the body
$log->write($output);
echo $output;

/**
 * Attempt to checkout specific hash if specified
 */
if (!empty($sha)) {
    // write to the log
    $log->write("*** RESET TO HASH INITIATED ***");
    exec(GIT . " reset --hard {$sha} 2>&1", $output, $exit);
    // reformat the output as a string
    $output = (!empty($output) ? implode("\n", $output) : "[no output]") . "\n";
    // if an error occurred, return 500 and log the error
    if ($exit !== 0) {
        http_response_code(500);
        $output = "=== ERROR: Reset failed using GIT `" . GIT . "` and \$sha `" . $sha . "` ===\n" . $output;
    }
    // write the output to the log and the body
    $log->write($output);
    echo $output;
}

/**
 * Attempt to execute AFTER_PULL if specified
 */
if (!empty(AFTER_PULL)) {
    // write to the log
    $log->write("*** AFTER_PULL INITIATED ***");
    // execute the command, returning the output and exit code
    exec(AFTER_PULL . " 2>&1", $output, $exit);
    // reformat the output as a string
    $output = (!empty($output) ? implode("\n", $output) : "[no output]") . "\n";
    // if an error occurred, return 500 and log the error
    if ($exit !== 0) {
        http_response_code(500);
        $output = "=== ERROR: AFTER_PULL `" . AFTER_PULL . "` failed ===\n" . $output;
    }
    // write the output to the log and the body
    $log->write($output);
    echo $output;
}

// write to the log
$log->write("*** AUTO PULL COMPLETE ***");

// close the log
$log->write("\n\n");
