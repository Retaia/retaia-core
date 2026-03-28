# Docker Prod Example

> Statut: exemple non normatif.

Ce document decrit le deploiement recommande en production: images GHCR (`core` + `ui`) derriere `caddy`.
Le fichier `/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/docker-compose.prod.yaml` est la base de deploiement.

## Profile recommande: NAS + agents workstations

Quand les agents tournent sur des workstations (hors reseau Docker du NAS):

- exposer une seule entree LAN (ex: `http://192.168.0.14:8080`)
- conserver `core` non expose directement sur host ports
- router `/api/*` via Caddy vers `core:9000` (`php_fastcgi`)
- router le reste vers `ui:80`
- configurer les agents workstations vers:
  - `http://192.168.0.14:8080/api/v1`

## Services du compose prod

- `core`: API PHP-FPM (image GHCR `ghcr.io/retaia/retaia-core:<tag>`)
- `ingest-cron`: polling ingest toutes les 60s (meme image que `core`)
- `ui`: frontend (image GHCR `ghcr.io/retaia/retaia-ui:<tag>`)
- `caddy`: reverse proxy LAN unique (API + UI)
- `db`: PostgreSQL

## Variables importantes

- `RETAIA_CORE_IMAGE`: tag image Core (ex: `ghcr.io/retaia/retaia-core:v1.0.0`)
- `RETAIA_UI_IMAGE`: tag image UI (ex: `ghcr.io/retaia/retaia-ui:v1.0.0`)
- `RETAIA_INGEST_HOST_DIR`: chemin hote (NAS/local) monte dans le conteneur sur `/var/local/RETAIA`
- `DEFAULT_URI`: URL publique canonique de l'API (generation d'URLs hors contexte HTTP)
- `RETAIA_PROD_HTTP_PORT`: port expose par Caddy (defaut `8080`)

Variables storage metier :

- `APP_STORAGE_IDS`: liste des storages declares, separes par des virgules
- `APP_STORAGE_DEFAULT_ID`: storage par defaut
- `APP_STORAGE_<ID>_DRIVER`: backend du storage (`local` ou `smb`)
- `APP_STORAGE_<ID>_WATCH_DIRECTORY`: dossier de polling relatif a la racine
- si `DRIVER=local` :
  - `APP_STORAGE_<ID>_ROOT_PATH`: racine du storage dans le conteneur
- si `DRIVER=smb` :
  - `APP_STORAGE_<ID>_HOST`
  - `APP_STORAGE_<ID>_SHARE`
  - `APP_STORAGE_<ID>_USERNAME`
  - `APP_STORAGE_<ID>_PASSWORD`
  - optionnel : `APP_STORAGE_<ID>_ROOT_PATH` (prefixe dans le share)
  - optionnel : `APP_STORAGE_<ID>_WORKGROUP`
  - optionnel : `APP_STORAGE_<ID>_TIMEOUT_SECONDS`
  - optionnel : `APP_STORAGE_<ID>_SMB_VERSION_MIN`
  - optionnel : `APP_STORAGE_<ID>_SMB_VERSION_MAX`
- `APP_STORAGE_<ID>_INGEST_ENABLED`: optionnel, `1` ou `0`
- `APP_STORAGE_<ID>_MANAGED_DIRECTORIES`: optionnel, liste de repertoires geres

Exemple simple:

```bash
export APP_STORAGE_IDS=nas-main
export APP_STORAGE_DEFAULT_ID=nas-main
export APP_STORAGE_NAS_MAIN_DRIVER=local
export APP_STORAGE_NAS_MAIN_ROOT_PATH=/var/local/RETAIA
export APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX
```

Exemple SMB :

```bash
export APP_STORAGE_IDS=nas-main
export APP_STORAGE_DEFAULT_ID=nas-main
export APP_STORAGE_NAS_MAIN_DRIVER=smb
export APP_STORAGE_NAS_MAIN_HOST=fileserver.local
export APP_STORAGE_NAS_MAIN_SHARE=media
export APP_STORAGE_NAS_MAIN_ROOT_PATH=retaia
export APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX
export APP_STORAGE_NAS_MAIN_USERNAME=retaia
export APP_STORAGE_NAS_MAIN_PASSWORD=change-me
export APP_STORAGE_NAS_MAIN_WORKGROUP=WORKGROUP
export APP_STORAGE_NAS_MAIN_TIMEOUT_SECONDS=20
export APP_STORAGE_NAS_MAIN_SMB_VERSION_MIN=SMB2
export APP_STORAGE_NAS_MAIN_SMB_VERSION_MAX=SMB3_11
```

Structure attendue cote hote dans `RETAIA_INGEST_HOST_DIR`:

- `INBOX/`
- `ARCHIVE/`
- `REJECTS/`

## Deploiement GHCR (recommande)

```bash
export RETAIA_CORE_IMAGE=ghcr.io/retaia/retaia-core:v1.0.0
export RETAIA_UI_IMAGE=ghcr.io/retaia/retaia-ui:v1.0.0
export RETAIA_INGEST_HOST_DIR=/volume1/retaia/ingest
export DEFAULT_URI=http://192.168.0.14:8080

docker compose -f docker-compose.prod.yaml pull
docker compose -f docker-compose.prod.yaml up -d core ingest-cron ui caddy db
```

Migration DB:

```bash
docker compose -f docker-compose.prod.yaml exec core php bin/console doctrine:migrations:migrate --no-interaction
```

## Build local de l'image Core (optionnel)

Pour pre-build localement (debug, validation, image privee):

```bash
RETAIA_BUILD_V1_READY=1 RETAIA_PROD_IMAGE=retaia-core:prod composer prod:image:build
```

Puis deploiement avec image locale:

```bash
export RETAIA_CORE_IMAGE=retaia-core:prod
docker compose -f docker-compose.prod.yaml up -d core ingest-cron ui caddy db
```

## UI updater (hors scope compose)

Si pas d'URL de ping applicative:

1. l'UI ne suit jamais `master` directement
2. l'UI telecharge la derniere release taggee
3. verification checksum/signature avant activation

Option disponible cote Core:

```bash
php bin/console app:release:write-ui-manifest \
  --ui-version=1.0.0 \
  --asset-url=https://downloads.example.com/retaia-ui-1.0.0.zip \
  --sha256=<sha256_64_hex> \
  --notes-url=https://example.com/releases/1.0.0
```

Sortie par defaut: `public/releases/latest.json`.

## Important

- Ce compose prod est un exemple redistribuable pour demarrage rapide.
- Adapte secrets, hostnames, ports, volumes et politique de backup avant usage reel.
- Pour un deploiement NAS + agents workstations, utiliser une URL LAN unique (`http://192.168.0.14:8080`) pour UI et API (`/api/v1`).
