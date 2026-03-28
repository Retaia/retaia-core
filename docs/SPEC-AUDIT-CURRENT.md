# Runtime Audit (`specs@b6eb044`)

Date: 2026-03-28
Spec baseline: `specs/api/openapi/v1.yaml` from `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`

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

### P3. Filesystem access is still handled through local ad hoc seams instead of a repo-wide storage abstraction

- Architecture rule:
  - every runtime access to business storage for ingest, assets, derived files, archive, rejects, purge and readiness on those volumes must go through a shared Flysystem-based storage port
  - local filesystem calls are not acceptable anymore on those paths, because the runtime may be backed by SMB storage instead of a local disk
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
- Concrete migration inventory once Flysystem is introduced:
  - ingest watch-root resolution and directory validation:
    - [`src/Ingest/Service/WatchPathResolver.php:25`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/WatchPathResolver.php:25)
    - used to resolve the ingest storage root before any polling or sidecar lookup
  - ingest polling and file discovery:
    - [`src/Ingest/Service/FilesystemFilePoller.php:20`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/FilesystemFilePoller.php:20)
    - this is the current local-disk enumerator for inbox content
  - sidecar detection against sibling files:
    - [`src/Ingest/Service/SidecarFileDetector.php:262`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/SidecarFileDetector.php:262)
    - sidecar presence checks must move to the same storage abstraction as the source asset
  - existing proxy materialization into `.derived`:
    - [`src/Ingest/Service/ExistingProxyFilesystem.php:9`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyFilesystem.php:9)
    - this is already a local seam, but still tied to direct local disk semantics
  - outbox apply and archive/reject file moves:
    - [`src/Command/IngestApplyOutboxCommand.php:82`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestApplyOutboxCommand.php:82)
    - [`src/Command/IngestApplyOutboxCommand.php:171`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestApplyOutboxCommand.php:171)
    - [`src/Command/IngestApplyOutboxCommand.php:248`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestApplyOutboxCommand.php:248)
    - archive/reject handling is part of the same business storage domain and must stop using direct path moves
  - ingest enqueue source-file existence checks:
    - [`src/Command/IngestEnqueueStableCommand.php:94`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestEnqueueStableCommand.php:94)
    - source asset presence must be checked through the storage port, not `is_file`
  - purge and derived cleanup:
    - [`src/Workflow/Service/BatchWorkflowService.php:307`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:307)
    - [`src/Workflow/Service/BatchWorkflowService.php:323`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:323)
    - [`src/Workflow/Service/BatchWorkflowService.php:368`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Workflow/Service/BatchWorkflowService.php:368)
    - delete/list/recursive cleanup on derived storage must be Flysystem-backed
  - startup storage marker lifecycle:
    - [`src/Startup/StorageMarkerStartupValidator.php:31`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Startup/StorageMarkerStartupValidator.php:31)
    - if the marker is used to validate the same storage volume used by ingest/assets, it belongs to the Flysystem batch too
  - ops/readiness directory checks for configured storage roots:
    - [`src/Controller/Api/OpsController.php:92`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:92)
    - [`src/Command/OpsReadinessCheckCommand.php:41`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/OpsReadinessCheckCommand.php:41)
    - readiness on business storage must validate the abstract storage backend, not only local directories
- Additional runtime spots that should be reviewed in the same Flysystem batch even if they are partly indirect today:
  - [`src/Command/IngestEnqueueStableCommand.php:103`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestEnqueueStableCommand.php:103)
    - the command still orchestrates source/sidecar/proxy decisions around local-path assumptions
  - [`src/Ingest/Service/ExistingProxyAttachmentService.php:25`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyAttachmentService.php:25)
    - once Flysystem exists, this local helper seam should probably disappear behind the shared storage port
- Explicit non-targets for the Flysystem batch:
  - [`src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier.php:17`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier.php:17)
  - [`src/Controller/Api/DocsController.php:77`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/DocsController.php:77)

## Recommended remediation order

### Batch 1: continue decomposing remaining god services

- keep reducing SQL / filesystem coordination inside `BatchWorkflowService`
- split operational command-side logic in `IngestEnqueueStableCommand` into narrower collaborators
- continue extracting purge-side file and filesystem coordination out of `BatchWorkflowService`
- prepare a dedicated filesystem/storage abstraction batch so future refactors stop creating local one-off seams
