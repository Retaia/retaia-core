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

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($unitDir, FilesystemIterator::SKIP_DOTS)
);

$violations = [];
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        continue;
    }

    foreach ($forbiddenCalls as $call) {
        if (!preg_match('/\b'.preg_quote($call, '/').'\s*\(/', $contents)) {
            continue;
        }
        $violations[] = sprintf('%s: forbidden call `%s()` in unit test', $path, $call);
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

