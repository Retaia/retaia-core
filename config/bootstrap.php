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

    $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
    if (is_file($envPath.'.'.$appEnv)) {
        $dotenv->load($envPath.'.'.$appEnv);
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
