# OpenAPI Runtime Audit (`specs@b6eb044`)

Date: 2026-03-28
Spec baseline: `specs/api/openapi/v1.yaml` from `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`
Runtime baseline: `retaia-core@master + auth-me alignment batch`

## Validation baseline

Executed locally:

- `composer check:openapi` ✅
- `composer check:openapi-docs-coherence` ✅
- `composer test:openapi-contract` ✅
- `composer test` ✅
- `composer test:quality` ✅

Important context:

- Route coverage is green: all OpenAPI v1 paths are present in the runtime router.
- The previous `assets`, agent-signing, `jobs` lease and `/auth/me` drifts have been aligned in runtime and guarded by tests.

## Executive summary

No concrete OpenAPI path/schema drift remains identified in this snapshot.

That does not mean the runtime is free of shortcuts. A deeper implementation review still shows several production-grade quick fixes and minimal implementations that should be treated as active engineering debt, especially around remaining agent-signing persistence, MCP signature semantics and operational projections.

The codebase also still contains several structural shortcuts that are below the quality bar expected for long-lived core runtime code: cache-backed security stores with no repository boundary, production code that still masks infrastructure failures too broadly, and operational projections coupled directly to transport/controller concerns.

## Findings

### P1. Agent public keys are still stored in `cache.app`

- Runtime location: [`src/Api/Service/AgentSignature/AgentPublicKeyStore.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyStore.php:8)
- Critical lines: [`src/Api/Service/AgentSignature/AgentPublicKeyStore.php:12`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyStore.php:12), [`src/Api/Service/AgentSignature/AgentPublicKeyStore.php:34`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyStore.php:34), [`src/Api/Service/AgentSignature/AgentPublicKeyStore.php:66`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyStore.php:66)
- Impact:
  - registered agent keys disappear on cache flush or restart
  - multi-node behavior depends on cache topology rather than explicit persistence
  - signed agent requests can fail after a perfectly valid previous registration
- Why this is a quick fix:
  - the runtime now persists `agent_runtime` in DB, but the key material required to validate the same agent is still only cache-backed
  - this is not a stable source of truth for a security-sensitive registry

### P1. Signature replay protection is only cache-backed

- Runtime location: [`src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:8)
- Critical lines: [`src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:10`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:10), [`src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:24`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:24)
- Impact:
  - replay protection is best-effort if the cache is local or volatile
  - a restart reopens the replay window
  - a multi-node topology can accept the same nonce on two different API nodes if the cache is not strongly shared
- Why this is a quick fix:
  - it closes the happy-path security story locally, but not with the guarantees expected from a real replay-protection store

### P2. MCP signature validation is still explicitly lightweight and non-OpenPGP

- Runtime location: [`src/Auth/AuthMcpService.php:101`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpService.php:101)
- Critical lines:
  - [`src/Auth/AuthMcpService.php:102`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpService.php:102)
  - [`src/Auth/AuthMcpService.php:106`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpService.php:106)
  - [`src/Auth/AuthMcpService.php:114`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthMcpService.php:114)
- Impact:
  - the code path models MCP signing as an HMAC derived from public key material, not as a real OpenPGP signature verification flow
  - if MCP endpoints are reintroduced or expanded, they would still rely on an acknowledged shortcut
- Why this is a quick fix:
  - the code itself labels it as a “Lightweight signature validation until a dedicated OpenPGP library is introduced”

### P2. `/agents/register` still projects `client_id` from the authenticated user actor

- Runtime location: [`src/Application/Agent/RegisterAgentEndpointHandler.php:50`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentEndpointHandler.php:50)
- Critical lines: [`src/Application/Agent/RegisterAgentEndpointHandler.php:52`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentEndpointHandler.php:52), [`src/Application/Agent/RegisterAgentEndpointHandler.php:55`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentEndpointHandler.php:55), [`src/Application/Agent/RegisterAgentEndpointHandler.php:70`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Agent/RegisterAgentEndpointHandler.php:70)
- Impact:
  - the `client_id` exposed later by `/ops/agents` is really the registering user/admin actor id, not a true technical client identity
  - operational views and identity-conflict detection are therefore semantically approximate
- Why this is a quick fix:
  - it reuses an available authenticated identifier to fill a spec field, but does not model the actual client/agent identity layer cleanly

### P2. Runtime code still swallows broad DB failures to tolerate “minimal test schemas”

- Runtime locations:
  - [`src/Ingest/Repository/IngestDiagnosticsRepository.php:31`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php:31)
  - [`src/Workflow/Service/BatchWorkflowService.php:334`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:334)
- Critical lines:
  - [`src/Ingest/Repository/IngestDiagnosticsRepository.php:41`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php:41)
  - [`src/Ingest/Repository/IngestDiagnosticsRepository.php:53`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php:53)
  - [`src/Ingest/Repository/IngestDiagnosticsRepository.php:67`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Repository/IngestDiagnosticsRepository.php:67)
  - [`src/Workflow/Service/BatchWorkflowService.php:339`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:339)
- Impact:
  - real DB/configuration failures can be silently degraded into partial behavior
  - prod behavior becomes harder to observe and reason about because the failure is intentionally masked
  - purge/ingest can report success while some persistence-side effects are skipped
- Why this is a quick fix:
  - test-environment resilience is implemented directly in production code paths through broad `catch (\Throwable)`

### P2. Agent signing still lacks repository-grade boundaries around trust material and replay state

- Runtime locations:
  - [`src/Api/Service/AgentSignature/AgentPublicKeyStore.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentPublicKeyStore.php:8)
  - [`src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/AgentSignatureNonceStore.php:8)
  - [`src/Api/Service/SignedAgentRequestValidator.php:14`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/SignedAgentRequestValidator.php:14)
- Impact:
  - the validator still speaks directly to cache-shaped stores and transport headers
  - trust material, replay control and request validation are coupled too early in the stack
  - it is harder to evolve toward durable storage, auditing and stronger verification semantics independently
- Why this is below the target quality bar:
  - key registry and nonce consumption should sit behind repository/port interfaces, with the request validator focused on HTTP validation/orchestration only

### P3. `AgentRuntimeStore` is DB-backed but still acts as an ad hoc persistence layer instead of a repository

- Runtime location: [`src/Api/Service/AgentRuntimeStore.php:7`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeStore.php:7)
- Critical lines:
  - [`src/Api/Service/AgentRuntimeStore.php:71`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeStore.php:71)
  - [`src/Api/Service/AgentRuntimeStore.php:104`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentRuntimeStore.php:104)
- Impact:
  - naming suggests a lightweight service/store, but it is already a persistence boundary with SQL and projection logic
  - controller and runtime mutation code are coupled to this ad hoc type instead of a clearer repository + projection split
- Why this is below the target quality bar:
  - at this point it should be promoted to a repository (or split into repository + projector) to make ops runtime state an explicit domain concern

### P3. Several runtime services still combine domain orchestration with infrastructure details in the same class

- Representative locations:
  - [`src/Derived/Service/DerivedUploadService.php:47`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/Service/DerivedUploadService.php:47)
  - [`src/Workflow/Service/BatchWorkflowService.php:192`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:192)
  - [`src/Command/IngestEnqueueStableCommand.php:399`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestEnqueueStableCommand.php:399)
- Impact:
  - business rules, SQL access and side-effect coordination remain interleaved
  - these classes are harder to reason about, mock cleanly or replace piecemeal
  - the codebase keeps growing around “god services” instead of stable domain/infrastructure seams
- Why this is below the target quality bar:
  - the core pattern should be: application service/use case orchestrates, repositories/gateways persist, lower-level adapters handle transport or filesystem concerns

### P3. `/ops/agents` is still a partial operational projection

- Runtime location: [`src/Controller/Api/OpsController.php:289`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:289)
- Critical lines: [`src/Controller/Api/OpsController.php:296`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:296), [`src/Controller/Api/OpsController.php:318`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:318)
- Impact:
  - `current_job` is still forced to `null`
  - `last_successful_job` and `last_failed_job` are not projected at all
  - the endpoint is contract-compliant, but still weaker than the operational model described by the spec
- Why this is a quick fix:
  - the shape is present, but the backing execution history model is not yet complete enough to populate it faithfully

## Coverage gaps in current tests

No major test-depth gap is currently tracked relative to the implemented runtime surface.

### Critical-path gaps

No remaining critical-path gap is tracked in this snapshot.

### Secondary gaps

No remaining secondary gap is tracked in this snapshot.

### Notes on surfaces already reviewed

- `assets`, `purge`, `derived upload`, `jobs` lease/fencing, app policy/features, device flow, client token mint/revoke/rotate, ops endpoints, and `/auth/me` shape all have direct runtime coverage.
- the remaining work identified above is not contract drift; it is implementation-hardening and model-completeness work.

## Recommended remediation order

### Batch 1: auth state persistence hardening

- move `AgentPublicKeyStore` to a persistent DB-backed repository
- move `AgentSignatureNonceStore` to a durable/shared atomic store

### Batch 2: agent-signing persistence hardening

- keep the current GPG verification path, but stop depending on volatile cache state for trust and replay guarantees

### Batch 3: introduce missing repository boundaries on already-persistent runtime state

- promote/split `AgentRuntimeStore` into a repository and, if needed, a projection layer
- stop adding new DB-backed “service/store” classes without an explicit persistence boundary

### Batch 4: remove prod-path “minimal test schema” fallbacks

- replace broad `catch (\Throwable)` masking with explicit optional-table handling or environment-scoped test helpers
- make ingest/purge fail loudly when prod persistence is inconsistent

### Batch 5: finish the operational model behind `/ops/agents`

- introduce a proper technical `client_id` model for registered agents
- add enough job execution history to populate `current_job`, `last_successful_job`, and `last_failed_job` without inventing timestamps

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

However, this snapshot still contains real quick fixes in production code, most notably around auth state persistence, agent-signing persistence and runtime error masking. The remaining work is now less about path/schema drift and more about replacing those shortcuts with durable runtime models.
