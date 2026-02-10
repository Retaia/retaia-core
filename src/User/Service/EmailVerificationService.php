<?php

namespace App\User\Service;

use App\User\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EmailVerificationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%app.email_verification.secret%')]
        private string $secret,
        #[Autowire('%app.email_verification_ttl_seconds%')]
        private int $tokenTtlSeconds,
    ) {
    }

    public function requestVerification(string $email): ?string
    {
        $emailHash = hash('sha256', mb_strtolower(trim($email)));
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->logger->info('auth.email_verification.request.ignored', [
                'reason' => 'user_not_found',
                'email_hash' => $emailHash,
            ]);

            return null;
        }

        if ($user->isEmailVerified()) {
            $this->logger->info('auth.email_verification.request.ignored', [
                'reason' => 'already_verified',
                'user_id' => $user->getId(),
            ]);

            return null;
        }

        $token = $this->createToken($user->getId());
        $this->logger->info('auth.email_verification.request.accepted', [
            'user_id' => $user->getId(),
            'email_hash' => $emailHash,
            'ttl_seconds' => $this->tokenTtlSeconds,
            'token_exposed' => $this->environment !== 'prod',
        ]);

        if ($this->environment !== 'prod') {
            return $token;
        }

        return null;
    }

    public function confirmVerification(string $token): bool
    {
        $userId = $this->validateToken($token);
        if ($userId === null || $userId === '') {
            $this->logger->info('auth.email_verification.confirm.failed', [
                'reason' => 'invalid_or_expired_token',
            ]);

            return false;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->logger->warning('auth.email_verification.confirm.failed', [
                'reason' => 'user_not_found',
                'user_id' => $userId,
            ]);

            return false;
        }

        $alreadyVerified = $user->isEmailVerified();
        if (!$alreadyVerified) {
            $this->users->save($user->withEmailVerified(true));
        }

        $this->logger->info('auth.email_verification.confirm.completed', [
            'user_id' => $user->getId(),
            'already_verified' => $alreadyVerified,
        ]);

        return true;
    }

    public function forceVerifyByEmail(string $email, ?string $actorId): bool
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->logger->warning('auth.email_verification.admin_forced.failed', [
                'reason' => 'user_not_found',
                'actor_id' => $actorId,
                'email_hash' => hash('sha256', mb_strtolower(trim($email))),
            ]);

            return false;
        }

        $alreadyVerified = $user->isEmailVerified();
        if (!$alreadyVerified) {
            $this->users->save($user->withEmailVerified(true));
        }

        $this->logger->info('auth.email_verification.admin_forced.completed', [
            'actor_id' => $actorId,
            'target_user_id' => $user->getId(),
            'already_verified' => $alreadyVerified,
        ]);

        return true;
    }

    private function createToken(string $userId): string
    {
        $payload = json_encode([
            'uid' => $userId,
            'exp' => (new \DateTimeImmutable(sprintf('+%d seconds', $this->tokenTtlSeconds)))->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret);

        return $encodedPayload.'.'.$signature;
    }

    private function validateToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (\count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($encodedPayload), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $userId = $payload['uid'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        if (!is_string($userId) || $userId === '' || !is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt < (new \DateTimeImmutable())->getTimestamp()) {
            return null;
        }

        return $userId;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): string
    {
        $remainder = \strlen($encoded) % 4;
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
