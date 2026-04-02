<?php

namespace App\Controller\Api;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\RequestPayloadTrait;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Job\Repository\JobRepository;
use App\Storage\BusinessStorageRegistryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/ops')]
final class OpsIngestController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;

    private const ALLOWED_UNMATCHED_REASONS = [
        'missing_parent',
        'ambiguous_parent',
        'disabled_by_policy',
    ];

    public function __construct(
        private OpsAdminAccessGuard $adminAccessGuard,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private AssetRepositoryInterface $assets,
        private JobRepository $jobs,
        private BusinessStorageRegistryInterface $storageRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/ingest/diagnostics', name: 'api_ops_ingest_diagnostics', methods: ['GET'])]
    public function diagnostics(): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->ingestDiagnostics->diagnosticsSnapshot(), Response::HTTP_OK);
    }

    #[Route('/ingest/unmatched', name: 'api_ops_ingest_unmatched', methods: ['GET'])]
    public function unmatched(Request $request): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $reason = trim((string) $request->query->get('reason', ''));
        if ($reason !== '' && !in_array($reason, self::ALLOWED_UNMATCHED_REASONS, true)) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                $this->translator->trans('ops.error.unmatched_reason_invalid'),
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
                    $this->translator->trans('ops.error.unmatched_since_invalid'),
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
    public function requeue(Request $request): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $payload = $this->payload($request);
        $assetUuid = trim((string) ($payload['asset_uuid'] ?? ''));
        $path = trim((string) ($payload['path'] ?? ''));
        $storageId = trim((string) ($payload['storage_id'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($assetUuid === '' && $path === '') {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_target_required'), Response::HTTP_BAD_REQUEST);
        }
        if ($reason === '') {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_reason_required'), Response::HTTP_BAD_REQUEST);
        }
        if ($assetUuid !== '' && !$this->isValidUuid($assetUuid)) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_asset_uuid_invalid'), Response::HTTP_BAD_REQUEST);
        }
        if ($path !== '' && !$this->isSafeRelativePath($path)) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_path_invalid'), Response::HTTP_BAD_REQUEST);
        }
        if ($path !== '' && $assetUuid === '' && $storageId === '') {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                $this->translator->trans('ops.error.requeue_storage_required'),
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($storageId !== '' && !$this->storageRegistry->has($storageId)) {
            return $this->errorResponse(
                'VALIDATION_FAILED',
                $this->translator->trans('ops.error.requeue_storage_unknown'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $includeDerived = true;
        if (array_key_exists('include_derived', $payload)) {
            if (!is_bool($payload['include_derived'])) {
                return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_include_derived_boolean'), Response::HTTP_BAD_REQUEST);
            }
            $includeDerived = (bool) $payload['include_derived'];
        }
        if (array_key_exists('include_sidecars', $payload) && !is_bool($payload['include_sidecars'])) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.requeue_include_sidecars_boolean'), Response::HTTP_BAD_REQUEST);
        }

        $normalizedPath = ltrim($path, '/');
        $targetAsset = $assetUuid !== ''
            ? $this->assets->findByUuid($assetUuid)
            : $this->assets->findByUuid($this->assetUuidFromStoragePath($storageId, $normalizedPath));

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
                'asset_uuid' => $assetUuid !== '' ? $assetUuid : $targetAsset?->getUuid(),
                'path' => $normalizedPath !== '' ? $normalizedPath : null,
                'storage_id' => $normalizedPath !== '' ? $storageId : null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'requeued_assets' => $requeuedAssets,
            'requeued_jobs' => $requeuedJobs,
            'deduplicated_jobs' => $deduplicatedJobs,
        ], Response::HTTP_ACCEPTED);
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

    private function assetUuidFromStoragePath(string $storageId, string $path): string
    {
        $hex = md5($storageId.'|'.$path);

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
