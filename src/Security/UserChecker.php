<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException('EMAIL_NOT_VERIFIED');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
