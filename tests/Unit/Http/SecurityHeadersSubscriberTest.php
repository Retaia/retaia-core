<?php

namespace App\Tests\Unit\Http;

use App\Http\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    private SecurityHeadersSubscriber $subscriber;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriber = new SecurityHeadersSubscriber(false);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testAddsBaseHeadersForApiResponses(): void
    {
        $response = new Response();
        $request = Request::create('/api/v1/docs', 'GET');
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
        self::assertFalse($response->headers->has('Access-Control-Expose-Headers'));
    }

    public function testDoesNotAddHeadersForNonApiResponses(): void
    {
        $response = new Response();
        $request = Request::create('/health', 'GET');
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
        self::assertFalse($response->headers->has('Referrer-Policy'));
        self::assertFalse($response->headers->has('Permissions-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
        self::assertFalse($response->headers->has('Access-Control-Expose-Headers'));
    }

    public function testAddsHstsWhenRequestIsSecure(): void
    {
        $response = new Response();
        $request = Request::create('/api/v1/docs', 'GET', [], [], [], ['HTTPS' => 'on']);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        self::assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testExposesProfilerHeadersInDebugMode(): void
    {
        $this->subscriber = new SecurityHeadersSubscriber(true);
        $response = new Response();
        $response->headers->set('Access-Control-Expose-Headers', 'ETag');
        $request = Request::create('/api/v1/docs', 'GET');
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        self::assertSame(
            'ETag, X-Debug-Token, X-Debug-Token-Link, X-Previous-Debug-Token',
            $response->headers->get('Access-Control-Expose-Headers')
        );
    }

    public function testDoesNotExposeProfilerHeadersWhenNotInDebugMode(): void
    {
        $response = new Response();
        $response->headers->set('Access-Control-Expose-Headers', 'ETag');
        $request = Request::create('/api/v1/docs', 'GET');
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        self::assertSame('ETag', $response->headers->get('Access-Control-Expose-Headers'));
    }
}
