#!/usr/bin/env bash
set -euo pipefail

mkdir -p var/coverage

if php -r 'exit((int) !(extension_loaded("xdebug") || extension_loaded("pcov")));'; then
  vendor/bin/phpunit --coverage-clover var/coverage/clover.xml
  exit 0
fi

if command -v phpdbg >/dev/null 2>&1; then
  phpdbg -qrr vendor/bin/phpunit --coverage-clover var/coverage/clover.xml
  exit 0
fi

echo "No coverage driver available. Install/enable xdebug or pcov, or install phpdbg." >&2
exit 1
