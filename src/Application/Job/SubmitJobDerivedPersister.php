<?php

namespace App\Application\Job;

use App\Derived\DerivedFileRepositoryInterface;

final class SubmitJobDerivedPersister
{
    public function __construct(
        private DerivedFileRepositoryInterface $derivedFiles,
    ) {
    }

    /**
     * @param array<string, mixed> $derivedPatch
     */
    public function persist(string $assetUuid, array $derivedPatch): void
    {
        $manifest = is_array($derivedPatch['derived_manifest'] ?? null) ? $derivedPatch['derived_manifest'] : [];
        foreach ($manifest as $item) {
            if (!is_array($item)) {
                continue;
            }

            $kind = trim((string) ($item['kind'] ?? ''));
            $ref = trim((string) ($item['ref'] ?? ''));
            if ($kind === '' || $ref === '') {
                continue;
            }

            $this->derivedFiles->upsertMaterialized(
                $assetUuid,
                $kind,
                $this->contentTypeForDerivedKind($kind, $ref),
                is_int($item['size_bytes'] ?? null) ? $item['size_bytes'] : 0,
                is_string($item['sha256'] ?? null) ? $item['sha256'] : null,
                ltrim($ref, '/'),
            );
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $derivedPatch
     * @return array<string, mixed>
     */
    public function applyFlags(array $fields, array $derivedPatch): array
    {
        $manifest = is_array($derivedPatch['derived_manifest'] ?? null) ? $derivedPatch['derived_manifest'] : [];
        foreach ($manifest as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            if (in_array($kind, ['proxy_video', 'proxy_audio', 'proxy_photo'], true)) {
                $fields['proxy_done'] = true;
            }
            if ($kind === 'thumb') {
                $fields['thumbs_done'] = true;
            }
            if ($kind === 'waveform') {
                $fields['waveform_done'] = true;
            }
        }

        return $fields;
    }

    private function contentTypeForDerivedKind(string $kind, string $ref): string
    {
        $ext = strtolower(pathinfo($ref, PATHINFO_EXTENSION));

        return match ($kind) {
            'proxy_photo', 'thumb' => match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            },
            'proxy_audio' => match ($ext) {
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                default => 'audio/mp4',
            },
            'waveform' => match ($ext) {
                'json' => 'application/json',
                'png' => 'image/png',
                default => 'application/octet-stream',
            },
            default => match ($ext) {
                'webm' => 'video/webm',
                'mov' => 'video/quicktime',
                default => 'video/mp4',
            },
        };
    }
}
