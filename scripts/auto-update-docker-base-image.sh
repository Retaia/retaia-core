#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKERFILE_PATH="${ROOT_DIR}/Dockerfile.prod"

BASE_IMAGE_REPO="${RETAIA_DOCKER_BASE_REPO:-fullfrontend/php-fpm}"
BASE_IMAGE_TAG="${RETAIA_DOCKER_BASE_TAG:-latest}"
BASE_IMAGE_SOURCE="${BASE_IMAGE_REPO}:${BASE_IMAGE_TAG}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker command is required." >&2
  exit 1
fi

if ! docker buildx version >/dev/null 2>&1; then
  echo "docker buildx is required." >&2
  exit 1
fi

BASE_IMAGE_DIGEST="$(docker buildx imagetools inspect "${BASE_IMAGE_SOURCE}" --format '{{json .Manifest.Digest}}' | tr -d '"')"

if [[ -z "${BASE_IMAGE_DIGEST}" || "${BASE_IMAGE_DIGEST}" == "null" ]]; then
  echo "Unable to resolve digest for ${BASE_IMAGE_SOURCE}." >&2
  exit 1
fi

PINNED_BASE_IMAGE="${BASE_IMAGE_SOURCE}@${BASE_IMAGE_DIGEST}"

BASE_IMAGE_REF="${PINNED_BASE_IMAGE}" perl -0pi -e 's/^ARG BASE_IMAGE=.*$/ARG BASE_IMAGE=$ENV{BASE_IMAGE_REF}/m' "${DOCKERFILE_PATH}"

echo "Pinned base image: ${PINNED_BASE_IMAGE}"
