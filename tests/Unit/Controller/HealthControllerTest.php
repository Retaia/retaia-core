<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\HealthController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends TestCase
{
    public function testInvokeReturnsOkPayload(): void
    {
        $controller = new HealthController();

        $response = $controller->__invoke();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('ok', (string) json_decode((string) $response->getContent(), true)['status']);
    }
}
