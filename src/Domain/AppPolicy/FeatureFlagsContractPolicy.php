<?php

namespace App\Domain\AppPolicy;

final class FeatureFlagsContractPolicy
{
    private const SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+$/';

    /**
     * @param array<int, mixed> $acceptedVersions
     * @return array<int, string>
     */
    public function normalizedAcceptedVersions(string $latestVersion, array $acceptedVersions): array
    {
        $normalized = [];
        foreach ($acceptedVersions as $version) {
            if (!is_string($version) || !preg_match(self::SEMVER_PATTERN, $version)) {
                continue;
            }
            $normalized[] = $version;
        }

        if (!in_array($latestVersion, $normalized, true)) {
            $normalized[] = $latestVersion;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, string> $acceptedVersions
     */
    public function isSupportedClientVersion(string $clientVersion, array $acceptedVersions): bool
    {
        if ($clientVersion === '') {
            return true;
        }

        return in_array($clientVersion, $acceptedVersions, true);
    }

    public function effectiveVersion(string $clientVersion, string $latestVersion): string
    {
        return $clientVersion !== '' ? $clientVersion : $latestVersion;
    }

    public function compatibilityMode(string $effectiveVersion, string $latestVersion): string
    {
        return $effectiveVersion === $latestVersion ? 'STRICT' : 'COMPAT';
    }
}
