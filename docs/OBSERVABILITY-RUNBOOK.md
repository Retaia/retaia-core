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

## Procédure de triage rapide

1. Vérifier `/api/v1/health` et l’état base PostgreSQL.
2. Corréler les erreurs API avec les événements structurés sur la même fenêtre temporelle.
3. Pour les conflits jobs, regrouper par `job_id` puis vérifier `agent_id` et fréquence.
4. Pour les incidents auth, vérifier les spikes `auth.login.throttled` et les codes API `429`.
5. En cas de correction, passer par PR avec tests de non-régression.

