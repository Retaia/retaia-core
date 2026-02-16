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
