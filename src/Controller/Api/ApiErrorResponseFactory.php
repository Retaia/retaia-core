<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiErrorResponseFactory
{
    /**
     * @param array<string, mixed> $details
     */
    public static function create(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return new JsonResponse($payload, $status);
    }
}
