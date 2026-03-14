# Observability Runbook (Core local)

> Statut : non normatif.
> Triage fonctionnel global : `retaia-docs/ops/OBSERVABILITY-TRIAGE.md`.

## Objectif

Documenter les commandes et details d'implementation observabilite propres a `retaia-core`.

## Evenements et stockage locaux

- table de metriques persistantes: `ops_metric_event`
- cles emises localement:
  - `api.error.<CODE>`
  - `lock.active.detected`
  - `lock.active.detected.asset_move_lock`
  - `lock.active.detected.asset_purge_lock`
  - `lock.acquire.success.asset_move_lock`
  - `lock.acquire.failed.asset_move_lock`
  - `lock.release.asset_move_lock`
  - `lock.acquire.success.asset_purge_lock`
  - `lock.acquire.failed.asset_purge_lock`
  - `lock.release.asset_purge_lock`

## Commandes locales

Alerte conflits/locks:

```bash
php bin/console app:alerts:state-conflicts --window-minutes=15 --state-conflicts-threshold=20 --lock-failed-threshold=10 --active-locks-threshold=200 --stale-locks-threshold=0 --stale-lock-minutes=30
```

Recovery stale locks:

```bash
php bin/console app:locks:watchdog-recover --stale-lock-minutes=30
php bin/console app:locks:watchdog-recover --stale-lock-minutes=30 --dry-run
```

Readiness et probe:

```bash
php bin/console app:ops:readiness-check
php bin/console app:sentry:probe
```

## Details d'implementation Core

- le probe Sentry prod attend un host `sentry.fullfrontend.be`
- le watchdog relache les locks `asset_move_lock` et `asset_purge_lock` depassant le seuil configure
