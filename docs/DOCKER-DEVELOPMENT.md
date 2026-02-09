# Docker Compose Dev Environment

> Statut : non normatif.

## Stack

- `app`: `fullfrontend/php-fpm:latest`
- `composer`: même image, profil `tools`
- `database`: `postgres:16-alpine`

Le code du repo est monté dans `/var/www/html`.

## Démarrage

```bash
docker compose up -d app database
```

## Commandes utiles

Installer les dépendances :

```bash
docker compose run --rm composer install --no-interaction --prefer-dist --optimize-autoloader
```

Exécuter les tests :

```bash
docker compose run --rm app composer test
```

Appliquer les migrations Doctrine :

```bash
docker compose run --rm app php bin/console doctrine:migrations:migrate --no-interaction
```

Ouvrir un shell dans le conteneur app :

```bash
docker compose exec app sh
```

Arrêter l'environnement :

```bash
docker compose down
```

Supprimer aussi le volume PostgreSQL (reset complet) :

```bash
docker compose down -v
```
