<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Application\Asset\DecideAssetHandler;
use App\Application\Asset\DecideAssetResult;
use App\Application\Asset\PatchAssetHandler;
use App\Application\Asset\PatchAssetResult;
use App\Application\Asset\ReopenAssetHandler;
use App\Application\Asset\ReopenAssetResult;
use App\Application\Asset\ReprocessAssetHandler;
use App\Application\Asset\ReprocessAssetResult;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets')]
final class AssetController
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private PatchAssetHandler $patchAssetHandler,
        private DecideAssetHandler $decideAssetHandler,
        private ReopenAssetHandler $reopenAssetHandler,
        private ReprocessAssetHandler $reprocessAssetHandler,
        private TranslatorInterface $translator,
        private ResolveAgentActorHandler $resolveAgentActorHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private IdempotencyService $idempotency,
        private bool $featureSuggestedTagsFiltersEnabled,
    ) {
    }

    #[Route('', name: 'api_assets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $state = $request->query->get('state');
        $mediaType = $request->query->get('media_type');
        $query = $request->query->get('q');
        $suggestedTags = $this->csvList($request->query->get('suggested_tags'));
        $suggestedTagsMode = strtoupper((string) $request->query->get('suggested_tags_mode', 'AND'));
        $limit = max(1, (int) $request->query->get('limit', 50));

        if ($suggestedTags !== [] && !$this->featureSuggestedTagsFiltersEnabled) {
            return $this->forbiddenScopeResponse();
        }

        if (!in_array($suggestedTagsMode, ['AND', 'OR'], true)) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'suggested_tags_mode must be AND or OR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assets = $this->assets->listAssets(
            is_string($state) ? $state : null,
            is_string($mediaType) ? $mediaType : null,
            is_string($query) ? $query : null,
            $limit,
        );
        if ($suggestedTags !== []) {
            $assets = array_values(array_filter(
                $assets,
                fn (Asset $asset): bool => $this->matchesSuggestedTags($asset, $suggestedTags, $suggestedTagsMode)
            ));
        }

        return new JsonResponse([
            'items' => array_map(fn (Asset $asset): array => $this->summary($asset), $assets),
            'next_cursor' => null,
        ], Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_get', methods: ['GET'])]
    public function getOne(string $uuid): JsonResponse
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->detail($asset), Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_patch', methods: ['PATCH'])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        $result = $this->patchAssetHandler->handle($uuid, $this->payload($request));
        if ($result->status() === PatchAssetResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($result->status() === PatchAssetResult::STATUS_PURGED_READ_ONLY) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.purged_read_only'),
            ], Response::HTTP_GONE);
        }

        if ($result->status() === PatchAssetResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/{uuid}/decision', name: 'api_assets_decision', methods: ['POST'])]
    public function decision(string $uuid, Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid, $request): JsonResponse {
            $payload = $this->payload($request);
            $action = trim((string) ($payload['action'] ?? ''));
            $result = $this->decideAssetHandler->handle($uuid, $action);

            if ($result->status() === DecideAssetResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === DecideAssetResult::STATUS_STATE_CONFLICT) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            if ($result->status() === DecideAssetResult::STATUS_VALIDATION_FAILED_ACTION_REQUIRED) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('asset.error.decision_action_required'),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    #[Route('/{uuid}/reopen', name: 'api_assets_reopen', methods: ['POST'])]
    public function reopen(string $uuid): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        $result = $this->reopenAssetHandler->handle($uuid);
        if ($result->status() === ReopenAssetResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($result->status() === ReopenAssetResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/{uuid}/reprocess', name: 'api_assets_reprocess', methods: ['POST'])]
    public function reprocess(string $uuid, Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $result = $this->reprocessAssetHandler->handle($uuid);
            if ($result->status() === ReprocessAssetResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === ReprocessAssetResult::STATUS_STATE_CONFLICT) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
            'notes' => $asset->getNotes(),
            'fields' => $asset->getFields(),
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
        ];
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

    private function forbiddenActorResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function forbiddenScopeResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_SCOPE',
            'message' => $this->translator->trans('auth.error.forbidden_scope'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    private function isForbiddenAgentActor(): bool
    {
        return $this->resolveAgentActorHandler->handle()->status() === ResolveAgentActorResult::STATUS_AUTHORIZED;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function csvList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => mb_strtolower(trim($item)), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param array<int, string> $expected
     */
    private function matchesSuggestedTags(Asset $asset, array $expected, string $mode): bool
    {
        $fields = $asset->getFields();
        $tags = [];
        if (is_array($fields['suggestions']['suggested_tags'] ?? null)) {
            $tags = $fields['suggestions']['suggested_tags'];
        } elseif (is_array($fields['suggested_tags'] ?? null)) {
            $tags = $fields['suggested_tags'];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), $tags),
            static fn (string $tag): bool => $tag !== ''
        ));

        if ($mode === 'OR') {
            foreach ($expected as $tag) {
                if (in_array($tag, $normalized, true)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($expected as $tag) {
            if (!in_array($tag, $normalized, true)) {
                return false;
            }
        }

        return true;
    }
}
