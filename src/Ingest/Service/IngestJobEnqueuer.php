<?php

namespace App\Ingest\Service;

use App\Entity\Asset;
use App\Job\Repository\JobRepository;
use App\Asset\Repository\AssetRepositoryInterface;
use Psr\Log\LoggerInterface;

final class IngestJobEnqueuer
{
    public function __construct(
        private JobRepository $jobs,
        private AssetRepositoryInterface $assets,
        private LoggerInterface $logger,
    ) {
    }

    public function enqueueRequiredJobs(Asset $asset, bool $hasExistingProxy): int
    {
        $queued = 0;
        $stateVersion = $this->ensureReviewProcessingVersion($asset);
        $ingestCorrelationId = $this->newIngestCorrelationId($asset->getUuid());
        $jobs = ['extract_facts', 'generate_thumbnails'];
        if (!$hasExistingProxy) {
            $jobs[] = 'generate_preview';
        }
        if ($asset->getMediaType() === 'AUDIO') {
            $jobs[] = 'generate_audio_waveform';
        }

        foreach ($jobs as $jobType) {
            if ($this->jobs->enqueuePendingIfMissing($asset->getUuid(), $jobType, $stateVersion, $ingestCorrelationId)) {
                ++$queued;
                $this->logger->info('ingest.job.queued', [
                    'correlation_id' => $ingestCorrelationId,
                    'asset_uuid' => $asset->getUuid(),
                    'job_type' => $jobType,
                ]);
            } else {
                $this->logger->info('ingest.job.deduplicated', [
                    'correlation_id' => $ingestCorrelationId,
                    'asset_uuid' => $asset->getUuid(),
                    'job_type' => $jobType,
                ]);
            }
        }

        return $queued;
    }

    private function ensureReviewProcessingVersion(Asset $asset): string
    {
        $fields = $asset->getFields();
        $current = trim((string) ($fields['review_processing_version'] ?? ''));
        if ($current !== '') {
            return $current;
        }

        $fields['review_processing_version'] = '1';
        $asset->setFields($fields);
        $this->assets->save($asset);

        return '1';
    }

    private function newIngestCorrelationId(string $assetUuid): string
    {
        return sprintf('ing-%s', substr(hash('sha256', $assetUuid.'|'.bin2hex(random_bytes(8))), 0, 24));
    }
}
