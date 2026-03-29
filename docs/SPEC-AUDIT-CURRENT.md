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
