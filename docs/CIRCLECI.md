# CircleCI Setup

> Statut : non normatif.

## Pipeline

Le pipeline CircleCI est défini dans :

- `.circleci/config.yml`

Il exécute une seule job `test` :

1. `composer validate --strict`
2. `composer install --no-interaction --prefer-dist --optimize-autoloader`
3. `composer test` (PHPUnit + Behat)

## Pré-requis

- `composer.lock` à jour
- scripts `composer test:unit`, `composer test:behat`, `composer test` valides

## Vérification locale

Pour reproduire le pipeline localement :

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --optimize-autoloader
composer test
```

