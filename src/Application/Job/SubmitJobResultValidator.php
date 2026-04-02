<?php

namespace App\Application\Job;

final class SubmitJobResultValidator
{
    /**
     * @param array<string, mixed> $result
     */
    public function isAllowedForJobType(string $jobType, array $result): bool
    {
        if (!in_array($jobType, ['extract_facts', 'generate_preview', 'generate_thumbnails', 'generate_audio_waveform', 'transcribe_audio'], true)) {
            return true;
        }

        foreach (array_keys($result) as $key) {
            if (!in_array((string) $key, ['facts_patch', 'transcript_patch', 'derived_patch', 'warnings', 'metrics'], true)) {
                return false;
            }
        }
        if (array_key_exists('warnings', $result) && !$this->isWarningsValid($result['warnings'])) {
            return false;
        }
        if (array_key_exists('metrics', $result) && !is_array($result['metrics'])) {
            return false;
        }

        if ($jobType === 'extract_facts') {
            if (!is_array($result['facts_patch'] ?? null) || array_key_exists('derived_patch', $result) || array_key_exists('transcript_patch', $result)) {
                return false;
            }

            return $this->isFactsPatchValid($result['facts_patch']);
        }

        if ($jobType === 'transcribe_audio') {
            if (!is_array($result['transcript_patch'] ?? null)
                || array_key_exists('derived_patch', $result)
                || array_key_exists('facts_patch', $result)) {
                return false;
            }

            return $this->isTranscriptPatchValid($result['transcript_patch']);
        }

        if (!is_array($result['derived_patch'] ?? null) || array_key_exists('facts_patch', $result) || array_key_exists('transcript_patch', $result)) {
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
     * @param array<string, mixed> $transcriptPatch
     */
    private function isTranscriptPatchValid(array $transcriptPatch): bool
    {
        foreach (array_keys($transcriptPatch) as $key) {
            if (!in_array((string) $key, ['status', 'text', 'text_preview', 'language', 'updated_at'], true)) {
                return false;
            }
        }

        if (array_key_exists('status', $transcriptPatch)
            && !in_array($transcriptPatch['status'], ['NONE', 'RUNNING', 'DONE', 'FAILED'], true)) {
            return false;
        }
        foreach (['text', 'text_preview', 'language'] as $nullableStringField) {
            if (array_key_exists($nullableStringField, $transcriptPatch)
                && !is_string($transcriptPatch[$nullableStringField])
                && $transcriptPatch[$nullableStringField] !== null) {
                return false;
            }
        }
        if (array_key_exists('updated_at', $transcriptPatch)
            && (!is_string($transcriptPatch['updated_at']) || trim($transcriptPatch['updated_at']) === '')) {
            return false;
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
            if (array_key_exists('size_bytes', $item) && !is_int($item['size_bytes'])) {
                return false;
            }
            if (array_key_exists('sha256', $item) && !is_string($item['sha256'])) {
                return false;
            }
        }

        return true;
    }

    private function isWarningsValid(mixed $warnings): bool
    {
        if (!is_array($warnings)) {
            return false;
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                return false;
            }
        }

        return true;
    }
}
