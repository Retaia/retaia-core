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

That does not mean the runtime is free of shortcuts. A deeper implementation review still shows a small number of production-grade quick fixes and structural shortcuts that should be treated as active engineering debt, now mostly around MCP signature semantics and a few remaining service boundaries.

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

### Batch 1: replace the remaining acknowledged cryptographic shortcut for MCP signing

- remove the lightweight HMAC-like MCP verification path
- introduce a real OpenPGP verification flow or a dedicated verifier abstraction with equivalent guarantees

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

However, this snapshot still contains real quick fixes in production code, now concentrated around MCP signature semantics and a few structural service boundaries. The remaining work is now less about path/schema drift and more about replacing those shortcuts with durable runtime models.
