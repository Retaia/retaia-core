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
  - `GET /api/v1/openapi`

- endpoints auth :
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
  - `GET /api/v1/auth/me`
  - `POST /api/v1/auth/lost-password/request`
  - `POST /api/v1/auth/lost-password/reset`
  - `POST /api/v1/auth/verify-email/request`
  - `POST /api/v1/auth/verify-email/confirm`
  - `POST /api/v1/auth/verify-email/admin-confirm` (ROLE_ADMIN)

- flux auth :
  - login/logout gérés par le firewall Security
  - réponses JSON custom sur succès/échec
  - messages localisables via `Accept-Language` (`en`, `fr`) avec fallback `en`
  - login refusé (`403 EMAIL_NOT_VERIFIED`) si le compte n’a pas d’email vérifié
  - throttling login activé (`5` tentatives / `15` minutes)
  - throttling lost-password/request activé (`5` tentatives / `15` minutes par email+IP)
  - throttling verify-email/request activé (`3` tentatives / `1` minute par email+IP)
  - réponse `429` explicite en cas de trop nombreuses tentatives de login
  - logs structurés d’auth (login success/fail/throttled, logout, reset request/reset done)

- persistance utilisateurs :
  - table PostgreSQL `app_user` (Doctrine ORM)
  - migration : `migrations/Version20260209223000.php`

- persistance tokens reset :
  - table PostgreSQL `password_reset_token` (Doctrine ORM)
  - migration : `migrations/Version20260209235500.php`
  - cleanup CLI : `php bin/console app:password-reset:cleanup`

- persistance sécurité/concurrence :
  - table idempotency : `idempotency_entry` (endpoints critiques v1)
  - table locks opérationnels : `asset_operation_lock`
  - migration lock : `migrations/Version20260210174000.php`

- bootstrap auth explicite :
  - la migration ne crée plus aucun compte ni secret par défaut
  - en production, les users initiaux et les clients techniques initiaux sont créés uniquement via commande
  - lancer `php bin/console app:bootstrap:initial-auth` sur une base vide pour créer :
    - un admin initial
    - `agent-default`
    - `mcp-default`
  - sans options, le mot de passe admin et les secrets clients sont générés puis affichés une seule fois
  - options utiles :
    - `--admin-email=admin@retaia.local`
    - `--admin-password=...`
    - `--agent-secret=...`
    - `--mcp-secret=...`
    - `--reset-admin-password`
    - `--rotate-existing-secrets`

## Lancer les tests

- PHPUnit :
  - `vendor/bin/phpunit`
  - couvre explicitement : throttling login, reset token expiré, logout non authentifié

- Behat :
  - `vendor/bin/behat`
  - couvre les cas positifs et négatifs auth/reset/verify en mémoire (credentials invalides, token invalide/expiré)

- Fixtures de test Faker/AliceBundle :
  - `fixtures/test/users.yaml`
  - seed Faker fixée (`nelmio_alice.seed=1234`) pour limiter la variabilité entre runs CI/local

## Base de données

- développement : PostgreSQL (`DATABASE_URL` dans `.env`)
- tests : mémoire (`DATABASE_URL=sqlite:///:memory:` dans `.env.test`)

## Configuration storage

Le storage metier est configure avec des variables explicites par storage, sans JSON:

- `APP_STORAGE_IDS`
- `APP_STORAGE_DEFAULT_ID`
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

Exemple minimal:

```dotenv
APP_STORAGE_IDS=nas-main
APP_STORAGE_DEFAULT_ID=nas-main
APP_STORAGE_NAS_MAIN_DRIVER=local
APP_STORAGE_NAS_MAIN_ROOT_PATH=./docker/RETAIA
APP_STORAGE_NAS_MAIN_WATCH_DIRECTORY=INBOX
```

## Points d’attention

- La persistance utilisateur applicative est Doctrine + PostgreSQL.
- Les tests unitaires/Behat restent en mémoire via un repository de test dédié.
- Les tests fonctionnels Doctrine chargent des fixtures via AliceBundle + Faker.
- En environnement non `prod`, `lost-password/request` retourne aussi un `reset_token` pour faciliter les tests.
- En environnement non `prod`, `verify-email/request` retourne aussi un `verification_token` pour faciliter les tests.
- `lost-password/reset` applique une policy configurable (longueur + complexité).
- Les logs auth n’incluent ni mot de passe ni token brut (email hashé).
- Toute vérification email forcée par admin est tracée en audit log.
- `decision` et `reprocess` exigent `Idempotency-Key` (conforme contrat API v1).
- Les workflows move/purge sont protégés par locks persistés pour éviter les courses concurrentes.
- Le poller ingest ignore les symlinks et les chemins non sûrs.
- Le poller ingest reste tolérant aux races de scan (fichier renommé/supprimé pendant scan) et aux sous-dossiers non lisibles.
- Le move outbox gère les collisions massives de nom sans écrasement (suffixes déterministes par asset).
- Ne jamais embarquer de secrets ou de mots de passe bootstrap dans les migrations.

## Changelog runtime v1 (normalisation HTTP)

Contexte:

- source normative: `specs/change-management/HTTP-STATUS-IMPLEMENTATION-TICKETS.md`
- ticket Core: `TICKET-CORE-HTTP-001`

Changements actés côté Core:

- `POST /api/v1/auth/clients/device/poll`:
  - les états métier device flow (`PENDING`, `APPROVED`, `DENIED`, `EXPIRED`) sont pilotés via `200` + `status`
  - les anciens signaux `401 AUTHORIZATION_PENDING` et `403 ACCESS_DENIED` ne sont plus utilisés pour ce pilotage
- `POST /api/v1/auth/clients/token`:
  - `client_kind in {UI_WEB, MCP}` est refusé en `403 FORBIDDEN_ACTOR` (et non `422`)

Impact migration client:

- les clients techniques (Agent/MCP) et surfaces interactives concernées doivent piloter la machine de poll device depuis le payload de `200` (`status`)
- les clients ne doivent plus brancher la logique de poll sur des `401/403` legacy
- les erreurs HTTP hors état métier restent inchangées (`400`, `422`, `429`)

Rollback/compatibilité:

- cette normalisation est la cible v1 runtime gelée
- aucun mode legacy n'est maintenu côté Core avant publication v1

## Paramètres configurables

- `app.password_reset_ttl_seconds` (défaut: `3600`)
- `app.email_verification_ttl_seconds` (défaut: `86400`)
- `app.email_verification.secret` (défaut: `%kernel.secret%`)
- `app.password_policy.min_length` (défaut: `12`)
- `app.password_policy.require_mixed_case` (défaut: `true`)
- `app.password_policy.require_number` (défaut: `true`)
- `app.password_policy.require_special` (défaut: `true`)
