#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

$openApiPath = dirname(__DIR__).'/specs/api/openapi/v1.yaml';
if (!is_file($openApiPath)) {
    fwrite(STDERR, "OpenAPI file not found: {$openApiPath}\n");
    exit(1);
}

/** @var array<string, mixed> $openApi */
$openApi = Yaml::parseFile($openApiPath);
$paths = is_array($openApi['paths'] ?? null) ? $openApi['paths'] : [];
$targetPathPrefixes = [
    '/api/v1/assets',
    '/api/v1/jobs',
    '/api/v1/agents',
    '/api/v1/auth',
    '/api/v1/app',
];

/** @var array<string, bool> $openApiMethods */
$openApiMethods = [];
foreach ($paths as $path => $pathConfig) {
    if (!is_string($path) || !is_array($pathConfig)) {
        continue;
    }

    foreach ($pathConfig as $method => $config) {
        if (!is_string($method)) {
            continue;
        }

        $methodUpper = strtoupper($method);
        if (!in_array($methodUpper, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            continue;
        }

        $openApiMethods[$methodUpper.' '.normalizePath('/api/v1'.$path)] = true;
    }
}

/** @var array<string, bool> $targetOpenApiMethods */
$targetOpenApiMethods = [];
foreach ($openApiMethods as $signature => $_) {
    $parts = explode(' ', $signature, 2);
    if (count($parts) !== 2) {
        continue;
    }

    [, $path] = $parts;
    foreach ($targetPathPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $targetOpenApiMethods[$signature] = true;
            break;
        }
    }
}

$routeJson = shell_exec('php bin/console debug:router --format=json 2>/dev/null');
if (!is_string($routeJson) || trim($routeJson) === '') {
    fwrite(STDERR, "Unable to read router definitions via bin/console debug:router\n");
    exit(1);
}

/** @var array<string, array<string, mixed>>|null $routes */
$routes = json_decode($routeJson, true);
if (!is_array($routes)) {
    fwrite(STDERR, "Invalid router JSON output\n");
    exit(1);
}

$targetPrefixes = ['api_assets_', 'api_jobs_', 'api_agents_', 'api_auth_', 'api_app_'];
/** @var array<string, bool> $implementedMethods */
$implementedMethods = [];

foreach ($routes as $name => $route) {
    if (!is_string($name) || !is_array($route)) {
        continue;
    }

    $matchesPrefix = false;
    foreach ($targetPrefixes as $prefix) {
        if (str_starts_with($name, $prefix)) {
            $matchesPrefix = true;
            break;
        }
    }

    if (!$matchesPrefix) {
        continue;
    }

    $rawPath = (string) ($route['path'] ?? '');
    if (!str_starts_with($rawPath, '/api/v1/')) {
        continue;
    }

    $rawMethods = strtoupper((string) ($route['method'] ?? 'ANY'));
    $methods = $rawMethods === 'ANY' ? ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'] : explode('|', $rawMethods);
    foreach ($methods as $method) {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            continue;
        }

        $implementedMethods[$method.' '.normalizePath($rawPath)] = true;
    }
}

$missingInOpenApi = array_values(array_diff(array_keys($implementedMethods), array_keys($openApiMethods)));
sort($missingInOpenApi);

if ($missingInOpenApi !== []) {
    fwrite(STDERR, "Implemented API routes missing in OpenAPI:\n");
    foreach ($missingInOpenApi as $signature) {
        fwrite(STDERR, " - {$signature}\n");
    }

    exit(1);
}

$missingInRuntime = array_values(array_diff(array_keys($targetOpenApiMethods), array_keys($implementedMethods)));
sort($missingInRuntime);

if ($missingInRuntime !== []) {
    fwrite(STDERR, "OpenAPI routes missing in runtime:\n");
    foreach ($missingInRuntime as $signature) {
        fwrite(STDERR, " - {$signature}\n");
    }

    exit(1);
}

fwrite(STDOUT, "OpenAPI route coverage OK for assets/jobs/agents/auth/app endpoints\n");
exit(0);

function normalizePath(string $path): string
{
    $path = preg_replace('#\{[^}/]+\}#', '{}', $path) ?? $path;

    return rtrim($path, '/');
}
