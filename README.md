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

1. Install dependencies:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
```

2. Configure env:

- Copy `.env` values as needed (especially `DATABASE_URL`).

3. Run migrations:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

4. Start app (example):

```bash
symfony server:start
```

## Tests

Run full test suite:

```bash
composer test
```

Useful checks:

```bash
php bin/console lint:yaml config
php bin/console lint:container
composer audit --no-interaction
```

DX shortcuts:

```bash
make test
make qa
make ci-local
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

## Additional Docs

- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/AGENT.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/DEVELOPMENT-BEST-PRACTICES.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/BOOTSTRAP-TECHNIQUE.md`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docs/AUTH-OPS-RUNBOOK.md`
