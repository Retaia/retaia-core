# Release & Exploitation Runbook (V1)

> Statut: non normatif.  
> Les regles produit restent dans `specs/`.

## Objectif

Donner une procedure unique, actionnable, pour:

- preparer une release V1
- deployer
- verifier en post-deploiement
- operer au quotidien
- rollback si necessaire

## 1) Prerequis release

Executer sur `master` a jour:

```bash
composer release:check
composer audit --no-interaction
```

Resultat attendu:

- checks CI locaux verts
- couverture au-dessus du seuil
- aucune vulnerabilite composer

## 2) Build & deploiement prod (exemple Docker)

Build image API:

```bash
RETAIA_BUILD_V1_READY=1 composer prod:image:build
```

Demarrage stack:

```bash
docker compose -f docker-compose.prod.yaml up -d app-prod ingest-cron-prod caddy-prod database-prod
```

Migration DB:

```bash
docker compose -f docker-compose.prod.yaml exec app-prod php bin/console doctrine:migrations:migrate --no-interaction
```

## 3) Verification post-deploiement

Verifier disponibilite et preconditions runtime:

```bash
curl -sS http://localhost:${RETAIA_PROD_HTTP_PORT:-8080}/api/v1/health
docker compose -f docker-compose.prod.yaml exec app-prod php bin/console app:ops:readiness-check
docker compose -f docker-compose.prod.yaml exec app-prod php bin/console app:sentry:probe
```

Verifier polling ingest:

```bash
docker compose -f docker-compose.prod.yaml logs --tail=200 ingest-cron-prod
```

## 4) Exploitation quotidienne

Alerting conflits/locks:

```bash
php bin/console app:alerts:state-conflicts --window-minutes=15 --state-conflicts-threshold=20 --lock-failed-threshold=10 --active-locks-threshold=200 --stale-locks-threshold=0 --stale-lock-minutes=30
```

Recovery locks stale:

```bash
php bin/console app:locks:watchdog-recover --stale-lock-minutes=30
```

Diagnostic global:

```bash
php bin/console app:ops:readiness-check
```

## 5) Rollback minimal

Conditions de rollback:

- indisponibilite API prolongee
- erreurs metier critiques non recuperables
- corruption fonctionnelle constatee apres release

Procedure:

1. redeployer l'image precedente stable
2. verifier migrations impliquees avant rollback DB
3. restaurer backup DB uniquement si necessaire et valide
4. relancer checks section post-deploiement

## 6) UI updater

Si pas d'URL de ping applicative:

- ne jamais suivre `master` directement cote UI
- telecharger depuis derniere release taggee
- verifier checksum/signature avant activation

Option disponible:

```bash
php bin/console app:release:write-ui-manifest --ui-version=<v> --asset-url=<url> --sha256=<sha256_64_hex>
```

Cette commande genere `public/releases/latest.json` (par defaut) pour un flow de ping manifeste.

## 7) Liens utiles

- `docs/DOCKER-PROD-EXAMPLE.md`
- `docs/OPS-READINESS-CHECKLIST.md`
- `docs/OBSERVABILITY-RUNBOOK.md`
- `docs/AUTH-OPS-RUNBOOK.md`
