<?php

namespace App\Ingest\Service;

use App\Ingest\Port\FilePollerInterface;
use App\Storage\BusinessStorageFile;
use App\Storage\BusinessStorageRegistryInterface;

class FilesystemFilePoller implements FilePollerInterface
{
    public function __construct(
        private BusinessStorageRegistryInterface $storageRegistry,
    ) {
    }

    /**
     * @return list<array{storage_id:string,path:string,size:int,mtime:\DateTimeImmutable}>
     */
    public function poll(int $limit = 100): array
    {
        $limit = max(1, $limit);
        $rows = [];

        foreach ($this->storageRegistry->ingestEnabled() as $definition) {
            foreach ($definition->storage->listFiles($definition->storage->watchDirectory(), true) as $file) {
                try {
                    $row = $this->buildRow($definition->id, $definition->storage->watchDirectory(), $file);
                } catch (\Throwable) {
                    $row = null;
                }
                if ($row === null) {
                    continue;
                }

                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array{storage_id:string,path:string,size:int,mtime:\DateTimeImmutable}|null
     */
    protected function buildRow(string $storageId, string $watchDirectory, BusinessStorageFile $file): ?array
    {
        $prefix = rtrim($watchDirectory, '/').'/';
        if (!str_starts_with($file->path, $prefix)) {
            return null;
        }

        $normalizedPath = substr($file->path, strlen($prefix));
        if ($normalizedPath === '' || str_contains($normalizedPath, '../')) {
            return null;
        }

        return [
            'storage_id' => $storageId,
            'path' => $normalizedPath,
            'size' => $file->size,
            'mtime' => $file->lastModified,
        ];
    }
}
