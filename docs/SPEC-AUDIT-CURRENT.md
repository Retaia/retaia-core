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

### P3. Business storage is now Flysystem-backed and multi-storage aware, but the backend is still hardcoded to local disk

- Architecture rule:
  - every runtime access to business storage for ingest, assets, derived files, archive, rejects, purge and readiness on those volumes must go through a shared Flysystem-based storage port
  - the Flysystem backend itself must be selectable explicitly when the runtime uses SMB-backed storage instead of a local disk
- Representative locations:
  - [`src/Storage/LocalBusinessStorageFactory.php:7`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Storage/LocalBusinessStorageFactory.php:7)
  - [`config/services.yaml:81`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/config/services.yaml:81)
  - [`composer.json:12`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/composer.json:12)
- Impact:
  - the runtime now depends on Flysystem semantics end-to-end for business storage, with a multi-storage registry, but only through `league/flysystem-local`
  - a deployment that mounts business storage over SMB still has no first-class backend wiring in the repo
  - switching storage backend would still require code edits instead of environment-level configuration
- Why this is below the target quality bar:
  - the code now has the right application/storage seam, but deployment portability still stops at the local adapter
  - if SMB-backed storage is a supported runtime, the repo should expose that choice explicitly through configuration and a dedicated adapter, not through a later ad hoc rewrite
- Concrete remaining work for the storage backend layer:
  - add the preferred SMB adapter package and wire it behind the same storage port:
    - `jerodev/flysystem-v3-smb-adapter`
  - make the storage backend selectable by configuration rather than by hardcoded factory:
    - local
    - SMB
  - centralize backend-specific configuration in one place:
    - local root path
    - SMB host/share/path/credentials/options
  - keep all ingest/assets/derived/archive/rejects/readiness flows on the existing `BusinessStorageInterface`
    - they should not need further code changes once the backend factory becomes configurable
- Explicit non-targets for the Flysystem batch:
  - [`src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier.php:17`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Api/Service/AgentSignature/GpgCliAgentRequestSignatureVerifier.php:17)
  - [`src/Controller/Api/DocsController.php:77`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/DocsController.php:77)

## Recommended remediation order

### Batch 1: make the Flysystem backend configurable

- add first-class SMB backend support behind `BusinessStorageInterface`
- remove the hardcoded local-only factory
- keep the application/storage seam stable while finishing backend portability

### Batch 2: continue decomposing remaining god services

- keep reducing SQL / side-effect coordination inside `BatchWorkflowService`
- split operational command-side logic in `IngestEnqueueStableCommand` into narrower collaborators
