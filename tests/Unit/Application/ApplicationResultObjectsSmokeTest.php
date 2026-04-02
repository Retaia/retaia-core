<?php

namespace App\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationResultObjectsSmokeTest extends TestCase
{
    #[DataProvider('resultFactories')]
    public function testResultClassCanBeInstantiatedAndQueried(string $className, callable $factory): void
    {
        $instance = $factory();

        foreach (get_class_methods($instance) as $method) {
            if (str_starts_with($method, '__')) {
                continue;
            }

            $instance->{$method}();
        }

        self::assertInstanceOf($className, $instance);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: callable(): object}>
     */
    public static function resultFactories(): iterable
    {
        yield 'App\Application\Agent\RegisterAgentEndpointResult' => ['App\Application\Agent\RegisterAgentEndpointResult', static fn (): object => new \App\Application\Agent\RegisterAgentEndpointResult('STATUS', null, null)];
        yield 'App\Application\Agent\RegisterAgentResult' => ['App\Application\Agent\RegisterAgentResult', static fn (): object => new \App\Application\Agent\RegisterAgentResult('STATUS', [], null)];
        yield 'App\Application\AppPolicy\AppPolicyEndpointResult' => ['App\Application\AppPolicy\AppPolicyEndpointResult', static fn (): object => new \App\Application\AppPolicy\AppPolicyEndpointResult('STATUS', [], [], null, null, null)];
        yield 'App\Application\AppPolicy\GetAppFeaturesEndpointResult' => ['App\Application\AppPolicy\GetAppFeaturesEndpointResult', static fn (): object => new \App\Application\AppPolicy\GetAppFeaturesEndpointResult('STATUS', null)];
        yield 'App\Application\AppPolicy\GetAppFeaturesResult' => ['App\Application\AppPolicy\GetAppFeaturesResult', static fn (): object => new \App\Application\AppPolicy\GetAppFeaturesResult([], [], [], [])];
        yield 'App\Application\AppPolicy\GetAppPolicyResult' => ['App\Application\AppPolicy\GetAppPolicyResult', static fn (): object => new \App\Application\AppPolicy\GetAppPolicyResult(true, [], 'LATESTVERSION', 'EFFECTIVEVERSION', 'COMPATIBILITYMODE', [])];
        yield 'App\Application\AppPolicy\PatchAppFeaturesEndpointResult' => ['App\Application\AppPolicy\PatchAppFeaturesEndpointResult', static fn (): object => new \App\Application\AppPolicy\PatchAppFeaturesEndpointResult('STATUS', null, null)];
        yield 'App\Application\AppPolicy\PatchAppFeaturesResult' => ['App\Application\AppPolicy\PatchAppFeaturesResult', static fn (): object => new \App\Application\AppPolicy\PatchAppFeaturesResult('STATUS', null, null)];
        yield 'App\Application\Asset\AssetEndpointResult' => ['App\Application\Asset\AssetEndpointResult', static fn (): object => new \App\Application\Asset\AssetEndpointResult('STATUS', null)];
        yield 'App\Application\Asset\DecideAssetResult' => ['App\Application\Asset\DecideAssetResult', static fn (): object => new \App\Application\Asset\DecideAssetResult('STATUS', null)];
        yield 'App\Application\Asset\GetAssetResult' => ['App\Application\Asset\GetAssetResult', static fn (): object => new \App\Application\Asset\GetAssetResult('STATUS', null)];
        yield 'App\Application\Asset\ListAssetsResult' => ['App\Application\Asset\ListAssetsResult', static fn (): object => new \App\Application\Asset\ListAssetsResult('STATUS', null, null)];
        yield 'App\Application\Asset\PatchAssetResult' => ['App\Application\Asset\PatchAssetResult', static fn (): object => new \App\Application\Asset\PatchAssetResult('STATUS', null)];
        yield 'App\Application\Asset\ReopenAssetResult' => ['App\Application\Asset\ReopenAssetResult', static fn (): object => new \App\Application\Asset\ReopenAssetResult('STATUS', null)];
        yield 'App\Application\Asset\ReprocessAssetResult' => ['App\Application\Asset\ReprocessAssetResult', static fn (): object => new \App\Application\Asset\ReprocessAssetResult('STATUS', null)];
        yield 'App\Application\Auth\AdminConfirmEmailVerificationEndpointResult' => ['App\Application\Auth\AdminConfirmEmailVerificationEndpointResult', static fn (): object => new \App\Application\Auth\AdminConfirmEmailVerificationEndpointResult('STATUS')];
        yield 'App\Application\Auth\AdminConfirmEmailVerificationResult' => ['App\Application\Auth\AdminConfirmEmailVerificationResult', static fn (): object => new \App\Application\Auth\AdminConfirmEmailVerificationResult('STATUS')];
        yield 'App\Application\Auth\AuthMeEndpointResult' => ['App\Application\Auth\AuthMeEndpointResult', static fn (): object => new \App\Application\Auth\AuthMeEndpointResult('STATUS', null, null, [], null, false, false)];
        yield 'App\Application\Auth\ConfirmEmailVerificationEndpointResult' => ['App\Application\Auth\ConfirmEmailVerificationEndpointResult', static fn (): object => new \App\Application\Auth\ConfirmEmailVerificationEndpointResult('STATUS')];
        yield 'App\Application\Auth\ConfirmEmailVerificationResult' => ['App\Application\Auth\ConfirmEmailVerificationResult', static fn (): object => new \App\Application\Auth\ConfirmEmailVerificationResult('STATUS')];
        yield 'App\Application\Auth\DisableTwoFactorResult' => ['App\Application\Auth\DisableTwoFactorResult', static fn (): object => new \App\Application\Auth\DisableTwoFactorResult('STATUS')];
        yield 'App\Application\Auth\EnableTwoFactorResult' => ['App\Application\Auth\EnableTwoFactorResult', static fn (): object => new \App\Application\Auth\EnableTwoFactorResult('STATUS', [])];
        yield 'App\Application\Auth\GetAuthMeProfileResult' => ['App\Application\Auth\GetAuthMeProfileResult', static fn (): object => new \App\Application\Auth\GetAuthMeProfileResult('ID', 'EMAIL', [], null, false, false)];
        yield 'App\Application\Auth\GetMyFeaturesEndpointResult' => ['App\Application\Auth\GetMyFeaturesEndpointResult', static fn (): object => new \App\Application\Auth\GetMyFeaturesEndpointResult('STATUS', null)];
        yield 'App\Application\Auth\MyFeaturesResult' => ['App\Application\Auth\MyFeaturesResult', static fn (): object => new \App\Application\Auth\MyFeaturesResult([], [], [], [], [])];
        yield 'App\Application\Auth\PatchMyFeaturesEndpointResult' => ['App\Application\Auth\PatchMyFeaturesEndpointResult', static fn (): object => new \App\Application\Auth\PatchMyFeaturesEndpointResult('STATUS', null, null)];
        yield 'App\Application\Auth\PatchMyFeaturesResult' => ['App\Application\Auth\PatchMyFeaturesResult', static fn (): object => new \App\Application\Auth\PatchMyFeaturesResult('STATUS', null, null)];
        yield 'App\Application\Auth\RegenerateTwoFactorRecoveryCodesResult' => ['App\Application\Auth\RegenerateTwoFactorRecoveryCodesResult', static fn (): object => new \App\Application\Auth\RegenerateTwoFactorRecoveryCodesResult('STATUS', [])];
        yield 'App\Application\Auth\RequestEmailVerificationEndpointResult' => ['App\Application\Auth\RequestEmailVerificationEndpointResult', static fn (): object => new \App\Application\Auth\RequestEmailVerificationEndpointResult('STATUS', null, null)];
        yield 'App\Application\Auth\RequestEmailVerificationResult' => ['App\Application\Auth\RequestEmailVerificationResult', static fn (): object => new \App\Application\Auth\RequestEmailVerificationResult('STATUS', null)];
        yield 'App\Application\Auth\RequestPasswordResetEndpointResult' => ['App\Application\Auth\RequestPasswordResetEndpointResult', static fn (): object => new \App\Application\Auth\RequestPasswordResetEndpointResult('STATUS', null, null)];
        yield 'App\Application\Auth\RequestPasswordResetResult' => ['App\Application\Auth\RequestPasswordResetResult', static fn (): object => new \App\Application\Auth\RequestPasswordResetResult('STATUS', null)];
        yield 'App\Application\Auth\ResetPasswordEndpointResult' => ['App\Application\Auth\ResetPasswordEndpointResult', static fn (): object => new \App\Application\Auth\ResetPasswordEndpointResult('STATUS', [])];
        yield 'App\Application\Auth\ResetPasswordResult' => ['App\Application\Auth\ResetPasswordResult', static fn (): object => new \App\Application\Auth\ResetPasswordResult('STATUS', [])];
        yield 'App\Application\Auth\ResolveAdminActorResult' => ['App\Application\Auth\ResolveAdminActorResult', static fn (): object => new \App\Application\Auth\ResolveAdminActorResult('STATUS', null)];
        yield 'App\Application\Auth\ResolveAgentActorResult' => ['App\Application\Auth\ResolveAgentActorResult', static fn (): object => new \App\Application\Auth\ResolveAgentActorResult('STATUS')];
        yield 'App\Application\Auth\ResolveAuthenticatedUserResult' => ['App\Application\Auth\ResolveAuthenticatedUserResult', static fn (): object => new \App\Application\Auth\ResolveAuthenticatedUserResult('STATUS', null, null, [])];
        yield 'App\Application\Auth\SetupTwoFactorResult' => ['App\Application\Auth\SetupTwoFactorResult', static fn (): object => new \App\Application\Auth\SetupTwoFactorResult('STATUS', null)];
        yield 'App\Application\Auth\TwoFactorDisableEndpointResult' => ['App\Application\Auth\TwoFactorDisableEndpointResult', static fn (): object => new \App\Application\Auth\TwoFactorDisableEndpointResult('STATUS')];
        yield 'App\Application\Auth\TwoFactorEnableEndpointResult' => ['App\Application\Auth\TwoFactorEnableEndpointResult', static fn (): object => new \App\Application\Auth\TwoFactorEnableEndpointResult('STATUS', [])];
        yield 'App\Application\Auth\TwoFactorRecoveryCodesEndpointResult' => ['App\Application\Auth\TwoFactorRecoveryCodesEndpointResult', static fn (): object => new \App\Application\Auth\TwoFactorRecoveryCodesEndpointResult('STATUS', [])];
        yield 'App\Application\Auth\TwoFactorSetupEndpointResult' => ['App\Application\Auth\TwoFactorSetupEndpointResult', static fn (): object => new \App\Application\Auth\TwoFactorSetupEndpointResult('STATUS', null)];
        yield 'App\Application\AuthClient\ApproveDeviceFlowResult' => ['App\Application\AuthClient\ApproveDeviceFlowResult', static fn (): object => new \App\Application\AuthClient\ApproveDeviceFlowResult('STATUS')];
        yield 'App\Application\AuthClient\CancelDeviceFlowEndpointResult' => ['App\Application\AuthClient\CancelDeviceFlowEndpointResult', static fn (): object => new \App\Application\AuthClient\CancelDeviceFlowEndpointResult('STATUS')];
        yield 'App\Application\AuthClient\CancelDeviceFlowResult' => ['App\Application\AuthClient\CancelDeviceFlowResult', static fn (): object => new \App\Application\AuthClient\CancelDeviceFlowResult('STATUS')];
        yield 'App\Application\AuthClient\CompleteDeviceApprovalResult' => ['App\Application\AuthClient\CompleteDeviceApprovalResult', static fn (): object => new \App\Application\AuthClient\CompleteDeviceApprovalResult('STATUS')];
        yield 'App\Application\AuthClient\MintClientTokenEndpointResult' => ['App\Application\AuthClient\MintClientTokenEndpointResult', static fn (): object => new \App\Application\AuthClient\MintClientTokenEndpointResult('STATUS', null, null, null, null)];
        yield 'App\Application\AuthClient\MintClientTokenResult' => ['App\Application\AuthClient\MintClientTokenResult', static fn (): object => new \App\Application\AuthClient\MintClientTokenResult('STATUS', null)];
        yield 'App\Application\AuthClient\PollDeviceFlowEndpointResult' => ['App\Application\AuthClient\PollDeviceFlowEndpointResult', static fn (): object => new \App\Application\AuthClient\PollDeviceFlowEndpointResult('STATUS', null, null)];
        yield 'App\Application\AuthClient\PollDeviceFlowResult' => ['App\Application\AuthClient\PollDeviceFlowResult', static fn (): object => new \App\Application\AuthClient\PollDeviceFlowResult('STATUS', null)];
        yield 'App\Application\AuthClient\RevokeClientTokenEndpointResult' => ['App\Application\AuthClient\RevokeClientTokenEndpointResult', static fn (): object => new \App\Application\AuthClient\RevokeClientTokenEndpointResult('STATUS', null)];
        yield 'App\Application\AuthClient\RevokeClientTokenResult' => ['App\Application\AuthClient\RevokeClientTokenResult', static fn (): object => new \App\Application\AuthClient\RevokeClientTokenResult('STATUS', null)];
        yield 'App\Application\AuthClient\RotateClientSecretEndpointResult' => ['App\Application\AuthClient\RotateClientSecretEndpointResult', static fn (): object => new \App\Application\AuthClient\RotateClientSecretEndpointResult('STATUS', null, null)];
        yield 'App\Application\AuthClient\RotateClientSecretResult' => ['App\Application\AuthClient\RotateClientSecretResult', static fn (): object => new \App\Application\AuthClient\RotateClientSecretResult('STATUS', null, null)];
        yield 'App\Application\AuthClient\StartDeviceFlowEndpointResult' => ['App\Application\AuthClient\StartDeviceFlowEndpointResult', static fn (): object => new \App\Application\AuthClient\StartDeviceFlowEndpointResult('STATUS', null, null)];
        yield 'App\Application\AuthClient\StartDeviceFlowResult' => ['App\Application\AuthClient\StartDeviceFlowResult', static fn (): object => new \App\Application\AuthClient\StartDeviceFlowResult('STATUS', null)];
        yield 'App\Application\Derived\CompleteDerivedUploadResult' => ['App\Application\Derived\CompleteDerivedUploadResult', static fn (): object => new \App\Application\Derived\CompleteDerivedUploadResult('STATUS', null)];
        yield 'App\Application\Derived\DerivedEndpointResult' => ['App\Application\Derived\DerivedEndpointResult', static fn (): object => new \App\Application\Derived\DerivedEndpointResult('STATUS', null)];
        yield 'App\Application\Derived\GetDerivedByKindResult' => ['App\Application\Derived\GetDerivedByKindResult', static fn (): object => new \App\Application\Derived\GetDerivedByKindResult('STATUS', null)];
        yield 'App\Application\Derived\InitDerivedUploadResult' => ['App\Application\Derived\InitDerivedUploadResult', static fn (): object => new \App\Application\Derived\InitDerivedUploadResult('STATUS', null)];
        yield 'App\Application\Derived\ListDerivedFilesResult' => ['App\Application\Derived\ListDerivedFilesResult', static fn (): object => new \App\Application\Derived\ListDerivedFilesResult('STATUS', null)];
        yield 'App\Application\Derived\UploadDerivedPartResult' => ['App\Application\Derived\UploadDerivedPartResult', static fn (): object => new \App\Application\Derived\UploadDerivedPartResult('STATUS')];
        yield 'App\Application\Job\ClaimJobResult' => ['App\Application\Job\ClaimJobResult', static fn (): object => new \App\Application\Job\ClaimJobResult('STATUS', null)];
        yield 'App\Application\Job\FailJobResult' => ['App\Application\Job\FailJobResult', static fn (): object => new \App\Application\Job\FailJobResult('STATUS', null)];
        yield 'App\Application\Job\HeartbeatJobResult' => ['App\Application\Job\HeartbeatJobResult', static fn (): object => new \App\Application\Job\HeartbeatJobResult('STATUS', null)];
        yield 'App\Application\Job\JobEndpointResult' => ['App\Application\Job\JobEndpointResult', static fn (): object => new \App\Application\Job\JobEndpointResult('STATUS', null, null, null, null, null, null)];
        yield 'App\Application\Job\SubmitJobResult' => ['App\Application\Job\SubmitJobResult', static fn (): object => new \App\Application\Job\SubmitJobResult('STATUS', null)];
        yield 'App\Application\Workflow\ApplyDecisionsResult' => ['App\Application\Workflow\ApplyDecisionsResult', static fn (): object => new \App\Application\Workflow\ApplyDecisionsResult('STATUS', null)];
        yield 'App\Application\Workflow\GetBatchReportResult' => ['App\Application\Workflow\GetBatchReportResult', static fn (): object => new \App\Application\Workflow\GetBatchReportResult('STATUS', null)];
        yield 'App\Application\Workflow\PreviewDecisionsResult' => ['App\Application\Workflow\PreviewDecisionsResult', static fn (): object => new \App\Application\Workflow\PreviewDecisionsResult('STATUS', null)];
        yield 'App\Application\Workflow\PreviewPurgeResult' => ['App\Application\Workflow\PreviewPurgeResult', static fn (): object => new \App\Application\Workflow\PreviewPurgeResult('STATUS', null)];
        yield 'App\Application\Workflow\PurgeAssetResult' => ['App\Application\Workflow\PurgeAssetResult', static fn (): object => new \App\Application\Workflow\PurgeAssetResult('STATUS', null)];
        yield 'App\Application\Workflow\WorkflowEndpointResult' => ['App\Application\Workflow\WorkflowEndpointResult', static fn (): object => new \App\Application\Workflow\WorkflowEndpointResult('STATUS', null)];
    }
}
