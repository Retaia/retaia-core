<?php

namespace App\Application\Auth\Port;

interface PasswordPolicyGateway
{
    /**
     * @return list<string>
     */
    public function violations(string $password): array;
}
