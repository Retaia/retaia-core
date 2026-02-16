# GitHub Actions Setup

> Statut : non normatif.

## Pipeline

Le pipeline CI est défini dans :

- `.github/workflows/ci.yml`

Il exécute quatre jobs :

0. `branch-up-to-date` (PR uniquement)
0bis. `pr-metadata` (PR uniquement)
1. `no-black-magic`
2. `lint`
3. `test`
4. `security-audit`

Détail supplémentaire :

- `branch-up-to-date` (sur `pull_request`) :
  - `scripts/check-branch-up-to-date.sh`
  - échoue si la branche PR n’est pas rebased sur la base (`master`)
  - échoue si des merge commits de synchronisation sont présents dans l’historique PR
- `pr-metadata` (sur `pull_request`) :
  - `scripts/check-pr-metadata.sh`
  - échoue si la description PR ne contient pas les sections obligatoires :
    - `## Summary`
    - `## Out Of Scope`
    - `## Specs Impact`
    - `## Risks`
    - `## Rollback`
    - `## Tests`
  - source de format : `.github/pull_request_template.md`

Détail :

- `no-black-magic` :
  - `./scripts/no-black-magic.sh`
  - échoue si des patterns dynamiques/interdits sont détectés (`eval`, `exec`, `shell_exec`, `unserialize`, `call_user_func`, `include/require` dynamiques, etc.)

- `lint` :
  - `composer validate --strict --no-check-publish`
  - `php bin/console lint:yaml config`
  - `php bin/console lint:container`
  - `php scripts/check-translation-keys.php` (gate bloquant en/fr)
  - `php scripts/check-openapi-routes.php` (gate bloquant: endpoints implémentés assets/jobs/agents/auth présents dans OpenAPI)
    - source OpenAPI unique: `specs/api/openapi/v1.yaml`
    - aucun fallback local autorisé; tout besoin d'évolution de contrat doit d'abord passer par `retaia-docs`
  - le gate i18n bloque aussi :
    - clés critiques manquantes (`auth.error.*` critiques)
    - valeurs de traduction vides
    - placeholders interdits (`TODO`, `FIXME`, `TRANSLATE_ME`)

- `test` :
  - `composer test:quality` (PHPUnit avec coverage + Behat + gate coverage 80%)
  - inclut des non-régressions token auth (token expiré/invalide, payload/signature altérés)
  - inclut des tests contractuels OpenAPI automatiques (schéma `ErrorResponse`, runtime request/response/error model sur endpoints critiques)

- `security-audit` :
  - `composer audit --no-interaction`

## Déclenchement

- `push` sur `master`
- `pull_request`

## Auto-update (sans gate de validation)

Deux workflows dedies executent une mise a jour automatique puis commit/push sur `master`:

- `.github/workflows/docker-base-image-auto-update.yml`
  - script: `scripts/auto-update-docker-base-image.sh`
  - cadence: hebdomadaire + `workflow_dispatch`
  - met a jour:
    - `Dockerfile.prod`
    - `docker-compose.prod.yaml`
- `.github/workflows/ui-release-auto-update.yml`
  - script: `scripts/auto-update-ui-release-manifest.sh`
  - cadence: hebdomadaire + `workflow_dispatch`
  - met a jour:
    - `public/releases/latest.json`

Ces workflows sont des jobs d'auto-remediation: ils n'executent pas de suite de validation metier.
Ils tentent un push direct sur `master`; si la protection de branche le refuse, ils ouvrent automatiquement une PR.

Variables utiles:

- Docker:
  - `RETAIA_DOCKER_BASE_REPO` (defaut: `fullfrontend/php-fpm`)
  - `RETAIA_DOCKER_BASE_TAG` (defaut: `latest`)
- UI:
  - `RETAIA_UI_REPOSITORY` (defaut: `Retaia/retaia-ui`)
  - `RETAIA_UI_REF` (defaut: `master`)
  - `RETAIA_UI_RELEASE_CHANNEL` (defaut: `stable`)

Secrets recommandes:

- `RETAIA_UI_REPO_TOKEN`: token avec acces lecture au repo UI prive (si `Retaia/retaia-ui` n'est pas public).
- `GITHUB_TOKEN`: utilise pour commit/push (ou creation de PR fallback si push direct refuse).

## Required Checks (master)

Objectif recommandé :

- exiger `lint`, `test`, `security-audit` avant merge vers `master`.

Script local fourni :

```bash
./scripts/apply-branch-protection.sh Retaia/retaia-core master
```

Note:

- si l’API retourne `403`, la fonctionnalité n’est pas disponible sur le plan GitHub courant pour ce repository privé.

## Vérification locale

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
./scripts/no-black-magic.sh
composer validate --strict --no-check-publish
php bin/console lint:yaml config
php bin/console lint:container
composer test:quality
composer audit --no-interaction
```

## Raccourci local

Le repository expose aussi un `Makefile` pour coller au pipeline :

```bash
make qa
make ci-local
```
