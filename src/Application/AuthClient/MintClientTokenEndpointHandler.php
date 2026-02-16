<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Input\MintClientTokenInput;
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
        $input = MintClientTokenInput::fromPayload($payload);
        $clientId = $input->clientId();
        $clientKind = $input->clientKind();

        if (!$input->isValid()) {
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

        $result = $this->mintClientTokenHandler->handle($clientId, $clientKind, $input->secretKey());
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
