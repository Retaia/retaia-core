#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: validate-commit-message.php <path-to-commit-msg>\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "Commit message file not found: {$path}\n");
    exit(1);
}

$message = trim((string) file_get_contents($path));

$pattern = '/^(build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test)(\([a-z0-9._\/-]+\))?(!)?: .+$/';

if (preg_match($pattern, $message) === 1) {
    exit(0);
}

fwrite(STDERR, "Invalid commit message. Use Conventional Commits.\n");
fwrite(STDERR, "Example: feat(auth): add login rate limiter\n");
exit(1);
