# Spec Audit: `specs@a86c5d8` -> `specs@ed86b95`

## Scope

- Previous pinned specs revision: `a86c5d84101b1120baf108eda5f6a1cca6ef8fef`
- New specs revision: `ed86b95dd1409b65f347e85dc78af46258b01c44`
- Audited file: `specs/api/openapi/v1.yaml`

## High-signal v1 changes

### Added paths

- `/assets/purge`
- `/auth/me/sessions`
- `/auth/me/sessions/{session_id}/revoke`
- `/auth/me/sessions/revoke-others`

### Removed paths

- `/auth/mcp/register`
- `/auth/mcp/challenge`
- `/auth/mcp/token`
- `/auth/mcp/{client_id}/rotate-key`
- `/auth/webauthn/register/options`
- `/auth/webauthn/register/verify`
- `/auth/webauthn/authenticate/options`
- `/auth/webauthn/authenticate/verify`

### Contract drifts beyond paths

- `POST /app/policy` now declares `409 STATE_CONFLICT` for non-runtime-mutable flags.
- `AppFeaturesResponse` now requires explanation fields:
  - `app_feature_explanations`
- `ErrorResponse.code` enum changed:
  - `RATE_LIMITED` is no longer present in the current v1 enum.
- Many endpoints now declare `Accept-Language` explicitly as a shared parameter.

## Observed repo status after bump

### Automated checks

- `composer check:openapi` fails
  - extra runtime routes still exposed in v1:
    - `POST /api/v1/auth/webauthn/register/options`
    - `POST /api/v1/auth/webauthn/register/verify`
    - `POST /api/v1/auth/webauthn/authenticate/options`
    - `POST /api/v1/auth/webauthn/authenticate/verify`
- `composer test:openapi-contract` fails
  - current runtime has no `GET /api/v1/auth/me/sessions`
  - `OpenApiContractTest` still asserts obsolete error code `RATE_LIMITED`
- `composer check:contracts` required snapshot refresh
  - refreshed in this branch via `contracts/openapi-v1.sha256`
- `composer check:openapi-docs-coherence` passes

## Runtime gaps to implement

### 1. Remove obsolete v1 WebAuthn endpoints

Current runtime still exposes:

- `/api/v1/auth/webauthn/register/options`
- `/api/v1/auth/webauthn/register/verify`
- `/api/v1/auth/webauthn/authenticate/options`
- `/api/v1/auth/webauthn/authenticate/verify`

Required action:

- remove them from v1 routing, auth allowlists, and v1-focused tests
- if WebAuthn remains a product requirement, move it to a future contract version instead of keeping it on v1

### 2. Add interactive session management endpoints

New v1 requires:

- `GET /api/v1/auth/me/sessions`
- `POST /api/v1/auth/me/sessions/{session_id}/revoke`
- `POST /api/v1/auth/me/sessions/revoke-others`

Current `UserAccessTokenService` stores active interactive tokens keyed by `user_id|client_id`, so this is implementable without a new persistence layer, but it needs:

- stable session identifiers
- current-session detection
- self-revoke conflict handling (`409 STATE_CONFLICT`)
- revoke-all-others semantics

### 3. Add batch asset purge endpoint

New v1 adds `/assets/purge`.

Current runtime only exposes per-asset purge under:

- `/api/v1/assets/{uuid}/purge`
- `/api/v1/assets/{uuid}/purge/preview`

Required action:

- implement batch purge semantics or explicitly postpone to a later version in specs

### 4. Align app feature responses with the new schema

`AppFeaturesResponse` now requires explanation payloads.

Current runtime returns:

- `app_feature_enabled`
- `feature_governance`
- `core_v1_global_features`

Missing:

- `app_feature_explanations`

Likely adjacent gap:

- user feature endpoints may also need explanation payload alignment if specs added the same shape on the auth self-service side

### 5. Update OpenAPI contract tests

Current tests are pinned to the previous spec shape and need updates for:

- removal of `RATE_LIMITED` from expected error codes
- secured operation matrix now including `/auth/me/sessions`
- removal of WebAuthn v1 assumptions

## Suggested implementation order

1. Remove WebAuthn from v1 runtime and tests
2. Implement `/auth/me/sessions*`
3. Align `AppFeaturesResponse` explanations
4. Implement `/assets/purge`
5. Re-run full release checks and re-audit remaining schema drifts

## Files likely impacted next

- `src/Controller/Api/AuthController.php`
- `src/Auth/UserAccessTokenService.php`
- `src/Controller/Api/AppController.php`
- `src/Controller/Api/AssetController.php` or `src/Controller/Api/WorkflowController.php`
- `config/packages/security.yaml`
- `src/Security/ApiBearerAuthenticator.php`
- `tests/Functional/OpenApiContractTest.php`
- `tests/Functional/ApiAuthFlowTest.php`
