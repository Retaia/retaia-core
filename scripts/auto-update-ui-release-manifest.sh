#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${ROOT_DIR}/public/releases/latest.json"

UI_REPOSITORY="${RETAIA_UI_REPOSITORY:-Retaia/retaia-ui}"
UI_RELEASE_CHANNEL="${RETAIA_UI_RELEASE_CHANNEL:-stable}"
UI_REF="${RETAIA_UI_REF:-master}"
GITHUB_API_URL="https://api.github.com/repos/${UI_REPOSITORY}/releases/latest"
GITHUB_COMMIT_API_URL="https://api.github.com/repos/${UI_REPOSITORY}/commits/${UI_REF}"
GITHUB_ZIPBALL_URL="https://api.github.com/repos/${UI_REPOSITORY}/zipball/${UI_REF}"

if ! command -v curl >/dev/null 2>&1; then
  echo "curl command is required." >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq command is required." >&2
  exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
  SHA256_CMD=(sha256sum)
elif command -v shasum >/dev/null 2>&1; then
  SHA256_CMD=(shasum -a 256)
else
  echo "sha256sum or shasum command is required." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

RELEASE_JSON="${TMP_DIR}/release.json"
api_get() {
  local url="$1"
  local output_file="$2"
  local http_code

  if [[ -n "${GH_TOKEN:-}" ]]; then
    http_code="$(curl -sS -L -o "${output_file}" -w "%{http_code}" -H "Accept: application/vnd.github+json" -H "Authorization: Bearer ${GH_TOKEN}" "${url}")"
  elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
    http_code="$(curl -sS -L -o "${output_file}" -w "%{http_code}" -H "Accept: application/vnd.github+json" -H "Authorization: Bearer ${GITHUB_TOKEN}" "${url}")"
  else
    http_code="$(curl -sS -L -o "${output_file}" -w "%{http_code}" -H "Accept: application/vnd.github+json" "${url}")"
  fi

  echo "${http_code}"
}

UI_VERSION=""
NOTES_URL=""
ASSET_URL=""
GENERATED_AT=""

RELEASE_HTTP_CODE="$(api_get "${GITHUB_API_URL}" "${RELEASE_JSON}")"
if [[ "${RELEASE_HTTP_CODE}" == "200" ]]; then
  UI_VERSION="$(jq -r '.tag_name // empty' "${RELEASE_JSON}")"
  NOTES_URL="$(jq -r '.html_url // empty' "${RELEASE_JSON}")"
  GENERATED_AT="$(jq -r '.published_at // .created_at // empty' "${RELEASE_JSON}")"
  ASSET_URL="$(jq -r '.assets[]? | select(.name | test("(dist|bundle|release|ui).*\\.zip$"; "i")) | .browser_download_url' "${RELEASE_JSON}" | head -n1)"
  if [[ -z "${ASSET_URL}" ]]; then
    ASSET_URL="$(jq -r '.assets[]? | select(.name | test("\\.zip$"; "i")) | .browser_download_url' "${RELEASE_JSON}" | head -n1)"
  fi
  if [[ -z "${ASSET_URL}" ]]; then
    ASSET_URL="$(jq -r '.assets[0].browser_download_url // .zipball_url // empty' "${RELEASE_JSON}")"
  fi
fi

if [[ -z "${UI_VERSION}" || -z "${NOTES_URL}" || -z "${ASSET_URL}" ]]; then
  COMMIT_JSON="${TMP_DIR}/commit.json"
  COMMIT_HTTP_CODE="$(api_get "${GITHUB_COMMIT_API_URL}" "${COMMIT_JSON}")"
  if [[ "${COMMIT_HTTP_CODE}" != "200" ]]; then
    echo "Unable to read ${UI_REPOSITORY} metadata (release and ${UI_REF} commit). Configure GH_TOKEN/GITHUB_TOKEN with access." >&2
    exit 1
  fi

  UI_SHA="$(jq -r '.sha // empty' "${COMMIT_JSON}")"
  NOTES_URL="$(jq -r '.html_url // empty' "${COMMIT_JSON}")"
  GENERATED_AT="$(jq -r '.commit.committer.date // empty' "${COMMIT_JSON}")"
  if [[ -z "${UI_SHA}" || -z "${NOTES_URL}" ]]; then
    echo "Unable to parse commit metadata for ${UI_REPOSITORY}@${UI_REF}." >&2
    exit 1
  fi

  UI_VERSION="${UI_REF}-${UI_SHA:0:12}"
  ASSET_URL="${GITHUB_ZIPBALL_URL}"
fi

if [[ -z "${GENERATED_AT}" ]]; then
  GENERATED_AT="$(date -u +"%Y-%m-%dT%H:%M:%S+00:00")"
fi

ASSET_FILE="${TMP_DIR}/ui-release-asset"
if [[ -n "${GH_TOKEN:-}" ]]; then
  curl -fsSL -L -H "Authorization: Bearer ${GH_TOKEN}" "${ASSET_URL}" -o "${ASSET_FILE}"
elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
  curl -fsSL -L -H "Authorization: Bearer ${GITHUB_TOKEN}" "${ASSET_URL}" -o "${ASSET_FILE}"
else
  curl -fsSL -L "${ASSET_URL}" -o "${ASSET_FILE}"
fi
ASSET_SHA256="$("${SHA256_CMD[@]}" "${ASSET_FILE}" | awk '{print $1}')"

if [[ ! "${ASSET_SHA256}" =~ ^[a-f0-9]{64}$ ]]; then
  echo "Invalid SHA-256 generated for asset ${ASSET_URL}." >&2
  exit 1
fi

mkdir -p "$(dirname "${OUTPUT_PATH}")"

jq -n \
  --arg version "${UI_VERSION}" \
  --arg channel "${UI_RELEASE_CHANNEL}" \
  --arg asset_url "${ASSET_URL}" \
  --arg sha256 "${ASSET_SHA256}" \
  --arg notes_url "${NOTES_URL}" \
  --arg generated_at "${GENERATED_AT}" \
  '{
    version: $version,
    channel: $channel,
    asset_url: $asset_url,
    sha256: $sha256,
    notes_url: $notes_url,
    generated_at: $generated_at
  }' > "${OUTPUT_PATH}"

echo "Updated UI manifest at ${OUTPUT_PATH} for ${UI_VERSION}"
