<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetReadGateway;

final class ListAssetsHandler
{
    public function __construct(
        private AssetReadGateway $gateway,
        private ListAssetsQueryNormalizer $queryNormalizer = new ListAssetsQueryNormalizer(),
        private AssetListCursorCodec $cursorCodec = new AssetListCursorCodec(),
    ) {
    }

    /**
     * @param array<int, string> $states
     * @param array<int, string> $tags
     */
    public function handle(
        array $states,
        ?string $mediaType,
        ?string $query,
        ?string $sort,
        ?string $capturedAtFrom,
        ?string $capturedAtTo,
        int $limit,
        ?string $cursor,
        array $tags,
        string $tagsMode,
        ?bool $hasPreview,
        ?string $locationCountry,
        ?string $locationCity,
        ?string $geoBbox,
    ): ListAssetsResult {
        $normalized = $this->queryNormalizer->normalize(
            $states,
            $sort,
            $capturedAtFrom,
            $capturedAtTo,
            $tags,
            $tagsMode,
            $geoBbox,
        );
        if ($normalized === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $cursorContextHash = $this->cursorCodec->contextHash(
            $normalized['states'],
            $mediaType,
            $query,
            $normalized['sort'],
            $normalized['capturedAtFrom'],
            $normalized['capturedAtTo'],
            $limit,
            $tags,
            $normalized['tagsMode'],
            $hasPreview,
            $locationCountry,
            $locationCity,
            $normalized['geoBbox'],
        );
        $cursorOffset = $this->cursorCodec->decodeOffset($cursor, $cursorContextHash);
        if ($cursor !== null && trim($cursor) !== '' && $cursorOffset === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $page = $this->gateway->list(
            $normalized['states'],
            $mediaType,
            $query,
            $normalized['sort'],
            $normalized['capturedAtFrom'],
            $normalized['capturedAtTo'],
            $limit,
            $cursorOffset ?? 0,
            $tags,
            $normalized['tagsMode'],
            $hasPreview,
            $locationCountry,
            $locationCity,
            $normalized['geoBbox'],
        );
        if ($page === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_FORBIDDEN_SCOPE);
        }

        $items = $page['items'] ?? [];
        $nextCursor = ($page['has_more'] ?? false) === true
            ? $this->cursorCodec->encode(($cursorOffset ?? 0) + count($items), $cursorContextHash)
            : null;

        return new ListAssetsResult(ListAssetsResult::STATUS_OK, $items, $nextCursor);
    }
}
