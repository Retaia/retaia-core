# Bootstrap Technique Minimal

> Statut : non normatif.
> Les règles produit restent dans `specs/`.

## Objectif de ce bootstrap

Mettre en place un socle exécutable pour démarrer l’implémentation :

- structure API Symfony minimale
- gestion utilisateur locale (login/logout/lost password)
- persistance PostgreSQL via Doctrine ORM
- authentification session via Security (authenticator Symfony)
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
  - `POST /api/v1/auth/verify-email/request`
  - `POST /api/v1/auth/verify-email/confirm`

- flux auth :
  - login/logout gérés par le firewall Security
  - réponses JSON custom sur succès/échec
  - login refusé (`403 EMAIL_NOT_VERIFIED`) si le compte n’a pas d’email vérifié
  - throttling login activé (`5` tentatives / `15` minutes)
  - réponse `429` explicite en cas de trop nombreuses tentatives de login
  - logs structurés d’auth (login success/fail/throttled, logout, reset request/reset done)

- persistance utilisateurs :
  - table PostgreSQL `app_user` (Doctrine ORM)
  - migration : `migrations/Version20260209223000.php`

- persistance tokens reset :
  - table PostgreSQL `password_reset_token` (Doctrine ORM)
  - migration : `migrations/Version20260209235500.php`
  - cleanup CLI : `php bin/console app:password-reset:cleanup`

- utilisateur initial (créé par migration) :
  - email : `admin@retaia.local`
  - password : `change-me`

## Lancer les tests

- PHPUnit :
  - `vendor/bin/phpunit`
  - couvre explicitement : throttling login, reset token expiré, logout non authentifié

- Behat :
  - `vendor/bin/behat`
  - inclut un scénario d’expiration du token de reset en mémoire

- Fixtures de test Faker/AliceBundle :
  - `fixtures/test/users.yaml`

## Base de données

- développement : PostgreSQL (`DATABASE_URL` dans `.env`)
- tests : mémoire (`DATABASE_URL=sqlite:///:memory:` dans `.env.test`)

## Points d’attention

- La persistance utilisateur applicative est Doctrine + PostgreSQL.
- Les tests unitaires/Behat restent en mémoire via un repository de test dédié.
- Les tests fonctionnels Doctrine chargent des fixtures via AliceBundle + Faker.
- En environnement non `prod`, `lost-password/request` retourne aussi un `reset_token` pour faciliter les tests.
- En environnement non `prod`, `verify-email/request` retourne aussi un `verification_token` pour faciliter les tests.
- `lost-password/reset` applique une policy configurable (longueur + complexité).
- Les logs auth n’incluent ni mot de passe ni token brut (email hashé).
- Changer les secrets et mots de passe par défaut avant tout usage réel.

## Paramètres configurables

- `app.password_reset_ttl_seconds` (défaut: `3600`)
- `app.email_verification_ttl_seconds` (défaut: `86400`)
- `app.email_verification.secret` (défaut: `%kernel.secret%`)
- `app.password_policy.min_length` (défaut: `12`)
- `app.password_policy.require_mixed_case` (défaut: `true`)
- `app.password_policy.require_number` (défaut: `true`)
- `app.password_policy.require_special` (défaut: `true`)
