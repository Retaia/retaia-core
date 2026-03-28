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

That does not mean the runtime is free of shortcuts. A deeper implementation review still shows several production-grade quick fixes and minimal implementations that should be treated as active engineering debt, especially around MCP signature semantics, operational projections and remaining prod-path error masking.

The codebase also still contains several structural shortcuts that are below the quality bar expected for long-lived core runtime code: production code that still masks infrastructure failures too broadly, and operational projections coupled directly to transport/controller concerns.

## Findings

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

### Batch 1: remove prod-path “minimal test schema” fallbacks

- replace broad `catch (\Throwable)` masking with explicit optional-table handling or environment-scoped test helpers
- make ingest/purge fail loudly when prod persistence is inconsistent

### Batch 2: finish the operational model behind `/ops/agents`

- introduce a proper technical `client_id` model for registered agents
- add enough job execution history to populate `current_job`, `last_successful_job`, and `last_failed_job` without inventing timestamps

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

However, this snapshot still contains real quick fixes in production code, most notably around auth state persistence, agent-signing persistence and runtime error masking. The remaining work is now less about path/schema drift and more about replacing those shortcuts with durable runtime models.
