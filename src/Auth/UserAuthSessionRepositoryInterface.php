<?php

namespace App\Auth;

interface UserAuthSessionRepositoryInterface
{
    public function findByRefreshToken(string $refreshToken): ?UserAuthSession;

    public function findBySessionId(string $sessionId): ?UserAuthSession;

    /**
     * @return list<UserAuthSession>
     */
    public function findByUserId(string $userId): array;

    public function save(UserAuthSession $session): void;

    public function delete(string $sessionId): void;
}
