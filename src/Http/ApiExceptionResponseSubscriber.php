<?php

namespace App\Http;

use App\Controller\Api\ApiErrorResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$this->supportsPath($path)) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $code = $this->errorCode($status);
        $message = $this->message($status);

        $this->log($throwable, $request->getMethod(), $path, $status, $code);

        $event->setResponse(ApiErrorResponseFactory::create($code, $message, $status));
    }

    private function supportsPath(string $path): bool
    {
        if (str_starts_with($path, '/device')) {
            return true;
        }

        if (!str_starts_with($path, '/api/v1/')) {
            return false;
        }

        return !\in_array($path, [
            '/api/v1/docs',
            '/api/v1/openapi',
        ], true);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
            Response::HTTP_UNAUTHORIZED => 'UNAUTHORIZED',
            Response::HTTP_FORBIDDEN => 'FORBIDDEN',
            Response::HTTP_NOT_FOUND => 'NOT_FOUND',
            Response::HTTP_METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            Response::HTTP_CONFLICT => 'CONFLICT',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'VALIDATION_FAILED',
            Response::HTTP_TOO_MANY_REQUESTS => 'TOO_MANY_REQUESTS',
            default => $status >= 500 ? 'INTERNAL_SERVER_ERROR' : 'HTTP_ERROR',
        };
    }

    private function message(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Internal Server Error';
    }

    private function log(\Throwable $throwable, string $method, string $path, int $status, string $code): void
    {
        $context = [
            'exception' => $throwable::class,
            'status' => $status,
            'code' => $code,
            'method' => $method,
            'path' => $path,
        ];

        if ($status >= 500) {
            $this->logger->error('api.exception_response', $context + ['message' => $throwable->getMessage()]);

            return;
        }

        $this->logger->warning('api.exception_response', $context);
    }
}
