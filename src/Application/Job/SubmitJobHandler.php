<?php

namespace App\Application\Job;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Application\Job\Port\JobGateway;
use App\Job\Job;

final class SubmitJobHandler
{
    public function __construct(
        private JobGateway $gateway,
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private CheckSuggestTagsSubmitScopeHandler $checkSuggestTagsSubmitScopeHandler,
        private ResolveJobLockConflictCodeHandler $resolveLockConflictCodeHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, string>   $actorRoles
     */
    public function handle(string $jobId, string $lockToken, string $jobType, array $result, array $actorRoles): SubmitJobResult
    {
        $current = $this->gateway->find($jobId);
        if ($current instanceof Job && $current->jobType !== $jobType) {
            return new SubmitJobResult(SubmitJobResult::STATUS_VALIDATION_FAILED);
        }
        if (!$this->isResultAllowedForJobType($jobType, $result)) {
            return new SubmitJobResult(SubmitJobResult::STATUS_VALIDATION_FAILED);
        }

        if ($current instanceof Job
            && $current->jobType === 'suggest_tags'
            && !$this->checkSuggestTagsSubmitScopeHandler->handle($actorRoles)
        ) {
            return new SubmitJobResult(SubmitJobResult::STATUS_FORBIDDEN_SCOPE);
        }

        $job = $this->gateway->submit($jobId, $lockToken, $result);
        if ($job === null) {
            $code = $this->resolveLockConflictCodeHandler->handle($jobId, $lockToken);

            return new SubmitJobResult($code === 'STALE_LOCK_TOKEN'
                ? SubmitJobResult::STATUS_STALE_LOCK_TOKEN
                : SubmitJobResult::STATUS_LOCK_INVALID);
        }

        $this->applyResultToAsset($job, $result);

        return new SubmitJobResult(SubmitJobResult::STATUS_SUBMITTED, $job);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function applyResultToAsset(Job $job, array $result): void
    {
        if (!in_array($job->jobType, ['extract_facts', 'generate_proxy', 'generate_thumbnails', 'generate_audio_waveform'], true)) {
            return;
        }

        $asset = $this->assets->findByUuid($job->assetUuid);
        if ($asset === null) {
            return;
        }

        $fields = $asset->getFields();
        if ($job->jobType === 'extract_facts') {
            $factsPatch = is_array($result['facts_patch'] ?? null) ? $result['facts_patch'] : [];
            $fields['facts'] = array_replace_recursive(is_array($fields['facts'] ?? null) ? $fields['facts'] : [], $factsPatch);
            $fields['facts_done'] = true;
        } else {
            $derivedPatch = is_array($result['derived_patch'] ?? null) ? $result['derived_patch'] : [];
            $fields['derived'] = array_replace_recursive(is_array($fields['derived'] ?? null) ? $fields['derived'] : [], $derivedPatch);
            $fields = $this->applyDerivedFlags($fields, $derivedPatch);
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
     * @param array<string, mixed> $derivedPatch
     * @return array<string, mixed>
     */
    private function applyDerivedFlags(array $fields, array $derivedPatch): array
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

    /**
     * @param array<string, mixed> $result
     */
    private function isResultAllowedForJobType(string $jobType, array $result): bool
    {
        if (!in_array($jobType, ['extract_facts', 'generate_proxy', 'generate_thumbnails', 'generate_audio_waveform'], true)) {
            return true;
        }

        foreach (array_keys($result) as $key) {
            if (!in_array((string) $key, ['facts_patch', 'derived_patch', 'warnings', 'metrics'], true)) {
                return false;
            }
        }

        if ($jobType === 'extract_facts') {
            if (!is_array($result['facts_patch'] ?? null) || array_key_exists('derived_patch', $result)) {
                return false;
            }

            return $this->isFactsPatchValid($result['facts_patch']);
        }

        if (!is_array($result['derived_patch'] ?? null) || array_key_exists('facts_patch', $result)) {
            return false;
        }

        return $this->isDerivedPatchValid($result['derived_patch']);
    }

    /**
     * @param array<string, mixed> $factsPatch
     */
    private function isFactsPatchValid(array $factsPatch): bool
    {
        foreach (array_keys($factsPatch) as $key) {
            if (!in_array((string) $key, ['duration_ms', 'media_format', 'video_codec', 'audio_codec', 'width', 'height', 'fps'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $derivedPatch
     */
    private function isDerivedPatchValid(array $derivedPatch): bool
    {
        foreach (array_keys($derivedPatch) as $key) {
            if (!in_array((string) $key, ['derived_manifest'], true)) {
                return false;
            }
        }

        $manifest = $derivedPatch['derived_manifest'] ?? [];
        if (!is_array($manifest)) {
            return false;
        }

        foreach ($manifest as $item) {
            if (!is_array($item)) {
                return false;
            }
            foreach (array_keys($item) as $key) {
                if (!in_array((string) $key, ['kind', 'ref', 'size_bytes', 'sha256'], true)) {
                    return false;
                }
            }
            if (!in_array((string) ($item['kind'] ?? ''), ['proxy_video', 'proxy_audio', 'proxy_photo', 'thumb', 'waveform'], true)) {
                return false;
            }
            if (!is_string($item['ref'] ?? null) || trim((string) $item['ref']) === '') {
                return false;
            }
        }

        return true;
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
