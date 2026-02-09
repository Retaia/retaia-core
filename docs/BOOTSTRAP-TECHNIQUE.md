# Bootstrap Technique Minimal

> Statut : non normatif.
> Les règles produit restent dans `specs/`.

## Objectif de ce bootstrap

Mettre en place un socle exécutable pour démarrer l’implémentation :

- structure API Symfony minimale
- gestion utilisateur locale (login/logout/lost password)
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

- stockage local bootstrap :
  - utilisateurs : `var/data/users.json`
  - tokens reset : `var/data/password_reset_tokens.json`

- utilisateur initial (créé automatiquement si stockage absent) :
  - email : `admin@retaia.local`
  - password : `change-me`

## Lancer les tests

- PHPUnit :
  - `vendor/bin/phpunit`

- Behat :
  - `vendor/bin/behat`

## Points d’attention

- Le stockage utilisateur actuel est un bootstrap local (JSON) pour démarrer vite.
- À remplacer par une persistance applicative robuste avant mise en production.
- En environnement non `prod`, `lost-password/request` retourne aussi un `reset_token` pour faciliter les tests.
- Changer les secrets et mots de passe par défaut avant tout usage réel.

