<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param callable(): JsonResponse $callback
     */
    public function execute(Request $request, string $actorId, callable $callback): JsonResponse
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($key === '') {
            return new JsonResponse([
                'code' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => 'Idempotency-Key header is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $requestHash = hash('sha256', $request->getContent());
        $method = strtoupper($request->getMethod());
        $path = (string) $request->getPathInfo();

        $existing = $this->connection->fetchAssociative(
            'SELECT request_hash, response_status, response_body
             FROM idempotency_entry
             WHERE actor_id = :actorId AND method = :method AND path = :path AND idempotency_key = :key',
            [
                'actorId' => $actorId,
                'method' => $method,
                'path' => $path,
                'key' => $key,
            ]
        );

        if (is_array($existing)) {
            if (($existing['request_hash'] ?? '') !== $requestHash) {
                return new JsonResponse([
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'Idempotency-Key was already used with a different payload',
                ], Response::HTTP_CONFLICT);
            }

            $body = json_decode((string) ($existing['response_body'] ?? '{}'), true);

            return new JsonResponse(
                is_array($body) ? $body : [],
                (int) ($existing['response_status'] ?? Response::HTTP_OK)
            );
        }

        $response = $callback();

        $this->connection->insert('idempotency_entry', [
            'actor_id' => $actorId,
            'method' => $method,
            'path' => $path,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $response;
    }
}
