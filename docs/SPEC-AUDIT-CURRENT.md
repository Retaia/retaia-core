# OpenAPI Runtime Audit (`specs@b6eb044`)

Date: 2026-03-26
Spec baseline: `specs/api/openapi/v1.yaml` from `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`
Runtime baseline: `retaia-core@master`

## Validation baseline

Executed locally:

- `composer check:openapi` ✅
- `composer check:openapi-docs-coherence` ✅
- `composer test:openapi-contract` ✅
- `composer test` ✅

Important context:

- Route coverage is green: all OpenAPI v1 paths are present in the runtime router.
- The remaining drift is therefore mostly behavioral or schema-level, not missing-route drift.

## Executive summary

The runtime is no longer far from the current v1 spec on route presence, but it is still materially behind the contract in two areas:

1. `jobs` lease contract drift: `fencing_token` is still absent end-to-end, and job type naming is still on the old `generate_proxy` vocabulary.
2. agent request signing remains structurally validated, but not cryptographically validated as described by the OpenAPI surface.
3. contract test/tooling coverage still misses several of the newer v1 behaviors, so some drifts remain invisible while the suite stays green.

## Findings

### P1. Job lease contract is missing `fencing_token` end-to-end

Spec:

- `Job` includes `fencing_token`.
- `POST /jobs/{job_id}/heartbeat` requires `lock_token` and `fencing_token`, and returns `locked_until` plus `fencing_token`.
- `POST /jobs/{job_id}/submit` and `POST /jobs/{job_id}/fail` require `fencing_token`.

Runtime:

- `src/Job/Job.php` has no `fencing_token` property and never serializes one.
- `src/Application/Job/JobEndpointsHandler.php` validates only `lock_token` for heartbeat and only `lock_token` / `job_type` for submit.
- `fail()` also ignores `fencing_token` entirely.
- `src/Controller/Api/JobController.php` returns heartbeat payloads without `fencing_token`.

Impact:

- The lease/write-protection model implemented by runtime is weaker than the documented contract.
- A client following the current spec cannot rely on monotone fencing to protect writes.

Concrete drift:

- Spec job contract: `specs/api/openapi/v1.yaml` under `Job`, `/jobs/{job_id}/heartbeat`, `/jobs/{job_id}/submit`, `/jobs/{job_id}/fail`
- Runtime model/controller: `src/Job/Job.php`, `src/Application/Job/JobEndpointsHandler.php`, `src/Controller/Api/JobController.php`

### P1. Retryable job failure keeps stale claimant identity on a job returned to `pending`

Runtime:

- `src/Job/Repository/JobRepository.php::fail()` moves the job back to `pending` when `retryable=true`.
- The same update clears `lock_token` and `locked_until`, but does not clear `claimed_by`.

Impact:

- A pending job can still expose the previous claimant identity even though the lease has been released.
- This weakens the semantics of the job model and can mislead diagnostics or downstream automation reading job payloads.

Concrete drift:

- Runtime persistence logic: `src/Job/Repository/JobRepository.php`

### P1. Job type vocabulary is still on the old `generate_proxy` naming

Spec:

- Job types are `extract_facts`, `generate_preview`, `generate_thumbnails`, `generate_audio_waveform`, `transcribe_audio`.

Runtime still uses:

- `generate_proxy` in job validation and job policy logic.
- `generate_proxy` in server policy payloads and ingest enqueueing.

Confirmed locations:

- `src/Application/Job/JobEndpointsHandler.php`
- `src/Application/Job/SubmitJobHandler.php`
- `src/Application/Job/JobContractPolicy.php`
- `src/Controller/Api/AppController.php`
- `src/Application/Agent/RegisterAgentHandler.php`
- `src/Command/IngestEnqueueStableCommand.php`
- tests such as `tests/Functional/JobApiTest.php`

Impact:

- Current runtime advertises and accepts a job type that is absent from the normative spec.
- Current runtime does not fully align with the v1 submit schemas that are keyed on `generate_preview` and `transcribe_audio`.

### P2. Agent signed requests are validated structurally, not cryptographically

Spec surface strongly implies signed agent requests built around OpenPGP signing identity:

- `X-Retaia-Agent-Id`
- `X-Retaia-OpenPGP-Fingerprint`
- `X-Retaia-Signature`
- `X-Retaia-Signature-Timestamp`
- `X-Retaia-Signature-Nonce`

Runtime:

- `src/Api/Service/SignedAgentRequestValidator.php` checks presence, timestamp parseability, and some header/payload consistency.
- It does not verify a cryptographic signature against a stored agent public key.
- It does not implement nonce replay storage / replay rejection.

Related evidence:

- `src/Auth/AuthMcpService.php` contains an explicit comment that its own signature validation is still “lightweight”.
- The same maturity level is visible on the agent request validator path.

Impact:

- The runtime security model is weaker than the API surface suggests.
- A caller can satisfy the validator with syntactically correct headers without proving key ownership.

Concrete drift:

- Spec signed request headers: `specs/api/openapi/v1.yaml` under agents/jobs/derived operations and reusable parameters
- Runtime validator: `src/Api/Service/SignedAgentRequestValidator.php`

### P2. Job lease operations are protected by `lock_token`, but not bound to the authenticated technical principal

Runtime:

- `heartbeat`, `submit`, and `fail` only validate the presented `lock_token`.
- The current actor identity is not checked against `claimed_by` during these lease mutations.

Impact:

- Any technical principal that obtains a valid `lock_token` can continue another agent's lease operations.
- This is weaker than the implied ownership model of signed, agent-identified job processing.

Concrete drift:

- Application handlers: `src/Application/Job/HeartbeatJobHandler.php`, `src/Application/Job/SubmitJobHandler.php`, `src/Application/Job/FailJobHandler.php`
- Persistence logic: `src/Job/Repository/JobRepository.php`

### P3. `/auth/me` remains semantically behind the richer current user shape

Spec:

- `AuthCurrentUser` documents `uuid`, `email`, `display_name`, `email_verified`, `roles`, `mfa_enabled`.

Runtime:

- `src/Controller/Api/AuthController.php::me()` returns only `id`, `email`, `roles`.

Nuance:

- This is not a hard schema violation today because `AuthCurrentUser` has `additionalProperties: true` and only `email` is required.
- It is still a semantic drift: the runtime is not yet serving the richer profile shape suggested by the current spec.

## Coverage gaps in current tests

The current test suite is green, but the following drifts are not strongly guarded today:

- no contract assertion for `fencing_token` presence in job request/response payloads
- no contract assertion that runtime job type names match the current enum (`generate_preview` vs `generate_proxy`)
- no test that retryable job failure clears claimant identity when the job returns to `pending`
- no test that lease mutations are bound to the claiming principal rather than only to `lock_token`
- no broad negative coverage for `412 PRECONDITION_FAILED` / `428 PRECONDITION_REQUIRED` across all asset and derived endpoints protected by `If-Match`
- no broad negative coverage for missing/invalid signed-agent headers across all signed agent endpoints
- no test that agent signature validation performs actual cryptographic verification or nonce replay prevention

## Recommended remediation order

### Batch 1: jobs lease/model alignment

- introduce `fencing_token` in the job model, persistence and serializers
- require and validate `fencing_token` on heartbeat / submit / fail
- return `fencing_token` on lease responses
- migrate runtime job types from `generate_proxy` to `generate_preview`
- align job submit validation and tests with the current enums
- clear `claimed_by` when a retryable failure returns the job to `pending`
- bind lease mutations to the claiming principal, not only to `lock_token`

### Batch 2: agent signing hardening

- replace header-only validation with real signature verification against registered agent keys
- add nonce replay storage and TTL enforcement
- add negative tests for forged signatures and nonce replay

## Bottom line

The repo is currently healthy from a route-presence and test-green perspective, but not yet fully conformant with the latest OpenAPI v1 runtime contract.

The biggest remaining drifts are not hidden bugs in obscure corners; they are visible contract mismatches on:

- `jobs` lease semantics and job type naming
- agent request signature strength
