<?php

namespace App\Workflow;

interface BatchMoveReportRepositoryInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $batchId, array $payload): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $batchId): ?array;
}
