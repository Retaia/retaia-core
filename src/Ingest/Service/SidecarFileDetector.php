<?php

namespace App\Ingest\Service;

class SidecarFileDetector
{
    public function __construct(
        private SidecarDetectionRules $rules = new SidecarDetectionRules(),
        private SidecarPathResolver $paths = new SidecarPathResolver(),
    ) {
    }

    /**
     * @return array{path:string,type:string,kind:string,original:string}|null
     */
    public function detectProxyFile(string $filePath, callable $fileExists): ?array
    {
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->paths->extension($normalized);
        $basename = $this->paths->basename($normalized);

        // DJI proxy sidecar.
        if ($extension === 'lrf') {
            $original = $this->paths->findSiblingByExtensions($normalized, $this->rules->videoExtensions(), $fileExists);
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
        if (in_array($extension, $this->rules->photoProxyExtensions(), true)) {
            $original = $this->paths->findSiblingByExtensions($normalized, $this->rules->rawExtensions(), $fileExists);
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
        $folderProxyOriginal = $this->paths->findProxyFolderParentOriginal(
            $normalized,
            $basename,
            $this->rules->proxyFolderNames(),
            array_merge($this->rules->videoExtensions(), $this->rules->rawExtensions(), ['wav', 'mp3', 'aac']),
            $fileExists
        );
        if ($folderProxyOriginal !== null) {
            $kind = $this->rules->proxyKindForExtension($extension);
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
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->paths->extension($normalized);

        if (in_array($extension, $this->rules->rawExtensions(), true)) {
            $proxy = $this->paths->findSiblingByExtensions($normalized, $this->rules->photoProxyExtensions(), $fileExists);
            $proxy ??= $this->paths->findProxyInSiblingProxyFolders($normalized, $this->rules->proxyFolderNames(), $this->rules->photoProxyExtensions(), $fileExists);
            if ($proxy !== null) {
                return [
                    'path' => $proxy,
                    'type' => 'raw_jpg',
                    'kind' => 'proxy_photo',
                    'original' => $normalized,
                ];
            }
        }

        if (in_array($extension, $this->rules->videoExtensions(), true)) {
            $proxy = $this->paths->findSiblingByExtensions($normalized, ['lrf'], $fileExists);
            $proxy ??= $this->paths->findProxyInSiblingProxyFolders($normalized, $this->rules->proxyFolderNames(), $this->rules->videoProxyExtensions(), $fileExists);
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
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->paths->extension($normalized);
        if (!$this->rules->isAuxiliarySidecarExtension($extension)) {
            return null;
        }
        if ($this->auxiliaryUnmatchedReason($normalized, $fileExists) !== null) {
            return null;
        }
        $originalExtensions = $this->rules->originalExtensionsForAuxiliary($extension);

        $originalCandidates = $this->paths->findSiblingCandidatesByExtensions($normalized, $originalExtensions, $fileExists);
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
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return [];
        }

        $extension = $this->paths->extension($normalized);
        $sidecarExtensions = $this->rules->existingAuxiliaryExtensionsForOriginal($extension);

        if ($sidecarExtensions === []) {
            return [];
        }

        $sidecars = [];
        $dirname = dirname($normalized);
        $basename = $this->paths->basename($normalized);
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
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return false;
        }

        $extension = $this->paths->extension($normalized);
        if ($extension === 'lrf') {
            return true;
        }

        return $this->paths->isInsideProxyFolder($normalized, $this->rules->proxyFolderNames())
            && $this->rules->proxyKindForExtension($extension) !== null;
    }

    public function isAuxiliarySidecarPath(string $filePath): bool
    {
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return false;
        }

        return $this->rules->isAuxiliarySidecarExtension($this->paths->extension($normalized));
    }

    public function auxiliaryUnmatchedReason(string $filePath, callable $fileExists): ?string
    {
        $normalized = $this->paths->normalizePath($filePath);
        if (!$this->paths->isInboxPath($normalized)) {
            return null;
        }

        $extension = $this->paths->extension($normalized);
        if (!$this->rules->isAuxiliarySidecarExtension($extension)) {
            return null;
        }
        if (!$this->rules->isAttachableAuxiliarySidecarExtension($extension)) {
            return 'disabled_by_policy';
        }

        $originalExtensions = $this->rules->originalExtensionsForAuxiliary($extension);
        $originalCandidates = $this->paths->findSiblingCandidatesByExtensions($normalized, $originalExtensions, $fileExists);
        if (count($originalCandidates) === 0) {
            return 'missing_parent';
        }
        if (count($originalCandidates) > 1) {
            return 'ambiguous_parent';
        }

        return null;
    }
}
