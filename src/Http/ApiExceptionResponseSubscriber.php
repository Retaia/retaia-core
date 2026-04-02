<?php

namespace App\Http;

use App\Controller\Api\ApiErrorResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiExceptionResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
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
        return $this->translator->trans(match ($status) {
            Response::HTTP_BAD_REQUEST => 'http.error.bad_request',
            Response::HTTP_UNAUTHORIZED => 'http.error.unauthorized',
            Response::HTTP_FORBIDDEN => 'http.error.forbidden',
            Response::HTTP_NOT_FOUND => 'http.error.not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'http.error.method_not_allowed',
            Response::HTTP_CONFLICT => 'http.error.conflict',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'http.error.validation_failed',
            Response::HTTP_TOO_MANY_REQUESTS => 'http.error.too_many_requests',
            default => 'http.error.internal_server_error',
        });
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
