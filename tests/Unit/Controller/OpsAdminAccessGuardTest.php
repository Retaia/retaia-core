<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsAdminAccessGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsAdminAccessGuardTest extends TestCase
{
    public function testRequireAdminReturnsForbiddenResponse(): void
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool
            {
                return false;
            }

            public function actorId(): ?string
            {
                return null;
            }
        };

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $guard = new OpsAdminAccessGuard(new ResolveAdminActorHandler($gateway), $translator);
        $response = $guard->requireAdmin();

        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('FORBIDDEN_ACTOR', json_decode((string) $response?->getContent(), true, 512, JSON_THROW_ON_ERROR)['code'] ?? null);
    }
}
