<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ErrorResponseContractSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (!str_contains($contentType, 'application/json')) {
            return;
        }

        $decoded = json_decode((string) $response->getContent(), true);
        if (!is_array($decoded) || !isset($decoded['code'], $decoded['message'])) {
            return;
        }

        if (!array_key_exists('retryable', $decoded)) {
            $decoded['retryable'] = $this->isRetryableCode((string) $decoded['code']);
        }

        if (!array_key_exists('correlation_id', $decoded)) {
            $decoded['correlation_id'] = $this->resolveCorrelationId($event);
        }

        $response->headers->set('X-Correlation-Id', (string) $decoded['correlation_id']);
        $response->setContent((string) json_encode($decoded, JSON_UNESCAPED_SLASHES));
    }

    private function isRetryableCode(string $code): bool
    {
        return in_array($code, [
            'RATE_LIMITED',
            'TEMPORARY_UNAVAILABLE',
        ], true);
    }

    private function resolveCorrelationId(ResponseEvent $event): string
    {
        $fromRequest = trim((string) $event->getRequest()->headers->get('X-Correlation-Id', ''));
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        return bin2hex(random_bytes(16));
    }
}

