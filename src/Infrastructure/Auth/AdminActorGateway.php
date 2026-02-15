<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\AdminActorGateway as AdminActorGatewayPort;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final class AdminActorGateway implements AdminActorGatewayPort
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    public function actorId(): ?string
    {
        $actor = $this->security->getUser();

        return $actor instanceof User ? $actor->getId() : null;
    }
}
