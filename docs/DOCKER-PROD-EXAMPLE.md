# Docker Prod Example

> Statut: exemple non normatif.

Ce document montre un premier moyen de deploiement via image Docker pour Retaia Core.
Le fichier `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docker-compose.prod.yaml` est un exemple de base a adapter.

Variables importantes pour un deploiement NAS:

- `RETAIA_INGEST_HOST_DIR`: chemin hote (NAS/local) monte dans le conteneur sur `/var/local/RETAIA`
- `DEFAULT_URI`: URL publique canonique de l'API (utilisee pour generation d'URLs hors contexte HTTP)

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

Exemple NAS (bind mount explicite):

```bash
export RETAIA_INGEST_HOST_DIR=/volume1/retaia/ingest
export DEFAULT_URI=https://api.retaia.example
docker compose -f docker-compose.prod.yaml up -d app-prod ingest-cron-prod caddy-prod database-prod
```

Structure attendue cote hote dans `RETAIA_INGEST_HOST_DIR`:

- `INBOX/`
- `ARCHIVE/`
- `REJECTS/`

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

## Mise a jour UI (sans URL de ping)

Si aucune URL de ping/version n'est encore disponible, utiliser un mode simple et deterministe:

1. l'UI ne suit jamais `master` directement
2. l'UI telecharge la derniere release taggee (artefact officiel)
3. verification checksum/signature avant activation

## Manifeste de ping UI (option implementee)

Le Core fournit une commande pour generer un manifeste statique JSON (servi ensuite par Caddy/CDN):

```bash
php bin/console app:release:write-ui-manifest \
  --ui-version=1.0.0 \
  --asset-url=https://downloads.example.com/retaia-ui-1.0.0.zip \
  --sha256=<sha256_64_hex> \
  --notes-url=https://example.com/releases/1.0.0
```

Sortie par defaut:

- `public/releases/latest.json`

Tu peux surcharger le chemin:

```bash
php bin/console app:release:write-ui-manifest ... --output=public/releases/latest.json
```

Pattern recommande:

1. pipeline release UI publie l'artefact
2. pipeline ecrit/maj `public/releases/latest.json`
3. client UI ping ce manifeste et telecharge l'asset reference

Auto-update CI disponible:

- `scripts/auto-update-ui-release-manifest.sh`
- workflow: `.github/workflows/ui-release-auto-update.yml`

Quand une URL de ping applicative dediee sera disponible, ce mode pourra evoluer vers:

- ping version/manifeste
- comparaison de version locale
- download de la derniere release stable uniquement

## Important

- Ce compose prod est un exemple redistribuable pour demarrage rapide.
- Adapte secrets, hostnames, ports, volumes et politique de backup avant usage reel.
