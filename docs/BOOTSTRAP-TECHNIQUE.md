# Bootstrap Technique Minimal

> Statut : non normatif.
> Les règles produit restent dans `specs/`.

## Objectif de ce bootstrap

Mettre en place un socle exécutable pour démarrer l’implémentation :

- structure API Symfony minimale
- gestion utilisateur locale (login/logout/lost password)
- persistance PostgreSQL via Doctrine ORM
- tests unitaires avec PHPUnit
- tests comportementaux avec Behat

## Ce qui est en place

- endpoint santé :
  - `GET /api/v1/health`

- endpoints auth :
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
  - `GET /api/v1/auth/me`
  - `POST /api/v1/auth/lost-password/request`
  - `POST /api/v1/auth/lost-password/reset`

- persistance utilisateurs :
  - table PostgreSQL `app_user` (Doctrine ORM)
  - migration : `migrations/Version20260209223000.php`

- stockage local tokens reset :
  - `var/data/password_reset_tokens.json`

- utilisateur initial (créé par migration) :
  - email : `admin@retaia.local`
  - password : `change-me`

## Lancer les tests

- PHPUnit :
  - `vendor/bin/phpunit`

- Behat :
  - `vendor/bin/behat`

## Base de données

- développement : PostgreSQL (`DATABASE_URL` dans `.env`)
- tests : mémoire (`DATABASE_URL=sqlite:///:memory:` dans `.env.test`)

## Points d’attention

- La persistance utilisateur applicative est Doctrine + PostgreSQL.
- Les tests unitaires/Behat restent en mémoire via un repository de test dédié.
- En environnement non `prod`, `lost-password/request` retourne aussi un `reset_token` pour faciliter les tests.
- Changer les secrets et mots de passe par défaut avant tout usage réel.
