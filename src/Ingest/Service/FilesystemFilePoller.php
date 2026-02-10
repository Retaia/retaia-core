<?php

namespace App\Ingest\Service;

use App\Ingest\Port\FilePollerInterface;

final class FilesystemFilePoller implements FilePollerInterface
{
    public function __construct(
        private WatchPathResolver $watchPathResolver,
    ) {
    }

    /**
     * @return list<array{path:string,size:int,mtime:\DateTimeImmutable}>
     */
    public function poll(int $limit = 100): array
    {
        $path = $this->watchPathResolver->resolve();
        $root = rtrim((string) realpath($path), DIRECTORY_SEPARATOR);
        if ($root === '') {
            return [];
        }

        $limit = max(1, $limit);

        $finder = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rows = [];
        foreach ($finder as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $resolved = realpath($file->getPathname());
            if (!is_string($resolved) || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = ltrim(substr($resolved, strlen($root)), DIRECTORY_SEPARATOR);
            $normalizedPath = str_replace('\\', '/', $relative);
            if ($normalizedPath === '' || str_contains($normalizedPath, '../')) {
                continue;
            }

            $mtime = \DateTimeImmutable::createFromFormat('U', (string) $file->getMTime());
            $rows[] = [
                'path' => $normalizedPath,
                'size' => (int) $file->getSize(),
                'mtime' => $mtime ?: new \DateTimeImmutable('@0'),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp($left['path'], $right['path']);
        });

        return array_slice($rows, 0, $limit);
    }
}
