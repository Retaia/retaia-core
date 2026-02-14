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

if (!is_file($snapshotPath)) {
    fwrite(STDERR, "Contracts snapshot not found: {$snapshotPath}\n");
    fwrite(STDERR, "Run: php scripts/contracts-refresh.php\n");
    exit(1);
}

$expected = trim((string) file_get_contents($snapshotPath));
if ($expected === '') {
    fwrite(STDERR, "Contracts snapshot is empty: {$snapshotPath}\n");
    exit(1);
}

$actual = hash_file('sha256', $openApiPath);
if (!is_string($actual) || $actual === '') {
    fwrite(STDERR, "Unable to compute SHA-256 hash for {$openApiPath}\n");
    exit(1);
}

if (!hash_equals($expected, $actual)) {
    fwrite(STDERR, "OpenAPI contract drift detected.\n");
    fwrite(STDERR, "Snapshot: {$expected}\n");
    fwrite(STDERR, "Current : {$actual}\n");
    fwrite(STDERR, "Run: php scripts/contracts-refresh.php\n");
    exit(1);
}

fwrite(STDOUT, "OpenAPI contract snapshot matches current spec.\n");
exit(0);
