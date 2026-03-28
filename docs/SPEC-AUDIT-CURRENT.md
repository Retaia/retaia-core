# Runtime Audit (`specs@b6eb044`)

Date: 2026-03-28
Spec baseline: `specs/api/openapi/v1.yaml` from `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`

## Findings

### P1. Derived files are still modeled through two concurrent links: repository rows and `derived_manifest`

- Representative locations:
  - [`src/Derived/DerivedFileRepository.php:60`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Derived/DerivedFileRepository.php:60)
  - [`src/Ingest/Service/ExistingProxyAttachmentService.php:78`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyAttachmentService.php:78)
  - [`src/Command/IngestApplyOutboxCommand.php:194`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestApplyOutboxCommand.php:194)
  - [`src/Application/Job/SubmitJobHandler.php:236`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Application/Job/SubmitJobHandler.php:236)
- Impact:
  - the canonical derived relation already exists in `asset_derived_file`
  - the same relation is still duplicated in `fields['derived']['derived_manifest']`
  - ingest, outbox moves, and job submit patches update both representations, so they can drift independently
- Why this is below the target quality bar:
  - derived files must have a single persisted business link
  - asset fields should not duplicate repository state when that state already has its own table and repository
- Target state:
  - keep derived linkage only in `asset_derived_file`
  - build any manifest/view payload as a projection
  - remove runtime writes to `fields['derived']['derived_manifest']`

### P2. Sidecar lists currently mix two different concepts and therefore keep a secondary concurrent link for derived files

- Representative locations:
  - [`src/Command/IngestEnqueueStableCommand.php:269`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestEnqueueStableCommand.php:269)
  - [`src/Ingest/Service/ExistingProxyAttachmentService.php:68`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Ingest/Service/ExistingProxyAttachmentService.php:68)
  - [`src/Command/IngestApplyOutboxCommand.php:185`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Command/IngestApplyOutboxCommand.php:185)
- Impact:
  - `paths.sidecars_relative` contains both true auxiliary sidecars and materialized derived/proxy files
  - the same derived refs therefore exist in both sidecar lists and `asset_derived_file`
  - this blurs the domain boundary between “sidecar attached to the original” and “derived output generated/materialized for the asset”
- Why this is below the target quality bar:
  - auxiliary sidecars and derived files are not the same business concept
  - keeping both in one list creates another concurrent link for derived files
- Target state:
  - keep `paths.sidecars_relative` only for true auxiliary sidecars
  - project derived files exclusively from the derived repository/table
  - stop copying derived storage paths into sidecar lists

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

### P2. Some admin flows still resolve assets by relative path without a storage identifier

- Representative locations:
  - [`src/Controller/Api/OpsController.php:457`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:457)
  - [`src/Controller/Api/OpsController.php:535`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Controller/Api/OpsController.php:535)
- Impact:
  - once multiple business storages are active simultaneously, the same relative path can exist on more than one storage
  - path-only admin recovery/requeue flows can therefore resolve the wrong asset UUID or fail to resolve deterministically
- Why this is below the target quality bar:
  - storage-aware runtime needs storage-aware lookup semantics end-to-end
  - any flow that accepts a path as identity must either:
    - include `storage_id`, or
    - resolve through a repository query that disambiguates path + storage explicitly

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

### Batch 1: collapse derived file linkage to one canonical representation

- keep derived persistence only in `asset_derived_file`
- stop writing `fields['derived']['derived_manifest']`
- stop copying derived refs into `paths.sidecars_relative`
- generate manifest-like payloads as read projections only

### Batch 2: make path-based admin flows storage-aware

- remove path-only UUID derivation in ops/admin flows
- resolve assets with explicit storage context when a relative path is used as input

### Batch 3: make the Flysystem backend configurable

- add first-class SMB backend support behind `BusinessStorageInterface`
- remove the hardcoded local-only factory
- keep the application/storage seam stable while finishing backend portability

### Batch 4: continue decomposing remaining god services

- keep reducing SQL / side-effect coordination inside `BatchWorkflowService`
- split operational command-side logic in `IngestEnqueueStableCommand` into narrower collaborators
