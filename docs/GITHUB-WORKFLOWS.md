# GitHub Actions Setup

> Statut : non normatif.

## Pipeline

Le pipeline CI est défini dans :

- `.github/workflows/ci.yml`

Il exécute trois jobs :

1. `lint`
2. `test`
3. `security-audit`

Détail :

- `lint` :
  - `composer validate --strict --no-check-publish`
  - `php bin/console lint:yaml config`
  - `php bin/console lint:container`

- `test` :
  - `composer test` (PHPUnit + Behat)

- `security-audit` :
  - `composer audit --no-interaction`

## Déclenchement

- `push` sur `master`
- `pull_request`

## Vérification locale

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
composer validate --strict --no-check-publish
php bin/console lint:yaml config
php bin/console lint:container
composer test
composer audit --no-interaction
```
