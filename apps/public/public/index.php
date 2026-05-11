<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TaskTracker\Public\Bootstrap;
use TaskTracker\Config\Env;

$projectRoot = dirname(__DIR__, 3);
$env = Env::fromEnvironment($projectRoot, $projectRoot);

$displayErrors = filter_var(
    getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : '0',
    FILTER_VALIDATE_BOOL,
);

Bootstrap::boot($env, $displayErrors)->run();
