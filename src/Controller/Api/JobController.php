<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Job\ClaimJobHandler;
use App\Application\Job\ClaimJobResult;
use App\Application\Job\FailJobHandler;
use App\Application\Job\FailJobResult;
use App\Application\Job\HeartbeatJobHandler;
use App\Application\Job\HeartbeatJobResult;
use App\Application\Job\ListClaimableJobsHandler;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\SubmitJobResult;
use App\Api\Service\IdempotencyService;
use App\Job\Job;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/jobs')]
final class JobController
{
    public function __construct(
        private IdempotencyService $idempotency,
        private ListClaimableJobsHandler $listClaimableJobsHandler,
        private ClaimJobHandler $claimJobHandler,
        private HeartbeatJobHandler $heartbeatJobHandler,
        private SubmitJobHandler $submitJobHandler,
        private FailJobHandler $failJobHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, (int) $request->query->get('limit', 20));

        $jobs = $this->listClaimableJobsHandler->handle($limit);
        $this->logger->info('jobs.list_claimable', [
            'agent_id' => $this->actorId(),
            'limit' => $limit,
            'count' => count($jobs),
        ]);

        return new JsonResponse([
            'items' => $jobs,
        ], Response::HTTP_OK);
    }

    #[Route('/{jobId}/claim', name: 'api_jobs_claim', methods: ['POST'])]
    public function claim(string $jobId): JsonResponse
    {
        $result = $this->claimJobHandler->handle($jobId, $this->actorId());
        if ($result->status() === ClaimJobResult::STATUS_STATE_CONFLICT) {
            $this->logger->warning('jobs.claim.conflict', [
                'job_id' => $jobId,
                'agent_id' => $this->actorId(),
            ]);

            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Job is not claimable',
            ], Response::HTTP_CONFLICT);
        }

        $job = $result->job();
        if (!$job instanceof Job) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Job is not claimable',
            ], Response::HTTP_CONFLICT);
        }
        $this->logger->info('jobs.claim.succeeded', $this->jobContext($job));

        return new JsonResponse($job->toArray(), Response::HTTP_OK);
    }

    #[Route('/{jobId}/heartbeat', name: 'api_jobs_heartbeat', methods: ['POST'])]
    public function heartbeat(string $jobId, Request $request): JsonResponse
    {
        $lockToken = trim((string) ($this->payload($request)['lock_token'] ?? ''));
        if ($lockToken === '') {
            return $this->lockRequiredResponse();
        }

        $result = $this->heartbeatJobHandler->handle($jobId, $lockToken);
        if ($result->status() !== HeartbeatJobResult::STATUS_HEARTBEATED) {
            $conflictCode = $result->status() === HeartbeatJobResult::STATUS_STALE_LOCK_TOKEN
                ? 'STALE_LOCK_TOKEN'
                : 'LOCK_INVALID';
            $this->logger->warning('jobs.heartbeat.conflict', [
                'job_id' => $jobId,
                'agent_id' => $this->actorId(),
                'code' => $conflictCode,
            ]);

            return $this->lockConflictResponse($conflictCode);
        }

        $job = $result->job();
        if (!$job instanceof Job) {
            return $this->lockConflictResponse('LOCK_INVALID');
        }
        $this->logger->info('jobs.heartbeat.succeeded', $this->jobContext($job));

        return new JsonResponse([
            'locked_until' => $job->lockedUntil?->format(DATE_ATOM),
        ], Response::HTTP_OK);
    }

    #[Route('/{jobId}/submit', name: 'api_jobs_submit', methods: ['POST'])]
    public function submit(string $jobId, Request $request): JsonResponse
    {
        return $this->idempotency->execute($request, $this->actorId(), function () use ($jobId, $request): JsonResponse {
            $payload = $this->payload($request);
            $lockToken = trim((string) ($payload['lock_token'] ?? ''));
            if ($lockToken === '') {
                return $this->lockRequiredResponse();
            }

            $result = $payload['result'] ?? [];
            if (!is_array($result)) {
                $result = [];
            }
            $submission = $this->submitJobHandler->handle($jobId, $lockToken, $result, $this->actorRoles());
            if ($submission->status() === SubmitJobResult::STATUS_FORBIDDEN_SCOPE) {
                return $this->forbiddenScope();
            }
            if ($submission->status() !== SubmitJobResult::STATUS_SUBMITTED) {
                $conflictCode = $submission->status() === SubmitJobResult::STATUS_STALE_LOCK_TOKEN
                    ? 'STALE_LOCK_TOKEN'
                    : 'LOCK_INVALID';
                $this->logger->warning('jobs.submit.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $this->actorId(),
                    'code' => $conflictCode,
                ]);

                return $this->lockConflictResponse($conflictCode);
            }

            $job = $submission->job();
            if (!$job instanceof Job) {
                return $this->lockConflictResponse('LOCK_INVALID');
            }
            $this->logger->info('jobs.submit.succeeded', $this->jobContext($job));

            return new JsonResponse($job->toArray(), Response::HTTP_OK);
        });
    }

    #[Route('/{jobId}/fail', name: 'api_jobs_fail', methods: ['POST'])]
    public function fail(string $jobId, Request $request): JsonResponse
    {
        return $this->idempotency->execute($request, $this->actorId(), function () use ($jobId, $request): JsonResponse {
            $payload = $this->payload($request);
            $lockToken = trim((string) ($payload['lock_token'] ?? ''));
            $errorCode = trim((string) ($payload['error_code'] ?? ''));
            $message = trim((string) ($payload['message'] ?? ''));
            $retryable = (bool) ($payload['retryable'] ?? false);

            if ($lockToken === '') {
                return $this->lockRequiredResponse();
            }

            if ($errorCode === '' || $message === '') {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'error_code and message are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $failure = $this->failJobHandler->handle($jobId, $lockToken, $retryable, $errorCode, $message);
            if ($failure->status() !== FailJobResult::STATUS_FAILED) {
                $conflictCode = $failure->status() === FailJobResult::STATUS_STALE_LOCK_TOKEN
                    ? 'STALE_LOCK_TOKEN'
                    : 'LOCK_INVALID';
                $this->logger->warning('jobs.fail.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $this->actorId(),
                    'error_code' => $errorCode,
                    'retryable' => $retryable,
                    'code' => $conflictCode,
                ]);

                return $this->lockConflictResponse($conflictCode);
            }

            $job = $failure->job();
            if (!$job instanceof Job) {
                return $this->lockConflictResponse('LOCK_INVALID');
            }
            $context = $this->jobContext($job);
            $context['error_code'] = $errorCode;
            $context['retryable'] = $retryable;
            $this->logger->info('jobs.fail.succeeded', $context);

            return new JsonResponse($job->toArray(), Response::HTTP_OK);
        });
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

    private function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    /**
     * @return array<int, string>
     */
    private function actorRoles(): array
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return [];
        }

        return $authenticatedUser->roles();
    }

    private function forbiddenScope(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_SCOPE',
            'message' => $this->translator->trans('auth.error.forbidden_scope'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function lockRequiredResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'LOCK_REQUIRED',
            'message' => 'lock_token is required',
        ], Response::HTTP_LOCKED);
    }

    private function lockConflictResponse(string $conflictCode): JsonResponse
    {
        $status = $conflictCode === 'STALE_LOCK_TOKEN'
            ? Response::HTTP_CONFLICT
            : Response::HTTP_LOCKED;

        return new JsonResponse([
            'code' => $conflictCode,
            'message' => 'Invalid lock token or expired lock',
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobContext(Job $job): array
    {
        return [
            'job_id' => $job->id,
            'asset_uuid' => $job->assetUuid,
            'agent_id' => $this->actorId(),
            'job_type' => $job->jobType,
            'status' => $job->status->value,
        ];
    }
}
