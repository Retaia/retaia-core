# Ops Readiness Checklist (Core local)

> Statut : non normatif.
> Checklist fonctionnelle globale : `retaia-docs/ops/READINESS-CHECKLIST.md`.

## Commande locale

Lancer avant release et apres migration infra:

```bash
php bin/console app:ops:readiness-check
```

## Ce que la commande valide localement

- connectivite base de donnees (`SELECT 1`)
- presence et droits d'ecriture sur `INBOX`, `ARCHIVE`, `REJECTS`
- coherence `SENTRY_DSN` en production (`sentry.fullfrontend.be`)

## Checks d'implementation a confirmer

1. migrations appliquees
2. scheduler ingest actif:
   - `app:ingest:cron-tick`
3. monitoring branche sur:
   - `app:alerts:state-conflicts`
   - `app:locks:watchdog-recover --stale-lock-minutes=30`
4. headers API actifs et cookies secure en prod HTTPS
5. gate locale verte:
   - `composer test:quality`
