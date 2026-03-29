<?php

namespace App\Auth;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class UserAccessTokenService
{
    public function __construct(
        private UserAuthSessionService $sessionService,
        private UserAccessJwtService $jwtService,
        #[Autowire('%app.user_refresh_token_ttl_seconds%')]
        private int $refreshTtlSeconds = 2592000,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}
     */
    public function issue(User $user, string $clientId, string $clientKind): array
    {
        return $this->issueForPrincipal($user->getId(), $user->getEmail(), $clientId, $clientKind);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}|null
     */
    public function refresh(string $refreshToken, ?string $clientId = null, ?string $clientKind = null): ?array
    {
        $normalizedRefreshToken = trim($refreshToken);
        if ($normalizedRefreshToken === '') {
            return null;
        }

        $session = $this->sessionService->byRefreshToken($normalizedRefreshToken);
        if (!$session instanceof UserAuthSession) {
            return null;
        }

        $activeClientId = $session->clientId;
        $activeClientKind = $session->clientKind;

        if ($clientId !== null && $clientId !== '' && !hash_equals($activeClientId, $clientId)) {
            return null;
        }

        if ($clientKind !== null && $clientKind !== '' && !hash_equals($activeClientKind, $clientKind)) {
            return null;
        }

        $userId = $session->userId;
        $email = $session->email;
        if ($userId === '' || $email === '' || $activeClientId === '' || $activeClientKind === '') {
            return null;
        }

        return $this->issueForPrincipal($userId, $email, $activeClientId, $activeClientKind, $session);
    }

    /**
     * @return array{user_id: string, email: string, client_id: string, client_kind: string, session_id: string}|null
     */
    public function validate(string $rawToken): ?array
    {
        $claims = $this->jwtService->validate($rawToken);
        if (!is_array($claims)) {
            return null;
        }

        $active = $this->sessionService->bySessionId((string) $claims['session_id']);
        if (!$active instanceof UserAuthSession) {
            return null;
        }

        if (!hash_equals($active->accessToken, $rawToken)) {
            return null;
        }

        $this->sessionService->save($active->withLastUsedAt(time()));

        return $claims;
    }

    public function revoke(string $rawToken): bool
    {
        $claims = $this->jwtService->validate($rawToken);
        if (!is_array($claims)) {
            return false;
        }

        $sessionId = (string) $claims['session_id'];
        $active = $this->sessionService->bySessionId($sessionId);
        if (!$active instanceof UserAuthSession) {
            return false;
        }

        if (!hash_equals($active->accessToken, $rawToken)) {
            return false;
        }

        $this->sessionService->delete($sessionId);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForUser(string $userId, string $currentSessionId): array
    {
        return $this->sessionService->sessionsForUser($userId, $currentSessionId);
    }

    public function revokeSession(string $userId, string $sessionId, string $currentSessionId): string
    {
        return $this->sessionService->revokeSession($userId, $sessionId, $currentSessionId);
    }

    public function revokeOtherSessions(string $userId, string $currentSessionId): int
    {
        return $this->sessionService->revokeOtherSessions($userId, $currentSessionId);
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, client_id: string, client_kind: string}
     */
    private function issueForPrincipal(string $userId, string $email, string $clientId, string $clientKind, ?UserAuthSession $session = null): array
    {
        $issuedAt = time();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp($issuedAt);
        $refreshExpiresAt = $now->modify(sprintf('+%d seconds', $this->refreshTtlSeconds));
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $sessionId = trim((string) ($session?->sessionId ?? ''));
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }
        $createdAt = (int) ($session?->createdAt ?? $issuedAt);

        $tokenPayload = $this->jwtService->issue($userId, $email, $sessionId, $clientId, $clientKind, $issuedAt);

        $this->sessionService->save(new UserAuthSession(
            $sessionId,
            $tokenPayload['token'],
            $refreshToken,
            $tokenPayload['expires_at'],
            $refreshExpiresAt->getTimestamp(),
            $userId,
            $email,
            $clientId,
            $clientKind,
            $createdAt,
            $issuedAt,
        ));

        return [
            'access_token' => $tokenPayload['token'],
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtService->ttlSeconds(),
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ];
    }
}
