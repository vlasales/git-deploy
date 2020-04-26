<?php
// The secret token to add as a GitHub or GitLab secret, or otherwise as
// https://www.example.com/?token=secret-token
define("TOKEN", "secret-token");

// The SSH URL to your repository
define("REMOTE_REPOSITORY", "git@github.com:username/repository.git");

// The path to your repostiroy; this must begin with a forward slash (/)
define("DIR", "/var/www/vhosts/repository/");

// The branch route
define("BRANCH", "refs/heads/master");

// The name of the file you want to log to.
define("LOGFILE", "deploy.log");

// The path to the git executable
define("GIT", "/usr/bin/git");

// Override for PHP's max_execution_time (may need set in php.ini)
define("MAX_EXECUTION_TIME", 180);

// A command to execute before pulling
define("BEFORE_PULL", "");

// A command to execute after successfully pulling
define("AFTER_PULL", "");

require_once("deployer.php");
