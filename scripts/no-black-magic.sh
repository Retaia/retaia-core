#!/usr/bin/env bash
set -euo pipefail

# Patterns disallowed in application code to avoid hidden runtime behavior.
readonly FORBIDDEN_PATTERN='\beval\s*\(|\bassert\s*\(|\bexec\s*\(|\bshell_exec\s*\(|\bsystem\s*\(|\bpassthru\s*\(|\bproc_open\s*\(|\bpopen\s*\(|\bpcntl_exec\s*\(|\bunserialize\s*\(|\bcall_user_func(_array)?\s*\(|\brequire(_once)?\s*\$|\binclude(_once)?\s*\$'

# Restrict scan to runtime source paths to avoid false positives in docs/vendor.
readonly TARGETS=(src tests config bin)

if rg -n --pcre2 --color=never "$FORBIDDEN_PATTERN" "${TARGETS[@]}"; then
  echo
  echo "Forbidden dynamic/runtime pattern detected."
  echo "Refactor to explicit dependencies and typed code paths."
  exit 1
fi

echo "No forbidden dynamic/runtime patterns detected."
