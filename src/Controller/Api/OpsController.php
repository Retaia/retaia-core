<?php

namespace App\Controller\Api;

use App\Api\Service\AgentJobProjectionRepositoryInterface;
use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Controller\RequestPayloadTrait;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Ingest\Service\WatchPathResolver;
use App\Job\Repository\JobRepository;
use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/ops')]
final class OpsController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;
    private const MAX_SELF_HEALING_SECONDS = 300;
    private const AGENT_STALE_AFTER_SECONDS = 300;

    private const ALLOWED_UNMATCHED_REASONS = [
        'missing_parent',
        'ambiguous_parent',
        'disabled_by_policy',
    ];

    public function __construct(
        private ResolveAdminActorHandler $resolveAdminActorHandler,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private OperationLockRepository $locks,
        private JobRepository $jobs,
        private AgentRuntimeRepositoryInterface $agentRuntimeRepository,
        private AgentJobProjectionRepositoryInterface $agentJobProjectionRepository,
        private AssetRepositoryInterface $assets,
        private Connection $connection,
        private WatchPathResolver $watchPathResolver,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/ingest/diagnostics', name: 'api_ops_ingest_diagnostics', methods: ['GET'])]
    public function ingestDiagnostics(): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->ingestDiagnostics->diagnosticsSnapshot(), Response::HTTP_OK);
    }

    #[Route('/readiness', name: 'api_ops_readiness', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $checks = [];

        $databaseOk = false;
        try {
            $databaseOk = (string) $this->connection->fetchOne('SELECT 1') === '1';
        } catch (\Throwable) {
            $databaseOk = false;
        }
        $checks[] = [
            'name' => 'database',
            'status' => $databaseOk ? 'ok' : 'fail',
            'message' => $databaseOk ? 'Database connectivity check passed.' : 'Database connectivity check failed.',
        ];

        $folders = ['INBOX', 'ARCHIVE', 'REJECTS'];
        $missing = [];
        $notWritable = [];
        try {
            $root = $this->watchPathResolver->resolveRoot();
            foreach ($folders as $folder) {
                $path = $root.DIRECTORY_SEPARATOR.$folder;
                if (!is_dir($path)) {
                    $missing[] = $path;
                    continue;
                }

                if (!is_writable($path)) {
                    $notWritable[] = $path;
                }
            }
        } catch (\Throwable $e) {
            $missing[] = $e->getMessage();
        }

        $watchPathOk = $missing === [];
        $checks[] = [
            'name' => 'ingest_watch_path',
            'status' => $watchPathOk ? 'ok' : 'fail',
            'message' => $watchPathOk
                ? 'Ingest watch path structure is present.'
                : 'Missing ingest directories or resolution failure: '.implode(' | ', $missing),
        ];

        $storageWritableOk = $notWritable === [] && $watchPathOk;
        $checks[] = [
            'name' => 'storage_writable',
            'status' => $storageWritableOk ? 'ok' : 'fail',
            'message' => $storageWritableOk
                ? 'Ingest directories are writable.'
                : 'Non-writable ingest directories: '.implode(' | ', $notWritable),
        ];

        $status = 'ok';
        $selfHealing = [
            'active' => false,
            'deadline_at' => null,
            'max_self_healing_seconds' => self::MAX_SELF_HEALING_SECONDS,
        ];
        if (!$databaseOk) {
            $status = 'down';
        } elseif (!$watchPathOk || !$storageWritableOk) {
            $canSelfHeal = $notWritable === [];
            if ($canSelfHeal) {
                $status = 'degraded';
                $selfHealing['active'] = true;
                $selfHealing['deadline_at'] = (new \DateTimeImmutable(sprintf('+%d seconds', self::MAX_SELF_HEALING_SECONDS)))->format(DATE_ATOM);
            } else {
                $status = 'down';
            }
        }

        return new JsonResponse([
            'status' => $status,
            'self_healing' => $selfHealing,
            'checks' => $checks,
        ], Response::HTTP_OK);
    }

    #[Route('/locks', name: 'api_ops_locks', methods: ['GET'])]
    public function locks(Request $request): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $assetUuid = trim((string) $request->query->get('asset_uuid', ''));
        $lockType = trim((string) $request->query->get('lock_type', ''));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        return new JsonResponse(
            $this->locks->activeLocksSnapshot(
                $assetUuid !== '' ? $assetUuid : null,
                $lockType !== '' ? $lockType : null,
                $limit,
                $offset,
            ),
            Response::HTTP_OK
        );
    }

    #[Route('/locks/recover', name: 'api_ops_locks_recover', methods: ['POST'])]
    public function recoverLocks(Request $request): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $payload = $this->payload($request);
        $staleLockMinutes = 30;
        if (array_key_exists('stale_lock_minutes', $payload)) {
            $raw = $payload['stale_lock_minutes'];
            if (!is_int($raw) || $raw < 1) {
                return $this->errorResponse(
                    'VALIDATION_FAILED',
                    'stale_lock_minutes must be an integer >= 1',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $staleLockMinutes = $raw;
        }

        $dryRun = false;
        if (array_key_exists('dry_run', $payload)) {
            $raw = $payload['dry_run'];
            if (!is_bool($raw)) {
                return $this->errorResponse(
                    'VALIDATION_FAILED',
                    'dry_run must be a boolean',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $dryRun = $raw;
        }
        $before = new \DateTimeImmutable(sprintf('-%d minutes', $staleLockMinutes));

        $staleExamined = 0;
        $recovered = 0;
        foreach ([OperationLockType::MOVE, OperationLockType::PURGE] as $type) {
            $stale = $this->locks->countStaleActiveLocksByType($type, $before);
            $staleExamined += $stale;
            if (!$dryRun && $stale > 0) {
                $recovered += $this->locks->releaseStaleActiveLocksByType($type, $before);
            }
        }

        return new JsonResponse([
            'stale_examined' => $staleExamined,
            'recovered' => $recovered,
            'dry_run' => $dryRun,
        ], Response::HTTP_OK);
    }

    #[Route('/jobs/queue', name: 'api_ops_jobs_queue', methods: ['GET'])]
    public function jobsQueue(): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->jobs->queueDiagnosticsSnapshot(), Response::HTTP_OK);
    }

    #[Route('/agents', name: 'api_ops_agents', methods: ['GET'])]
    public function agents(Request $request): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $statusFilter = trim((string) $request->query->get('status', ''));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $items = $this->projectAgents();
        if ($statusFilter !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['status'] ?? null) === $statusFilter
            ));
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_seen_at'] ?? ''), (string) ($left['last_seen_at'] ?? ''));
        });
        $total = count($items);

        return new JsonResponse([
            'items' => array_slice($items, $offset, $limit),
            'total' => $total,
        ], Response::HTTP_OK);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectAgents(): array
    {
        $entries = $this->agentRuntimeRepository->findAll();
        $jobSnapshots = $this->agentJobProjectionRepository->snapshotsForAgents(array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['agent_id'] ?? '')),
            $entries
        ), static fn (string $agentId): bool => $agentId !== '')));
        $clientUsage = [];
        foreach ($entries as $entry) {
            $clientId = (string) ($entry['client_id'] ?? '');
            if ($clientId === '') {
                continue;
            }
            $clientUsage[$clientId] = ($clientUsage[$clientId] ?? 0) + 1;
        }

        $items = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($entries as $entry) {
            $agentId = trim((string) ($entry['agent_id'] ?? ''));
            if ($agentId === '') {
                continue;
            }

            $lastSeenAt = $this->atomOrNow($entry['last_seen_at'] ?? null, $now);
            $jobSnapshot = $jobSnapshots[$agentId] ?? [
                'current_job' => null,
                'last_successful_job' => null,
                'last_failed_job' => null,
            ];
            $hasActiveJob = is_array($jobSnapshot['current_job'] ?? null);
            $isStale = ($now->getTimestamp() - $lastSeenAt->getTimestamp()) > self::AGENT_STALE_AFTER_SECONDS;
            $status = $isStale
                ? 'stale'
                : ($hasActiveJob ? 'online_busy' : 'online_idle');

            $clientId = trim((string) ($entry['client_id'] ?? 'unknown'));
            $items[] = [
                'agent_id' => $agentId,
                'client_id' => $clientId,
                'agent_name' => (string) ($entry['agent_name'] ?? ''),
                'agent_version' => (string) ($entry['agent_version'] ?? ''),
                'os_name' => $entry['os_name'] ?? null,
                'os_version' => $entry['os_version'] ?? null,
                'arch' => $entry['arch'] ?? null,
                'status' => $status,
                'identity_conflict' => ($clientUsage[$clientId] ?? 0) > 1,
                'last_seen_at' => $lastSeenAt->format(DATE_ATOM),
                'last_register_at' => $this->atomOrNow($entry['last_register_at'] ?? null, $now)->format(DATE_ATOM),
                'last_heartbeat_at' => $this->atomOrNull($entry['last_heartbeat_at'] ?? null)?->format(DATE_ATOM),
                'effective_capabilities' => array_values(is_array($entry['effective_capabilities'] ?? null) ? $entry['effective_capabilities'] : []),
                'capability_warnings' => array_values(is_array($entry['capability_warnings'] ?? null) ? $entry['capability_warnings'] : []),
                'debug' => [
                    'max_parallel_jobs' => max(1, (int) (($entry['debug']['max_parallel_jobs'] ?? 1))),
                    'feature_flags_contract_version' => $entry['debug']['feature_flags_contract_version'] ?? null,
                    'effective_feature_flags_contract_version' => $entry['debug']['effective_feature_flags_contract_version'] ?? null,
                    'server_time_skew_seconds' => $entry['debug']['server_time_skew_seconds'] ?? null,
                ],
                'current_job' => $jobSnapshot['current_job'] ?? null,
                'last_successful_job' => $jobSnapshot['last_successful_job'] ?? null,
                'last_failed_job' => $jobSnapshot['last_failed_job'] ?? null,
            ];
        }

        return $items;
    }

    private function atomOrNow(mixed $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function atomOrNull(mixed $value): ?\DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    #[Route('/ingest/unmatched', name: 'api_ops_ingest_unmatched', methods: ['GET'])]
    public function ingestUnmatched(Request $request): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $reason = trim((string) $request->query->get('reason', ''));
        if ($reason !== '' && !in_array($reason, self::ALLOWED_UNMATCHED_REASONS, true)) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'reason must be one of: missing_parent, ambiguous_parent, disabled_by_policy',
                Response::HTTP_BAD_REQUEST
            );
        }
        $sinceRaw = trim((string) $request->query->get('since', ''));
        $since = null;
        if ($sinceRaw !== '') {
            try {
                $since = new \DateTimeImmutable($sinceRaw);
            } catch (\Throwable) {
                return $this->errorResponse(
                    'VALIDATION_FAILED',
                    'since must be a valid ISO-8601 date-time',
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        return new JsonResponse(
            $this->ingestDiagnostics->unmatchedSnapshot($reason !== '' ? $reason : null, $since, $limit),
            Response::HTTP_OK
        );
    }

    #[Route('/ingest/requeue', name: 'api_ops_ingest_requeue', methods: ['POST'])]
    public function ingestRequeue(Request $request): JsonResponse
    {
        $forbidden = $this->requireAdminActor();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $payload = $this->payload($request);
        $assetUuid = trim((string) ($payload['asset_uuid'] ?? ''));
        $path = trim((string) ($payload['path'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($assetUuid === '' && $path === '') {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'asset_uuid or path is required',
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($reason === '') {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'reason is required',
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($assetUuid !== '' && !$this->isValidUuid($assetUuid)) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'asset_uuid must be a valid UUID',
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($path !== '' && !$this->isSafeRelativePath($path)) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'path must be a safe relative path',
                Response::HTTP_BAD_REQUEST
            );
        }

        $includeDerived = true;
        if (array_key_exists('include_derived', $payload)) {
            if (!is_bool($payload['include_derived'])) {
                return $this->errorResponse(
                    'VALIDATION_FAILED',
                    'include_derived must be a boolean',
                    Response::HTTP_BAD_REQUEST
                );
            }
            $includeDerived = (bool) $payload['include_derived'];
        }
        if (array_key_exists('include_sidecars', $payload) && !is_bool($payload['include_sidecars'])) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                'include_sidecars must be a boolean',
                Response::HTTP_BAD_REQUEST
            );
        }

        $normalizedPath = ltrim($path, '/');
        $targetAsset = $assetUuid !== ''
            ? $this->assets->findByUuid($assetUuid)
            : $this->assets->findByUuid($this->assetUuidFromPath($normalizedPath));

        $requeuedAssets = 0;
        $requeuedJobs = 0;
        $deduplicatedJobs = 0;
        if ($targetAsset instanceof Asset) {
            [$requeuedJobs, $deduplicatedJobs] = $this->requeueJobsForAsset($targetAsset, $includeDerived);
            $requeuedAssets = 1;
        }

        return new JsonResponse([
            'accepted' => true,
            'target' => array_filter([
                'asset_uuid' => $assetUuid !== '' ? $assetUuid : ($targetAsset?->getUuid()),
                'path' => $normalizedPath !== '' ? $normalizedPath : null,
            ], static fn (mixed $v): bool => is_string($v) && $v !== ''),
            'requeued_assets' => $requeuedAssets,
            'requeued_jobs' => $requeuedJobs,
            'deduplicated_jobs' => $deduplicatedJobs,
        ], Response::HTTP_ACCEPTED);
    }

    private function requireAdminActor(): ?JsonResponse
    {
        if ($this->resolveAdminActorHandler->handle()->status() === ResolveAdminActorResult::STATUS_AUTHORIZED) {
            return null;
        }

        return $this->errorResponse('FORBIDDEN_ACTOR', $this->translator->trans('auth.error.forbidden_actor'), Response::HTTP_FORBIDDEN);
    }

    private function isSafeRelativePath(string $path): bool
    {
        $normalized = ltrim(trim($path), '/');
        if ($normalized === '' || str_contains($normalized, "\0")) {
            return false;
        }

        return !str_contains($normalized, '../') && !str_contains($normalized, '..\\');
    }

    private function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $uuid);
    }

    /**
     * @return array{int,int}
     */
    private function requeueJobsForAsset(Asset $asset, bool $includeDerived): array
    {
        $jobs = ['extract_facts'];
        $fields = $asset->getFields();
        $proxyDone = (bool) ($fields['proxy_done'] ?? false);

        if ($includeDerived) {
            $jobs[] = 'generate_thumbnails';
            if (!$proxyDone) {
                $jobs[] = 'generate_preview';
            }
            if ($asset->getMediaType() === 'AUDIO') {
                $jobs[] = 'generate_audio_waveform';
            }
        }

        $requeued = 0;
        $deduplicated = 0;
        foreach ($jobs as $jobType) {
            if ($this->jobs->enqueuePendingIfMissing($asset->getUuid(), $jobType)) {
                ++$requeued;
            } else {
                ++$deduplicated;
            }
        }

        return [$requeued, $deduplicated];
    }

    private function assetUuidFromPath(string $path): string
    {
        $hex = md5($path);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

}
