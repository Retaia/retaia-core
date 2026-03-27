<?php

namespace App\Application\Auth;

use App\User\Repository\UserRepositoryInterface;
use App\User\Service\TwoFactorService;

final class GetAuthMeProfileHandler
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TwoFactorService $twoFactorService,
    ) {
    }

    /**
     * @param array<int, string> $roles
     */
    public function handle(string $id, string $email, array $roles): GetAuthMeProfileResult
    {
        $user = $this->users->findById($id);

        return new GetAuthMeProfileResult(
            $id,
            $email,
            $roles,
            $this->displayName($email),
            $user?->isEmailVerified() ?? false,
            $this->twoFactorService->isEnabled($id)
        );
    }

    private function displayName(string $email): string
    {
        $localPart = trim((string) preg_replace('/@.*$/', '', $email));
        if ($localPart === '') {
            return $email;
        }

        $normalized = preg_replace('/[._-]+/', ' ', $localPart);
        $normalized = is_string($normalized) ? trim($normalized) : $localPart;

        return $normalized === '' ? $email : ucwords($normalized);
    }
}
