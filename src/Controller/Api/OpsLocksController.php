<?php

namespace App\Controller\Api;

use App\Controller\RequestPayloadTrait;
use App\Lock\OperationLockType;
use App\Lock\Repository\OperationLockRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/ops')]
final class OpsLocksController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;

    public function __construct(
        private OpsAdminAccessGuard $adminAccessGuard,
        private OperationLockRepository $locks,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/locks', name: 'api_ops_locks', methods: ['GET'])]
    public function locks(Request $request): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
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
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $payload = $this->payload($request);
        $staleLockMinutes = 30;
        if (array_key_exists('stale_lock_minutes', $payload)) {
            $raw = $payload['stale_lock_minutes'];
            if (!is_int($raw) || $raw < 1) {
                return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.stale_lock_minutes_invalid'), Response::HTTP_BAD_REQUEST);
            }

            $staleLockMinutes = $raw;
        }

        $dryRun = false;
        if (array_key_exists('dry_run', $payload)) {
            $raw = $payload['dry_run'];
            if (!is_bool($raw)) {
                return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('ops.error.dry_run_boolean'), Response::HTTP_BAD_REQUEST);
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
}
