<?php

namespace App\User\Service;

use App\User\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private LoggerInterface $logger,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        #[Autowire('%kernel.environment%')]
        private string $environment,
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

        $token = $this->createToken($user->getId(), $email);
        $this->logger->info('auth.email_verification.request.accepted', [
            'user_id' => $user->getId(),
            'email_hash' => $emailHash,
            'token_exposed' => $this->environment !== 'prod',
        ]);

        if ($this->environment !== 'prod') {
            return $token;
        }

        return null;
    }

    public function confirmVerification(string $token): bool
    {
        $userId = $this->extractUserIdFromToken($token);
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
        if (!$this->isTokenValidForUser($token, $user->getId(), $user->getEmail())) {
            $this->logger->info('auth.email_verification.confirm.failed', [
                'reason' => 'invalid_or_expired_token',
                'user_id' => $userId,
            ]);

            return false;
        }

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

    private function createToken(string $userId, string $userEmail): string
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'api_auth_verify_email_confirm',
            $userId,
            mb_strtolower(trim($userEmail)),
            ['id' => $userId]
        );

        return $signatureComponents->getSignedUrl();
    }

    private function extractUserIdFromToken(string $token): ?string
    {
        $query = parse_url($token, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $userId = $params['id'] ?? null;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    private function isTokenValidForUser(string $token, string $userId, string $userEmail): bool
    {
        try {
            $request = Request::create($token, 'GET');
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                $userId,
                mb_strtolower(trim($userEmail))
            );
        } catch (VerifyEmailExceptionInterface) {
            return false;
        }

        return true;
    }
}
