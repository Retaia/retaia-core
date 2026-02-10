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
        $limit = max(1, $limit);

        $finder = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rows = [];
        foreach ($finder as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $mtime = \DateTimeImmutable::createFromFormat('U', (string) $file->getMTime());
            $rows[] = [
                'path' => str_replace($path.DIRECTORY_SEPARATOR, '', $file->getPathname()),
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

