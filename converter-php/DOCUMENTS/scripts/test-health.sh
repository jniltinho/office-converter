#!/usr/bin/env bash
#
# test-health.sh
# Quick health check for the office-converter server.
#
# Usage:
#   ./scripts/test-health.sh
#   SERVER_URL=http://127.0.0.1:9000 ./scripts/test-health.sh
#
set -euo pipefail

SERVER_URL="${SERVER_URL:-http://localhost:8080}"
HEALTH_URL="$SERVER_URL/healthz"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
pass() { echo "${GREEN}PASS${RESET} $*"; }
fail() { echo "${RED}FAIL${RESET} $*"; exit 1; }
info() { echo "${YELLOW}INFO${RESET} $*"; }

command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 1; }

echo "==> Health check against $HEALTH_URL"

# GET
status_get=$(curl -sS -o /tmp/health_get.txt -w '%{http_code}' "$HEALTH_URL")
body_get=$(cat /tmp/health_get.txt)
if [[ "$status_get" == "200" && "$body_get" == "ok" ]]; then
  pass "GET /healthz -> 200 'ok'"
else
  fail "GET /healthz -> $status_get body='$body_get' (expected 200 'ok')"
fi

# HEAD
status_head=$(curl -sS -I -o /tmp/health_head.txt -w '%{http_code}' "$HEALTH_URL")
if [[ "$status_head" == "200" ]]; then
  pass "HEAD /healthz -> 200 (no body)"
else
  fail "HEAD /healthz -> $status_head (expected 200)"
fi

info "Server is healthy."
