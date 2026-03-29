<?php

namespace App\Tests\Unit\Auth;

use App\Auth\TechnicalAccessTokenRecord;
use App\Auth\TechnicalAccessTokenRepository;
use App\Tests\Support\TechnicalAccessTokenEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class TechnicalAccessTokenRepositoryTest extends TestCase
{
    use TechnicalAccessTokenEntityManagerTrait;

    public function testSaveFindAndDeleteRoundTripsTokenRecord(): void
    {
        $repository = new TechnicalAccessTokenRepository($this->technicalAccessTokenEntityManager());
        $repository->save(new TechnicalAccessTokenRecord('client-1', 'token-1', 'AGENT', 10));

        self::assertSame('client-1', $repository->findByClientId('client-1')?->clientId);
        self::assertSame('client-1', $repository->findByAccessToken('token-1')?->clientId);

        $repository->deleteByClientId('client-1');

        self::assertNull($repository->findByClientId('client-1'));
        self::assertNull($repository->findByAccessToken('token-1'));
    }

    public function testSaveUpdatesExistingRecord(): void
    {
        $repository = new TechnicalAccessTokenRepository($this->technicalAccessTokenEntityManager());
        $repository->save(new TechnicalAccessTokenRecord('client-1', 'token-1', 'AGENT', 10));
        $repository->save(new TechnicalAccessTokenRecord('client-1', 'token-2', 'MCP', 20));

        $record = $repository->findByClientId('client-1');

        self::assertSame('token-2', $record?->accessToken);
        self::assertSame('MCP', $record?->clientKind);
        self::assertSame(20, $record?->issuedAt);
    }
}
