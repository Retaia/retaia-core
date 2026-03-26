<?php

use Symfony\Component\Dotenv\Dotenv;

if (class_exists(Dotenv::class)) {
    $dotenv = new Dotenv();
    $envPath = dirname(__DIR__).'/.env';

    if (is_file($envPath)) {
        $dotenv->load($envPath);
    } elseif (is_file($envPath.'.dist')) {
        $dotenv->load($envPath.'.dist');
    }

    $appEnv = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev');
    $appEnvFile = match ($appEnv) {
        'dev' => $envPath.'.dev',
        'test' => $envPath.'.test',
        'prod' => $envPath.'.prod',
        default => null,
    };

    if ($appEnvFile !== null && is_file($appEnvFile)) {
        $dotenv->load($appEnvFile);
    }

    if (is_file($envPath.'.local')) {
        $dotenv->load($envPath.'.local');
    }

    $_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'dev';
    $_SERVER += $_ENV;

    $prodEnvs = ['prod'];
    $debug = $_SERVER['APP_DEBUG'] ?? !in_array((string) $_SERVER['APP_ENV'], $prodEnvs, true);
    $debugEnabled = (int) $debug || (!is_bool($debug) && filter_var($debug, FILTER_VALIDATE_BOOL));
    $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $debugEnabled ? '1' : '0';
}
