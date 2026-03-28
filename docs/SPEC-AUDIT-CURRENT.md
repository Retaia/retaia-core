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

That does not mean the runtime is free of shortcuts. A deeper implementation review still shows a small number of structural shortcuts that should be treated as active engineering debt, now mostly around service boundaries that still mix orchestration and infrastructure details.

## Findings

### P3. Several runtime services still combine domain orchestration with infrastructure details in the same class

- Representative locations:
  - [`src/Workflow/Service/BatchWorkflowService.php:192`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:192)
  - [`src/Command/IngestEnqueueStableCommand.php:103`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestEnqueueStableCommand.php:103)
- Impact:
  - business rules, SQL access and side-effect coordination remain interleaved
  - these classes are harder to reason about, mock cleanly or replace piecemeal
  - the codebase keeps growing around “god services” instead of stable domain/infrastructure seams
- Why this is below the target quality bar:
  - the core pattern should be: application service/use case orchestrates, repositories/gateways persist, lower-level adapters handle transport or filesystem concerns
- Progress already made:
  - existing proxy materialization and derived-file persistence have been extracted out of `IngestEnqueueStableCommand` into a dedicated service/repository seam
  - the remaining ingest debt is now mostly asset creation, auxiliary sidecar attachment and job-enqueue orchestration still concentrated in the command

### P3. Filesystem access is still handled through local ad hoc seams instead of a repo-wide storage abstraction

- Representative locations:
  - [`src/Ingest/Service/ExistingProxyFilesystem.php:5`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyFilesystem.php:5)
  - [`src/Ingest/Service/FilesystemFilePoller.php:7`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/FilesystemFilePoller.php:7)
  - [`src/Workflow/Service/BatchWorkflowService.php:373`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:373)
- Impact:
  - filesystem concerns are being extracted case by case, but each flow still defines its own local seam and path conventions
  - the repo has no single storage abstraction for local disk vs future alternate backends
  - cross-cutting concerns like move/copy/delete semantics, path normalization and test doubles are still fragmented
- Why this is below the target quality bar:
  - if filesystem access is part of the runtime model, it should be represented by an explicit storage port reused across ingest, derived, purge and related operational flows
  - the target remediation should be a dedicated PR introducing a repo-wide abstraction, likely via `league/flysystem`, rather than more one-off wrappers
  - if network-mounted storage remains a supported runtime target, that batch should also evaluate an SMB adapter such as `jerodev/flysystem-v3-smb-adapter` so the abstraction covers both local disk and SMB-backed volumes explicitly

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

### Batch 1: continue decomposing remaining god services

- keep reducing SQL / filesystem coordination inside `BatchWorkflowService`
- split operational command-side logic in `IngestEnqueueStableCommand` into narrower collaborators
- continue extracting purge-side file and filesystem coordination out of `BatchWorkflowService`
- prepare a dedicated filesystem/storage abstraction batch so future refactors stop creating local one-off seams

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

However, this snapshot still contains structural shortcuts in production code, now concentrated around service decomposition and separation of concerns rather than contract drift or missing persistence. The remaining work is now mostly about keeping the runtime model maintainable as the codebase grows.
