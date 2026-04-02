<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentRuntimeRowMapper;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeRowMapperTest extends TestCase
{
    public function testMapsRuntimeEntryToPersistenceRow(): void
    {
        $mapper = new AgentRuntimeRowMapper();

        $row = $mapper->toPersistenceRow([
            'agent_id' => 'agent-1',
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'effective_capabilities' => ['extract_facts'],
            'capability_warnings' => ['degraded'],
            'last_register_at' => '2026-04-02T10:00:00+00:00',
            'last_seen_at' => '2026-04-02T10:00:00+00:00',
            'last_heartbeat_at' => null,
            'debug' => [
                'max_parallel_jobs' => 2,
                'feature_flags_contract_version' => '1.0.0',
                'effective_feature_flags_contract_version' => '1.1.0',
                'server_time_skew_seconds' => -3,
            ],
        ]);

        self::assertSame('agent-1', $row['agent_id']);
        self::assertSame('["extract_facts"]', $row['effective_capabilities']);
        self::assertSame('["degraded"]', $row['capability_warnings']);
        self::assertSame(2, $row['max_parallel_jobs']);
        self::assertSame(-3, $row['server_time_skew_seconds']);
    }

    public function testMapsPersistenceRowBackToRuntimeShape(): void
    {
        $mapper = new AgentRuntimeRowMapper();

        $entry = $mapper->fromPersistenceRow([
            'agent_id' => 'agent-1',
            'client_id' => 'client-1',
            'agent_name' => 'ffmpeg',
            'agent_version' => '1.0.0',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'effective_capabilities' => '["extract_facts","","extract_facts"]',
            'capability_warnings' => 'not-json',
            'last_register_at' => '2026-04-02T10:00:00+00:00',
            'last_seen_at' => '2026-04-02T10:01:00+00:00',
            'last_heartbeat_at' => '',
            'max_parallel_jobs' => '0',
            'feature_flags_contract_version' => '1.0.0',
            'effective_feature_flags_contract_version' => null,
            'server_time_skew_seconds' => '5',
        ]);

        self::assertSame(['extract_facts'], $entry['effective_capabilities']);
        self::assertSame([], $entry['capability_warnings']);
        self::assertNull($entry['last_heartbeat_at']);
        self::assertSame(1, $entry['debug']['max_parallel_jobs']);
        self::assertSame(5, $entry['debug']['server_time_skew_seconds']);
    }
}
