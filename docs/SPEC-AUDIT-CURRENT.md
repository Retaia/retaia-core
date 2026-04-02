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

- [`src/Application/Asset/ListAssetsHandler`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Asset/ListAssetsHandler.php)
  - still mixes cursor validation, filter normalization, sort validation, and gateway orchestration
  - next seams:
    - `ListAssetsQueryNormalizer` for filters and sort validation
    - `AssetListCursorCodec` for context hash, encode/decode, and offset rules
    - handler kept as orchestration only

### Priority 2

- [`src/Storage/BusinessStorageRegistryFactory`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/BusinessStorageRegistryFactory.php)
  - env parsing/validation is extracted, but the factory still keeps backend selection and per-driver construction
  - next seams:
    - narrower builder map per driver
    - optional dedicated validator for driver-specific completeness checks if SMB/local setup grows again
- [`src/Workflow/Service/BatchWorkflowService`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php)
  - improved already, but still carries orchestration for move preview/apply, decision preview/apply, and purge/report flows
  - next seams:
    - move coordinator
    - decision coordinator
    - purge coordinator
- [`src/Lock/Repository/OperationLockRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Lock/Repository/OperationLockRepository.php)
  - still mixes lock lifecycle persistence with query helpers and stale cleanup semantics
  - next seams:
    - active lock writer
    - stale lock cleanup/query projector
- [`src/Ingest/Repository/IngestDiagnosticsRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php)
  - still handles unmatched-sidecar writes, counters, latest snapshot, and filtered listing in one class
  - next seams:
    - unmatched-sidecar writer
    - diagnostics summary projector
    - unmatched-sidecar listing projector
- [`src/Security/ApiLoginAuthenticator`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Security/ApiLoginAuthenticator.php)
  - still mixes credential auth, throttling, MFA challenge branching, and token minting handoff
  - next seams:
    - credential payload extractor
    - MFA challenge responder
    - second-factor throttling guard

### Priority 3

- [`src/Api/Service/AgentRuntimeRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeRepository.php)
  - still mixes runtime writes and ops-facing read projection helpers
- [`src/Application/Job/JobEndpointsHandler`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/JobEndpointsHandler.php)
  - still exposes a broad façade over claim, heartbeat, submit, fail, and list/read concerns
- [`src/Security/ApiLoginAuthenticator`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Security/ApiLoginAuthenticator.php)
  - still mixes credential auth, throttling, MFA challenge branching, and token minting handoff
- [`src/Api/Service/AgentJobProjectionRepository`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentJobProjectionRepository.php)
  - still deserves narrower projection helpers once job ops reporting grows further

## Cleanup Rule

- Treat this list as structural debt only.
- Do not change OpenAPI or runtime behavior just to shrink classes.
- Each cleanup should extract a coherent responsibility with its own direct unit coverage.

## Missing Direct Tests Inventory

Status:
- No active missing direct-test findings in the current audit inventory.
- Runtime services, controller adapters, value/record objects, and application result wrappers previously tracked here now have direct unit coverage.

Rule:
- Keep this section empty unless a concrete uncovered class is found during a new audit pass.

## ReflectionClass Test Inventory

Status:
- No active runtime finding.
- This is test-structure debt: these tests still depend on `ReflectionClass` or `newInstanceWithoutConstructor()`.

Files currently using reflection in tests:
- [`tests/Unit/Application/ApplicationResultObjectsSmokeTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Application/ApplicationResultObjectsSmokeTest.php)
- [`tests/Unit/Controller/AgentControllerTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Controller/AgentControllerTest.php)
- [`tests/Unit/Controller/AuthCurrentSessionResolverTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Controller/AuthCurrentSessionResolverTest.php)
- [`tests/Unit/Controller/ControllerInstantiationTrait.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Controller/ControllerInstantiationTrait.php)
- [`tests/Unit/Controller/DeviceControllerTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Controller/DeviceControllerTest.php)
- [`tests/Unit/Controller/JobControllerTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Controller/JobControllerTest.php)
- [`tests/Unit/Ingest/Service/IngestStableFileEnqueueServiceTest.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Unit/Ingest/Service/IngestStableFileEnqueueServiceTest.php)

Rule:
- Prefer constructor-based test setup or dedicated test builders/stubs.
- Do not add new reflection-based test instantiation without a concrete justification.

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

### 6. Keep direct coverage current

- Maintain direct tests when extracting new helpers, facades, or repositories.
- Do not reopen a missing-test backlog for classes already covered unless the coverage becomes stale or indirect again.

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

#### Derived storage and upload state

- [`src/Derived/Service/DerivedUploadService.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/Service/DerivedUploadService.php)

#### Existing ORM entities to normalize with traits

### ORM repositories that should explicitly use QueryBuilder/DQL where needed

- [`src/Asset/Repository/AssetRepository.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Asset/Repository/AssetRepository.php)
  - filters, search, pagination, sorting
- future ORM repositories replacing:

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
