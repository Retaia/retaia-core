#!/usr/bin/env php
<?php

declare(strict_types=1);

$unitDir = dirname(__DIR__).'/tests/Unit';
if (!is_dir($unitDir)) {
    fwrite(STDOUT, "Unit purity check skipped: tests/Unit does not exist.\n");
    exit(0);
}

$forbiddenCalls = [
    'sys_get_temp_dir',
    'mkdir',
    'rmdir',
    'unlink',
    'rename',
    'chmod',
    'chown',
    'touch',
    'file_put_contents',
    'file_get_contents',
    'fopen',
    'fwrite',
    'fread',
    'fclose',
    'realpath',
    'glob',
    'scandir',
    'exec',
    'shell_exec',
    'passthru',
    'proc_open',
    'curl_init',
    'sleep',
    'usleep',
    'time',
    'microtime',
    'random_bytes',
];

$ignoreMarker = '@unit-purity-ignore';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($unitDir, FilesystemIterator::SKIP_DOTS)
);

$violations = [];
/** @var array<string, true> $forbiddenSet */
$forbiddenSet = array_fill_keys($forbiddenCalls, true);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        continue;
    }

    $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
    $tokens = token_get_all($contents);
    $tokenCount = count($tokens);
    for ($i = 0; $i < $tokenCount; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = $token[1];
        if (!isset($forbiddenSet[$name])) {
            continue;
        }

        $line = (int) ($token[2] ?? 0);
        if ($line > 0 && isset($lines[$line - 1]) && str_contains((string) $lines[$line - 1], $ignoreMarker)) {
            continue;
        }

        $prev = previousNonWhitespaceToken($tokens, $i);
        $next = nextNonWhitespaceToken($tokens, $i);
        if ($next !== '(') {
            continue;
        }

        if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }

        $relativePath = str_replace(dirname(__DIR__).'/', '', $path);
        $violations[] = sprintf('%s:%d forbidden call `%s()` in unit test', $relativePath, max(1, $line), $name);
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Unit purity violations detected:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, " - {$violation}\n");
    }
    fwrite(STDERR, "Move these tests to tests/Integration or mock/stub the dependency.\n");
    exit(1);
}

fwrite(STDOUT, "Unit purity check OK.\n");
exit(0);

/**
 * @param array<int, mixed> $tokens
 * @return array{int, string, int}|string|null
 */
function previousNonWhitespaceToken(array $tokens, int $index): array|string|null
{
    for ($i = $index - 1; $i >= 0; --$i) {
        $candidate = $tokens[$i];
        if (is_string($candidate)) {
            if (trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        if (in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $candidate;
    }

    return null;
}

/**
 * @param array<int, mixed> $tokens
 * @return array{int, string, int}|string|null
 */
function nextNonWhitespaceToken(array $tokens, int $index): array|string|null
{
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; ++$i) {
        $candidate = $tokens[$i];
        if (is_string($candidate)) {
            if (trim($candidate) === '') {
                continue;
            }

            return $candidate;
        }

        if (in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $candidate;
    }

    return null;
}
