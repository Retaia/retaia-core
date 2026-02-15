<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\AuthenticatedUserGateway as AuthenticatedUserGatewayPort;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final class AuthenticatedUserGateway implements AuthenticatedUserGatewayPort
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function currentUser(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ];
    }
}
