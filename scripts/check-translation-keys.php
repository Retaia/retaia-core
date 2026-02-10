#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * @return array<string, mixed>
 */
function loadCatalog(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Missing catalog: {$path}\n");
        exit(1);
    }

    $data = Yaml::parseFile($path);

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $data
 * @return list<string>
 */
function flattenKeys(array $data, string $prefix = ''): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $fullKey = $prefix === '' ? $key : $prefix.'.'.$key;
        if (is_array($value)) {
            array_push($keys, ...flattenKeys($value, $fullKey));
            continue;
        }

        $keys[] = $fullKey;
    }

    sort($keys);

    return $keys;
}

$baseDir = dirname(__DIR__).'/translations';
$enPath = $baseDir.'/messages.en.yaml';
$frPath = $baseDir.'/messages.fr.yaml';

$enKeys = flattenKeys(loadCatalog($enPath));
$frKeys = flattenKeys(loadCatalog($frPath));

$missingInFr = array_values(array_diff($enKeys, $frKeys));
$missingInEn = array_values(array_diff($frKeys, $enKeys));

if ($missingInFr === [] && $missingInEn === []) {
    fwrite(STDOUT, "Translation key sync OK (en <-> fr)\n");
    exit(0);
}

if ($missingInFr !== []) {
    fwrite(STDERR, "Missing in fr:\n");
    foreach ($missingInFr as $key) {
        fwrite(STDERR, " - {$key}\n");
    }
}

if ($missingInEn !== []) {
    fwrite(STDERR, "Missing in en:\n");
    foreach ($missingInEn as $key) {
        fwrite(STDERR, " - {$key}\n");
    }
}

exit(1);
