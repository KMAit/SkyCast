<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$dotenv = new Dotenv();

$envFile = dirname(__DIR__).'/.env.test';
if (file_exists($envFile)) {
    // ✅ Prefer .env.test when running tests
    $dotenv->bootEnv($envFile);
} else {
    // Fallback to standard .env if test file is missing
    $dotenv->bootEnv(dirname(__DIR__).'/.env');
}

// ✅ Ensure test environment is enforced even if misconfigured
$_SERVER['APP_ENV']   = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_SERVER['CACHE_DSN'] = $_ENV['CACHE_DSN'] = 'cache.adapter.array';

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
