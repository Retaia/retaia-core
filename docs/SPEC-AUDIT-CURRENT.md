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

No concrete runtime/spec mismatch remains identified in this snapshot.

The remaining work is now on test-depth rather than runtime behavior.

## Findings

No active runtime finding remains in this audit snapshot.

## Coverage gaps in current tests

The following gaps remain after reviewing the runtime and the current functional/unit suite:

### Critical-path gaps

No remaining critical-path gap is tracked after batch 1.

### Secondary gaps

- no dedicated controller/functional assertion that `POST /auth/logout` rejects malformed bearer variants beyond the broader auth flows already covered.
- no dedicated functional assertion that `/auth/me/sessions/{session_id}/revoke` returns `404` for an unknown foreign session id. Current coverage exercises the success path and the self-revoke `409` path.
- no dedicated functional assertion that `/auth/me/sessions/revoke-others` returns `0` and preserves the current session when no peer session exists.
- no dedicated negative functional assertion that `/auth/2fa/recovery-codes/regenerate` returns `409 MFA_NOT_ENABLED` on a fresh account through the HTTP endpoint. The behavior is covered deeper in unit and broader auth-process scenarios, but not as an isolated contract guard.
- no dedicated functional assertion that `/auth/2fa/setup` rejects unauthorized requests with the exact current shape after the recent payload alignment. Unauthorized auth coverage exists globally, but not as a route-focused guard for this endpoint.

### Notes on surfaces already reviewed

- `assets`, `purge`, `derived upload`, `jobs` lease/fencing, app policy/features, device flow, client token mint/revoke/rotate, ops endpoints, and `/auth/me` shape all have direct runtime coverage.
- the gaps above are therefore residual depth gaps, not evidence of an untested subsystem.

## Recommended remediation order

### Batch 1: self-service negative edge guards

- add isolated HTTP guards for session revoke `404`, revoke-others on single session, and 2FA setup/regenerate edge responses

## Bottom line

The repo is currently aligned with the current OpenAPI v1 runtime contract to the extent verified by the implemented runtime and current local validation suite.

The remaining tracked work is limited to a small number of high-value coverage additions. No concrete runtime/spec drift is currently identified.
