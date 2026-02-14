# Docker Compose Dev Environment

> Statut : non normatif.

## Stack

- `app`: `fullfrontend/php-fpm:latest`
- `ingest-cron`: worker dédié ingest (polling + déplacement fichiers), séparé de `app`
- `caddy`: reverse proxy HTTP local vers `app` (upload max `10GB`)
- `composer`: même image, profil `tools`
- `database`: `postgres:16-alpine`

Le code du repo est monté dans `/var/www/html`.
La configuration Docker est centralisée dans `/docker` (ex: `/docker/Caddyfile`, `/docker/db-data`).
Le dossier de polling local est `/docker/RETAIA/INBOX` (monté dans le conteneur via `/var/local/RETAIA`).
Les dossiers locaux disponibles sont aussi `/docker/RETAIA/ARCHIVE` et `/docker/RETAIA/REJECTS`.

## Démarrage

```bash
docker compose up -d app ingest-cron caddy database
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

Smoke test API (via Caddy) :

```bash
curl -H "Host: api.retaia.test" http://localhost:8080/api/v1/health
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
docker compose -f docker-compose.staging.yml up -d app-staging caddy-staging database-staging
docker compose -f docker-compose.staging.yml exec app-staging php bin/console doctrine:migrations:migrate --no-interaction
```

Smoke test :

```bash
curl -H "Host: api-staging.retaia.test" http://localhost:18081/api/v1/health
```

Arrêt :

```bash
docker compose -f docker-compose.staging.yml down
```

Raccourcis Makefile équivalents :

```bash
make staging-up
make staging-migrate
make staging-health
make staging-down
```
