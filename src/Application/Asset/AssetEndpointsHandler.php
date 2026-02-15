<?php

namespace App\Application\Asset;

use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;

final class AssetEndpointsHandler
{
    public function __construct(
        private ListAssetsHandler $listAssetsHandler,
        private GetAssetHandler $getAssetHandler,
        private PatchAssetHandler $patchAssetHandler,
        private DecideAssetHandler $decideAssetHandler,
        private ReopenAssetHandler $reopenAssetHandler,
        private ReprocessAssetHandler $reprocessAssetHandler,
        private ResolveAgentActorHandler $resolveAgentActorHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
    ) {
    }

    /**
     * @param array<int, string> $suggestedTags
     */
    public function list(?string $state, ?string $mediaType, ?string $query, int $limit, array $suggestedTags, string $suggestedTagsMode): AssetEndpointResult
    {
        $result = $this->listAssetsHandler->handle($state, $mediaType, $query, $limit, $suggestedTags, $suggestedTagsMode);
        if ($result->status() === ListAssetsResult::STATUS_VALIDATION_FAILED) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_VALIDATION_FAILED);
        }
        if ($result->status() === ListAssetsResult::STATUS_FORBIDDEN_SCOPE) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, [
            'items' => $result->items(),
            'next_cursor' => null,
        ]);
    }

    public function getOne(string $uuid): AssetEndpointResult
    {
        $result = $this->getAssetHandler->handle($uuid);
        if ($result->status() === GetAssetResult::STATUS_NOT_FOUND) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_NOT_FOUND);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, $result->asset() ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patch(string $uuid, array $payload): AssetEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->patchAssetHandler->handle($uuid, $payload);
        if ($result->status() === PatchAssetResult::STATUS_NOT_FOUND) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === PatchAssetResult::STATUS_PURGED_READ_ONLY) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_PURGED_READ_ONLY);
        }
        if ($result->status() === PatchAssetResult::STATUS_STATE_CONFLICT) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, $result->payload() ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function decision(string $uuid, array $payload): AssetEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $action = trim((string) ($payload['action'] ?? ''));
        $result = $this->decideAssetHandler->handle($uuid, $action);
        if ($result->status() === DecideAssetResult::STATUS_NOT_FOUND) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === DecideAssetResult::STATUS_STATE_CONFLICT) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_STATE_CONFLICT);
        }
        if ($result->status() === DecideAssetResult::STATUS_VALIDATION_FAILED_ACTION_REQUIRED) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_VALIDATION_FAILED);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, $result->payload() ?? []);
    }

    public function reopen(string $uuid): AssetEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->reopenAssetHandler->handle($uuid);
        if ($result->status() === ReopenAssetResult::STATUS_NOT_FOUND) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === ReopenAssetResult::STATUS_STATE_CONFLICT) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, $result->payload() ?? []);
    }

    public function reprocess(string $uuid): AssetEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->reprocessAssetHandler->handle($uuid);
        if ($result->status() === ReprocessAssetResult::STATUS_NOT_FOUND) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === ReprocessAssetResult::STATUS_STATE_CONFLICT) {
            return new AssetEndpointResult(AssetEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, $result->payload() ?? []);
    }

    public function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    public function isForbiddenAgentActor(): bool
    {
        return $this->resolveAgentActorHandler->handle()->status() === ResolveAgentActorResult::STATUS_AUTHORIZED;
    }
}
