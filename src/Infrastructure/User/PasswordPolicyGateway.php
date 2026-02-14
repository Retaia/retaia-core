<?php

namespace App\Infrastructure\User;

use App\Application\Auth\Port\PasswordPolicyGateway as PasswordPolicyGatewayPort;
use App\User\Service\PasswordPolicy;

final class PasswordPolicyGateway implements PasswordPolicyGatewayPort
{
    public function __construct(
        private PasswordPolicy $passwordPolicy,
    ) {
    }

    public function violations(string $password): array
    {
        return $this->passwordPolicy->violations($password);
    }
}
