# Ops Readiness Checklist (V1)

> Statut : non normatif. Les règles métier restent définies dans `specs/`.

## Commande de diagnostic

Lancer avant release (et après migration infra):

```bash
php bin/console app:ops:readiness-check
```

La commande vérifie:

- connectivité base de données (`SELECT 1`)
- présence + droits d’écriture sur `INBOX`, `ARCHIVE`, `REJECTS` (racine ingest)
- cohérence `SENTRY_DSN` en production (`sentry.fullfrontend.be`)

## Checklist release V1

1. Base de données
   - migrations appliquées
   - backup / plan rollback validés
2. Ingest
   - répertoires `docker/RETAIA/INBOX`, `docker/RETAIA/ARCHIVE`, `docker/RETAIA/REJECTS` présents et persistants
   - cron `app:ingest:cron-tick` actif
3. Observabilité
   - sonde `app:sentry:probe` validée en prod
   - alerte `app:alerts:state-conflicts` branchée sur monitoring
   - watchdog locks planifié: `app:locks:watchdog-recover --stale-lock-minutes=30`
4. Sécurité
   - headers API actifs
   - cookies secure en prod HTTPS
5. Validation finale
   - `composer test:quality`
   - `php bin/console app:ops:readiness-check`
