#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$openApiPath = $root.'/specs/api/openapi/v1.yaml';
$apiContractsPath = $root.'/specs/api/API-CONTRACTS.md';

if (!is_file($openApiPath)) {
    fwrite(STDERR, "OpenAPI file not found: {$openApiPath}\n");
    exit(1);
}
if (!is_file($apiContractsPath)) {
    fwrite(STDERR, "API contracts doc not found: {$apiContractsPath}\n");
    exit(1);
}

/** @var array<string, mixed> $openApi */
$openApi = Yaml::parseFile($openApiPath);
$openApiPaths = array_keys(is_array($openApi['paths'] ?? null) ? $openApi['paths'] : []);
$openApiFieldNames = openApiFieldNames($openApi);
$apiContractsMarkdown = (string) file_get_contents($apiContractsPath);

$documentedEndpoints = documentedEndpoints($apiContractsMarkdown);
$documentedFieldNames = documentedCriticalFieldNames($apiContractsMarkdown);

$missingEndpoints = [];
foreach ($documentedEndpoints as $endpoint) {
    if (!in_array($endpoint, $openApiPaths, true)) {
        $missingEndpoints[] = $endpoint;
    }
}

$missingFields = [];
foreach ($documentedFieldNames as $fieldName) {
    if (!in_array($fieldName, $openApiFieldNames, true)) {
        $missingFields[] = $fieldName;
    }
}

sort($missingEndpoints);
sort($missingFields);

if ($missingEndpoints !== [] || $missingFields !== []) {
    fwrite(STDERR, "OpenAPI/API-CONTRACTS coherence check failed.\n");
    if ($missingEndpoints !== []) {
        fwrite(STDERR, "Documented endpoints missing in OpenAPI:\n");
        foreach ($missingEndpoints as $endpoint) {
            fwrite(STDERR, " - {$endpoint}\n");
        }
    }
    if ($missingFields !== []) {
        fwrite(STDERR, "Documented field names missing in OpenAPI:\n");
        foreach ($missingFields as $fieldName) {
            fwrite(STDERR, " - {$fieldName}\n");
        }
    }
    exit(1);
}

fwrite(STDOUT, "OpenAPI/API-CONTRACTS coherence OK.\n");
exit(0);

/**
 * @return list<string>
 */
function documentedEndpoints(string $markdown): array
{
    $markdown = stripPlannedSections($markdown);

    preg_match_all('/`(\/[a-z0-9\/._{}-]+)`/i', $markdown, $matches);
    $endpoints = [];
    foreach (($matches[1] ?? []) as $endpoint) {
        if (!is_string($endpoint)) {
            continue;
        }
        $clean = trim($endpoint);
        if (in_array($clean, ['/api/v1', '/v2'], true)) {
            continue;
        }
        if (str_starts_with($clean, '/api/v1/')) {
            $clean = substr($clean, 7);
        }
        if ($clean !== '') {
            $endpoints[$clean] = true;
        }
    }

    return array_keys($endpoints);
}

function stripPlannedSections(string $markdown): string
{
    return (string) preg_replace('/^### .*?\(planned[^\\n]*\\)\\R.*?(?=^## |^### |\\z)/ims', '', $markdown);
}

/**
 * @return list<string>
 */
function documentedCriticalFieldNames(string $markdown): array
{
    $fieldNames = [];
    foreach (criticalFieldNames() as $fieldName) {
        if (str_contains($markdown, '`'.$fieldName.'`')) {
            $fieldNames[$fieldName] = true;
        }
    }

    return array_keys($fieldNames);
}

/**
 * @param array<string, mixed> $openApi
 * @return list<string>
 */
function openApiFieldNames(array $openApi): array
{
    $fieldNames = [];
    collectSchemaFieldNames($openApi, $fieldNames);

    $names = array_keys($fieldNames);
    sort($names);

    return $names;
}

/**
 * @param array<string, mixed> $node
 * @param array<string, bool> $fieldNames
 */
function collectSchemaFieldNames(array $node, array &$fieldNames): void
{
    foreach ($node as $key => $value) {
        if ($key === 'name' && is_string($value) && $value !== '') {
            $fieldNames[$value] = true;
        }

        if ($key === 'properties' && is_array($value)) {
            foreach ($value as $propertyName => $propertyConfig) {
                if (is_string($propertyName) && $propertyName !== '') {
                    $fieldNames[$propertyName] = true;
                }
                if (is_array($propertyConfig)) {
                    collectSchemaFieldNames($propertyConfig, $fieldNames);
                }
            }
            continue;
        }

        if (is_array($value)) {
            collectSchemaFieldNames($value, $fieldNames);
        }
    }
}

/**
 * @return list<string>
 */
function criticalFieldNames(): array
{
    return [
        'client_feature_flags_contract_version',
        'feature_flags_contract_version',
        'accepted_feature_flags_contract_versions',
        'effective_feature_flags_contract_version',
        'feature_flags_compatibility_mode',
        'code',
        'message',
        'details',
        'retryable',
        'correlation_id',
    ];
}
