#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$openApiPath = $root.'/specs/api/openapi/v1.yaml';
$snapshotPath = $root.'/contracts/openapi-v1.sha256';

if (!is_file($openApiPath)) {
    fwrite(STDERR, "OpenAPI file not found: {$openApiPath}\n");
    exit(1);
}

$hash = hash_file('sha256', $openApiPath);
if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "Unable to compute SHA-256 hash for {$openApiPath}\n");
    exit(1);
}

$contractsDir = dirname($snapshotPath);
if (!is_dir($contractsDir) && !mkdir($contractsDir, 0775, true) && !is_dir($contractsDir)) {
    fwrite(STDERR, "Unable to create directory: {$contractsDir}\n");
    exit(1);
}

if (file_put_contents($snapshotPath, $hash."\n") === false) {
    fwrite(STDERR, "Unable to write snapshot file: {$snapshotPath}\n");
    exit(1);
}

fwrite(STDOUT, "Updated contracts snapshot: {$snapshotPath}\n");
exit(0);
