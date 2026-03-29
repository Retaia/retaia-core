<?php

namespace App\Infrastructure\Asset;

use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;

final class AssetDerivedViewProjector
{
    public function __construct(
        private DerivedFileRepositoryInterface $derivedFiles,
    ) {
    }

    /**
     * @return array{preview_video_url: ?string, preview_audio_url: ?string, preview_photo_url: ?string, waveform_url: ?string, thumbs: list<string>}
     */
    public function project(Asset $asset): array
    {
        $fields = $asset->getFields();
        $derived = is_array($fields['derived'] ?? null) ? $fields['derived'] : [];
        $files = $this->derivedFiles->listByAsset($asset->getUuid());
        $byKind = [];
        foreach ($files as $file) {
            $byKind[$file->kind] ??= $file;
        }

        $thumbs = $derived['thumbs'] ?? $fields['thumbs'] ?? [];
        if (!is_array($thumbs)) {
            $thumbs = [];
        }
        if (isset($byKind['thumb'])) {
            $thumbs = [sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), 'thumb')];
        }

        return [
            'preview_video_url' => isset($byKind['proxy_video'])
                ? sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), 'proxy_video')
                : $this->optionalString($derived['preview_video_url'] ?? $derived['proxy_video_url'] ?? $fields['preview_video_url'] ?? $fields['proxy_video_url'] ?? null),
            'preview_audio_url' => isset($byKind['proxy_audio'])
                ? sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), 'proxy_audio')
                : $this->optionalString($derived['preview_audio_url'] ?? $derived['proxy_audio_url'] ?? $fields['preview_audio_url'] ?? $fields['proxy_audio_url'] ?? null),
            'preview_photo_url' => isset($byKind['proxy_photo'])
                ? sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), 'proxy_photo')
                : $this->optionalString($derived['preview_photo_url'] ?? $derived['proxy_photo_url'] ?? $fields['preview_photo_url'] ?? $fields['proxy_photo_url'] ?? null),
            'waveform_url' => isset($byKind['waveform'])
                ? sprintf('/api/v1/assets/%s/derived/%s', $asset->getUuid(), 'waveform')
                : $this->optionalString($derived['waveform_url'] ?? $fields['waveform_url'] ?? null),
            'thumbs' => array_values(array_filter(
                array_map(static fn (mixed $thumb): string => (string) $thumb, $thumbs),
                static fn (string $thumb): bool => $thumb !== ''
            )),
        ];
    }

    public function hasPreview(Asset $asset): bool
    {
        $derived = $this->project($asset);

        return $derived['preview_video_url'] !== null
            || $derived['preview_audio_url'] !== null
            || $derived['preview_photo_url'] !== null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
