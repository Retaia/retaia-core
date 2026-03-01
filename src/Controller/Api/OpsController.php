<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
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
        if (!$databaseOk) {
            $status = 'down';
        } elseif (!$watchPathOk || !$storageWritableOk) {
            $status = 'degraded';
        }

        return new JsonResponse([
            'status' => $status,
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

    private function requireAdminActor(): ?JsonResponse
    {
        if ($this->resolveAdminActorHandler->handle()->status() === ResolveAdminActorResult::STATUS_AUTHORIZED) {
            return null;
        }

        return $this->errorResponse('FORBIDDEN_ACTOR', $this->translator->trans('auth.error.forbidden_actor'), Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
