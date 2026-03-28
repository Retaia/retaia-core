<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\Port\AssetReadGateway as AssetReadGatewayPort;
use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Storage\BusinessStorageRegistryInterface;

final class AssetReadGateway implements AssetReadGatewayPort
{
    private const HIDDEN_FIELD_KEYS = ['projects'];

    public function __construct(
        private AssetRepositoryInterface $assets,
        private DerivedFileRepositoryInterface $derivedFiles,
        private BusinessStorageRegistryInterface $storageRegistry,
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
        array $states,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?\DateTimeImmutable $capturedAtFrom,
        ?\DateTimeImmutable $capturedAtTo,
        int $limit,
        int $offset,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?array $geoBbox,
    ): ?array {
        $assets = $this->assets->listAssets(null, $mediaType, $query, max(200, $offset + $limit + 1));
        $assets = $this->applyStateFilter($assets, $states);
        $assets = $this->applyCapturedAtRange($assets, $capturedAtFrom, $capturedAtTo);
        $assets = $this->applyTagsFilter($assets, $tags, $tagsMode);
        $assets = $this->applyHasPreviewFilter($assets, $hasPreview);
        $assets = $this->applyLocationFilter($assets, $locationCountry, $locationCity);
        $assets = $this->applyGeoBboxFilter($assets, $geoBbox);
        $assets = $this->applySort($assets, $sort ?? '-created_at');
        $slice = array_slice($assets, max(0, $offset), max(1, min(200, $limit)));

        return [
            'items' => array_map(fn (Asset $asset): array => $this->summary($asset), $slice),
            'has_more' => count($assets) > ($offset + count($slice)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        $fields = $asset->getFields();
        $source = $this->sourceFromFields($fields, $asset->getFilename());
        $derived = $this->derivedFromAsset($asset);
        $summary = $this->summary($asset);
        $projects = $this->projectsFromFields($fields);

        return [
            'summary' => $summary,
            'notes' => $asset->getNotes(),
            'fields' => $this->publicFields($fields),
            'gps_latitude' => $this->optionalNumber($fields['gps_latitude'] ?? null),
            'gps_longitude' => $this->optionalNumber($fields['gps_longitude'] ?? null),
            'gps_altitude_m' => $this->optionalNumber($fields['gps_altitude_m'] ?? null),
            'gps_altitude_relative_m' => $this->optionalNumber($fields['gps_altitude_relative_m'] ?? null),
            'gps_altitude_absolute_m' => $this->optionalNumber($fields['gps_altitude_absolute_m'] ?? null),
            'location_country' => $this->optionalString($fields['location_country'] ?? null),
            'location_city' => $this->optionalString($fields['location_city'] ?? null),
            'location_label' => $this->optionalString($fields['location_label'] ?? null),
            'projects' => $projects,
            'paths' => $source,
            'processing' => [
                'facts_done' => (bool) ($fields['facts_done'] ?? false),
                'thumbs_done' => (bool) ($fields['thumbs_done'] ?? !empty($derived['thumbs'])),
                'proxy_done' => (bool) ($fields['proxy_done'] ?? ($derived['preview_video_url'] !== null || $derived['preview_audio_url'] !== null || $derived['preview_photo_url'] !== null)),
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
        $derived = $this->derivedFromAsset($asset);

        return [
            'uuid' => $asset->getUuid(),
            'name' => $asset->getFilename(),
            'media_type' => $asset->getMediaType(),
            'state' => $asset->getState()->value,
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
            'revision_etag' => AssetRevisionTag::fromAsset($asset),
            'captured_at' => is_string($fields['captured_at'] ?? null) ? $fields['captured_at'] : null,
            'duration' => is_numeric($fields['duration'] ?? null) ? (float) $fields['duration'] : null,
            'tags' => $asset->getTags(),
            'has_preview' => $derived['preview_video_url'] !== null || $derived['preview_audio_url'] !== null || $derived['preview_photo_url'] !== null,
            'thumb_url' => $derived['thumbs'][0] ?? null,
        ];
    }

    /**
     * @param array<int, Asset> $assets
     * @param array<int, string> $states
     * @return array<int, Asset>
     */
    private function applyStateFilter(array $assets, array $states): array
    {
        if ($states === []) {
            return $assets;
        }

        return array_values(array_filter(
            $assets,
            static fn (Asset $asset): bool => in_array($asset->getState()->value, $states, true)
        ));
    }

    /**
     * @param array<int, Asset> $assets
     * @param array<int, string> $tags
     * @return array<int, Asset>
     */
    private function applyTagsFilter(array $assets, array $tags, string $mode): array
    {
        if ($tags === []) {
            return $assets;
        }

        return array_values(array_filter($assets, fn (Asset $asset): bool => $this->matchesTags($asset, $tags, $mode)));
    }

    /**
     * @param array<int, Asset> $assets
     * @return array<int, Asset>
     */
    private function applyHasPreviewFilter(array $assets, ?bool $hasPreview): array
    {
        if ($hasPreview === null) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($hasPreview): bool {
            $derived = $this->derivedFromAsset($asset);
            $value = $derived['preview_video_url'] !== null
                || $derived['preview_audio_url'] !== null
                || $derived['preview_photo_url'] !== null;

            return $value === $hasPreview;
        }));
    }

    /**
     * @param array<int, Asset> $assets
     * @return array<int, Asset>
     */
    private function applyLocationFilter(array $assets, ?string $locationCountry, ?string $locationCity): array
    {
        if ($locationCountry === null && $locationCity === null) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($locationCountry, $locationCity): bool {
            $fields = $asset->getFields();
            $country = mb_strtolower(trim((string) ($fields['location_country'] ?? '')));
            $city = mb_strtolower(trim((string) ($fields['location_city'] ?? '')));

            if ($locationCountry !== null && $country !== mb_strtolower($locationCountry)) {
                return false;
            }

            if ($locationCity !== null && $city !== mb_strtolower($locationCity)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int, string> $expected
     */
    private function matchesTags(Asset $asset, array $expected, string $mode): bool
    {
        $normalized = array_values(array_filter(
            array_map(static fn (string $tag): string => mb_strtolower(trim($tag)), $asset->getTags()),
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
        $storageId = $this->requiredStorageId($paths, $filename);
        $original = $this->requiredOriginalRelativePath($paths, $filename);

        return [
            'storage_id' => $storageId,
            'original_relative' => $original,
            'sidecars_relative' => $this->sanitizeRelativePaths(is_array($paths['sidecars_relative'] ?? null) ? $paths['sidecars_relative'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredStorageId(array $paths, string $filename): string
    {
        $storageId = trim((string) ($paths['storage_id'] ?? ''));
        if ($storageId === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.storage_id.', $filename));
        }
        if (!$this->storageRegistry->has($storageId)) {
            throw new \RuntimeException(sprintf('Asset "%s" references unknown storage "%s".', $filename, $storageId));
        }

        return $storageId;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function requiredOriginalRelativePath(array $paths, string $filename): string
    {
        $original = $this->sanitizeRelativePath((string) ($paths['original_relative'] ?? ''));
        if ($original === '') {
            throw new \RuntimeException(sprintf('Asset "%s" is missing canonical paths.original_relative.', $filename));
        }

        return $original;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function derivedFromAsset(Asset $asset): array
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
     * @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $geoBbox
     * @return array<int, Asset>
     */
    private function applyGeoBboxFilter(array $assets, ?array $geoBbox): array
    {
        if ($geoBbox === null) {
            return $assets;
        }

        return array_values(array_filter($assets, function (Asset $asset) use ($geoBbox): bool {
            $fields = $asset->getFields();
            $lon = $this->optionalNumber($fields['gps_longitude'] ?? null);
            $lat = $this->optionalNumber($fields['gps_latitude'] ?? null);
            if ($lon === null || $lat === null) {
                return false;
            }

            return $lon >= $geoBbox['min_lon']
                && $lon <= $geoBbox['max_lon']
                && $lat >= $geoBbox['min_lat']
                && $lat <= $geoBbox['max_lat'];
        }));
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

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function publicFields(array $fields): array
    {
        foreach (self::HIDDEN_FIELD_KEYS as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function projectsFromFields(array $fields): array
    {
        $projects = $fields['projects'] ?? null;
        if (!is_array($projects)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = trim((string) ($project['project_id'] ?? ''));
            $projectName = trim((string) ($project['project_name'] ?? ''));
            $createdAt = trim((string) ($project['created_at'] ?? ''));
            if ($projectId === '' || $projectName === '' || !$this->isValidDateTime($createdAt) || isset($seen[$projectId])) {
                continue;
            }

            $item = [
                'project_id' => $projectId,
                'project_name' => $projectName,
                'created_at' => $createdAt,
            ];

            if (array_key_exists('description', $project)) {
                $description = $project['description'];
                $item['description'] = is_string($description) ? $description : null;
            }

            $normalized[] = $item;
            $seen[$projectId] = true;
        }

        return $normalized;
    }

    private function isValidDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalNumber(mixed $value): float|int|null
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        return $value;
    }
}
