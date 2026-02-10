<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Keep test runs isolated: reset persisted cache pools (incl. rate limiter state).
$testPoolsDir = dirname(__DIR__).'/var/cache/test/pools';
if (is_dir($testPoolsDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testPoolsDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }
}
