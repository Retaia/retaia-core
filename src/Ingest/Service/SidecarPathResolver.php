<?php

namespace App\Ingest\Service;

final class SidecarPathResolver
{
    public function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    public function isInboxPath(string $path): bool
    {
        $parts = explode('/', $path);

        return isset($parts[0]) && strtolower((string) $parts[0]) === 'inbox';
    }

    public function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    public function basename(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    public function findSiblingByExtensions(string $path, array $extensions, callable $fileExists): ?string
    {
        foreach ($this->findSiblingCandidatesByExtensions($path, $extensions, $fileExists) as $candidate) {
            return $candidate;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function findSiblingCandidatesByExtensions(string $path, array $extensions, callable $fileExists): array
    {
        $dirname = dirname($path);
        $basename = $this->basename($path);
        $candidates = [];

        foreach ($extensions as $extension) {
            $candidate = ($dirname === '.' ? '' : $dirname.'/').$basename.'.'.$extension;
            if ($candidate === $path) {
                continue;
            }
            if ($fileExists($candidate)) {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    public function findProxyFolderParentOriginal(string $path, string $basename, array $proxyFolderNames, array $extensions, callable $fileExists): ?string
    {
        $parts = explode('/', $path);
        $proxyFolderIndex = $this->proxyFolderIndex($parts, $proxyFolderNames);

        if ($proxyFolderIndex === null || $proxyFolderIndex < 1) {
            return null;
        }

        $parentParts = array_slice($parts, 0, $proxyFolderIndex);
        $parentDir = implode('/', $parentParts);

        foreach ($extensions as $extension) {
            $candidate = $parentDir.'/'.$basename.'.'.$extension;
            if ($fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function isInsideProxyFolder(string $path, array $proxyFolderNames): bool
    {
        return $this->proxyFolderIndex(explode('/', $path), $proxyFolderNames) !== null;
    }

    public function findProxyInSiblingProxyFolders(string $path, array $proxyFolderNames, array $extensions, callable $fileExists): ?string
    {
        $dirname = dirname($path);
        $basename = $this->basename($path);
        $baseDir = $dirname === '.' ? '' : $dirname;

        foreach ($proxyFolderNames as $folderName) {
            foreach ($extensions as $extension) {
                $candidate = ($baseDir === '' ? '' : $baseDir.'/').$folderName.'/'.$basename.'.'.$extension;
                if ($fileExists($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $parts
     * @param array<int, string> $proxyFolderNames
     */
    private function proxyFolderIndex(array $parts, array $proxyFolderNames): ?int
    {
        foreach ($parts as $index => $part) {
            if (in_array(strtolower($part), $proxyFolderNames, true)) {
                return $index;
            }
        }

        return null;
    }
}
