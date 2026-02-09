# GitHub Actions Setup

> Statut : non normatif.

## Pipeline

Le pipeline CI est défini dans :

- `.github/workflows/ci.yml`

Il exécute une seule job `test` :

1. `composer validate --strict`
2. `composer install --no-interaction --prefer-dist --optimize-autoloader`
3. `composer test` (PHPUnit + Behat)

## Déclenchement

- `push` sur `master`
- `pull_request`

## Vérification locale

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --optimize-autoloader
composer test
```

