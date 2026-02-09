<?php

namespace App\Tests\Unit\User;

use App\User\Service\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testReturnsViolationsForWeakPassword(): void
    {
        $policy = new PasswordPolicy(12, true, true, true);

        $violations = $policy->violations('short');

        self::assertNotEmpty($violations);
        self::assertContains('new_password must be at least 12 characters', $violations);
        self::assertContains('new_password must include lowercase and uppercase letters', $violations);
        self::assertContains('new_password must include at least one number', $violations);
        self::assertContains('new_password must include at least one special character', $violations);
    }

    public function testPassesForStrongPassword(): void
    {
        $policy = new PasswordPolicy(12, true, true, true);

        self::assertSame([], $policy->violations('StrongPassword1!'));
    }
}
