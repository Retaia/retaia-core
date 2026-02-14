#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${BASE_REF:-}" || -z "${HEAD_REF:-}" ]]; then
  echo "BASE_REF and HEAD_REF are required."
  exit 1
fi

git fetch origin "${BASE_REF}" "${HEAD_REF}" --quiet

BASE_SHA="$(git rev-parse "origin/${BASE_REF}")"
HEAD_SHA="$(git rev-parse "origin/${HEAD_REF}")"
MERGE_BASE="$(git merge-base "${BASE_SHA}" "${HEAD_SHA}")"

if [[ "${MERGE_BASE}" != "${BASE_SHA}" ]]; then
  echo "Branch is not up to date with base."
  echo "base=${BASE_REF} (${BASE_SHA})"
  echo "head=${HEAD_REF} (${HEAD_SHA})"
  echo "merge-base=${MERGE_BASE}"
  exit 1
fi

if [[ -n "$(git rev-list --merges "${BASE_SHA}..${HEAD_SHA}")" ]]; then
  echo "Merge commits detected in PR branch history."
  echo "Please rebase on ${BASE_REF} and keep history linear."
  exit 1
fi

echo "Branch history is up to date and linear."
