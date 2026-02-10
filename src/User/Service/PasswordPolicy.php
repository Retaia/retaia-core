<?php

namespace App\User\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PasswordPolicy
{
    public function __construct(
        #[Autowire('%app.password_policy.min_length%')]
        private int $minLength,
        #[Autowire('%app.password_policy.require_mixed_case%')]
        private bool $requireMixedCase,
        #[Autowire('%app.password_policy.require_number%')]
        private bool $requireNumber,
        #[Autowire('%app.password_policy.require_special%')]
        private bool $requireSpecial,
    ) {
    }

    /**
     * @return list<string>
     */
    public function violations(string $password): array
    {
        $violations = [];

        if (mb_strlen($password) < $this->minLength) {
            $violations[] = sprintf('new_password must be at least %d characters', $this->minLength);
        }

        if ($this->requireMixedCase && (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password))) {
            $violations[] = 'new_password must include lowercase and uppercase letters';
        }

        if ($this->requireNumber && !preg_match('/\d/', $password)) {
            $violations[] = 'new_password must include at least one number';
        }

        if ($this->requireSpecial && !preg_match('/[^a-zA-Z\d]/', $password)) {
            $violations[] = 'new_password must include at least one special character';
        }

        return $violations;
    }
}
