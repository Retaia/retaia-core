<?php

namespace App\Ingest\Service;

use App\Ingest\Port\FilePollerInterface;

class FilesystemFilePoller implements FilePollerInterface
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
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $rows = [];
        foreach ($finder as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            try {
                $row = $this->buildRow($file, $root);
            } catch (\Throwable) {
                $row = null;
            }
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp($left['path'], $right['path']);
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array{path:string,size:int,mtime:\DateTimeImmutable}|null
     */
    protected function buildRow(\SplFileInfo $file, string $root): ?array
    {
        try {
            $resolved = realpath($file->getPathname());
            if (!is_string($resolved) || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                return null;
            }
            if (!is_readable($resolved)) {
                return null;
            }

            $relative = ltrim(substr($resolved, strlen($root)), DIRECTORY_SEPARATOR);
            $normalizedPath = str_replace('\\', '/', $relative);
            if ($normalizedPath === '' || str_contains($normalizedPath, '../')) {
                return null;
            }

            $size = filesize($resolved);
            $mtimeRaw = filemtime($resolved);
            if (!is_int($size) || !is_int($mtimeRaw)) {
                return null;
            }

            $mtime = \DateTimeImmutable::createFromFormat('U', (string) $mtimeRaw);

            return [
                'path' => $normalizedPath,
                'size' => $size,
                'mtime' => $mtime ?: new \DateTimeImmutable('@0'),
            ];
        } catch (\Throwable) {
            // Files can be renamed/deleted while scanning; keep polling resilient.
            return null;
        }
    }
}
