<?php

namespace App\Application\Job;

final class JobContractPolicy
{
    /**
     * @var array<int, string>
     */
    private const V1_JOB_TYPES = [
        'extract_facts',
        'generate_proxy',
        'generate_thumbnails',
        'generate_audio_waveform',
    ];

    public function isV1JobType(string $jobType): bool
    {
        return in_array($jobType, self::V1_JOB_TYPES, true);
    }

    /**
     * @return array<int, string>
     */
    public function requiredCapabilities(string $jobType): array
    {
        return match ($jobType) {
            'extract_facts' => ['facts:write'],
            'generate_proxy', 'generate_thumbnails', 'generate_audio_waveform' => ['derived:write'],
            default => [],
        };
    }

    /**
     * @param array<int, string> $actorRoles
     */
    public function isActorCompatible(string $jobType, array $actorRoles): bool
    {
        $capabilities = $this->actorCapabilities($actorRoles);
        foreach ($this->requiredCapabilities($jobType) as $required) {
            if (!in_array($required, $capabilities, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $roles
     * @return array<int, string>
     */
    private function actorCapabilities(array $roles): array
    {
        $capabilities = [];
        if (in_array('ROLE_AGENT', $roles, true)) {
            $capabilities[] = 'facts:write';
            $capabilities[] = 'derived:write';
        }
        if (in_array('ROLE_SUGGESTIONS_WRITE', $roles, true)) {
            $capabilities[] = 'suggestions:write';
        }

        return array_values(array_unique($capabilities));
    }
}

