# Observability Runbook

> Statut : non normatif.

## Objectif

Fournir un socle opérationnel minimal pour diagnostiquer les incidents API côté jobs et auth.

## Événements structurés clés

- `jobs.list_claimable`
- `jobs.claim.succeeded`
- `jobs.claim.conflict`
- `jobs.heartbeat.succeeded`
- `jobs.heartbeat.conflict`
- `jobs.submit.succeeded`
- `jobs.submit.conflict`
- `jobs.fail.succeeded`
- `jobs.fail.conflict`
- `auth.login.failed`
- `auth.login.throttled`
- `auth.password_reset.*`
- `auth.email_verification.*`

## Champs à vérifier dans les logs

- `job_id`
- `asset_uuid`
- `agent_id`
- `job_type`
- `status`
- `error_code` (sur fail)
- `retryable` (sur fail)

## Métriques opérationnelles persistées

Table: `ops_metric_event`

Clés émises:

- `api.error.STATE_CONFLICT` (et plus généralement `api.error.<CODE>`)
- `lock.acquire.success.asset_move_lock`
- `lock.acquire.failed.asset_move_lock`
- `lock.release.asset_move_lock`
- `lock.acquire.success.asset_purge_lock`
- `lock.acquire.failed.asset_purge_lock`
- `lock.release.asset_purge_lock`

Commande d'alerte:

```bash
php bin/console app:alerts:state-conflicts --window-minutes=15 --state-conflicts-threshold=20 --lock-failed-threshold=10
```

La commande retourne un code non-zéro si un seuil est dépassé.

## Procédure de triage rapide

1. Vérifier `/api/v1/health` et l’état base PostgreSQL.
2. Corréler les erreurs API avec les événements structurés sur la même fenêtre temporelle.
3. Pour les conflits jobs, regrouper par `job_id` puis vérifier `agent_id` et fréquence.
4. Pour les incidents auth, vérifier les spikes `auth.login.throttled` et les codes API `429`.
5. Pour les incidents de concurrence, lancer `app:alerts:state-conflicts` puis investiguer les clés lock + `STATE_CONFLICT`.
6. En cas de correction, passer par PR avec tests de non-régression.
