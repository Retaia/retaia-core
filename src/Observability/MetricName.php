<?php

namespace App\Observability;

final class MetricName
{
    public const AUTH_CLIENT_TOKEN_FORBIDDEN_ACTOR_UI_WEB = 'auth.client.token.forbidden_actor.ui_web';
    public const AUTH_DEVICE_POLL_INVALID_DEVICE_CODE = 'auth.device.poll.invalid_device_code';
    public const AUTH_DEVICE_POLL_THROTTLED = 'auth.device.poll.throttled';
    public const LOCK_ACTIVE_DETECTED = 'lock.active.detected';

    public static function authDevicePollStatus(string $status): string
    {
        return 'auth.device.poll.status.'.$status;
    }

    public static function lockAcquireFailed(string $lockType): string
    {
        return 'lock.acquire.failed.'.$lockType;
    }

    public static function lockAcquireSuccess(string $lockType): string
    {
        return 'lock.acquire.success.'.$lockType;
    }

    public static function lockRelease(string $lockType): string
    {
        return 'lock.release.'.$lockType;
    }

    public static function lockActiveDetectedByType(string $lockType): string
    {
        return 'lock.active.detected.'.$lockType;
    }

    public static function lockWatchdogReleased(string $lockType): string
    {
        return 'lock.watchdog.released.'.$lockType;
    }

    public static function apiError(string $code): string
    {
        return 'api.error.'.$code;
    }
}
