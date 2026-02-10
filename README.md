# Retaia Core

Core API repository for the Retaia project.

## Source Of Truth

- Product rules and behavior are defined in `/specs` (submodule).
- Local docs in `/docs` are implementation guides only.

## Tech Stack

- PHP 8.4+
- Symfony 7.4
- Doctrine ORM + Migrations
- PostgreSQL (dev/prod)
- PHPUnit + Behat
- Faker + AliceBundle (test fixtures)

## Quick Start

0. Coverage driver for dev hooks/tests (`pcov`):

```bash
pecl install pcov
echo "extension=pcov.so" >> /opt/homebrew/etc/php/8.5/php.ini
php -m | grep pcov
```

1. Install dependencies:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
```

2. Configure env:

- Copy `.env` values as needed (especially `DATABASE_URL`).
- For reverse proxy / auth hardening, configure:
  - `SYMFONY_TRUSTED_PROXIES`
  - `APP_AUTH_LOST_PASSWORD_LIMIT`, `APP_AUTH_LOST_PASSWORD_INTERVAL`
  - `APP_AUTH_VERIFY_EMAIL_LIMIT`, `APP_AUTH_VERIFY_EMAIL_INTERVAL`

3. Run migrations:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

4. Start app (example):

```bash
symfony server:start
```

## Ingest Polling (Bootstrap)

- Config path via env: `APP_INGEST_WATCH_PATH` (default: `./docker/RETAIA/INBOX` in local env).
- Pipeline commands:

```bash
php bin/console app:ingest:poll --limit=100
php bin/console app:ingest:poll --json
php bin/console app:ingest:enqueue-stable --limit=100
php bin/console app:ingest:apply-outbox --limit=100
php bin/console app:ingest:cron-tick --poll-limit=100 --enqueue-limit=100 --apply-limit=200
```

- Recommended scheduler: cron runs a single tick every minute.

```bash
* * * * * cd /var/www/html && php bin/console app:ingest:cron-tick --no-interaction >> var/log/ingest-cron.log 2>&1
```

In Docker dev, run the dedicated `ingest-cron` service so file polling/moves stay isolated from API/UI workers.

## Tests

Run full test suite:

```bash
composer test
```

Run coverage and enforce threshold (80%):

```bash
composer test:quality
```

Useful checks:

```bash
php bin/console lint:yaml config
php bin/console lint:container
composer audit --no-interaction
php bin/console app:sentry:probe
php bin/console app:alerts:state-conflicts --window-minutes=15 --state-conflicts-threshold=20 --lock-failed-threshold=10
php bin/console app:sentry:probe
```

DX shortcuts:

```bash
make test
make qa
make ci-local
```

## V1 Safety Rules (Implemented)

- `Idempotency-Key` is enforced on critical endpoints, including:
  - `POST /api/v1/assets/{uuid}/decision`
  - `POST /api/v1/assets/{uuid}/reprocess`
  - `POST /api/v1/batches/moves`
  - `POST /api/v1/decisions/apply`
  - `POST /api/v1/assets/{uuid}/purge`
  - `POST /api/v1/jobs/{job_id}/submit`
  - `POST /api/v1/jobs/{job_id}/fail`
- Operation locks are enforced for move/purge concurrency safety (`asset_operation_lock` table).
- Job claimability is blocked when an asset is `MOVE_QUEUED`, `PURGED`, or under active operation lock.
- Ingest polling ignores symlinks and unsafe paths.
- Ingest polling tolerates transient file races (rename/delete during scan), permission issues, and large filename collisions on outbox moves.
- API localization supports `Accept-Language` (`en`, `fr`) with fallback to `en`.

## Git Hooks (Husky)

This repository supports Husky hooks for local commit quality gates:

- `pre-commit`: runs `composer test:quality` (PHPUnit with coverage + Behat + 80% gate)
- `commit-msg`: enforces Conventional Commits via `commitlint`

Coverage prerequisite for `pre-commit`:

- install/enable a PHP coverage driver locally (`xdebug` or `pcov`)
- recommended on this project: `pcov`

Setup once locally:

```bash
npm install
npm run prepare
```

## Docker Dev

Use docker-compose setup documented in:

- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/DOCKER-DEVELOPMENT.md`

## Authentication Endpoints

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/lost-password/request`
- `POST /api/v1/auth/lost-password/reset`
- `POST /api/v1/auth/verify-email/request`
- `POST /api/v1/auth/verify-email/confirm`

## CI

GitHub Actions workflow:

- `lint`
- `test`
- `security-audit`

See details in:

- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/GITHUB-WORKFLOWS.md`

## Sentry (Prod Probe)

- Configure `SENTRY_DSN` in production with host `sentry.fullfrontend.be`.
- Probe command:

```bash
php bin/console app:sentry:probe
```

- Non-prod is skipped by default (use `--allow-non-prod` only for explicit checks).

## Branching & PR Policy

- Never commit or push directly to `master`.
- Always work from a dedicated feature branch (recommended: `codex/<feature>`).
- All changes must go through a Pull Request.
- Conventional Commit messages are required.

## Additional Docs

- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/AGENT.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/DEVELOPMENT-BEST-PRACTICES.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/BOOTSTRAP-TECHNIQUE.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/AUTH-OPS-RUNBOOK.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/OBSERVABILITY-RUNBOOK.md`
