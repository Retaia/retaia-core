<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AgentJobProjectionRepositoryInterface;
use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Controller\Api\OpsAgentsViewProjector;
use PHPUnit\Framework\TestCase;

final class OpsAgentsViewProjectorTest extends TestCase
{
    public function testPaginatedProjectsStatusAndIdentityConflict(): void
    {
        $projector = $this->projector([
            [
                'agent_id' => 'agent-busy',
                'client_id' => 'client-1',
                'agent_name' => 'Busy Agent',
                'agent_version' => '1.0.0',
                'last_seen_at' => '2030-01-01T00:00:00+00:00',
                'last_register_at' => '2030-01-01T00:00:00+00:00',
                'last_heartbeat_at' => '2030-01-01T00:00:00+00:00',
                'effective_capabilities' => ['ingest'],
                'capability_warnings' => [],
                'debug' => ['max_parallel_jobs' => 4],
            ],
            [
                'agent_id' => 'agent-stale',
                'client_id' => 'client-1',
                'agent_name' => 'Stale Agent',
                'agent_version' => '1.0.0',
                'last_seen_at' => '2000-01-01T00:00:00+00:00',
                'last_register_at' => '2000-01-01T00:00:00+00:00',
                'last_heartbeat_at' => '2000-01-01T00:00:00+00:00',
                'effective_capabilities' => [],
                'capability_warnings' => ['old'],
                'debug' => [],
            ],
        ], [
            'agent-busy' => [
                'current_job' => ['id' => 'job-1'],
                'last_successful_job' => null,
                'last_failed_job' => null,
            ],
        ]);

        $payload = $projector->paginated(null, 10, 0);

        self::assertSame(2, $payload['total']);
        self::assertSame('agent-busy', $payload['items'][0]['agent_id']);
        self::assertSame('online_busy', $payload['items'][0]['status']);
        self::assertTrue($payload['items'][0]['identity_conflict']);
        self::assertSame('stale', $payload['items'][1]['status']);
    }

    public function testPaginatedFiltersAndSlices(): void
    {
        $projector = $this->projector([
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
            [
                'agent_id' => 'agent-2',
                'client_id' => 'client-2',
                'agent_name' => 'Agent 2',
                'agent_version' => '1.0.0',
                'last_seen_at' => '2030-01-01T00:00:01+00:00',
                'last_register_at' => '2030-01-01T00:00:01+00:00',
                'last_heartbeat_at' => '2030-01-01T00:00:01+00:00',
                'effective_capabilities' => [],
                'capability_warnings' => [],
                'debug' => [],
            ],
        ]);

        $payload = $projector->paginated('online_idle', 1, 1);

        self::assertSame(2, $payload['total']);
        self::assertCount(1, $payload['items']);
        self::assertSame('agent-1', $payload['items'][0]['agent_id']);
    }

    public function testPaginatedIgnoresEntriesWithoutAgentIdAndFallsBackOnInvalidTimestamps(): void
    {
        $projector = $this->projector([
            [
                'agent_id' => '',
                'client_id' => 'client-empty',
                'agent_name' => 'Missing',
                'agent_version' => '1.0.0',
                'last_seen_at' => '2030-01-01T00:00:00+00:00',
                'last_register_at' => '2030-01-01T00:00:00+00:00',
                'last_heartbeat_at' => '2030-01-01T00:00:00+00:00',
                'effective_capabilities' => [],
                'capability_warnings' => [],
                'debug' => [],
            ],
            [
                'agent_id' => 'agent-invalid-time',
                'client_id' => 'client-1',
                'agent_name' => 'Agent Invalid Time',
                'agent_version' => '1.0.0',
                'last_seen_at' => 'not-a-date',
                'last_register_at' => 'also-not-a-date',
                'last_heartbeat_at' => 'still-not-a-date',
                'effective_capabilities' => [],
                'capability_warnings' => [],
                'debug' => [],
            ],
        ]);

        $payload = $projector->paginated(null, 10, 0);

        self::assertSame(1, $payload['total']);
        self::assertSame('agent-invalid-time', $payload['items'][0]['agent_id']);
        self::assertSame('online_idle', $payload['items'][0]['status']);
        self::assertNull($payload['items'][0]['last_heartbeat_at']);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array{current_job:?array<string,mixed>,last_successful_job:?array<string,mixed>,last_failed_job:?array<string,mixed>}> $snapshots
     */
    private function projector(array $entries, array $snapshots = []): OpsAgentsViewProjector
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
}
