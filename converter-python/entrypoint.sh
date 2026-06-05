#!/usr/bin/env bash
#
# entrypoint.sh
#
# Starts the FastAPI application server via uvicorn.
# Configuration is read from OFFICE_* environment variables.
#
set -eu

export OFFICE_HOST="${OFFICE_HOST:-0.0.0.0}"
export OFFICE_PORT="${OFFICE_PORT:-8080}"
export OFFICE_MAX_UPLOAD_SIZE="${OFFICE_MAX_UPLOAD_SIZE:-104857600}"
export OFFICE_MAX_CONCURRENT_CONVERSIONS="${OFFICE_MAX_CONCURRENT_CONVERSIONS:-2}"
export OFFICE_CONVERSION_TIMEOUT="${OFFICE_CONVERSION_TIMEOUT:-60s}"

echo "==> office-converter (Python + FastAPI + uvicorn)"
echo "    listen: ${OFFICE_HOST}:${OFFICE_PORT}"
echo "    max upload: ${OFFICE_MAX_UPLOAD_SIZE} bytes"
echo "    max concurrent conversions: ${OFFICE_MAX_CONCURRENT_CONVERSIONS}"
echo "    conversion timeout: ${OFFICE_CONVERSION_TIMEOUT}"
echo

# --workers 1 is intentional: asyncio.Semaphore is per-process; a single worker
# correctly enforces MAX_CONCURRENT_CONVERSIONS across all requests.
exec /opt/venv/bin/python3 -m uvicorn app.main:app \
    --host "$OFFICE_HOST" \
    --port "$OFFICE_PORT" \
    --workers 1
