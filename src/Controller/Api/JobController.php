<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Entity\User;
use App\Job\Job;
use App\Job\JobStatus;
use App\Job\Repository\JobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/jobs')]
final class JobController
{
    public function __construct(
        private JobRepository $jobs,
        private IdempotencyService $idempotency,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, (int) $request->query->get('limit', 20));

        $jobs = $this->jobs->listClaimable($limit);
        $this->logger->info('jobs.list_claimable', [
            'agent_id' => $this->actorId(),
            'limit' => $limit,
            'count' => count($jobs),
        ]);

        return new JsonResponse([
            'items' => array_map(static fn ($job): array => $job->toArray(), $jobs),
        ], Response::HTTP_OK);
    }

    #[Route('/{jobId}/claim', name: 'api_jobs_claim', methods: ['POST'])]
    public function claim(string $jobId): JsonResponse
    {
        $job = $this->jobs->claim($jobId, $this->actorId(), 300);
        if ($job === null) {
            $this->logger->warning('jobs.claim.conflict', [
                'job_id' => $jobId,
                'agent_id' => $this->actorId(),
            ]);

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
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'lock_token is required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = $this->jobs->heartbeat($jobId, $lockToken, 300);
        if ($job === null) {
            $conflictCode = $this->lockConflictCode($jobId, $lockToken);
            $this->logger->warning('jobs.heartbeat.conflict', [
                'job_id' => $jobId,
                'agent_id' => $this->actorId(),
                'code' => $conflictCode,
            ]);

            return new JsonResponse([
                'code' => $conflictCode,
                'message' => 'Invalid lock token or expired lock',
            ], Response::HTTP_CONFLICT);
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
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'lock_token is required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = $payload['result'] ?? [];
            if (!is_array($result)) {
                $result = [];
            }

            $job = $this->jobs->submit($jobId, $lockToken, $result);
            if ($job === null) {
                $conflictCode = $this->lockConflictCode($jobId, $lockToken);
                $this->logger->warning('jobs.submit.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $this->actorId(),
                    'code' => $conflictCode,
                ]);

                return new JsonResponse([
                    'code' => $conflictCode,
                    'message' => 'Invalid lock token or expired lock',
                ], Response::HTTP_CONFLICT);
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

            if ($lockToken === '' || $errorCode === '' || $message === '') {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'lock_token, error_code and message are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $job = $this->jobs->fail($jobId, $lockToken, $retryable, $errorCode, $message);
            if ($job === null) {
                $conflictCode = $this->lockConflictCode($jobId, $lockToken);
                $this->logger->warning('jobs.fail.conflict', [
                    'job_id' => $jobId,
                    'agent_id' => $this->actorId(),
                    'error_code' => $errorCode,
                    'retryable' => $retryable,
                    'code' => $conflictCode,
                ]);

                return new JsonResponse([
                    'code' => $conflictCode,
                    'message' => 'Invalid lock token or expired lock',
                ], Response::HTTP_CONFLICT);
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
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : 'anonymous';
    }

    private function lockConflictCode(string $jobId, string $lockToken): string
    {
        $current = $this->jobs->find($jobId);
        if ($current instanceof Job
            && $current->status === JobStatus::CLAIMED
            && is_string($current->lockToken)
            && $current->lockToken !== ''
            && !hash_equals($current->lockToken, $lockToken)
        ) {
            return 'STALE_LOCK_TOKEN';
        }

        return 'STATE_CONFLICT';
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
