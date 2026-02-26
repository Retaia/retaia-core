<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\Port\AssetReadGateway as AssetReadGatewayPort;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;

final class AssetReadGateway implements AssetReadGatewayPort
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private bool $featureSuggestedTagsFiltersEnabled,
        private string $defaultStorageId = 'nas-main',
    ) {
    }

    public function getByUuid(string $uuid): ?array
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return null;
        }

        return $this->detail($asset);
    }

    public function list(
        ?string $state,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        int $limit,
        array $suggestedTags,
        string $suggestedTagsMode,
    ): ?array {
        if ($suggestedTags !== [] && !$this->featureSuggestedTagsFiltersEnabled) {
            return null;
        }

        $assets = $this->assets->listAssets($state, $mediaType, $query, max(200, $limit));
        $assets = $this->applyCapturedAtRange($assets, $capturedAtFrom, $capturedAtTo);
        $assets = $this->applySort($assets, $sort ?? '-created_at');
        if ($suggestedTags !== []) {
            $assets = array_values(array_filter(
                $assets,
                fn (Asset $asset): bool => $this->matchesSuggestedTags($asset, $suggestedTags, $suggestedTagsMode)
            ));
        }
        $assets = array_slice($assets, 0, max(1, min(200, $limit)));

        return array_map(fn (Asset $asset): array => $this->summary($asset), $assets);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        $fields = $asset->getFields();
        $source = $this->sourceFromFields($fields, $asset->getFilename());
        $derived = $this->derivedFromFields($fields);
        $summary = $this->summary($asset);

        return [
            'summary' => $summary,
            'paths' => $source,
            'processing' => [
                'facts_done' => (bool) ($fields['facts_done'] ?? false),
                'thumbs_done' => (bool) ($fields['thumbs_done'] ?? !empty($derived['thumbs'])),
                'proxy_done' => (bool) ($fields['proxy_done'] ?? ($derived['proxy_video_url'] !== null || $derived['proxy_audio_url'] !== null || $derived['proxy_photo_url'] !== null)),
                'waveform_done' => (bool) ($fields['waveform_done'] ?? $derived['waveform_url'] !== null),
                'processing_profile' => is_string($fields['processing_profile'] ?? null) ? $fields['processing_profile'] : null,
                'review_processing_version' => is_string($fields['review_processing_version'] ?? null) ? $fields['review_processing_version'] : null,
            ],
            'derived' => $derived,
            'transcript' => [
                'status' => $this->transcriptStatus($fields['transcript']['status'] ?? $fields['transcript_status'] ?? null),
                'text_preview' => is_string($fields['transcript']['text_preview'] ?? null)
                    ? $fields['transcript']['text_preview']
                    : (is_string($fields['transcript_text_preview'] ?? null) ? $fields['transcript_text_preview'] : null),
                'updated_at' => is_string($fields['transcript']['updated_at'] ?? null)
                    ? $fields['transcript']['updated_at']
                    : (is_string($fields['transcript_updated_at'] ?? null) ? $fields['transcript_updated_at'] : null),
            ],
            'decisions' => [
                'current' => is_array($fields['decisions']['current'] ?? null)
                    ? $fields['decisions']['current']
                    : (is_array($fields['decision_current'] ?? null) ? $fields['decision_current'] : null),
                'history' => is_array($fields['decisions']['history'] ?? null)
                    ? $fields['decisions']['history']
                    : (is_array($fields['decision_history'] ?? null) ? $fields['decision_history'] : []),
            ],
            'audit' => [
                'path_history' => $this->normalizePathHistory($fields['path_history'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Asset $asset): array
    {
        $fields = $asset->getFields();
        $derived = $this->derivedFromFields($fields);

        return [
            'uuid' => $asset->getUuid(),
            'name' => $asset->getFilename(),
            'media_type' => $asset->getMediaType(),
            'state' => $asset->getState()->value,
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
            'captured_at' => is_string($fields['captured_at'] ?? null) ? $fields['captured_at'] : null,
            'duration' => is_numeric($fields['duration'] ?? null) ? (float) $fields['duration'] : null,
            'tags' => $asset->getTags(),
            'has_proxy' => $derived['proxy_video_url'] !== null || $derived['proxy_audio_url'] !== null || $derived['proxy_photo_url'] !== null,
            'thumb_url' => $derived['thumbs'][0] ?? null,
        ];
    }

    /**
     * @param array<int, string> $expected
     */
    private function matchesSuggestedTags(Asset $asset, array $expected, string $mode): bool
    {
        $fields = $asset->getFields();
        $tags = [];
        if (is_array($fields['suggestions']['suggested_tags'] ?? null)) {
            $tags = $fields['suggestions']['suggested_tags'];
        } elseif (is_array($fields['suggested_tags'] ?? null)) {
            $tags = $fields['suggested_tags'];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), $tags),
            static fn (string $tag): bool => $tag !== ''
        ));

        if ($mode === 'OR') {
            foreach ($expected as $tag) {
                if (in_array($tag, $normalized, true)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($expected as $tag) {
            if (!in_array($tag, $normalized, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function sourceFromFields(array $fields, string $filename): array
    {
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        $storageId = trim((string) ($paths['storage_id'] ?? $fields['storage_id'] ?? $this->defaultStorageId));
        if ($storageId === '') {
            $storageId = $this->defaultStorageId;
        }

        $fallbackOriginal = $this->sanitizeRelativePath('INBOX/'.$filename);
        $original = $this->sanitizeRelativePath((string) ($paths['original_relative'] ?? $fields['current_path'] ?? $fields['source_path'] ?? ''));
        if ($original === '') {
            $original = $fallbackOriginal;
        }

        return [
            'storage_id' => $storageId,
            'original_relative' => $original,
            'sidecars_relative' => $this->sanitizeRelativePaths(is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : ($fields['sidecars_relative'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function derivedFromFields(array $fields): array
    {
        $derived = is_array($fields['derived'] ?? null) ? $fields['derived'] : [];

        $thumbs = $derived['thumbs'] ?? $fields['thumbs'] ?? [];
        if (!is_array($thumbs)) {
            $thumbs = [];
        }

        return [
            'proxy_video_url' => $this->optionalString($derived['proxy_video_url'] ?? $fields['proxy_video_url'] ?? null),
            'proxy_audio_url' => $this->optionalString($derived['proxy_audio_url'] ?? $fields['proxy_audio_url'] ?? null),
            'proxy_photo_url' => $this->optionalString($derived['proxy_photo_url'] ?? $fields['proxy_photo_url'] ?? null),
            'waveform_url' => $this->optionalString($derived['waveform_url'] ?? $fields['waveform_url'] ?? null),
            'thumbs' => array_values(array_filter(
                array_map(fn (mixed $thumb): string => (string) $thumb, $thumbs),
                static fn (string $thumb): bool => $thumb !== ''
            )),
        ];
    }

    private function transcriptStatus(mixed $value): string
    {
        $status = strtoupper(trim((string) $value));
        if (!in_array($status, ['NONE', 'RUNNING', 'DONE', 'FAILED'], true)) {
            return 'NONE';
        }

        return $status;
    }

    /**
     * @param mixed $history
     * @return array<int, string>
     */
    private function normalizePathHistory(mixed $history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $items = [];
        foreach ($history as $entry) {
            if (is_array($entry) && is_string($entry['to'] ?? null)) {
                $items[] = (string) $entry['to'];
                continue;
            }
            if (is_string($entry)) {
                $items[] = $entry;
            }
        }

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private function sanitizeRelativePath(string $path): string
    {
        $trimmed = ltrim(trim($path), '/');
        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, '../') || str_contains($trimmed, '..\\')) {
            return '';
        }

        return $trimmed;
    }

    /**
     * @param array<int, Asset> $assets
     * @return array<int, Asset>
     */
    private function applyCapturedAtRange(array $assets, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (!$from instanceof \DateTimeImmutable && !$to instanceof \DateTimeImmutable) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($from, $to): bool {
            $capturedAt = $this->capturedAt($asset);
            if (!$capturedAt instanceof \DateTimeImmutable) {
                return false;
            }

            if ($from instanceof \DateTimeImmutable && $capturedAt < $from) {
                return false;
            }

            if ($to instanceof \DateTimeImmutable && $capturedAt > $to) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int, Asset> $assets
     * @return array<int, Asset>
     */
    private function applySort(array $assets, string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? -1 : 1;
        $field = ltrim($sort, '-');

        usort($assets, function (Asset $left, Asset $right) use ($field, $direction): int {
            $comparison = $this->compareByField($left, $right, $field);
            if ($comparison !== 0) {
                return $comparison * $direction;
            }

            return strcmp($left->getUuid(), $right->getUuid());
        });

        return $assets;
    }

    private function compareByField(Asset $left, Asset $right, string $field): int
    {
        $leftValue = $this->sortValue($left, $field);
        $rightValue = $this->sortValue($right, $field);

        if ($leftValue === $rightValue) {
            return 0;
        }

        if ($leftValue === null) {
            return 1;
        }

        if ($rightValue === null) {
            return -1;
        }

        if (is_numeric($leftValue) && is_numeric($rightValue)) {
            return $leftValue <=> $rightValue;
        }

        return strcmp((string) $leftValue, (string) $rightValue);
    }

    private function sortValue(Asset $asset, string $field): string|float|int|null
    {
        $fields = $asset->getFields();

        return match ($field) {
            'name' => mb_strtolower($asset->getFilename()),
            'created_at' => $asset->getCreatedAt()->getTimestamp(),
            'updated_at' => $asset->getUpdatedAt()->getTimestamp(),
            'captured_at' => $this->capturedAt($asset)?->getTimestamp(),
            'duration' => is_numeric($fields['duration'] ?? null) ? (float) $fields['duration'] : null,
            'media_type' => mb_strtolower($asset->getMediaType()),
            'state' => $asset->getState()->value,
            default => $asset->getCreatedAt()->getTimestamp(),
        };
    }

    private function capturedAt(Asset $asset): ?\DateTimeImmutable
    {
        $fields = $asset->getFields();
        $value = $fields['captured_at'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $paths
     * @return array<int, string>
     */
    private function sanitizeRelativePaths(mixed $paths): array
    {
        if (!is_array($paths)) {
            return [];
        }

        $result = [];
        foreach ($paths as $path) {
            $normalized = $this->sanitizeRelativePath((string) $path);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
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
