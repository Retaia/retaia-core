<?php

namespace App\Tests\Unit\Api;

use App\Api\Service\IdempotencyService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyServiceTest extends TestCase
{
    public function testExecuteRequiresIdempotencyKeyHeader(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');

        $service = new IdempotencyService($connection);
        $request = Request::create('/api/v1/jobs/submit', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"job":"1"}');

        $response = $service->execute($request, 'actor-1', static fn (): JsonResponse => new JsonResponse(['ok' => true]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('MISSING_IDEMPOTENCY_KEY', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testExecuteReturnsConflictWhenSameKeyHasDifferentPayload(): void
    {
        $request = Request::create('/api/v1/jobs/submit', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"job":"2"}');
        $request->headers->set('Idempotency-Key', 'idem-1');

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'request_hash' => 'old-hash',
                'response_status' => 200,
                'response_body' => '{"ok":true}',
            ]);
        $connection->expects(self::never())->method('insert');

        $service = new IdempotencyService($connection);
        $response = $service->execute($request, 'actor-1', static fn (): JsonResponse => new JsonResponse(['ok' => true], Response::HTTP_CREATED));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('IDEMPOTENCY_CONFLICT', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testExecuteReplaysStoredResponseWhenRequestHashMatches(): void
    {
        $payload = '{"job":"3"}';
        $request = Request::create('/api/v1/jobs/submit', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $request->headers->set('Idempotency-Key', 'idem-2');

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'request_hash' => hash('sha256', $payload),
                'response_status' => 202,
                'response_body' => '{"job_id":"job-3"}',
            ]);
        $connection->expects(self::never())->method('insert');

        $service = new IdempotencyService($connection);
        $response = $service->execute($request, 'actor-9', static fn (): JsonResponse => new JsonResponse(['should' => 'not-run']));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame('job-3', (string) json_decode((string) $response->getContent(), true)['job_id']);
    }

    public function testExecuteStoresResponseWhenKeyIsFirstSeen(): void
    {
        $payload = '{"job":"4"}';
        $request = Request::create('/api/v1/jobs/submit', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        $request->headers->set('Idempotency-Key', 'idem-4');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'idempotency_entry',
                self::callback(static function (array $values): bool {
                    return isset($values['actor_id'], $values['idempotency_key'], $values['request_hash'], $values['response_status'], $values['response_body'])
                        && $values['actor_id'] === 'actor-4'
                        && $values['idempotency_key'] === 'idem-4'
                        && $values['response_status'] === 201;
                })
            );

        $service = new IdempotencyService($connection);
        $response = $service->execute($request, 'actor-4', static fn (): JsonResponse => new JsonResponse(['job_id' => 'job-4'], Response::HTTP_CREATED));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('job-4', (string) json_decode((string) $response->getContent(), true)['job_id']);
    }
}
