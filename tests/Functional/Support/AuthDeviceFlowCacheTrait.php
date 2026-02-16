<?php

namespace App\Tests\Functional\Support;

use Psr\Cache\CacheItemPoolInterface;

trait AuthDeviceFlowCacheTrait
{
    protected function forceDeviceFlowExpiration(string $deviceCode): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $item = $cache->getItem('auth_device_flows');
        $flows = $item->get();
        if (!is_array($flows) || !isset($flows[$deviceCode]) || !is_array($flows[$deviceCode])) {
            self::fail('Device flow not found in cache for expiration fixture.');
        }

        $flow = $flows[$deviceCode];
        $flow['expires_at'] = time() - 1;
        $flows[$deviceCode] = $flow;
        $item->set($flows);
        $cache->save($item);
    }

    protected function forceDeviceFlowLastPolledAt(string $deviceCode, int $lastPolledAt): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $item = $cache->getItem('auth_device_flows');
        $flows = $item->get();
        if (!is_array($flows) || !isset($flows[$deviceCode]) || !is_array($flows[$deviceCode])) {
            self::fail('Device flow not found in cache for last_polled_at fixture.');
        }

        $flow = $flows[$deviceCode];
        $flow['last_polled_at'] = $lastPolledAt;
        $flows[$deviceCode] = $flow;
        $item->set($flows);
        $cache->save($item);
    }
}
