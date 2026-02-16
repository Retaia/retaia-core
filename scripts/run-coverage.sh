#!/usr/bin/env bash
set -euo pipefail

mkdir -p var/coverage

run_phpunit_with_coverage() {
  local out="$1"
  shift
  local cmd=(vendor/bin/phpunit "$@" --coverage-clover "$out")

  if php -r 'exit((int) !(extension_loaded("xdebug") || extension_loaded("pcov")));'; then
    "${cmd[@]}"
    return 0
  fi

  if command -v phpdbg >/dev/null 2>&1; then
    phpdbg -qrr "${cmd[@]}"
    return 0
  fi

  echo "No coverage driver available. Install/enable xdebug or pcov, or install phpdbg." >&2
  exit 1
}

# Unit suite: business/application layers covered by unit tests.
run_phpunit_with_coverage "var/coverage/clover-unit.xml" --configuration phpunit.unit-coverage.xml --testsuite Unit
# Functional suite: HTTP/API integration quality on controller layer.
run_phpunit_with_coverage "var/coverage/clover-functional.xml" --configuration phpunit.functional-coverage.xml --testsuite Functional
