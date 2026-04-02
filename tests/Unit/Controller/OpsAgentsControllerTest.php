<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AgentJobProjectionRepositoryInterface;
use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsAdminAccessGuard;
use App\Controller\Api\OpsAgentsController;
use App\Controller\Api\OpsAgentsViewProjector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsAgentsControllerTest extends TestCase
{
    public function testAgentsReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $controller = new OpsAgentsController(
            $this->forbiddenAdminGuard(),
            $this->projectorWithEntries([]),
        );

        self::assertSame(403, $controller->agents(new Request())->getStatusCode());
    }

    public function testAgentsReturnsProjectedPayload(): void
    {
        $controller = new OpsAgentsController(
            $this->allowAdminGuard(),
            $this->projectorWithEntries([
                [
                    'agent_id' => 'agent-1',
                    'client_id' => 'client-1',
                    'agent_name' => 'Agent 1',
                    'agent_version' => '1.0.0',
                    'last_seen_at' => '2030-01-01T00:00:00+00:00',
                    'last_register_at' => '2030-01-01T00:00:00+00:00',
                    'last_heartbeat_at' => '2030-01-01T00:00:00+00:00',
                    'effective_capabilities' => ['ingest'],
                    'capability_warnings' => [],
                    'debug' => ['max_parallel_jobs' => 2],
                ],
            ], [
                'agent-1' => [
                    'current_job' => ['id' => 'job-1'],
                    'last_successful_job' => null,
                    'last_failed_job' => null,
                ],
            ]),
        );

        $response = $controller->agents(new Request(['status' => 'online_busy', 'limit' => '5', 'offset' => '0']));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['total'] ?? null);
        self::assertSame('agent-1', $payload['items'][0]['agent_id'] ?? null);
        self::assertSame('online_busy', $payload['items'][0]['status'] ?? null);
    }

    public function testAgentsNormalizesExtremePaginationBounds(): void
    {
        $controller = new OpsAgentsController(
            $this->allowAdminGuard(),
            $this->projectorWithEntries([
                [
                    'agent_id' => 'agent-1',
                    'client_id' => 'client-1',
                    'agent_name' => 'Agent 1',
                    'agent_version' => '1.0.0',
                    'last_seen_at' => '2030-01-01T00:00:00+00:00',
                    'last_register_at' => '2030-01-01T00:00:00+00:00',
                    'last_heartbeat_at' => '2030-01-01T00:00:00+00:00',
                    'effective_capabilities' => [],
                    'capability_warnings' => [],
                    'debug' => [],
                ],
            ]),
        );

        $response = $controller->agents(new Request(['limit' => '999', 'offset' => '-5']));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['total'] ?? null);
        self::assertCount(1, $payload['items'] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array{current_job:?array<string,mixed>,last_successful_job:?array<string,mixed>,last_failed_job:?array<string,mixed>}> $snapshots
     */
    private function projectorWithEntries(array $entries, array $snapshots = []): OpsAgentsViewProjector
    {
        $runtimeRepository = new class($entries) implements AgentRuntimeRepositoryInterface {
            /** @param list<array<string, mixed>> $entries */
            public function __construct(private array $entries) {}
            public function saveRegistration(array $entry): void {}
            public function touchSeen(string $agentId): void {}
            public function touchHeartbeat(string $agentId): void {}
            public function findAll(): array { return $this->entries; }
        };

        $jobProjectionRepository = new class($snapshots) implements AgentJobProjectionRepositoryInterface {
            /** @param array<string, array{current_job:?array<string,mixed>,last_successful_job:?array<string,mixed>,last_failed_job:?array<string,mixed>}> $snapshots */
            public function __construct(private array $snapshots) {}
            public function snapshotsForAgents(array $agentIds): array { return $this->snapshots; }
        };

        return new OpsAgentsViewProjector($runtimeRepository, $jobProjectionRepository);
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
