<?php

namespace App\Observability;

use App\Observability\Repository\MetricEventRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiErrorMetricSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MetricEventRepository $metrics,
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
        $response = $event->getResponse();
        if ($response->getStatusCode() < 400) {
            return;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (!str_contains($contentType, 'application/json')) {
            return;
        }

        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload) || !is_string($payload['code'] ?? null)) {
            return;
        }

        $code = trim((string) $payload['code']);
        if ($code === '') {
            return;
        }

        $this->metrics->record(MetricName::apiError($code));
    }
}
