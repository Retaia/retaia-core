#!/usr/bin/env bash
set -euo pipefail

if [[ "${RETAIA_BUILD_V1_READY:-0}" != "1" ]]; then
  echo "Production image build blocked."
  echo "Set RETAIA_BUILD_V1_READY=1 after V1 mark."
  exit 1
fi

docker compose -f docker-compose.prod.yaml build app-prod
