#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${ROOT_DIR}/public/releases/latest.json"

UI_REPOSITORY="${RETAIA_UI_REPOSITORY:-Retaia/retaia-ui}"
UI_RELEASE_CHANNEL="${RETAIA_UI_RELEASE_CHANNEL:-stable}"
UI_TAG="${RETAIA_UI_TAG:-}"
GITHUB_API_BASE_URL="https://api.github.com/repos/${UI_REPOSITORY}"
GITHUB_RELEASE_API_URL="${GITHUB_API_BASE_URL}/releases/latest"
GITHUB_TAG_API_URL="${GITHUB_API_BASE_URL}/git/ref/tags"
GITHUB_ZIPBALL_BASE_URL="https://api.github.com/repos/${UI_REPOSITORY}/zipball"
GITHUB_HTML_BASE_URL="https://github.com/${UI_REPOSITORY}"

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

RELEASE_JSON="${TMP_DIR}/release.json"
RELEASE_HTTP_CODE="$(api_get "${GITHUB_RELEASE_API_URL}" "${RELEASE_JSON}")"
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
  if [[ -z "${UI_TAG}" ]]; then
    echo "Unable to resolve a tagged UI release from ${UI_REPOSITORY}. Configure RETAIA_UI_TAG with an explicit tag when no GitHub release is available." >&2
    exit 1
  fi

  TAG_JSON="${TMP_DIR}/tag.json"
  TAG_HTTP_CODE="$(api_get "${GITHUB_TAG_API_URL}/${UI_TAG}" "${TAG_JSON}")"
  if [[ "${TAG_HTTP_CODE}" != "200" ]]; then
    echo "Unable to read tag metadata for ${UI_REPOSITORY}@${UI_TAG}. Configure RETAIA_UI_TAG with an existing tag and valid token access if required." >&2
    exit 1
  fi

  TAG_REF="$(jq -r '.ref // empty' "${TAG_JSON}")"
  TAG_SHA="$(jq -r '.object.sha // empty' "${TAG_JSON}")"
  if [[ "${TAG_REF}" != "refs/tags/${UI_TAG}" || -z "${TAG_SHA}" ]]; then
    echo "Unable to parse tag metadata for ${UI_REPOSITORY}@${UI_TAG}." >&2
    exit 1
  fi

  UI_VERSION="${UI_TAG}"
  NOTES_URL="${GITHUB_HTML_BASE_URL}/releases/tag/${UI_TAG}"
  GENERATED_AT="$(date -u +"%Y-%m-%dT%H:%M:%S+00:00")"
  ASSET_URL="${GITHUB_ZIPBALL_BASE_URL}/${UI_TAG}"
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
