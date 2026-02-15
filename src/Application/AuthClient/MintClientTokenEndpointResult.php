<?php

namespace App\Application\AuthClient;

final class MintClientTokenEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_TOO_MANY_ATTEMPTS = 'TOO_MANY_ATTEMPTS';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_SUCCESS = 'SUCCESS';

    /**
     * @param array{access_token: string, token_type: string, client_id: string, client_kind: string}|null $token
     */
    public function __construct(
        private string $status,
        private ?array $token = null,
        private ?int $retryInSeconds = null,
        private ?string $clientId = null,
        private ?string $clientKind = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{access_token: string, token_type: string, client_id: string, client_kind: string}|null
     */
    public function token(): ?array
    {
        return $this->token;
    }

    public function retryInSeconds(): ?int
    {
        return $this->retryInSeconds;
    }

    public function clientId(): ?string
    {
        return $this->clientId;
    }

    public function clientKind(): ?string
    {
        return $this->clientKind;
    }
}
