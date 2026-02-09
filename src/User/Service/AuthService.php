<?php

namespace App\User\Service;

use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthService
{
    private const SESSION_USER_ID = 'auth_user_id';

    public function __construct(
        private UserRepositoryInterface $users,
        private RequestStack $requestStack,
    ) {
    }

    public function login(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return false;
        }

        if (!password_verify($password, $user->getPassword())) {
            return false;
        }

        $session = $this->requestStack->getSession();
        if ($session === null) {
            return false;
        }

        $session->set(self::SESSION_USER_ID, $user->getId());

        return true;
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        if ($session === null) {
            return;
        }

        $session->remove(self::SESSION_USER_ID);
    }

    public function currentUser(): ?User
    {
        $session = $this->requestStack->getSession();
        if ($session === null) {
            return null;
        }

        $userId = $session->get(self::SESSION_USER_ID);
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        return $this->users->findById($userId);
    }
}
