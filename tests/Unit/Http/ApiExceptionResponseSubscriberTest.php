<?php

namespace App\Tests\Unit\Http;

use App\Http\ApiExceptionResponseSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionResponseSubscriberTest extends TestCase
{
    public function testIgnoresNonApiPaths(): void
    {
        $subscriber = new ApiExceptionResponseSubscriber(new NullLogger());
        $event = new ExceptionEvent(
            $this->kernelMock(),
            Request::create('/health', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom')
        );

        $subscriber->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testReturnsStandardizedResponseForApiRuntimeExceptions(): void
    {
        $subscriber = new ApiExceptionResponseSubscriber(new NullLogger());
        $event = new ExceptionEvent(
            $this->kernelMock(),
            Request::create('/api/v1/assets', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom')
        );

        $subscriber->onKernelException($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame([
            'code' => 'INTERNAL_SERVER_ERROR',
            'message' => 'Internal Server Error',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testReturnsStandardizedResponseForApiHttpExceptions(): void
    {
        $subscriber = new ApiExceptionResponseSubscriber(new NullLogger());
        $event = new ExceptionEvent(
            $this->kernelMock(),
            Request::create('/api/v1/does-not-exist', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new NotFoundHttpException('Missing route.')
        );

        $subscriber->onKernelException($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame([
            'code' => 'NOT_FOUND',
            'message' => 'Not Found',
        ], json_decode((string) $response->getContent(), true));
    }

    private function kernelMock(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
