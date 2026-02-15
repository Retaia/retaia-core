<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\ClientTokenMintRateLimiterGateway;

final class MintClientTokenEndpointHandler
{
    public function __construct(
        private MintClientTokenHandler $mintClientTokenHandler,
        private ClientTokenMintRateLimiterGateway $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, string $remoteAddress): MintClientTokenEndpointResult
    {
        $clientId = trim((string) ($payload['client_id'] ?? ''));
        $clientKind = trim((string) ($payload['client_kind'] ?? ''));
        $secretKey = trim((string) ($payload['secret_key'] ?? ''));

        if ($clientId === '' || $clientKind === '' || $secretKey === '') {
            return new MintClientTokenEndpointResult(MintClientTokenEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $retryInSeconds = $this->rateLimiter->retryInSecondsOrNull($clientId, $clientKind, $remoteAddress);
        if ($retryInSeconds !== null) {
            return new MintClientTokenEndpointResult(
                MintClientTokenEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
                null,
                $retryInSeconds,
                $clientId,
                $clientKind,
            );
        }

        $result = $this->mintClientTokenHandler->handle($clientId, $clientKind, $secretKey);
        if ($result->status() === MintClientTokenResult::STATUS_FORBIDDEN_ACTOR) {
            return new MintClientTokenEndpointResult(MintClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR, null, null, $clientId, $clientKind);
        }
        if ($result->status() === MintClientTokenResult::STATUS_FORBIDDEN_SCOPE) {
            return new MintClientTokenEndpointResult(MintClientTokenEndpointResult::STATUS_FORBIDDEN_SCOPE, null, null, $clientId, $clientKind);
        }
        if ($result->status() === MintClientTokenResult::STATUS_UNAUTHORIZED || !is_array($result->token())) {
            return new MintClientTokenEndpointResult(MintClientTokenEndpointResult::STATUS_UNAUTHORIZED, null, null, $clientId, $clientKind);
        }

        return new MintClientTokenEndpointResult(
            MintClientTokenEndpointResult::STATUS_SUCCESS,
            $result->token(),
            null,
            $clientId,
            $clientKind,
        );
    }
}
