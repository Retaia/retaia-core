<?php

namespace App\Ingest\Service;

class SidecarFileDetector
{
    /** @var array<int, string> */
    private const RAW_EXTENSIONS = ['cr2', 'cr3', 'nef', 'arw', 'dng', 'rw2', 'orf', 'raf'];
    /** @var array<int, string> */
    private const VIDEO_EXTENSIONS = ['mov', 'mp4', 'mxf', 'avi', 'mkv'];
    /** @var array<int, string> */
    private const PHOTO_PROXY_EXTENSIONS = ['jpg', 'jpeg', 'webp'];
    /** @var array<int, string> */
    private const AUDIO_EXTENSIONS = ['wav', 'mp3', 'aac'];
    /** @var array<int, string> */
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** @var array<int, string> */
    private const LEGACY_VIDEO_SIDECAR_EXTENSIONS = ['lrv', 'thm'];
    /** @var array<int, string> */
    private const AUXILIARY_SIDECAR_EXTENSIONS = ['xmp', 'srt', 'lrv', 'thm'];
    /** @var array<int, string> */
    private const PROXY_FOLDER_NAMES = ['proxy', 'proxies', 'proxie'];

    public function __construct(
        private bool $videoLegacySidecarsEnabled = false,
    ) {
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectProxyFile(string $filePath, callable $fileExists): ?array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);
        $basename = pathinfo($normalized, PATHINFO_FILENAME);

        // DJI proxy sidecar.
        if ($extension === 'lrf') {
            $original = $this->findSiblingByExtensions($normalized, self::VIDEO_EXTENSIONS, $fileExists);
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
            $original = $this->findSiblingByExtensions($normalized, self::RAW_EXTENSIONS, $fileExists);
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
        $folderProxyOriginal = $this->findProxyFolderParentOriginal($normalized, $basename, $fileExists);
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
    public function detectExistingProxyForOriginal(string $filePath, callable $fileExists): ?array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);

        if (in_array($extension, self::RAW_EXTENSIONS, true)) {
            $proxy = $this->findSiblingByExtensions($normalized, self::PHOTO_PROXY_EXTENSIONS, $fileExists);
            $proxy ??= $this->findProxyInSiblingProxyFolders($normalized, self::PHOTO_PROXY_EXTENSIONS, $fileExists);
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
            $proxy = $this->findSiblingByExtensions($normalized, ['lrf'], $fileExists);
            $proxy ??= $this->findProxyInSiblingProxyFolders($normalized, array_merge(self::VIDEO_EXTENSIONS, ['lrf']), $fileExists);
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
     * @return array{path:string,type:string,original:string}|null
     */
    public function detectAuxiliarySidecarFile(string $filePath, callable $fileExists): ?array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);
        if (!$this->isAuxiliarySidecarExtension($extension)) {
            return null;
        }
        if ($this->auxiliaryUnmatchedReason($normalized, $fileExists) !== null) {
            return null;
        }
        $originalExtensions = $this->originalExtensionsForAuxiliary($extension);

        $originalCandidates = $this->findSiblingCandidatesByExtensions($normalized, $originalExtensions, $fileExists);
        if (count($originalCandidates) !== 1) {
            return null;
        }
        $original = $originalCandidates[0];

        return [
            'path' => $normalized,
            'type' => $extension,
            'original' => $original,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function detectExistingAuxiliarySidecarsForOriginal(string $filePath, callable $fileExists): array
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return [];
        }

        $extension = $this->extension($normalized);
        $sidecarExtensions = [];

        if (in_array($extension, array_merge(self::RAW_EXTENSIONS, self::PHOTO_EXTENSIONS, self::VIDEO_EXTENSIONS), true)) {
            $sidecarExtensions[] = 'xmp';
        }
        if (in_array($extension, array_merge(self::VIDEO_EXTENSIONS, self::AUDIO_EXTENSIONS), true)) {
            $sidecarExtensions[] = 'srt';
        }
        if ($this->videoLegacySidecarsEnabled && in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            $sidecarExtensions = array_merge($sidecarExtensions, self::LEGACY_VIDEO_SIDECAR_EXTENSIONS);
        }

        if ($sidecarExtensions === []) {
            return [];
        }

        $sidecars = [];
        $dirname = dirname($normalized);
        $basename = pathinfo($normalized, PATHINFO_FILENAME);
        foreach ($sidecarExtensions as $sidecarExtension) {
            $candidate = ($dirname === '.' ? '' : $dirname.'/').$basename.'.'.$sidecarExtension;
            if (!$fileExists($candidate)) {
                continue;
            }
            $detected = $this->detectAuxiliarySidecarFile($candidate, $fileExists);
            if (is_array($detected) && (string) ($detected['original'] ?? '') === $normalized) {
                $sidecars[] = $candidate;
            }
        }

        return array_values(array_unique($sidecars));
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectForPath(string $filePath, callable $fileExists): ?array
    {
        $asProxy = $this->detectProxyFile($filePath, $fileExists);
        if ($asProxy !== null) {
            return $asProxy;
        }

        return $this->detectExistingProxyForOriginal($filePath, $fileExists);
    }

    public function isProxyCandidatePath(string $filePath): bool
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return false;
        }

        $extension = $this->extension($normalized);
        if ($extension === 'lrf') {
            return true;
        }

        return $this->isInsideProxyFolder($normalized)
            && $this->proxyKindForExtension($extension) !== null;
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

    private function findSiblingByExtensions(string $path, array $extensions, callable $fileExists): ?string
    {
        $dirname = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);

        foreach ($extensions as $extension) {
            $candidate = ($dirname === '.' ? '' : $dirname.'/').$basename.'.'.$extension;
            if ($candidate === $path) {
                continue;
            }
            if ($fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function findSiblingCandidatesByExtensions(string $path, array $extensions, callable $fileExists): array
    {
        $dirname = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);
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

    public function isAuxiliarySidecarPath(string $filePath): bool
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return false;
        }

        return $this->isAuxiliarySidecarExtension($this->extension($normalized));
    }

    public function auxiliaryUnmatchedReason(string $filePath, callable $fileExists): ?string
    {
        $normalized = $this->normalizePath($filePath);
        if (!$this->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->extension($normalized);
        if (!$this->isAuxiliarySidecarExtension($extension)) {
            return null;
        }
        if (!$this->isAttachableAuxiliarySidecarExtension($extension)) {
            return 'disabled_by_policy';
        }

        $originalExtensions = $this->originalExtensionsForAuxiliary($extension);
        $originalCandidates = $this->findSiblingCandidatesByExtensions($normalized, $originalExtensions, $fileExists);
        if (count($originalCandidates) === 0) {
            return 'missing_parent';
        }
        if (count($originalCandidates) > 1) {
            return 'ambiguous_parent';
        }

        return null;
    }

    private function isAuxiliarySidecarExtension(string $extension): bool
    {
        return in_array($extension, self::AUXILIARY_SIDECAR_EXTENSIONS, true);
    }

    private function isAttachableAuxiliarySidecarExtension(string $extension): bool
    {
        if (!in_array($extension, self::LEGACY_VIDEO_SIDECAR_EXTENSIONS, true)) {
            return true;
        }

        return $this->videoLegacySidecarsEnabled;
    }

    /**
     * @return array<int, string>
     */
    private function originalExtensionsForAuxiliary(string $extension): array
    {
        return match ($extension) {
            'xmp' => array_merge(self::RAW_EXTENSIONS, self::PHOTO_EXTENSIONS, self::VIDEO_EXTENSIONS),
            'srt' => array_merge(self::VIDEO_EXTENSIONS, self::AUDIO_EXTENSIONS),
            'lrv', 'thm' => self::VIDEO_EXTENSIONS,
            default => [],
        };
    }

    private function findProxyFolderParentOriginal(string $path, string $basename, callable $fileExists): ?string
    {
        $parts = explode('/', $path);
        $proxyFolderIndex = $this->proxyFolderIndex($parts);

        if ($proxyFolderIndex === null || $proxyFolderIndex < 1) {
            return null;
        }

        $parentParts = array_slice($parts, 0, $proxyFolderIndex);
        $parentDir = implode('/', $parentParts);
        $extensions = array_merge(self::VIDEO_EXTENSIONS, self::RAW_EXTENSIONS, self::AUDIO_EXTENSIONS);

        foreach ($extensions as $extension) {
            $candidate = $parentDir.'/'.$basename.'.'.$extension;
            if ($fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isInsideProxyFolder(string $path): bool
    {
        return $this->proxyFolderIndex(explode('/', $path)) !== null;
    }

    /**
     * @param array<int, string> $parts
     */
    private function proxyFolderIndex(array $parts): ?int
    {
        foreach ($parts as $index => $part) {
            if (in_array(strtolower($part), self::PROXY_FOLDER_NAMES, true)) {
                return $index;
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
        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return 'proxy_audio';
        }

        return null;
    }

    private function findProxyInSiblingProxyFolders(string $path, array $extensions, callable $fileExists): ?string
    {
        $dirname = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $baseDir = $dirname === '.' ? '' : $dirname;

        foreach (self::PROXY_FOLDER_NAMES as $folderName) {
            foreach ($extensions as $extension) {
                $candidate = ($baseDir === '' ? '' : $baseDir.'/').$folderName.'/'.$basename.'.'.$extension;
                if ($fileExists($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
