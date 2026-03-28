# Docker Compose Dev Environment

> Statut : non normatif.

## Stack

- `app`: `fullfrontend/php-fpm:latest`
- `ingest-cron`: worker dédié ingest (polling + déplacement fichiers), séparé de `app`
- `caddy`: backend HTTP interne pour Traefik, vers `app` (upload max `10GB`)
- `composer`: même image, profil `tools`
- `database`: `postgres:16-alpine`

Le code du repo est monté dans `/var/www/html`.
La configuration Docker est centralisée dans `/docker` (ex: `/docker/Caddyfile`, `/docker/db-data`).
Le dossier de polling local est `/docker/RETAIA/INBOX` (monté dans le conteneur via `/var/local/RETAIA`).
Les dossiers locaux disponibles sont aussi `/docker/RETAIA/ARCHIVE` et `/docker/RETAIA/REJECTS`.

Variables storage utilisées par le compose dev :

- `APP_STORAGE_IDS=nas-main`
- `APP_STORAGE_DEFAULT_ID=nas-main`
- `APP_STORAGE_NAS_MAIN_DRIVER=local`
- `APP_STORAGE_NAS_MAIN_ROOT_PATH=/var/local/RETAIA`
- `APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX`

La convention générale pour plusieurs storages est :

- `APP_STORAGE_IDS=<id1>,<id2>,...`
- `APP_STORAGE_DEFAULT_ID=<id>`
- `APP_STORAGE_<ID>_DRIVER`
- `APP_STORAGE_<ID>_WATCH_DIRECTORY`
- si `DRIVER=local` :
  - `APP_STORAGE_<ID>_ROOT_PATH`
- si `DRIVER=smb` :
  - `APP_STORAGE_<ID>_HOST`
  - `APP_STORAGE_<ID>_SHARE`
  - `APP_STORAGE_<ID>_USERNAME`
  - `APP_STORAGE_<ID>_PASSWORD`
  - optionnel : `APP_STORAGE_<ID>_ROOT_PATH`
  - optionnel : `APP_STORAGE_<ID>_WORKGROUP`
  - optionnel : `APP_STORAGE_<ID>_TIMEOUT_SECONDS`
  - optionnel : `APP_STORAGE_<ID>_SMB_VERSION_MIN`
  - optionnel : `APP_STORAGE_<ID>_SMB_VERSION_MAX`
- optionnel : `APP_STORAGE_<ID>_INGEST_ENABLED`
- optionnel : `APP_STORAGE_<ID>_MANAGED_DIRECTORIES`

## Démarrage

Pre-requis:

- Traefik local actif (ou équivalent) sur le réseau Docker externe `web`
- DNS local/hosts pour `api.retaia.test`

```bash
docker compose up -d
```

Le déplacement de fichiers ingest est exécuté par `ingest-cron`, donc il ne bloque pas les workers API/UI du service `app`.

## Commandes utiles

Installer les dépendances :

```bash
docker compose run --rm composer install --no-interaction --prefer-dist --optimize-autoloader
```

Exécuter les tests :

```bash
docker compose run --rm app composer test
```

Exécuter les tests + coverage gate (80%) :

```bash
docker compose run --rm app composer test:quality
```

Si l'image n'a pas de driver coverage actif, activer `pcov` dans le conteneur :

```bash
docker compose run --rm app sh -lc "pecl install pcov && echo 'extension=pcov.so' >> /usr/local/etc/php/conf.d/pcov.ini"
```

Appliquer les migrations Doctrine :

```bash
docker compose run --rm app php bin/console doctrine:migrations:migrate --no-interaction
```

Ouvrir un shell dans le conteneur app :

```bash
docker compose exec app sh
```

Smoke test API (via Traefik) :

```bash
curl -k https://api.retaia.test/api/v1/openapi
```

Swagger UI:

```bash
open https://api.retaia.test/api/v1/docs
```

Lancer un poll manuel des fichiers à ingérer :

```bash
docker compose exec app php bin/console app:ingest:poll --limit=50
docker compose exec app php bin/console app:ingest:poll --json
docker compose exec app php bin/console app:ingest:enqueue-stable --limit=50
docker compose exec app php bin/console app:ingest:apply-outbox --limit=50
docker compose exec app php bin/console app:ingest:cron-tick --poll-limit=50 --enqueue-limit=50 --apply-limit=100
```

Configurer un cron (hôte ou conteneur) pour exécuter un tick par minute :

```bash
* * * * * cd /var/www/html && php bin/console app:ingest:cron-tick --no-interaction >> var/log/ingest-cron.log 2>&1
```

Note: en Docker local, le service `ingest-cron` intègre déjà cette exécution périodique; ce cron manuel est utile hors Docker.

Arrêter l'environnement :

```bash
docker compose down
```

Supprimer aussi les données PostgreSQL locales (reset complet) :

```bash
docker compose down
rm -rf docker/db-data
mkdir -p docker/db-data
```

## API staging locale (tests clients)

Stack isolée dédiée aux tests clients :

```bash
composer staging:up
composer staging:migrate
```

Smoke test :

```bash
composer staging:health
```

Arrêt :

```bash
composer staging:down
```
