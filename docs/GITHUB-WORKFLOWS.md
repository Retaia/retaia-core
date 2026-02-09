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
./scripts/no-black-magic.sh
composer validate --strict --no-check-publish
php bin/console lint:yaml config
php bin/console lint:container
composer test
composer audit --no-interaction
```
