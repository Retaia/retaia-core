<?php

namespace App\Asset\Service;

use App\Asset\AssetState;
use App\Entity\Asset;

final class AssetStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        AssetState::DISCOVERED->value => [AssetState::READY->value],
        AssetState::READY->value => [AssetState::PROCESSING_REVIEW->value],
        AssetState::PROCESSING_REVIEW->value => [AssetState::PROCESSED->value, AssetState::READY->value],
        AssetState::PROCESSED->value => [AssetState::DECISION_PENDING->value, AssetState::READY->value],
        AssetState::DECISION_PENDING->value => [AssetState::DECIDED_KEEP->value, AssetState::DECIDED_REJECT->value],
        AssetState::DECIDED_KEEP->value => [AssetState::MOVE_QUEUED->value, AssetState::DECIDED_REJECT->value, AssetState::DECISION_PENDING->value],
        AssetState::DECIDED_REJECT->value => [AssetState::MOVE_QUEUED->value, AssetState::DECIDED_KEEP->value, AssetState::DECISION_PENDING->value],
        AssetState::MOVE_QUEUED->value => [AssetState::ARCHIVED->value, AssetState::REJECTED->value],
        AssetState::ARCHIVED->value => [AssetState::DECISION_PENDING->value, AssetState::READY->value],
        AssetState::REJECTED->value => [AssetState::DECISION_PENDING->value, AssetState::READY->value, AssetState::PURGED->value],
        AssetState::PURGED->value => [],
    ];

    public function transition(Asset $asset, AssetState $target): void
    {
        $current = $asset->getState();

        if ($current === $target) {
            return;
        }

        if (!$this->canTransition($current, $target)) {
            throw new StateConflictException(sprintf('Transition %s -> %s is forbidden.', $current->value, $target->value));
        }

        $asset->setState($target);
    }

    public function decide(Asset $asset, string $action): void
    {
        $normalizedAction = strtoupper(trim($action));

        if ($normalizedAction === 'KEEP') {
            $this->transition($asset, AssetState::DECIDED_KEEP);

            return;
        }

        if ($normalizedAction === 'REJECT') {
            $this->transition($asset, AssetState::DECIDED_REJECT);

            return;
        }

        if ($normalizedAction === 'CLEAR') {
            $this->transition($asset, AssetState::DECISION_PENDING);

            return;
        }

        throw new StateConflictException(sprintf('Unsupported decision action: %s', $action));
    }

    private function canTransition(AssetState $current, AssetState $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true);
    }
}
