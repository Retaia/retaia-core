#!/usr/bin/env bash
set -euo pipefail

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required. Install and authenticate first." >&2
  exit 1
fi

REPO="${1:-Retaia/retaia-core}"
BRANCH="${2:-master}"

echo "Applying branch protection on ${REPO}:${BRANCH}..."

set +e
gh api -X PUT "repos/${REPO}/branches/${BRANCH}/protection" \
  -H "Accept: application/vnd.github+json" \
  -f required_status_checks.strict=true \
  -f 'required_status_checks.contexts[]=lint' \
  -f 'required_status_checks.contexts[]=test' \
  -f 'required_status_checks.contexts[]=security-audit' \
  -f enforce_admins=true \
  -f required_pull_request_reviews.dismiss_stale_reviews=true \
  -f required_pull_request_reviews.require_code_owner_reviews=false \
  -f required_pull_request_reviews.required_approving_review_count=1 \
  -f restrictions=
status=$?
set -e

if [ "$status" -ne 0 ]; then
  echo "Failed to apply branch protection."
  echo "If you got HTTP 403, your GitHub plan/repo visibility may not support this API for private repositories."
  exit "$status"
fi

echo "Branch protection applied. Verifying required checks..."
gh api "repos/${REPO}/branches/${BRANCH}/protection" --jq '.required_status_checks.contexts'
