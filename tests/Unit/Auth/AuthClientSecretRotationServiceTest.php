<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Auth\AuthClientSecretRotationService;
use App\Auth\TechnicalAccessTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class AuthClientSecretRotationServiceTest extends TestCase
{
    public function testRotateSecretPersistsNewSecretAndRevokesCurrentToken(): void
    {
        $registry = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registry->expects(self::once())
            ->method('findByClientId')
            ->with('client-1')
            ->willReturn(new AuthClientRegistryEntry('client-1', 'AGENT', 'old-secret', 'label', null, null, '2026-03-29T00:00:00+00:00', null));
        $registry->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (AuthClientRegistryEntry $entry): bool => $entry->clientId === 'client-1'
                && $entry->secretKey !== null
                && $entry->secretKey !== 'old-secret'));

        $tokens = $this->createMock(TechnicalAccessTokenRepositoryInterface::class);
        $tokens->expects(self::once())
            ->method('deleteByClientId')
            ->with('client-1');

        $service = new AuthClientSecretRotationService($registry, $tokens);
        $newSecret = $service->rotateSecret('client-1');

        self::assertIsString($newSecret);
        self::assertNotSame('old-secret', $newSecret);
    }
}
