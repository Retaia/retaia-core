# OpenAPI Runtime Audit (`specs@b6eb044`)

Date: 2026-03-27
Spec baseline: `specs/api/openapi/v1.yaml` from `retaia-docs@b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`
Runtime baseline: `retaia-core@master + jobs-alignment batch`

## Validation baseline

Executed locally:

- `composer check:openapi` ✅
- `composer check:openapi-docs-coherence` ✅
- `composer test:openapi-contract` ✅
- `composer test` ✅
- `composer test:quality` ✅

Important context:

- Route coverage is green: all OpenAPI v1 paths are present in the runtime router.
- The previous `assets`, agent-signing and `jobs` lease drifts have been aligned in runtime and guarded by tests.

## Executive summary

The runtime now tracks the current v1 spec closely on both route presence and the previously missing high-risk behaviors. The main remaining drift is now limited and semantic rather than structural:

1. `/auth/me` still returns a thinner current-user payload than the richer shape described by the current spec.
2. Some negative-path coverage is still thinner than the breadth of the current conditional-request contract.

## Findings

### P3. `/auth/me` remains semantically behind the richer current user shape

Spec:

- `AuthCurrentUser` documents `uuid`, `email`, `display_name`, `email_verified`, `roles`, `mfa_enabled`.

Runtime:

- `src/Controller/Api/AuthController.php::me()` returns only `id`, `email`, `roles`.

Nuance:

- This is not a hard schema violation today because `AuthCurrentUser` has `additionalProperties: true` and only `email` is required.
- It is still a semantic drift: the runtime is not yet serving the richer profile shape suggested by the current spec.

## Coverage gaps in current tests

The current suite is green and now guards the previously missing `jobs` lease semantics, but some coverage can still be broadened:

- no broad negative coverage for `412 PRECONDITION_FAILED` / `428 PRECONDITION_REQUIRED` across all asset and derived endpoints protected by `If-Match`
- no dedicated runtime assertion yet that `/auth/me` exposes the richer optional current-user fields when available

## Recommended remediation order

### Batch 1: current user shape

- enrich `/auth/me` with the optional current-user fields already documented by the spec
- add a focused functional test for the richer shape

### Batch 2: conditional request negative coverage

- extend functional coverage for `If-Match` protected endpoints to assert both `428` and `412` paths consistently

## Bottom line

The repo is now close to the current OpenAPI v1 contract in runtime behavior.

The only concrete runtime drift left in this snapshot is the thinner `/auth/me` payload, plus some test-depth gaps around conditional request failures.
