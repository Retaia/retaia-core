<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthMcpChallenge;
use App\Auth\AuthMcpChallengeRepository;
use App\Tests\Support\AuthMcpChallengeEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class AuthMcpChallengeRepositoryTest extends TestCase
{
    use AuthMcpChallengeEntityManagerTrait;

    public function testSaveFindAllAndDeleteRoundTripChallenge(): void
    {
        $repository = new AuthMcpChallengeRepository($this->authMcpChallengeEntityManager());
        $repository->save(new AuthMcpChallenge('c-1', 'client-1', 'FINGERPRINT1', 'challenge-1', 100, false, null));
        $repository->save(new AuthMcpChallenge('c-2', 'client-2', 'FINGERPRINT2', 'challenge-2', 200, true, 150));

        self::assertSame('c-1', $repository->findByChallengeId('c-1')?->challengeId);
        self::assertCount(2, $repository->findAll());

        $repository->delete('c-1');

        self::assertNull($repository->findByChallengeId('c-1'));
        self::assertCount(1, $repository->findAll());
    }

    public function testSaveUpdatesExistingChallenge(): void
    {
        $repository = new AuthMcpChallengeRepository($this->authMcpChallengeEntityManager());
        $repository->save(new AuthMcpChallenge('c-1', 'client-1', 'FINGERPRINT1', 'challenge-1', 100, false, null));
        $repository->save(new AuthMcpChallenge('c-1', 'client-1', 'FINGERPRINT1', 'challenge-1', 100, true, 99));

        $challenge = $repository->findByChallengeId('c-1');

        self::assertTrue($challenge?->used ?? false);
        self::assertSame(99, $challenge?->usedAt);
    }
}
