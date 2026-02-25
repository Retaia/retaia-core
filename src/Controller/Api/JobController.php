<?php

namespace App\Controller\Api;

use App\Application\Job\JobEndpointResult;
use App\Application\Job\JobEndpointsHandler;
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
        private JobEndpointsHandler $jobEndpointsHandler,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, (int) $request->query->get('limit', 20));

        $result = $this->jobEndpointsHandler->list($limit);
        $items = (array) ($result->payload()['items'] ?? []);
        $this->logger->info('jobs.list_claimable', [
            'agent_id' => $result->actorId() ?? 'anonymous',
            'limit' => $limit,
            'count' => count($items),
        ]);

        return new JsonResponse($result->payload() ?? ['items' => []], Response::HTTP_OK);
    }

    #[Route('/{jobId}/claim', name: 'api_jobs_claim', methods: ['POST'])]
    public function claim(string $jobId): JsonResponse
    {
        $result = $this->jobEndpointsHandler->claim($jobId);
        if ($result->status() === JobEndpointResult::STATUS_STATE_CONFLICT) {
            $this->logger->warning('jobs.claim.conflict', [
                'job_id' => $jobId,
                'agent_id' => $result->actorId() ?? 'anonymous',
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
        $result = $this->jobEndpointsHandler->heartbeat($jobId, $this->payload($request));
        if ($result->status() === JobEndpointResult::STATUS_LOCK_REQUIRED) {
            return $this->lockRequiredResponse();
        }
        if ($result->status() === JobEndpointResult::STATUS_LOCK_CONFLICT) {
            $conflictCode = $result->conflictCode() ?? 'LOCK_INVALID';
            $this->logger->warning('jobs.heartbeat.conflict', [
                'job_id' => $jobId,
                'agent_id' => $result->actorId() ?? 'anonymous',
                'code' => $conflictCode,
            ]);

            return $this->lockConflictResponse($conflictCode);
        }

        $job = $result->job();
        if (!$job instanceof Job) {
            return $this->lockConflictResponse('LOCK_INVALID');
        }
        $this->logger->info('jobs.heartbeat.succeeded', $this->jobContext($job));

        return new JsonResponse($result->payload() ?? ['locked_until' => $job->lockedUntil?->format(DATE_ATOM)], Response::HTTP_OK);
    }

    #[Route('/{jobId}/submit', name: 'api_jobs_submit', methods: ['POST'])]
    public function submit(string $jobId, Request $request): JsonResponse
    {
        return $this->idempotency->execute($request, $this->actorId(), function () use ($jobId, $request): JsonResponse {
            $submission = $this->jobEndpointsHandler->submit($jobId, $this->payload($request));
            if ($submission->status() === JobEndpointResult::STATUS_LOCK_REQUIRED) {
                return $this->lockRequiredResponse();
            }
            if ($submission->status() === JobEndpointResult::STATUS_FORBIDDEN_SCOPE) {
                return $this->forbiddenScope();
            }
            if ($submission->status() === JobEndpointResult::STATUS_VALIDATION_FAILED) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'job_type is required and must match the claimed job type',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($submission->status() === JobEndpointResult::STATUS_LOCK_CONFLICT) {
                $conflictCode = $submission->conflictCode() ?? 'LOCK_INVALID';
                $this->logger->warning('jobs.submit.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $submission->actorId() ?? 'anonymous',
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
            $failure = $this->jobEndpointsHandler->fail($jobId, $this->payload($request));
            if ($failure->status() === JobEndpointResult::STATUS_LOCK_REQUIRED) {
                return $this->lockRequiredResponse();
            }
            if ($failure->status() === JobEndpointResult::STATUS_VALIDATION_FAILED) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'error_code and message are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($failure->status() === JobEndpointResult::STATUS_LOCK_CONFLICT) {
                $conflictCode = $failure->conflictCode() ?? 'LOCK_INVALID';
                $this->logger->warning('jobs.fail.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $failure->actorId() ?? 'anonymous',
                    'error_code' => $failure->errorCode() ?? '',
                    'retryable' => (bool) ($failure->retryable() ?? false),
                    'code' => $conflictCode,
                ]);

                return $this->lockConflictResponse($conflictCode);
            }

            $job = $failure->job();
            if (!$job instanceof Job) {
                return $this->lockConflictResponse('LOCK_INVALID');
            }
            $context = $this->jobContext($job);
            $context['error_code'] = $failure->errorCode() ?? '';
            $context['retryable'] = (bool) ($failure->retryable() ?? false);
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
        return $this->jobEndpointsHandler->actorId();
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
