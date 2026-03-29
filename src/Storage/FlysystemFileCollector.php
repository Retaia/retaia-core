<?php

namespace App\Storage;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

final class FlysystemFileCollector
{
    /**
     * @return list<BusinessStorageFile>
     */
    public function collect(FilesystemOperator $filesystem, string $directory, bool $recursive): array
    {
        $files = [];

        try {
            $listing = $filesystem->listContents($directory, false);
        } catch (\Throwable) {
            return [];
        }

        try {
            /** @var StorageAttributes $attributes */
            foreach ($listing as $attributes) {
                if ($attributes instanceof FileAttributes) {
                    $lastModified = $attributes->lastModified();
                    $files[] = new BusinessStorageFile(
                        $attributes->path(),
                        $attributes->fileSize() ?? $filesystem->fileSize($attributes->path()),
                        new \DateTimeImmutable('@'.($lastModified ?? $filesystem->lastModified($attributes->path()))),
                    );
                    continue;
                }

                if ($recursive && $attributes instanceof DirectoryAttributes) {
                    array_push($files, ...$this->collect($filesystem, $attributes->path(), true));
                }
            }
        } catch (\Throwable) {
            return $files;
        }

        return $files;
    }
}
