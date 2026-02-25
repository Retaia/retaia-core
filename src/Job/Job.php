<?php

namespace App\Job;

final class Job
{
    /**
     * @param array<string, mixed> $source
     */
    public function __construct(
        public readonly string $id,
        public readonly string $assetUuid,
        public readonly string $jobType,
        public readonly JobStatus $status,
        public readonly ?string $claimedBy,
        public readonly ?string $lockToken,
        public readonly ?\DateTimeImmutable $lockedUntil,
        /** @var array<string, mixed> */
        public readonly array $result = [],
        /** @var array<string, mixed> */
        public readonly array $source = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $source = [
            'storage_id' => (string) ($this->source['storage_id'] ?? ''),
            'original_relative' => (string) ($this->source['original_relative'] ?? ''),
            'sidecars_relative' => array_values(is_array($this->source['sidecars_relative'] ?? null) ? $this->source['sidecars_relative'] : []),
        ];

        return [
            'job_id' => $this->id,
            'asset_uuid' => $this->assetUuid,
            'job_type' => $this->jobType,
            'status' => $this->status->value,
            'source' => $source,
            'required_capabilities' => $this->requiredCapabilities(),
            'claimed_by' => $this->claimedBy,
            'lock_token' => $this->lockToken,
            'locked_until' => $this->lockedUntil?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function requiredCapabilities(): array
    {
        return match ($this->jobType) {
            'extract_facts' => ['facts:write'],
            'generate_proxy' => ['derived:write'],
            'generate_thumbnails' => ['derived:write'],
            'generate_audio_waveform' => ['derived:write'],
            default => [],
        };
    }
}
