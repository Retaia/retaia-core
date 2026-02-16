#!/usr/bin/env bash
set -euo pipefail

if [ "${GITHUB_EVENT_NAME:-}" != "pull_request" ]; then
  echo "PR metadata check skipped (event: ${GITHUB_EVENT_NAME:-unknown})."
  exit 0
fi

event_path="${GITHUB_EVENT_PATH:-}"
if [ -z "${event_path}" ] || [ ! -f "${event_path}" ]; then
  echo "Missing GITHUB_EVENT_PATH for pull_request event." >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required for PR metadata check." >&2
  exit 1
fi

pr_body="$(jq -r '.pull_request.body // ""' "${event_path}")"

required_sections=(
  "## Summary"
  "## Out Of Scope"
  "## Specs Impact"
  "## Risks"
  "## Rollback"
  "## Tests"
)

missing=0
for section in "${required_sections[@]}"; do
  if ! grep -Fq "${section}" <<<"${pr_body}"; then
    echo "Missing required PR section: ${section}" >&2
    missing=1
  fi
done

if [ "${missing}" -ne 0 ]; then
  echo "PR metadata check failed." >&2
  echo "Required template: .github/pull_request_template.md" >&2
  echo "Tip: gh pr edit <number> --body-file .github/pull_request_template.md (then fill each section)." >&2
  exit 1
fi

echo "PR metadata structure OK."
