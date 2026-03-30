<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\OpsReadinessController;
use PHPUnit\Framework\TestCase;

final class OpsReadinessControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testReadinessReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $controller = $this->controller(OpsReadinessController::class, [
            'adminAccessGuard' => new class {
                public function requireAdmin(): \Symfony\Component\HttpFoundation\JsonResponse
                {
                    return new \Symfony\Component\HttpFoundation\JsonResponse(['code' => 'FORBIDDEN_ACTOR'], 403);
                }
            },
        ]);

        self::assertSame(403, $controller->readiness()->getStatusCode());
    }
}
