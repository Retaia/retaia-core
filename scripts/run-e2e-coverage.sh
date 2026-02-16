#!/usr/bin/env bash
set -euo pipefail

mkdir -p var/coverage

cmd=(vendor/bin/phpunit --configuration phpunit.e2e-coverage.xml --testsuite E2E --coverage-clover var/coverage/clover-e2e.xml)

if php -r 'exit((int) !(extension_loaded("xdebug") || extension_loaded("pcov")));'; then
  "${cmd[@]}"
  exit 0
fi

if command -v phpdbg >/dev/null 2>&1; then
  phpdbg -qrr "${cmd[@]}"
  exit 0
fi

echo "No coverage driver available. Install/enable xdebug or pcov, or install phpdbg." >&2
exit 1
