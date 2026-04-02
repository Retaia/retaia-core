<?php

namespace App\Application\Job;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Job\Job;

final class SubmitJobAssetMutator
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private SubmitJobDerivedPersister $derivedPersister,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function apply(Job $job, array $result): void
    {
        if (!in_array($job->jobType, ['extract_facts', 'generate_preview', 'generate_thumbnails', 'generate_audio_waveform', 'transcribe_audio'], true)) {
            return;
        }

        $asset = $this->assets->findByUuid($job->assetUuid);
        if (!$asset instanceof Asset) {
            return;
        }

        $fields = $asset->getFields();
        if ($job->jobType === 'extract_facts') {
            $factsPatch = is_array($result['facts_patch'] ?? null) ? $result['facts_patch'] : [];
            $fields['facts'] = array_replace_recursive(is_array($fields['facts'] ?? null) ? $fields['facts'] : [], $factsPatch);
            $fields['facts_done'] = true;
        } elseif ($job->jobType === 'transcribe_audio') {
            $transcriptPatch = is_array($result['transcript_patch'] ?? null) ? $result['transcript_patch'] : [];
            $fields['transcript'] = array_replace_recursive(is_array($fields['transcript'] ?? null) ? $fields['transcript'] : [], $transcriptPatch);
        } else {
            $derivedPatch = is_array($result['derived_patch'] ?? null) ? $result['derived_patch'] : [];
            $this->derivedPersister->persist($job->assetUuid, $derivedPatch);
            $fields = $this->derivedPersister->applyFlags($fields, $derivedPatch);
        }

        $asset->setFields($fields);

        try {
            if ($asset->getState() === AssetState::READY) {
                $this->stateMachine->transition($asset, AssetState::PROCESSING_REVIEW);
            }

            if ($this->isProcessingProfileComplete($asset->getFields())) {
                if ($asset->getState() === AssetState::PROCESSING_REVIEW) {
                    $this->stateMachine->transition($asset, AssetState::PROCESSED);
                }
                if ($asset->getState() === AssetState::PROCESSED) {
                    $this->stateMachine->transition($asset, AssetState::DECISION_PENDING);
                }
            }
        } catch (StateConflictException) {
            // Keep job completion even if asset cannot transition from its current state.
        }

        $this->assets->save($asset);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function isProcessingProfileComplete(array $fields): bool
    {
        return (bool) ($fields['facts_done'] ?? false)
            && (bool) ($fields['proxy_done'] ?? false)
            && (bool) ($fields['thumbs_done'] ?? false);
    }
}
