<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\Port\AssetReadGateway as AssetReadGatewayPort;
use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;

final class AssetReadGateway implements AssetReadGatewayPort
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetCanonicalPathsProjector $pathsProjector,
        private AssetDerivedViewProjector $derivedProjector,
        private AssetFieldViewProjector $fieldProjector,
        private AssetListView $listView,
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
        $assets = $this->listView->filterAndSort(
            $assets,
            $states,
            $capturedAtFrom,
            $capturedAtTo,
            $tags,
            $tagsMode,
            $hasPreview,
            $locationCountry,
            $locationCity,
            $geoBbox,
            $sort ?? '-created_at',
        );
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
        $source = $this->pathsProjector->project($fields, $asset->getFilename());
        $derived = $this->derivedProjector->project($asset);
        $summary = $this->summary($asset);
        $projects = $this->fieldProjector->projects($fields);

        return [
            'summary' => $summary,
            'notes' => $asset->getNotes(),
            'fields' => $this->fieldProjector->publicFields($fields),
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
                'status' => $this->fieldProjector->transcriptStatus($fields['transcript']['status'] ?? $fields['transcript_status'] ?? null),
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
                'path_history' => $this->fieldProjector->pathHistory($fields['path_history'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Asset $asset): array
    {
        $fields = $asset->getFields();
        $derived = $this->derivedProjector->project($asset);

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
            'has_preview' => $this->derivedProjector->hasPreview($asset),
            'thumb_url' => $derived['thumbs'][0] ?? null,
        ];
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
