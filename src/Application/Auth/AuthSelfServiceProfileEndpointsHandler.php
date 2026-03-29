<?php

namespace App\Application\Auth;

final class AuthSelfServiceProfileEndpointsHandler
{
    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private GetAuthMeProfileHandler $getAuthMeProfileHandler,
        private GetMyFeaturesHandler $getMyFeaturesHandler,
        private PatchMyFeaturesHandler $patchMyFeaturesHandler,
    ) {
    }

    public function me(): AuthMeEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new AuthMeEndpointResult(AuthMeEndpointResult::STATUS_UNAUTHORIZED);
        }

        $result = $this->getAuthMeProfileHandler->handle(
            (string) $authenticatedUser->id(),
            (string) $authenticatedUser->email(),
            $authenticatedUser->roles()
        );

        return new AuthMeEndpointResult(
            AuthMeEndpointResult::STATUS_SUCCESS,
            $result->id(),
            $result->email(),
            $result->roles(),
            $result->displayName(),
            $result->emailVerified(),
            $result->mfaEnabled()
        );
    }

    public function getMyFeatures(): GetMyFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new GetMyFeaturesEndpointResult(GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        return new GetMyFeaturesEndpointResult(
            GetMyFeaturesEndpointResult::STATUS_SUCCESS,
            $this->getMyFeaturesHandler->handle((string) $authenticatedUser->id())
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patchMyFeatures(array $payload): PatchMyFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        $rawUserFeatures = $payload['user_feature_enabled'] ?? null;
        if (!is_array($rawUserFeatures)) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD);
        }

        $result = $this->patchMyFeaturesHandler->handle((string) $authenticatedUser->id(), $rawUserFeatures);
        if ($result->status() === PatchMyFeaturesResult::STATUS_FORBIDDEN_SCOPE) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }
        if ($result->status() === PatchMyFeaturesResult::STATUS_VALIDATION_FAILED) {
            return new PatchMyFeaturesEndpointResult(
                PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED,
                $result->validationDetails()
            );
        }

        return new PatchMyFeaturesEndpointResult(
            PatchMyFeaturesEndpointResult::STATUS_UPDATED,
            null,
            $result->features()
        );
    }
}
