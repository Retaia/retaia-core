<?php

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

final class FlysystemAtomicWriter
{
    public function __construct(
        private StoragePathNormalizer $paths,
    ) {
    }

    public function write(FilesystemOperator $filesystem, string $path, string $contents): void
    {
        $this->paths->ensureParentDirectory($path, $filesystem->createDirectory(...));
        $filesystem->write($path, $contents);
    }

    public function writeAtomically(FilesystemOperator $filesystem, string $path, string $contents): void
    {
        $this->paths->ensureParentDirectory($path, $filesystem->createDirectory(...));
        $tempPath = sprintf('%s.tmp.%s', $path, bin2hex(random_bytes(6)));
        $filesystem->write($tempPath, $contents);

        try {
            if ($filesystem->fileExists($path)) {
                $filesystem->delete($path);
            }
            $filesystem->move($tempPath, $path);
        } catch (\Throwable $exception) {
            if ($filesystem->fileExists($tempPath)) {
                $filesystem->delete($tempPath);
            }
            throw $exception;
        }
    }

    public function probeWritableDirectory(FilesystemOperator $filesystem, string $directory, callable $write, callable $deleteFile): bool
    {
        $probe = rtrim($directory, '/').'/.__retaia_probe_'.bin2hex(random_bytes(6));

        try {
            $write($probe, 'ok');
            $deleteFile($probe);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
