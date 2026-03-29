<?php

namespace App\Feature;

use Psr\Cache\CacheItemPoolInterface;

final class FeatureToggleStore
{
    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, bool>
     */
    public function appFeatureEnabled(array $defaults): array
    {
        $item = $this->cache->getItem('features_app_enabled');
        $value = $item->get();
        if (is_array($value)) {
            return $this->normalizeBooleanMap($value);
        }

        return $this->normalizeBooleanMap($defaults);
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setAppFeatureEnabled(array $features): void
    {
        $item = $this->cache->getItem('features_app_enabled');
        $item->set($this->normalizeBooleanMap($features));
        $this->cache->save($item);
    }

    /**
     * @return array<string, bool>
     */
    public function userFeatureEnabled(string $userId): array
    {
        $item = $this->cache->getItem($this->userFeaturesKey($userId));
        $value = $item->get();

        return is_array($value) ? $this->normalizeBooleanMap($value) : [];
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setUserFeatureEnabled(string $userId, array $features): void
    {
        $item = $this->cache->getItem($this->userFeaturesKey($userId));
        $item->set($this->normalizeBooleanMap($features));
        $this->cache->save($item);
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, bool>
     */
    private function normalizeBooleanMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = (bool) $value;
        }

        return $normalized;
    }

    private function userFeaturesKey(string $userId): string
    {
        return 'features_user_'.sha1($userId);
    }
}
