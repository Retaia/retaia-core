<?php

namespace App\Tests\Unit\Api\Service;

use App\Api\Service\AgentJobProjectionAgentIdNormalizer;
use PHPUnit\Framework\TestCase;

final class AgentJobProjectionAgentIdNormalizerTest extends TestCase
{
    public function testNormalizeDeduplicatesAndTrimsIds(): void
    {
        $normalizer = new AgentJobProjectionAgentIdNormalizer();

        self::assertSame(['agent-1', 'agent-2'], $normalizer->normalize([' agent-1 ', '', 'agent-2', 'agent-1', null]));
    }

    public function testEmptySnapshotsScaffoldsAllSlots(): void
    {
        $normalizer = new AgentJobProjectionAgentIdNormalizer();

        self::assertSame([
            'agent-1' => [
                'current_job' => null,
                'last_successful_job' => null,
                'last_failed_job' => null,
            ],
        ], $normalizer->emptySnapshots(['agent-1']));
    }
}
