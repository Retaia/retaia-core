<?php

namespace App\Auth;

interface AuthMcpChallengeRepositoryInterface
{
    public function findByChallengeId(string $challengeId): ?AuthMcpChallenge;

    /**
     * @return list<AuthMcpChallenge>
     */
    public function findAll(): array;

    public function save(AuthMcpChallenge $challenge): void;

    public function delete(string $challengeId): void;
}
