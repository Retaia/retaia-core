# Release & Exploitation Runbook (Core local)

> Statut: non normatif.
> Procedure fonctionnelle globale: `retaia-docs/ops/RELEASE-OPERATIONS.md`.

## Objectif

Documenter les commandes et choix d'implementation locaux pour release, deployement et exploitation de `retaia-core`.

## 1) Prerequis locaux

Executer sur branche a jour:

```bash
composer release:check
composer audit --no-interaction
```

## 2) Deploiement prod (Docker + GHCR)

Configurer les tags d'images:

```bash
export RETAIA_CORE_IMAGE=ghcr.io/retaia/retaia-core:v1.0.0
export RETAIA_UI_IMAGE=ghcr.io/retaia/retaia-ui:v1.0.0
```

Demarrage stack:

```bash
docker compose -f docker-compose.prod.yaml pull
docker compose -f docker-compose.prod.yaml up -d core ingest-cron ui caddy db
```

Migration DB:

```bash
docker compose -f docker-compose.prod.yaml exec core php bin/console doctrine:migrations:migrate --no-interaction
```

## 3) Verification locale post-deploiement

```bash
curl -sS http://localhost:${RETAIA_PROD_HTTP_PORT:-8080}/api/v1/health
docker compose -f docker-compose.prod.yaml exec core php bin/console app:ops:readiness-check
docker compose -f docker-compose.prod.yaml exec core php bin/console app:sentry:probe
docker compose -f docker-compose.prod.yaml logs --tail=200 ingest-cron
```

## 4) Operations locales

Alerting:

```bash
php bin/console app:alerts:state-conflicts --window-minutes=15 --state-conflicts-threshold=20 --lock-failed-threshold=10 --active-locks-threshold=200 --stale-locks-threshold=0 --stale-lock-minutes=30
```

Recovery:

```bash
php bin/console app:locks:watchdog-recover --stale-lock-minutes=30
```

## 5) Build local image Core

```bash
RETAIA_BUILD_V1_READY=1 RETAIA_PROD_IMAGE=retaia-core:prod composer prod:image:build
export RETAIA_CORE_IMAGE=retaia-core:prod
```

## 6) UI updater local

```bash
php bin/console app:release:write-ui-manifest --ui-version=<v> --asset-url=<url> --sha256=<sha256_64_hex>
```

Cette commande genere `public/releases/latest.json`.
