<?php

namespace App\Infrastructure\Asset;

use App\Application\Asset\PatchAssetResult;
use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;

final class AssetPatchStateApplier
{
    public function __construct(
        private AssetStateMachine $stateMachine,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function apply(Asset $asset, array $payload): string
    {
        if (!array_key_exists('state', $payload)) {
            return PatchAssetResult::STATUS_PATCHED;
        }

        $value = $payload['state'];
        if (!is_string($value)) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [
            AssetState::DECISION_PENDING->value,
            AssetState::DECIDED_KEEP->value,
            AssetState::DECIDED_REJECT->value,
            AssetState::ARCHIVED->value,
            AssetState::REJECTED->value,
        ], true)) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        $target = AssetState::tryFrom($normalized);
        if (!$target instanceof AssetState) {
            return PatchAssetResult::STATUS_VALIDATION_FAILED;
        }

        try {
            $this->stateMachine->transition($asset, $target);
        } catch (StateConflictException) {
            return PatchAssetResult::STATUS_STATE_CONFLICT;
        }

        return PatchAssetResult::STATUS_PATCHED;
    }
}
