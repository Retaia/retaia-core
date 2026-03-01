<?php

namespace App\Ingest\Service;

class ProxyFileDetector
{
    /** @var array<int, string> */
    private const RAW_EXTENSIONS = ['cr2', 'cr3', 'nef', 'arw', 'dng', 'rw2', 'orf', 'raf'];
    /** @var array<int, string> */
    private const VIDEO_EXTENSIONS = ['mov', 'mp4', 'mxf', 'avi', 'mkv'];
    /** @var array<int, string> */
    private const PHOTO_PROXY_EXTENSIONS = ['jpg', 'jpeg', 'webp'];
    /** @var array<int, string> */
    private const PROXY_FOLDER_NAMES = ['proxy', 'proxies', 'proxie'];

    public function __construct(
        private WatchPathResolver $watchPathResolver,
    ) {
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectProxyFile(string $filePath): ?array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);
        $basename = pathinfo($normalized, PATHINFO_FILENAME);

        // DJI proxy sidecar.
        if ($extension === 'lrf') {
            $original = $this->findSiblingByExtensions($normalized, self::VIDEO_EXTENSIONS);
            if ($original === null) {
                return null;
            }

            return [
                'path' => $normalized,
                'type' => 'lrf',
                'kind' => 'proxy_video',
                'original' => $original,
            ];
        }

        // Camera JPEG sidecar used as existing photo proxy for RAW.
        if (in_array($extension, self::PHOTO_PROXY_EXTENSIONS, true)) {
            $original = $this->findSiblingByExtensions($normalized, self::RAW_EXTENSIONS);
            if ($original === null) {
                return null;
            }

            return [
                'path' => $normalized,
                'type' => 'raw_jpg',
                'kind' => 'proxy_photo',
                'original' => $original,
            ];
        }

        // DaVinci-style proxy folder.
        $folderProxyOriginal = $this->findProxyFolderParentOriginal($normalized, $basename);
        if ($folderProxyOriginal !== null) {
            $kind = $this->proxyKindForExtension($extension);
            if ($kind !== null) {
                return [
                    'path' => $normalized,
                    'type' => 'proxy_folder',
                    'kind' => $kind,
                    'original' => $folderProxyOriginal,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectExistingProxyForOriginal(string $filePath): ?array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);

        if (in_array($extension, self::RAW_EXTENSIONS, true)) {
            $proxy = $this->findSiblingByExtensions($normalized, self::PHOTO_PROXY_EXTENSIONS);
            $proxy ??= $this->findProxyInSiblingProxyFolders($normalized, self::PHOTO_PROXY_EXTENSIONS);
            if ($proxy !== null) {
                return [
                    'path' => $proxy,
                    'type' => 'raw_jpg',
                    'kind' => 'proxy_photo',
                    'original' => $normalized,
                ];
            }
        }

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            $proxy = $this->findSiblingByExtensions($normalized, ['lrf']);
            $proxy ??= $this->findProxyInSiblingProxyFolders($normalized, array_merge(self::VIDEO_EXTENSIONS, ['lrf']));
            if ($proxy !== null) {
                return [
                    'path' => $proxy,
                    'type' => 'lrf',
                    'kind' => 'proxy_video',
                    'original' => $normalized,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectForPath(string $filePath): ?array
    {
        $asProxy = $this->detectProxyFile($filePath);
        if ($asProxy !== null) {
            return $asProxy;
        }

        return $this->detectExistingProxyForOriginal($filePath);
    }

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function isInboxPath(string $path): bool
    {
        $parts = explode('/', $path);

        return isset($parts[0]) && strtolower((string) $parts[0]) === 'inbox';
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function fileExists(string $relativePath): bool
    {
        $root = rtrim($this->watchPathResolver->resolveRoot(), DIRECTORY_SEPARATOR);
        if ($root === '') {
            return false;
        }

        $absolute = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_file($absolute);
    }

    private function findSiblingByExtensions(string $path, array $extensions): ?string
    {
        $dirname = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);

        foreach ($extensions as $extension) {
            $candidate = ($dirname === '.' ? '' : $dirname.'/').$basename.'.'.$extension;
            if ($candidate === $path) {
                continue;
            }
            if ($this->fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findProxyFolderParentOriginal(string $path, string $basename): ?string
    {
        $parts = explode('/', $path);
        $proxyFolderIndex = null;
        foreach ($parts as $index => $part) {
            if (in_array(strtolower($part), self::PROXY_FOLDER_NAMES, true)) {
                $proxyFolderIndex = $index;
                break;
            }
        }

        if ($proxyFolderIndex === null || $proxyFolderIndex < 1) {
            return null;
        }

        $parentParts = array_slice($parts, 0, $proxyFolderIndex);
        $parentDir = implode('/', $parentParts);
        $extensions = array_merge(self::VIDEO_EXTENSIONS, self::RAW_EXTENSIONS, ['wav', 'mp3', 'aac']);

        foreach ($extensions as $extension) {
            $candidate = $parentDir.'/'.$basename.'.'.$extension;
            if ($this->fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function proxyKindForExtension(string $extension): ?string
    {
        if (in_array($extension, self::PHOTO_PROXY_EXTENSIONS, true)) {
            return 'proxy_photo';
        }
        if (in_array($extension, self::VIDEO_EXTENSIONS, true) || $extension === 'lrf') {
            return 'proxy_video';
        }
        if (in_array($extension, ['wav', 'mp3', 'aac'], true)) {
            return 'proxy_audio';
        }

        return null;
    }

    private function findProxyInSiblingProxyFolders(string $path, array $extensions): ?string
    {
        $dirname = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $baseDir = $dirname === '.' ? '' : $dirname;

        foreach (self::PROXY_FOLDER_NAMES as $folderName) {
            foreach ($extensions as $extension) {
                $candidate = ($baseDir === '' ? '' : $baseDir.'/').$folderName.'/'.$basename.'.'.$extension;
                if ($this->fileExists($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
