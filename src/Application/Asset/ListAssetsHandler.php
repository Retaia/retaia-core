<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetReadGateway;

final class ListAssetsHandler
{
    private const SORT_VALUES = [
        'name',
        '-name',
        'created_at',
        '-created_at',
        'updated_at',
        '-updated_at',
        'captured_at',
        '-captured_at',
        'duration',
        '-duration',
        'media_type',
        '-media_type',
        'state',
        '-state',
    ];

    public function __construct(
        private AssetReadGateway $gateway,
    ) {
    }

    /**
     * @param array<int, string> $suggestedTags
     */
    public function handle(
        ?string $state,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?string $capturedAtFrom,
        ?string $capturedAtTo,
        int $limit,
        array $suggestedTags,
        string $suggestedTagsMode,
    ): ListAssetsResult {
        $mode = strtoupper(trim($suggestedTagsMode));
        if (!in_array($mode, ['AND', 'OR'], true)) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $normalizedSort = $sort !== null && trim($sort) !== '' ? trim($sort) : '-created_at';
        if (!in_array($normalizedSort, self::SORT_VALUES, true)) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $from = $this->parseDateTime($capturedAtFrom);
        $to = $this->parseDateTime($capturedAtTo);
        if (($capturedAtFrom !== null && trim($capturedAtFrom) !== '' && $from === null)
            || ($capturedAtTo !== null && trim($capturedAtTo) !== '' && $to === null)
            || ($from !== null && $to !== null && $from > $to)
        ) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $items = $this->gateway->list($state, $mediaType, $query, $normalizedSort, $from, $to, $limit, $suggestedTags, $mode);
        if ($items === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_FORBIDDEN_SCOPE);
        }

        return new ListAssetsResult(ListAssetsResult::STATUS_OK, $items);
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
