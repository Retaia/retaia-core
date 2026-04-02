<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

trait ApiErrorResponderTrait
{
    /**
     * @param array<string, mixed> $details
     */
    private function errorResponse(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return ApiErrorResponseFactory::create($code, $message, $status, $details);
    }
}
