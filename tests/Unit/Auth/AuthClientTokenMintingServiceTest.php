<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthClientTokenMintingService;
use App\Auth\ClientAccessTokenFactory;
use App\Auth\TechnicalAccessTokenRecord;
use App\Auth\TechnicalAccessTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class AuthClientTokenMintingServiceTest extends TestCase
{
    public function testMintTokenReturnsBearerPayloadForMatchingClient(): void
    {
        $registry = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registry->expects(self::once())
            ->method('findByClientId')
            ->with('client-1')
            ->willReturn(new AuthClientRegistryEntry('client-1', 'AGENT', 'secret-1', null, null, null, null, null));

        $tokens = $this->createMock(TechnicalAccessTokenRepositoryInterface::class);
        $tokens->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (TechnicalAccessTokenRecord $record): bool => $record->clientId === 'client-1' && $record->clientKind === 'AGENT'));

        $service = new AuthClientTokenMintingService($registry, $tokens, new ClientAccessTokenFactory('test-secret', 3600));
        $result = $service->mintToken('client-1', 'AGENT', 'secret-1');

        self::assertSame('Bearer', $result['token_type'] ?? null);
        self::assertSame('client-1', $result['client_id'] ?? null);
        self::assertSame('AGENT', $result['client_kind'] ?? null);
        self::assertIsString($result['access_token'] ?? null);
    }
}
