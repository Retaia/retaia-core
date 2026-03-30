<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AuthCurrentSessionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthCurrentSessionResolverTest extends TestCase
{
    public function testResolveReturnsNullWithoutBearerToken(): void
    {
        $reflection = new \ReflectionClass(AuthCurrentSessionResolver::class);
        $resolver = $reflection->newInstanceWithoutConstructor();

        self::assertNull($resolver->resolve(Request::create('/api/v1/auth/me', 'GET')));
    }
}
