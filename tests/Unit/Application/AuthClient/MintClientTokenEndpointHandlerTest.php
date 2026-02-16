<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\MintClientTokenEndpointHandler;
use App\Application\AuthClient\MintClientTokenEndpointResult;
use App\Application\AuthClient\MintClientTokenHandler;
use App\Application\AuthClient\Port\AuthClientGateway;
use App\Application\AuthClient\Port\ClientTokenMintRateLimiterGateway;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;
use PHPUnit\Framework\TestCase;

final class MintClientTokenEndpointHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenPayloadMissingFields(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::never())->method('mintToken');

        $limiter = $this->createMock(ClientTokenMintRateLimiterGateway::class);
        $limiter->expects(self::never())->method('retryInSecondsOrNull');

        $handler = new MintClientTokenEndpointHandler(
            new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway),
            $limiter,
        );

        $result = $handler->handle([], '10.0.0.1');

        self::assertSame(MintClientTokenEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsTooManyAttemptsWhenRateLimited(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::never())->method('mintToken');

        $limiter = $this->createMock(ClientTokenMintRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('agent-default', 'AGENT', '10.0.0.2')->willReturn(55);

        $handler = new MintClientTokenEndpointHandler(
            new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway),
            $limiter,
        );

        $result = $handler->handle([
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'secret',
        ], '10.0.0.2');

        self::assertSame(MintClientTokenEndpointResult::STATUS_TOO_MANY_ATTEMPTS, $result->status());
        self::assertSame(55, $result->retryInSeconds());
    }

    public function testHandleReturnsForbiddenActor(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::never())->method('isMcpDisabledByAppPolicy');
        $gateway->expects(self::never())->method('mintToken');

        $limiter = $this->createMock(ClientTokenMintRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->willReturn(null);

        $handler = new MintClientTokenEndpointHandler(
            new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway),
            $limiter,
        );

        $result = $handler->handle([
            'client_id' => 'ui-web',
            'client_kind' => 'UI_WEB',
            'secret_key' => 'secret',
        ], '10.0.0.3');

        self::assertSame(MintClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testHandleReturnsSuccessWithToken(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('isMcpDisabledByAppPolicy')->willReturn(false);
        $gateway->expects(self::once())->method('mintToken')->with('agent-default', 'AGENT', 'secret')->willReturn([
            'access_token' => 'tok_123',
            'token_type' => 'Bearer',
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
        ]);

        $limiter = $this->createMock(ClientTokenMintRateLimiterGateway::class);
        $limiter->expects(self::once())->method('retryInSecondsOrNull')->with('agent-default', 'AGENT', '10.0.0.4')->willReturn(null);

        $handler = new MintClientTokenEndpointHandler(
            new MintClientTokenHandler(new TechnicalClientTokenPolicy(), $gateway),
            $limiter,
        );

        $result = $handler->handle([
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'secret',
        ], '10.0.0.4');

        self::assertSame(MintClientTokenEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('tok_123', $result->token()['access_token'] ?? null);
    }
}
