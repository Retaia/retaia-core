<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsAdminAccessGuard;
use App\Controller\Api\OpsJobsController;
use App\Job\Repository\JobRepository;
use App\Job\Repository\JobQueueDiagnosticsProjector;
use App\Job\Repository\JobSourceProjector;
use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsJobsControllerTest extends TestCase
{
    public function testQueueReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $controller = new OpsJobsController($this->forbiddenAdminGuard(), $this->repositoryWithQueueDiagnostics());

        self::assertSame(403, $controller->queue()->getStatusCode());
    }

    public function testQueueReturnsRepositorySnapshot(): void
    {
        $controller = new OpsJobsController($this->allowAdminGuard(), $this->repositoryWithQueueDiagnostics());

        $response = $controller->queue();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['summary']['pending_total'] ?? null);
        self::assertSame('generate_preview', $payload['by_type'][0]['job_type'] ?? null);
    }

    private function repositoryWithQueueDiagnostics(): JobRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['status' => 'pending', 'total' => 2]],
            [['job_type' => 'generate_preview', 'status' => 'pending', 'total' => 2]],
            [['job_type' => 'generate_preview', 'oldest_pending_at' => '2026-03-30 12:00:00']]
        );

        $registry = $this->createMock(BusinessStorageRegistryInterface::class);

        return new JobRepository(
            $connection,
            $registry,
            new JobQueueDiagnosticsProjector(),
            new JobSourceProjector($registry),
        );
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
