<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsAdminAccessGuard;
use App\Controller\Api\OpsLocksController;
use App\Lock\Repository\OperationLockRepository;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsLocksControllerTest extends TestCase
{
    public function testLocksReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $controller = new OpsLocksController($this->forbiddenAdminGuard(), $this->repositoryWithSnapshot());

        self::assertSame(403, $controller->locks(new Request())->getStatusCode());
    }

    public function testLocksReturnsSnapshot(): void
    {
        $controller = new OpsLocksController($this->allowAdminGuard(), $this->repositoryWithSnapshot());

        $response = $controller->locks(new Request(['asset_uuid' => 'asset-1', 'lock_type' => 'move', 'limit' => '10', 'offset' => '0']));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['total'] ?? null);
        self::assertSame('asset-1', $payload['items'][0]['asset_uuid'] ?? null);
    }

    public function testRecoverLocksValidatesPayload(): void
    {
        $controller = new OpsLocksController($this->allowAdminGuard(), $this->repositoryWithSnapshot());

        $invalidMinutes = Request::create('/', 'POST', [], [], [], [], json_encode(['stale_lock_minutes' => '30'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $controller->recoverLocks($invalidMinutes)->getStatusCode());

        $invalidDryRun = Request::create('/', 'POST', [], [], [], [], json_encode(['dry_run' => 'true'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $controller->recoverLocks($invalidDryRun)->getStatusCode());
    }

    public function testRecoverLocksReturnsRecoverySummary(): void
    {
        $controller = new OpsLocksController($this->allowAdminGuard(), $this->repositoryForRecover());
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['stale_lock_minutes' => 30, 'dry_run' => false], JSON_THROW_ON_ERROR));

        $response = $controller->recoverLocks($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['stale_examined'] ?? null);
        self::assertSame(3, $payload['recovered'] ?? null);
        self::assertFalse($payload['dry_run'] ?? true);
    }

    public function testRecoverLocksSupportsDryRun(): void
    {
        $controller = new OpsLocksController($this->allowAdminGuard(), $this->repositoryForDryRun());
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['stale_lock_minutes' => 30, 'dry_run' => true], JSON_THROW_ON_ERROR));

        $response = $controller->recoverLocks($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['stale_examined'] ?? null);
        self::assertSame(0, $payload['recovered'] ?? null);
        self::assertTrue($payload['dry_run'] ?? false);
    }

    private function repositoryWithSnapshot(): OperationLockRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'id' => 'lock-1',
                'asset_uuid' => 'asset-1',
                'lock_type' => 'move',
                'actor_id' => 'actor-1',
                'acquired_at' => '2026-03-30 10:00:00',
                'released_at' => null,
            ],
        ]);

        return new OperationLockRepository($connection, new MetricEventRepository($this->createMock(Connection::class)));
    }

    private function repositoryForRecover(): OperationLockRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(2, 1);
        $connection->method('executeStatement')->willReturnOnConsecutiveCalls(2, 1);

        $metricsConnection = $this->createMock(Connection::class);
        $metricsConnection->method('insert')->willReturn(1);

        return new OperationLockRepository($connection, new MetricEventRepository($metricsConnection));
    }

    private function repositoryForDryRun(): OperationLockRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(2, 1);

        return new OperationLockRepository($connection, new MetricEventRepository($this->createMock(Connection::class)));
    }

    private function allowAdminGuard(): OpsAdminAccessGuard
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool { return true; }
            public function actorId(): ?string { return 'admin-1'; }
        };

        return new OpsAdminAccessGuard(new ResolveAdminActorHandler($gateway), $this->translator());
    }

    private function forbiddenAdminGuard(): OpsAdminAccessGuard
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool { return false; }
            public function actorId(): ?string { return null; }
        };

        return new OpsAdminAccessGuard(new ResolveAdminActorHandler($gateway), $this->translator());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
