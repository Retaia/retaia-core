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
        return self::createWithFields($code, $message, $status, $details === [] ? [] : ['details' => $details]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function createWithFields(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $payload += $fields;
        }

        return new JsonResponse($payload, $status);
    }
}
