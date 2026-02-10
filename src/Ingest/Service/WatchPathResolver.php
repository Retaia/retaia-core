<?php

namespace App\Ingest\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WatchPathResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%app.ingest.watch_path%')]
        private string $configuredPath,
    ) {
    }

    public function resolve(): string
    {
        $candidate = trim($this->configuredPath);
        if ($candidate === '') {
            throw new \RuntimeException('APP_INGEST_WATCH_PATH cannot be empty.');
        }

        $path = $this->toAbsolutePath($candidate);
        if (!is_dir($path)) {
            throw new \RuntimeException(sprintf('Ingest watch path does not exist: %s', $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('Ingest watch path is not readable: %s', $path));
        }

        $normalized = realpath($path);
        if ($normalized === false) {
            throw new \RuntimeException(sprintf('Ingest watch path cannot be resolved: %s', $path));
        }

        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    public function resolveRoot(): string
    {
        return dirname($this->resolve());
    }

    private function toAbsolutePath(string $path): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
