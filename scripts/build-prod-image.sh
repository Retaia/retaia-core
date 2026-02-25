#!/usr/bin/env bash
set -euo pipefail

if [[ "${RETAIA_BUILD_V1_READY:-0}" != "1" ]]; then
  echo "Production image build blocked."
  echo "Set RETAIA_BUILD_V1_READY=1 after V1 mark."
  exit 1
fi

IMAGE_TAG="${RETAIA_PROD_IMAGE:-retaia-core:prod}"
BASE_IMAGE_ARG="${RETAIA_BASE_IMAGE:-}"

BUILD_ARGS=(
  --build-arg "RETAIA_BUILD_V1_READY=1"
)

if [[ -n "${BASE_IMAGE_ARG}" ]]; then
  BUILD_ARGS+=(--build-arg "BASE_IMAGE=${BASE_IMAGE_ARG}")
fi

docker build \
  --file Dockerfile.prod \
  --tag "${IMAGE_TAG}" \
  "${BUILD_ARGS[@]}" \
  .
