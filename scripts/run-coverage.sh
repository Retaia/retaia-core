#!/usr/bin/env bash
set -euo pipefail

if php -r 'exit((int) !(extension_loaded("xdebug") || extension_loaded("pcov")));'; then
  mkdir -p var/coverage
  vendor/bin/phpunit --coverage-clover var/coverage/clover.xml
  exit 0
fi

echo "No coverage driver available. Install/enable xdebug or pcov to generate coverage." >&2
exit 1
