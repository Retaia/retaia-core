# OpenAPI Runtime Audit (`specs@b6eb044`)

Date: 2026-03-27
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

That does not mean the runtime is free of shortcuts. A deeper implementation review still shows several production-grade quick fixes and minimal implementations that should be treated as active engineering debt, especially around auth state persistence, agent signing and operational projections.

## Findings

### P1. User interactive sessions and refresh-token state are still only stored in `cache.app`

- Runtime location: [`src/Auth/UserAccessTokenService.php:15`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:15)
- Critical lines:
  - [`src/Auth/UserAccessTokenService.php:21`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:21)
  - [`src/Auth/UserAccessTokenService.php:55`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:55)
  - [`src/Auth/UserAccessTokenService.php:121`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:121)
  - [`src/Auth/UserAccessTokenService.php:287`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:287)
  - [`src/Auth/UserAccessTokenService.php:316`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:316)
  - [`src/Auth/UserAccessTokenService.php:358`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/UserAccessTokenService.php:358)
- Impact:
  - active login sessions disappear on cache flush or restart
  - `/auth/refresh`, `/auth/me/sessions*`, logout and revocation semantics depend on volatile cache state rather than durable persistence
  - a multi-node deployment can invalidate or fragment user session state unless `cache.app` is strictly shared
- Why this is a quick fix:
  - the JWT layer looks complete, but the authoritative session registry behind it is still not persisted as real auth/session storage

### P1. 2FA state and recovery codes are still only stored in `cache.app`

- Runtime location: [`src/User/Service/TwoFactorService.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:8)
- Critical lines:
  - [`src/User/Service/TwoFactorService.php:13`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:13)
  - [`src/User/Service/TwoFactorService.php:49`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:49)
  - [`src/User/Service/TwoFactorService.php:78`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:78)
  - [`src/User/Service/TwoFactorService.php:125`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:125)
  - [`src/User/Service/TwoFactorService.php:188`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:188)
  - [`src/User/Service/TwoFactorService.php:213`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/User/Service/TwoFactorService.php:213)
- Impact:
  - enabled MFA, pending setup secrets and recovery codes disappear with cache eviction or restart
  - the repo can claim MFA is enabled at one moment and silently forget that state later
  - security posture depends on cache durability instead of a persistent security store
- Why this is a quick fix:
  - the feature behaves like a real MFA subsystem at the API level, but the backing state is still ephemeral application cache

### P1. Technical client registry, technical access tokens, device flows and MCP challenges are all cache-backed

- Runtime location: [`src/Auth/AuthClientStateStore.php:8`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:8)
- Critical lines:
  - [`src/Auth/AuthClientStateStore.php:18`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:18)
  - [`src/Auth/AuthClientStateStore.php:26`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:26)
  - [`src/Auth/AuthClientStateStore.php:55`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:55)
  - [`src/Auth/AuthClientStateStore.php:76`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:76)
  - [`src/Auth/AuthClientStateStore.php:97`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Auth/AuthClientStateStore.php:97)
- Impact:
  - provisioned technical clients, device flow approvals and MCP challenges are not durable
  - default fallback credentials (`agent-default`, `mcp-default`) are synthesized at runtime when the cache is empty
  - any restart or non-shared cache topology can rewrite or lose the client registry state
- Why this is a quick fix:
  - a large part of technical-client auth is implemented as application cache state instead of persistent auth/client tables

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

- move `UserAccessTokenService` state to persistent session/token storage
- move `TwoFactorService` state and recovery codes to persistent user security storage
- replace `AuthClientStateStore` cache-backed registry/flows/tokens/challenges with persistent auth-client tables

### Batch 2: agent-signing persistence hardening

- move `AgentPublicKeyStore` to a persistent DB-backed repository
- move `AgentSignatureNonceStore` to a durable/shared atomic store
- keep the current GPG verification path, but stop depending on volatile cache state for trust and replay guarantees

### Batch 3: remove prod-path “minimal test schema” fallbacks

- replace broad `catch (\Throwable)` masking with explicit optional-table handling or environment-scoped test helpers
- make ingest/purge fail loudly when prod persistence is inconsistent

### Batch 4: finish the operational model behind `/ops/agents`

- introduce a proper technical `client_id` model for registered agents
- add enough job execution history to populate `current_job`, `last_successful_job`, and `last_failed_job` without inventing timestamps

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

However, this snapshot still contains real quick fixes in production code, most notably around auth state persistence, agent-signing persistence and runtime error masking. The remaining work is now less about path/schema drift and more about replacing those shortcuts with durable runtime models.
