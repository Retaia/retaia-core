<?php

namespace App\Application\AuthClient;

final class MintClientTokenResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';

    /**
     * @param array{access_token: string, token_type: string, client_id: string, client_kind: string}|null $token
     */
    public function __construct(
        private string $status,
        private ?array $token = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function token(): ?array
    {
        return $this->token;
    }
}
