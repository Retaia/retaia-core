<?php

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $response = $event->getResponse();
        $this->applyBaseHeaders($response);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    private function applyBaseHeaders(Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($this->debug) {
            $this->exposeProfilerHeaders($response);
        }
    }

    private function exposeProfilerHeaders(Response $response): void
    {
        $existing = $response->headers->get('Access-Control-Expose-Headers', '');
        $headers = $existing === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $existing)), static fn (string $value): bool => $value !== ''));

        foreach (['X-Debug-Token', 'X-Debug-Token-Link', 'X-Previous-Debug-Token'] as $header) {
            if (!in_array($header, $headers, true)) {
                $headers[] = $header;
            }
        }

        $response->headers->set('Access-Control-Expose-Headers', implode(', ', $headers));
    }
}
