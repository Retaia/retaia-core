# GitHub Actions Setup

> Statut : non normatif.

## Pipeline

Le pipeline CI est défini dans :

- `.github/workflows/ci.yml`

Il exécute quatre jobs :

1. `no-black-magic`
2. `lint`
3. `test`
4. `security-audit`

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
