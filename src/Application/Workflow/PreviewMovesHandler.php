<?php

namespace App\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;

final class PreviewMovesHandler
{
    public function __construct(
        private WorkflowGateway $gateway,
    ) {
    }

    /**
     * @param array<int, string>|null $uuids
     *
     * @return array<string, mixed>
     */
    public function handle(?array $uuids): array
    {
        return $this->gateway->previewMoves($uuids);
    }
}
