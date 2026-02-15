<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetReadGateway;

final class ListAssetsHandler
{
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
        int $limit,
        array $suggestedTags,
        string $suggestedTagsMode,
    ): ListAssetsResult {
        $mode = strtoupper(trim($suggestedTagsMode));
        if (!in_array($mode, ['AND', 'OR'], true)) {
            return new ListAssetsResult(ListAssetsResult::STATUS_VALIDATION_FAILED);
        }

        $items = $this->gateway->list($state, $mediaType, $query, $limit, $suggestedTags, $mode);
        if ($items === null) {
            return new ListAssetsResult(ListAssetsResult::STATUS_FORBIDDEN_SCOPE);
        }

        return new ListAssetsResult(ListAssetsResult::STATUS_OK, $items);
    }
}
