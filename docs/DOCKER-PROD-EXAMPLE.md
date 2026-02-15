# Docker Prod Example

> Statut: exemple non normatif.

Ce document montre un premier moyen de deploiement via image Docker pour Retaia Core.
Le fichier `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docker-compose.prod.yaml` est un exemple de base a adapter.

## Fichiers ajoutes

- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/Dockerfile.prod`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docker-compose.prod.yaml`
- `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docker/Caddyfile.prod.example`

## Base image

Le build prod part de la meme base que le dev:

- `fullfrontend/php-fpm:latest`

Tu peux la surcharger avec `RETAIA_BASE_IMAGE`.

## Flag V1 obligatoire pour build

Le build d'image prod est bloque tant que le flag V1 n'est pas positionne:

- `RETAIA_BUILD_V1_READY=1`

Sans ce flag, `Dockerfile.prod` echoue volontairement.

Build standard:

```bash
RETAIA_BUILD_V1_READY=1 composer prod:image:build
```

Build manuel equivalent:

```bash
RETAIA_BUILD_V1_READY=1 docker compose -f docker-compose.prod.yaml build app-prod
```

## Lancement exemple

```bash
RETAIA_BUILD_V1_READY=1 docker compose -f docker-compose.prod.yaml build app-prod
docker compose -f docker-compose.prod.yaml up -d app-prod ingest-cron-prod caddy-prod database-prod
```

La stack exemple inclut:

- `app-prod` (PHP-FPM)
- `ingest-cron-prod` (polling ingest toutes les 60s)
- `caddy-prod` (sert API + UI statique)
- `database-prod` (PostgreSQL)

## Delivery UI (compatible CI)

Le compose prod sert l'UI statique depuis:

- `${RETAIA_UI_DIST_DIR:-./ui/dist}`

Par defaut, le dossier attendu est donc `./ui/dist` dans ce repository.
Cela evite la dependance a un dossier parent (`../...`) qui n'existe pas en CI.

### CI (exemple)

1. checkout du repo UI dans `./ui`
2. build UI pour produire `./ui/dist`
3. lancer le build/deploiement compose prod

Exemple GitHub Actions (principe):

```yaml
- uses: actions/checkout@v4
- uses: actions/checkout@v4
  with:
    repository: Retaia/retaia-ui
    path: ui
- run: npm ci && npm run build
  working-directory: ui
- run: RETAIA_BUILD_V1_READY=1 docker compose -f docker-compose.prod.yaml up -d --build
```

### Local avec UI voisine

Si ton UI est dans un dossier voisin, tu peux garder ce layout en overridant:

```bash
export RETAIA_UI_DIST_DIR=../retaia-ui/dist
docker compose -f docker-compose.prod.yaml up -d caddy-prod
```

## Important

- Ce compose prod est un exemple redistribuable pour demarrage rapide.
- Adapte secrets, hostnames, ports, volumes et politique de backup avant usage reel.
