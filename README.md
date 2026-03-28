# Retaia Core

Retaia Core is the backend API for the Retaia platform.
It manages authentication, policy/runtime feature governance, asset lifecycle, job orchestration, ingest polling, and operational safety checks.

## Table of Contents

- [What This Repo Contains](#what-this-repo-contains)
- [Source of Truth](#source-of-truth)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Docker](#docker)
- [Testing](#testing)
- [Authentication API](#authentication-api)
- [CI](#ci)
- [Contributing](#contributing)
- [Documentation](#documentation)
- [License](#license)

## What This Repo Contains

- Versioned API under `/api/v1`
- Asset workflow orchestration (state machine + jobs)
- Ingest polling pipeline (`poll`, `enqueue-stable`, `apply-outbox`, `cron-tick`)
- Auth flows (login/logout, reset password, verify email, 2FA)
- Runtime operational commands (readiness, sentry probe, alerts, lock watchdog)
- OpenAPI contract drift checks against specs SSOT

## Source of Truth

- Product and behavior rules live in `specs/` (git submodule: `retaia-docs`)
- Any normative behavior change must be made in specs first
- Local `docs/` are implementation and operations guides (non-normative)
- Functional runbooks now live in `retaia-docs/ops/`

## Tech Stack

- PHP 8.4+
- Symfony 7.4
- Doctrine ORM + Migrations
- PostgreSQL
- PHPUnit + Behat

## Requirements

- PHP (>= 8.4 recommended)
- Composer 2
- PostgreSQL (for local/runtime DB)
- Node.js + npm (for Husky hooks)
- Optional: `pcov` for local coverage gates

## Quick Start

Par defaut, le demarrage dev se fait avec Docker Compose (`docker-compose.yml`).

1. Lancer la stack dev:

```bash
composer dev:up
```

Pre-requis: un reverse proxy Traefik local doit etre actif sur le reseau Docker externe `web` et router `api.retaia.test`.

2. Installer les dependances PHP:

```bash
docker compose run --rm composer install --no-interaction --prefer-dist --optimize-autoloader
```

3. Appliquer les migrations:

```bash
composer dev:migrate
```

4. Verifier la sante API:

```bash
composer dev:health
```

Docs Swagger:

```text
https://api.retaia.test/api/v1/docs
```

Pour le detail des commandes dev Docker, voir `docs/DOCKER-DEVELOPMENT.md`.

Alternative non-Docker (optionnelle):

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
symfony server:start
```

## Docker

### Development

Use the dev setup documented in `docs/DOCKER-DEVELOPMENT.md`.

### Production (example)

- Example files:
  - `Dockerfile.prod`
  - `docker-compose.prod.yaml`
- Full guide: `docs/DOCKER-PROD-EXAMPLE.md`
- Normative deployment profile (NAS + workstations): `specs/architecture/DEPLOYMENT-TOPOLOGY.md`.

Build is intentionally gated and requires:

```bash
RETAIA_BUILD_V1_READY=1 composer prod:image:build
```

### Staging helpers

```bash
composer staging:up
composer staging:migrate
composer staging:health
```

## Storage Configuration

Business storage is configured with explicit env vars, not JSON.

Required keys:

- `APP_STORAGE_IDS`: comma-separated storage ids
- `APP_STORAGE_DEFAULT_ID`: default storage id when multiple storages exist
- `APP_STORAGE_<ID>_DRIVER`: currently `local`
- `APP_STORAGE_<ID>_ROOT_PATH`: storage root path
- `APP_STORAGE_<ID>_WATCH_DIRECTORY`: relative ingest watch directory inside that storage

Optional keys per storage:

- `APP_STORAGE_<ID>_INGEST_ENABLED`: `1` or `0`
- `APP_STORAGE_<ID>_MANAGED_DIRECTORIES`: comma-separated managed relative directories

Single-storage example:

```dotenv
APP_STORAGE_IDS=nas-main
APP_STORAGE_DEFAULT_ID=nas-main
APP_STORAGE_NAS_MAIN_DRIVER=local
APP_STORAGE_NAS_MAIN_ROOT_PATH=./docker/RETAIA
APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX
```

Multi-storage example:

```dotenv
APP_STORAGE_IDS=nas-main,nas-alt
APP_STORAGE_DEFAULT_ID=nas-main

APP_STORAGE_NAS_MAIN_DRIVER=local
APP_STORAGE_NAS_MAIN_ROOT_PATH=/mnt/retaia-main
APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX

APP_STORAGE_NAS_ALT_DRIVER=local
APP_STORAGE_NAS_ALT_ROOT_PATH=/mnt/retaia-alt
APP_STORAGE_NAS_ALT_WATCH_DIRECTORY=INBOX
APP_STORAGE_NAS_ALT_INGEST_ENABLED=0
```

## Ingest Polling

Main commands:

```bash
php bin/console app:ingest:poll --limit=100
php bin/console app:ingest:enqueue-stable --limit=100
php bin/console app:ingest:apply-outbox --limit=100
php bin/console app:ingest:cron-tick --poll-limit=100 --enqueue-limit=100 --apply-limit=200
```

Recommended scheduler:

```cron
* * * * * cd /var/www/html && php bin/console app:ingest:cron-tick --no-interaction >> var/log/ingest-cron.log 2>&1
```

## Testing

Run unit + Behat:

```bash
composer test
```

Run quality gate (coverage + Behat + threshold):

```bash
composer test:quality
```

Run release preflight:

```bash
composer release:check
```

Useful commands:

```bash
php bin/console lint:yaml config
php bin/console lint:container
composer audit --no-interaction
php bin/console app:ops:readiness-check
php bin/console app:sentry:probe
```

## Git Hooks

Husky hooks are enabled for local quality controls:

- `pre-commit`
  - blocks commits on `master`
  - runs `composer test:quality`
- `commit-msg`
  - enforces Conventional Commits

Setup:

```bash
npm install
npm run prepare
```

## Authentication API

Main endpoints:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/lost-password/request`
- `POST /api/v1/auth/lost-password/reset`
- `POST /api/v1/auth/verify-email/request`
- `POST /api/v1/auth/verify-email/confirm`
- `POST /api/v1/auth/verify-email/admin-confirm`

See specs for full contracts and status/error semantics.

## CI

GitHub Actions includes:

- `lint`
- `test`
- `security-audit`
- `release`
- `docker-image-build`
- `docker-base-image-auto-update`
- `ui-release-auto-update`

Details:

- `docs/GITHUB-WORKFLOWS.md`
- `docs/RELEASE-OPS-RUNBOOK.md`
- `https://github.com/Retaia/retaia-docs/tree/master/ops`

## Contributing

- No direct commits to `master`
- Work on dedicated branches (recommended: `codex/<feature>`)
- Open a PR for every change
- Use Conventional Commits

Start here:

- `CONTRIBUTING.md`
- `docs/DEVELOPMENT-BEST-PRACTICES.md`

## Documentation

Core docs:

- local implementation guides: `docs/*.md`
- functional operations runbooks: `retaia-docs/ops/*.md`
- normative product/API rules: `specs/*`
- `docs/OBSERVABILITY-RUNBOOK.md`
- `docs/OPS-READINESS-CHECKLIST.md`
- `docs/BOOTSTRAP-TECHNIQUE.md`

Normative specs:

- `specs/README.md`
- `specs/api/API-CONTRACTS.md`
- `specs/tests/TEST-PLAN.md`

## License

Current project license metadata is `proprietary` (see `composer.json`).
