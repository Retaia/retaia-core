<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepository;
use App\Tests\Support\AuthClientRegistryEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class AuthClientRegistryRepositoryTest extends TestCase
{
    use AuthClientRegistryEntityManagerTrait;

    public function testDefaultsAreSeededAndCustomEntryCanBeSaved(): void
    {
        $repository = new AuthClientRegistryRepository($this->authClientRegistryEntityManager());

        $default = $repository->findByClientId('agent-default');
        self::assertNotNull($default);
        self::assertSame('AGENT', $default->clientKind);
        self::assertSame('agent-secret', $default->secretKey);

        $repository->save(new AuthClientRegistryEntry(
            'agent-123',
            'AGENT',
            'secret-123',
            'worker',
            null,
            null,
            null,
            null,
        ));

        $stored = $repository->findByClientId('agent-123');
        self::assertNotNull($stored);
        self::assertSame('worker', $stored->clientLabel);
        self::assertSame('secret-123', $stored->secretKey);
    }
}
