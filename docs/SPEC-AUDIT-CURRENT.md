# Runtime Audit

Spec baseline:
- `specs/api/openapi/v1.yaml`
- `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`
- snapshot date: `2026-03-29`

Status:
- No active runtime audit findings.

## Structural Cleanup Inventory

The runtime/spec audit is green. The remaining work is structural: large classes that still mix too many responsibilities and should be decomposed without changing public contracts.

### Priority 1

- [`src/Controller/Api/AuthController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AuthController.php)
  - still concentrates login, refresh, logout, password reset, email verification, 2FA, self-service, and MCP-facing auth HTTP wiring
- [`src/Controller/Api/OpsController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php)
  - still mixes ingest ops, runtime agent ops, readiness-ish checks, and batch/admin actions
- [`src/Job/Repository/JobRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Job/Repository/JobRepository.php)
  - still holds too much persistence and projection logic for claiming, heartbeats, submit/fail paths, and ops/job views
- [`src/Ingest/Service/SidecarFileDetector`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/SidecarFileDetector.php)
  - still centralizes too many file-discovery rules and media-specific sidecar heuristics
- [`src/Feature/FeatureGovernanceService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Feature/FeatureGovernanceService.php)
  - still mixes app features, contract-version policy, and payload validation concerns

### Priority 2

- [`src/Application/Job/SubmitJobHandler`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/SubmitJobHandler.php)
  - still combines job-type validation, permission checks, asset mutations, derived persistence, and state transitions
- [`src/Infrastructure/Asset/AssetPatchGateway`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php)
  - still combines patch validation, mutable metadata rules, project normalization, and state transitions
- [`src/Storage/BusinessStorageRegistryFactory`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/BusinessStorageRegistryFactory.php)
  - still mixes env parsing, validation, backend selection, and per-driver construction
- [`src/Controller/Api/AssetController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AssetController.php)
  - still concentrates a wide asset HTTP surface that could be split by read, patch, workflow, and derived concerns
- [`src/Application/Asset/ListAssetsHandler`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/ListAssetsHandler.php)
  - still mixes cursor validation, filter normalization, sort validation, and gateway orchestration
- [`src/Workflow/Service/BatchWorkflowService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php)
  - improved already, but still carries too much orchestration around batch apply/cancel/purge/report flows
- [`src/Lock/Repository/OperationLockRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Lock/Repository/OperationLockRepository.php)
  - still mixes lock lifecycle persistence with query helpers and stale cleanup semantics
- [`src/Ingest/Repository/IngestDiagnosticsRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php)
  - still handles multiple diagnostic concerns in one repository and deserves narrower persistence seams

### Priority 3

- [`src/Api/Service/AgentRuntimeRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeRepository.php)
  - still mixes runtime writes and ops-facing read projection helpers
- [`src/Application/Job/JobEndpointsHandler`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/JobEndpointsHandler.php)
  - still exposes a broad façade over claim, heartbeat, submit, fail, and list/read concerns
- [`src/Security/ApiLoginAuthenticator`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Security/ApiLoginAuthenticator.php)
  - still mixes credential auth, throttling, MFA challenge branching, and token minting handoff
- [`src/Storage/FlysystemBusinessStorage`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/FlysystemBusinessStorage.php)
  - still wraps too many storage concerns in one concrete adapter and may need clearer internal helpers
- [`src/Api/Service/AgentJobProjectionRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentJobProjectionRepository.php)
  - still deserves narrower projection helpers once job ops reporting grows further

## Cleanup Rule

- Treat this list as structural debt only.
- Do not change OpenAPI or runtime behavior just to shrink classes.
- Each cleanup should extract a coherent responsibility with its own direct unit coverage.

## Missing Direct Tests Inventory

This inventory is about direct class coverage only.

- A class listed here may still be exercised indirectly by functional, integration, or higher-level unit tests.
- Interfaces, traits, and enums are intentionally excluded from this list.
- Result/DTO-style objects are listed separately from runtime services and adapters.

### Concrete runtime classes without direct tests

- [`src/Controller/Api/AuthController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AuthController.php)
- [`src/Controller/Api/OpsController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php)
- [`src/Job/Repository/JobRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Job/Repository/JobRepository.php)
- [`src/Ingest/Service/SidecarFileDetector`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/SidecarFileDetector.php)
- [`src/Feature/FeatureGovernanceService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Feature/FeatureGovernanceService.php)
- [`src/Infrastructure/Asset/AssetPatchGateway`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php)
- [`src/Storage/FlysystemBusinessStorage`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/FlysystemBusinessStorage.php)
- [`src/Controller/DeviceController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/DeviceController.php)
- [`src/Infrastructure/Asset/AssetReadGateway`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php)
- [`src/Infrastructure/Asset/AssetWorkflowGateway`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetWorkflowGateway.php)
- [`src/Auth/AuthMcpClientRegistrationService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpClientRegistrationService.php)
- [`src/Auth/AuthMcpChallengeService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpChallengeService.php)
- [`src/Auth/AuthClientDeviceFlowService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientDeviceFlowService.php)
- [`src/Auth/AuthClientAdminService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientAdminService.php)
- [`src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier.php)
- [`src/Controller/Api/AgentController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AgentController.php)
- [`src/Controller/Api/AssetController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AssetController.php)
- [`src/Controller/Api/JobController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/JobController.php)
- [`src/Controller/Api/AppController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/AppController.php)
- [`src/Controller/Api/DocsController`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/DocsController.php)
- [`src/Observability/Repository/MetricEventRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Observability/Repository/MetricEventRepository.php)
- [`src/User/Repository/PasswordResetTokenRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Repository/PasswordResetTokenRepository.php)
- [`src/User/Repository/UserRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Repository/UserRepository.php)
- [`src/Ingest/Repository/PathAuditRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/PathAuditRepository.php)
- [`src/Ingest/Service/ExistingProxyFilesystem`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyFilesystem.php)
- [`src/Ingest/Service/BusinessStorageAwareSidecarLocator`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/BusinessStorageAwareSidecarLocator.php)
- [`src/Ingest/Service/IngestDerivedOutboxMover`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/IngestDerivedOutboxMover.php)
- [`src/Ingest/Service/IngestOutboxMoveService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/IngestOutboxMoveService.php)
- [`src/Ingest/Service/IngestStableFileEnqueueService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/IngestStableFileEnqueueService.php)
- [`src/Asset/Repository/AssetRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Asset/Repository/AssetRepository.php)

### Result, record, and value-style classes without direct tests

- [`src/Asset/AssetRevisionTag`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Asset/AssetRevisionTag.php)
- [`src/Security/ApiClientPrincipal`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Security/ApiClientPrincipal.php)
- [`src/Auth/TechnicalAccessTokenRecord`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/TechnicalAccessTokenRecord.php)
- [`src/Auth/AuthClientRegistryEntry`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientRegistryEntry.php)
- [`src/Auth/AuthMcpChallenge`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpChallenge.php)
- [`src/Entity/WebAuthnDevice`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Entity/WebAuthnDevice.php)
- [`src/Storage/BusinessStorageDefinition`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/BusinessStorageDefinition.php)
- [`src/Storage/BusinessStorageFile`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/BusinessStorageFile.php)
- [`src/Observability/MetricName`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Observability/MetricName.php)
- [`src/Api/Service/AgentSignature/AgentSignatureNonceRecord`](/Users/fullfrontend/Jobs/A%20-%20Full Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentSignatureNonceRecord.php)
- [`src/Api/Service/AgentSignature/AgentPublicKeyRecord`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyRecord.php)

### Endpoint/result wrappers without direct tests

- [`src/Application/Derived/InitDerivedUploadResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/InitDerivedUploadResult.php)
- [`src/Application/Derived/CompleteDerivedUploadResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/CompleteDerivedUploadResult.php)
- [`src/Application/Derived/DerivedEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/DerivedEndpointResult.php)
- [`src/Application/Derived/ListDerivedFilesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/ListDerivedFilesResult.php)
- [`src/Application/Derived/UploadDerivedPartResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/UploadDerivedPartResult.php)
- [`src/Application/Derived/GetDerivedByKindResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Derived/GetDerivedByKindResult.php)
- [`src/Application/Asset/ReopenAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/ReopenAssetResult.php)
- [`src/Application/Asset/DecideAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/DecideAssetResult.php)
- [`src/Application/Asset/GetAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/GetAssetResult.php)
- [`src/Application/Asset/ListAssetsResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/ListAssetsResult.php)
- [`src/Application/Asset/ReprocessAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/ReprocessAssetResult.php)
- [`src/Application/Asset/AssetEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/AssetEndpointResult.php)
- [`src/Application/Asset/PatchAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/PatchAssetResult.php)
- [`src/Application/Auth/MyFeaturesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/MyFeaturesResult.php)
- [`src/Application/Auth/PatchMyFeaturesEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/PatchMyFeaturesEndpointResult.php)
- [`src/Application/Auth/RequestEmailVerificationEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/RequestEmailVerificationEndpointResult.php)
- [`src/Application/Auth/ConfirmEmailVerificationResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ConfirmEmailVerificationResult.php)
- [`src/Application/Auth/ResetPasswordResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ResetPasswordResult.php)
- [`src/Application/Auth/TwoFactorRecoveryCodesEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/TwoFactorRecoveryCodesEndpointResult.php)
- [`src/Application/Auth/GetAuthMeProfileResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/GetAuthMeProfileResult.php)
- [`src/Application/Auth/SetupTwoFactorResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/SetupTwoFactorResult.php)
- [`src/Application/Auth/PatchMyFeaturesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/PatchMyFeaturesResult.php)
- [`src/Application/Auth/RequestPasswordResetEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/RequestPasswordResetEndpointResult.php)
- [`src/Application/Auth/AdminConfirmEmailVerificationResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/AdminConfirmEmailVerificationResult.php)
- [`src/Application/Auth/ConfirmEmailVerificationEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ConfirmEmailVerificationEndpointResult.php)
- [`src/Application/Auth/GetMyFeaturesEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/GetMyFeaturesEndpointResult.php)
- [`src/Application/Auth/RegenerateTwoFactorRecoveryCodesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/RegenerateTwoFactorRecoveryCodesResult.php)
- [`src/Application/Auth/AuthMeEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/AuthMeEndpointResult.php)
- [`src/Application/Auth/DisableTwoFactorResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/DisableTwoFactorResult.php)
- [`src/Application/Auth/EnableTwoFactorResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/EnableTwoFactorResult.php)
- [`src/Application/Auth/ResetPasswordEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ResetPasswordEndpointResult.php)
- [`src/Application/Auth/TwoFactorEnableEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/TwoFactorEnableEndpointResult.php)
- [`src/Application/Auth/TwoFactorSetupEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/TwoFactorSetupEndpointResult.php)
- [`src/Application/Auth/ResolveAdminActorResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ResolveAdminActorResult.php)
- [`src/Application/Auth/RequestPasswordResetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/RequestPasswordResetResult.php)
- [`src/Application/Auth/ResolveAuthenticatedUserResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ResolveAuthenticatedUserResult.php)
- [`src/Application/Auth/AdminConfirmEmailVerificationEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/AdminConfirmEmailVerificationEndpointResult.php)
- [`src/Application/Auth/ResolveAgentActorResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/ResolveAgentActorResult.php)
- [`src/Application/Auth/RequestEmailVerificationResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Auth/RequestEmailVerificationResult.php)
- [`src/Application/AppPolicy/GetAppPolicyResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/GetAppPolicyResult.php)
- [`src/Application/AppPolicy/PatchAppFeaturesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/PatchAppFeaturesResult.php)
- [`src/Application/AppPolicy/PatchAppFeaturesEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/PatchAppFeaturesEndpointResult.php)
- [`src/Application/AppPolicy/AppPolicyEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/AppPolicyEndpointResult.php)
- [`src/Application/AppPolicy/GetAppFeaturesResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/GetAppFeaturesResult.php)
- [`src/Application/AppPolicy/GetAppFeaturesEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AppPolicy/GetAppFeaturesEndpointResult.php)
- [`src/Application/Agent/RegisterAgentResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentResult.php)
- [`src/Application/Agent/RegisterAgentEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentEndpointResult.php)
- [`src/Application/Workflow/GetBatchReportResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/GetBatchReportResult.php)
- [`src/Application/Workflow/PurgeAssetResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/PurgeAssetResult.php)
- [`src/Application/Workflow/PreviewPurgeResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/PreviewPurgeResult.php)
- [`src/Application/Workflow/PreviewDecisionsResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/PreviewDecisionsResult.php)
- [`src/Application/Workflow/WorkflowEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/WorkflowEndpointResult.php)
- [`src/Application/Workflow/ApplyDecisionsResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Workflow/ApplyDecisionsResult.php)
- [`src/Application/Job/SubmitJobResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/SubmitJobResult.php)
- [`src/Application/Job/HeartbeatJobResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/HeartbeatJobResult.php)
- [`src/Application/Job/JobEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/JobEndpointResult.php)
- [`src/Application/Job/ClaimJobResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/ClaimJobResult.php)
- [`src/Application/AuthClient/RevokeClientTokenResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/RevokeClientTokenResult.php)
- [`src/Application/AuthClient/PollDeviceFlowEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/PollDeviceFlowEndpointResult.php)
- [`src/Application/AuthClient/StartDeviceFlowEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/StartDeviceFlowEndpointResult.php)
- [`src/Application/AuthClient/RotateClientSecretResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/RotateClientSecretResult.php)
- [`src/Application/AuthClient/MintClientTokenEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/MintClientTokenEndpointResult.php)
- [`src/Application/AuthClient/ApproveDeviceFlowResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/ApproveDeviceFlowResult.php)
- [`src/Application/AuthClient/RotateClientSecretEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/RotateClientSecretEndpointResult.php)
- [`src/Application/AuthClient/RevokeClientTokenEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/RevokeClientTokenEndpointResult.php)
- [`src/Application/AuthClient/PollDeviceFlowResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/PollDeviceFlowResult.php)
- [`src/Application/AuthClient/CancelDeviceFlowResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/CancelDeviceFlowResult.php)
- [`src/Application/AuthClient/StartDeviceFlowResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/StartDeviceFlowResult.php)
- [`src/Application/AuthClient/MintClientTokenResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/MintClientTokenResult.php)
- [`src/Application/AuthClient/CompleteDeviceApprovalResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/CompleteDeviceApprovalResult.php)
- [`src/Application/AuthClient/CancelDeviceFlowEndpointResult`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/AuthClient/CancelDeviceFlowEndpointResult.php)

## Code Reinforcement Backlog

These items are not active runtime/spec regressions. They are the next code-quality and robustness improvements with the best expected payoff.

### 1. Database invariants

- Add stronger database-level guarantees instead of relying only on PHP guards.
- Prefer `CHECK`, `UNIQUE`, foreign keys, and targeted indexes for:
  - job lease and fencing consistency
  - derived-file uniqueness and linkage
  - auth session and refresh-token state
  - 2FA persisted state coherence

### 2. Replace weak arrays with typed models

- Reduce use of `array<string, mixed>` in business paths.
- Prioritize typed DTOs/value objects for:
  - asset field projections and mutations
  - job submit payloads
  - ops/runtime projections
  - storage configuration

### 3. Add architecture tests

- Introduce automated structural rules to prevent backsliding.
- Useful rules:
  - controllers do not contain business logic
  - application handlers do not execute SQL directly
  - only repositories/gateways touch persistence
  - business storage access only goes through the storage port

### 4. Add invariant/property-style tests

- Add higher-signal invariant tests where example-based tests are not enough.
- Good candidates:
  - asset state machine transitions
  - job leasing and fencing
  - canonical relative path normalization
  - 2FA and recovery-code one-shot semantics

### 5. Strengthen security observability

- Add or standardize security audit events and metrics for:
  - login failures and success
  - refresh failures and revocations
  - 2FA enable, disable, and recovery-code regeneration
  - auth-client secret rotation
  - MCP and agent registration/signature failures

### 6. Cover critical concrete classes directly

- Best next direct-test targets:
  - [`src/Infrastructure/Asset/AssetPatchGateway`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php)
  - [`src/Storage/FlysystemBusinessStorage`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/FlysystemBusinessStorage.php)
  - [`src/Ingest/Service/SidecarFileDetector`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/SidecarFileDetector.php)
  - [`src/Job/Repository/JobRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Job/Repository/JobRepository.php)

### 7. Prefer immutability in transport and domain helpers

- Increase use of `readonly` and immutable value objects.
- Reduce mutation-heavy helper flows where state is progressively patched in-place.

### 8. Clarify compatibility boundaries

- Document which code paths are:
  - public API contract
  - stable internal contract
  - implementation detail
- Use that boundary to keep refactors aggressive internally without accidental contract drift.

## Persistence Architecture Rule

Target direction: maximize Doctrine ORM usage and reduce DBAL/manual persistence to the smallest possible surface.

### Target rule

- ORM should be the default persistence model.
- DBAL/manual SQL should become the exception, not the norm.
- ORM repositories are allowed to use DQL and Doctrine `QueryBuilder` for:
  - filters
  - search
  - pagination
  - sorting
  - partial projections
- Any class that remains DBAL-based should have a clear justification:
  - locking/lease semantics that genuinely need explicit SQL
  - runtime projection tables
  - append-heavy observability tables

### DoctrineExtensions usage

DoctrineExtensions should be used where it removes repetitive ORM boilerplate cleanly.

- Preferred traits/extensions:
  - `Timestampable`
  - `SoftDeleteable` when the domain really needs soft deletion
  - `Blameable` when the persisted actor model is explicit and trustworthy
- Not a substitute for:
  - proper aggregate boundaries
  - typed value objects
  - replacing JSON-heavy ad hoc fields with modeled state

### First ORM migration targets

These are the files that should change first if the codebase moves to an ORM-first model.

#### Auth sessions and technical auth state

- [`src/Auth/UserAuthSession.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAuthSession.php)
- [`src/Auth/UserAuthSessionRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAuthSessionRepository.php)
- [`src/Auth/AuthDeviceFlow.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthDeviceFlow.php)
- [`src/Auth/AuthDeviceFlowRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthDeviceFlowRepository.php)
- [`src/Auth/AuthMcpChallenge.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpChallenge.php)
- [`src/Auth/AuthMcpChallengeRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpChallengeRepository.php)
- [`src/Auth/TechnicalAccessTokenRecord.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/TechnicalAccessTokenRecord.php)
- [`src/Auth/TechnicalAccessTokenRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/TechnicalAccessTokenRepository.php)
- [`src/Auth/AuthClientRegistryEntry.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientRegistryEntry.php)
- [`src/Auth/AuthClientRegistryRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientRegistryRepository.php)

#### Derived storage and upload state

- [`src/Derived/DerivedFile.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedFile.php)
- [`src/Derived/DerivedFileRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedFileRepository.php)
- [`src/Derived/DerivedUploadSession.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedUploadSession.php)
- [`src/Derived/DerivedUploadSessionRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedUploadSessionRepository.php)
- [`src/Derived/Service/DerivedUploadService.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/Service/DerivedUploadService.php)

#### Existing ORM entities to normalize with traits

- [`src/Entity/Asset.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Entity/Asset.php)
- [`src/Entity/User.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Entity/User.php)
- [`src/Entity/WebAuthnDevice.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Entity/WebAuthnDevice.php)

### ORM repositories that should explicitly use QueryBuilder/DQL where needed

- [`src/Asset/Repository/AssetRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Asset/Repository/AssetRepository.php)
  - filters, search, pagination, sorting
- future ORM repositories replacing:
  - [`src/Derived/DerivedFileRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedFileRepository.php)
  - [`src/Derived/DerivedUploadSessionRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedUploadSessionRepository.php)
  - [`src/Auth/UserAuthSessionRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAuthSessionRepository.php)
  - [`src/Auth/AuthDeviceFlowRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthDeviceFlowRepository.php)

### Likely DBAL holdouts even in an ORM-first model

These may still justify explicit SQL after review, but that should be a conscious exception.

- [`src/Job/Repository/JobRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Job/Repository/JobRepository.php)
- [`src/Lock/Repository/OperationLockRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Lock/Repository/OperationLockRepository.php)
- [`src/Api/Service/AgentRuntimeRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeRepository.php)
- [`src/Api/Service/AgentJobProjectionRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentJobProjectionRepository.php)
- [`src/Ingest/Repository/IngestDiagnosticsRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php)
- [`src/Ingest/Repository/PathAuditRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/PathAuditRepository.php)
- [`src/Observability/Repository/MetricEventRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Observability/Repository/MetricEventRepository.php)

### Explicit anti-goal

- Do not keep adding new DBAL-only pseudo-models when an ORM entity would be the simpler long-term choice.
- Do not use DoctrineExtensions traits as a substitute for the ORM migration itself.
