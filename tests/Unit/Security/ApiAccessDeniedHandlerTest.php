<?php

namespace App\Tests\Unit\Security;

use App\Security\ApiAccessDeniedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiAccessDeniedHandlerTest extends TestCase
{
    public function testHandleReturnsNullOutsideApiRoutes(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $handler = new ApiAccessDeniedHandler($translator);

        $response = $handler->handle(Request::create('/health'), new AccessDeniedException());

        self::assertNull($response);
    }

    public function testHandleReturnsForbiddenPayloadOnApiRoutes(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->with('auth.error.forbidden_scope')->willReturn('forbidden');

        $handler = new ApiAccessDeniedHandler($translator);
        $response = $handler->handle(Request::create('/api/v1/assets'), new AccessDeniedException());

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('FORBIDDEN_SCOPE', (string) json_decode((string) $response->getContent(), true)['code']);
    }
}
