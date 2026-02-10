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
 * @return array<string, string>
 */
function flattenCatalog(array $data, string $prefix = ''): array
{
    $flat = [];
    foreach ($data as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $fullKey = $prefix === '' ? $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += flattenCatalog($value, $fullKey);
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $flat[$fullKey] = trim((string) $value);
        }
    }

    ksort($flat);

    return $flat;
}

$baseDir = dirname(__DIR__).'/translations';
$enPath = $baseDir.'/messages.en.yaml';
$frPath = $baseDir.'/messages.fr.yaml';

$enCatalog = flattenCatalog(loadCatalog($enPath));
$frCatalog = flattenCatalog(loadCatalog($frPath));
$enKeys = array_keys($enCatalog);
$frKeys = array_keys($frCatalog);

$missingInFr = array_values(array_diff($enKeys, $frKeys));
$missingInEn = array_values(array_diff($frKeys, $enKeys));
$emptyValues = [];
$forbiddenMarkers = [];

/** @var list<string> $criticalKeys */
$criticalKeys = [
    'auth.error.authentication_required',
    'auth.error.invalid_credentials',
    'auth.error.email_not_verified',
    'auth.error.too_many_login_attempts',
    'auth.error.invalid_or_expired_token',
];

foreach ($criticalKeys as $key) {
    if (!array_key_exists($key, $enCatalog)) {
        $missingInEn[] = $key;
    }

    if (!array_key_exists($key, $frCatalog)) {
        $missingInFr[] = $key;
    }
}

$missingInFr = array_values(array_unique($missingInFr));
$missingInEn = array_values(array_unique($missingInEn));
sort($missingInFr);
sort($missingInEn);

foreach (['en' => $enCatalog, 'fr' => $frCatalog] as $locale => $catalog) {
    foreach ($catalog as $key => $value) {
        if ($value === '') {
            $emptyValues[] = sprintf('%s:%s', $locale, $key);
        }

        $upperValue = strtoupper($value);
        if (str_contains($upperValue, 'TODO') || str_contains($upperValue, 'FIXME') || str_contains($upperValue, 'TRANSLATE_ME')) {
            $forbiddenMarkers[] = sprintf('%s:%s', $locale, $key);
        }
    }
}

if ($missingInFr === [] && $missingInEn === [] && $emptyValues === [] && $forbiddenMarkers === []) {
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

if ($emptyValues !== []) {
    fwrite(STDERR, "Empty translations are not allowed:\n");
    foreach ($emptyValues as $entry) {
        fwrite(STDERR, " - {$entry}\n");
    }
}

if ($forbiddenMarkers !== []) {
    fwrite(STDERR, "Forbidden placeholder markers detected:\n");
    foreach ($forbiddenMarkers as $entry) {
        fwrite(STDERR, " - {$entry}\n");
    }
}

exit(1);
