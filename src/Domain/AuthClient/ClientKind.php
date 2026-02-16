<?php

namespace App\Domain\AuthClient;

final class ClientKind
{
    public const UI_WEB = 'UI_WEB';
    public const UI_MOBILE = 'UI_MOBILE';
    public const AGENT = 'AGENT';
    public const MCP = 'MCP';

    /**
     * @return list<string>
     */
    public static function interactive(): array
    {
        return [self::UI_WEB, self::UI_MOBILE, self::AGENT];
    }

    /**
     * @return list<string>
     */
    public static function technical(): array
    {
        return [self::AGENT, self::MCP];
    }

    public static function isInteractive(string $clientKind): bool
    {
        return in_array($clientKind, self::interactive(), true);
    }

    public static function isTechnical(string $clientKind): bool
    {
        return in_array($clientKind, self::technical(), true);
    }
}
