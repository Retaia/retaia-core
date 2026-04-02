<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;

final class IngestDiagnosticsRepository
{
    private IngestUnmatchedSidecarWriter $writer;
    private IngestDiagnosticsSummaryProjector $summaryProjector;
    private IngestUnmatchedSidecarListingProjector $listingProjector;

    public function __construct(
        Connection $connection,
        ?IngestUnmatchedSidecarWriter $writer = null,
        ?IngestDiagnosticsSummaryProjector $summaryProjector = null,
        ?IngestUnmatchedSidecarListingProjector $listingProjector = null,
    ) {
        $this->writer = $writer ?? new IngestUnmatchedSidecarWriter($connection);
        $this->summaryProjector = $summaryProjector ?? new IngestDiagnosticsSummaryProjector($connection);
        $this->listingProjector = $listingProjector ?? new IngestUnmatchedSidecarListingProjector($connection);
    }

    public function recordUnmatchedSidecar(string $path, string $reason): void
    {
        $this->writer->recordUnmatchedSidecar($path, $reason);
    }

    public function clearUnmatchedSidecar(string $path): void
    {
        $this->writer->clearUnmatchedSidecar($path);
    }

    /**
     * @return array{
     *     queued:int,
     *     missing:int,
     *     unmatched_sidecars:int,
     *     latest_unmatched:array<int, array{path:string,reason:string,detected_at:string}>
     * }
     */
    public function diagnosticsSnapshot(int $latestLimit = 20): array
    {
        return $this->summaryProjector->diagnosticsSnapshot($latestLimit);
    }

    /**
     * @return array{
     *     items:array<int, array{path:string,reason:string,detected_at:string}>,
     *     total:int
     * }
     */
    public function unmatchedSnapshot(?string $reason, ?\DateTimeImmutable $since, int $limit = 50): array
    {
        return $this->listingProjector->unmatchedSnapshot($reason, $since, $limit);
    }
}
