<?php

namespace App\Ingest\Service;

final class SidecarDetectionRules
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
     * @return array<int, string>
     */
    public function rawExtensions(): array
    {
        return self::RAW_EXTENSIONS;
    }

    /**
     * @return array<int, string>
     */
    public function videoExtensions(): array
    {
        return self::VIDEO_EXTENSIONS;
    }

    /**
     * @return array<int, string>
     */
    public function photoProxyExtensions(): array
    {
        return self::PHOTO_PROXY_EXTENSIONS;
    }

    /**
     * @return array<int, string>
     */
    public function videoProxyExtensions(): array
    {
        return array_merge(self::VIDEO_EXTENSIONS, ['lrf']);
    }

    /**
     * @return array<int, string>
     */
    public function proxyFolderNames(): array
    {
        return self::PROXY_FOLDER_NAMES;
    }

    public function isAuxiliarySidecarExtension(string $extension): bool
    {
        return in_array($extension, self::AUXILIARY_SIDECAR_EXTENSIONS, true);
    }

    public function isAttachableAuxiliarySidecarExtension(string $extension): bool
    {
        if (!in_array($extension, self::LEGACY_VIDEO_SIDECAR_EXTENSIONS, true)) {
            return true;
        }

        return $this->videoLegacySidecarsEnabled;
    }

    /**
     * @return array<int, string>
     */
    public function existingAuxiliaryExtensionsForOriginal(string $extension): array
    {
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

        return array_values(array_unique($sidecarExtensions));
    }

    /**
     * @return array<int, string>
     */
    public function originalExtensionsForAuxiliary(string $extension): array
    {
        return match ($extension) {
            'xmp' => array_merge(self::RAW_EXTENSIONS, self::PHOTO_EXTENSIONS, self::VIDEO_EXTENSIONS),
            'srt' => array_merge(self::VIDEO_EXTENSIONS, self::AUDIO_EXTENSIONS),
            'lrv', 'thm' => self::VIDEO_EXTENSIONS,
            default => [],
        };
    }

    public function proxyKindForExtension(string $extension): ?string
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
}
