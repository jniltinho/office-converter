#!/usr/bin/env bash
#
# run-integration-tests.sh
# Starts the office-converter (binary or docker), waits for healthy,
# runs the full test suite, then stops it.
#
# This is the recommended entry point for CI and local verification.
#
# Usage examples:
#   ./scripts/run-integration-tests.sh
#   ./scripts/run-integration-tests.sh --docker
#   PORT=19000 ./scripts/run-integration-tests.sh --keep
#   ./scripts/run-integration-tests.sh --url http://localhost:8080   # use already-running server
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Defaults
TEST_PORT="${PORT:-18180}"   # unlikely to conflict with dev 8080
USE_DOCKER=0
KEEP_SERVER=0
EXTERNAL_URL=""
BINARY="$ROOT_DIR/bin/office-converter"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'

usage() {
  cat <<EOF
Usage: $(basename "$0") [options]

Options:
  -h, --help          This help
  --docker            Run tests inside a fresh docker container (builds image)
  --port N            Use TCP port N (default: 18180)
  --keep              Do not stop the server after tests (useful with --url)
  --url URL           Use an already-running server at URL (skips start/stop)
  --binary PATH       Path to the office-converter binary (default: ./bin/office-converter)

Environment:
  PORT                Same as --port
  SERVER_URL          If set and --url not given, treated as external server
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --docker) USE_DOCKER=1; shift ;;
    --port) TEST_PORT="$2"; shift 2 ;;
    --keep) KEEP_SERVER=1; shift ;;
    --url) EXTERNAL_URL="$2"; shift 2 ;;
    --binary) BINARY="$2"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

# If user set SERVER_URL and didn't pass --url, honor it as external
if [[ -z "$EXTERNAL_URL" && -n "${SERVER_URL:-}" ]]; then
  EXTERNAL_URL="$SERVER_URL"
fi

SERVER_URL="${EXTERNAL_URL:-http://127.0.0.1:${TEST_PORT}}"
export SERVER_URL

echo "==> office-converter integration test runner"
echo "    mode: $( [[ -n "$EXTERNAL_URL" ]] && echo "external server at $SERVER_URL" || echo "managed server on :$TEST_PORT" )"
echo "    docker: $USE_DOCKER"
echo

need_cleanup=0
server_pid=""

cleanup() {
  if [[ $need_cleanup -eq 1 && $KEEP_SERVER -eq 0 ]]; then
    echo
    echo "==> Stopping test server..."
    if [[ $USE_DOCKER -eq 1 ]]; then
      docker rm -f xlsb-test 2>/dev/null || true
    else
      if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
      fi
    fi
  fi
  # Best effort cleanup of temp conversion dirs from this run
  rm -rf /tmp/convert-req-* /tmp/xlsb-to-xlsx-req-* /tmp/xlsx-to-ods-req-* /tmp/ods-to-xlsx-req-* /tmp/lo-profile-* 2>/dev/null || true
}
trap cleanup EXIT INT TERM

wait_for_healthy() {
  local url="$1"
  local max_wait=30
  local i=0
  echo -n "    waiting for healthy"
  while [[ $i -lt $max_wait ]]; do
    if curl -fsS --max-time 2 "$url/healthz" >/dev/null 2>&1; then
      echo " OK"
      return 0
    fi
    echo -n "."
    sleep 1
    ((i++))
  done
  echo " TIMEOUT"
  return 1
}

start_binary_server() {
  if [[ ! -x "$BINARY" ]]; then
    echo "${YELLOW}Binary $BINARY not found or not executable. Building...${RESET}"
    (cd "$ROOT_DIR" && make build)
  fi
  echo "==> Starting $BINARY on :$TEST_PORT (background)"
  "$BINARY" serve --port "$TEST_PORT" --host 127.0.0.1 > "$ROOT_DIR/test-server.log" 2>&1 &
  server_pid=$!
  need_cleanup=1
  echo "    pid: $server_pid (log: test-server.log)"
}

start_docker_server() {
  echo "==> Building docker image (if needed)..."
  (cd "$ROOT_DIR" && make docker-build >/dev/null)
  echo "==> Starting container on :$TEST_PORT"
  docker rm -f xlsb-test 2>/dev/null || true
  docker run -d --name xlsb-test \
    -p "${TEST_PORT}:${TEST_PORT}" \
    office-converter:latest \
    serve --port "$TEST_PORT" --host 0.0.0.0 >/dev/null
  need_cleanup=1
}

# --- Main flow ---------------------------------------------------------------

if [[ -n "$EXTERNAL_URL" ]]; then
  echo "==> Using external server: $SERVER_URL"
  if ! curl -fsS --max-time 3 "$SERVER_URL/healthz" >/dev/null; then
    echo "${RED}External server at $SERVER_URL is not responding to /healthz${RESET}" >&2
    exit 1
  fi
else
  if [[ $USE_DOCKER -eq 1 ]]; then
    command -v docker >/dev/null || { echo "docker not found"; exit 1; }
    start_docker_server
  else
    start_binary_server
  fi

  if ! wait_for_healthy "$SERVER_URL"; then
    echo "${RED}Server did not become healthy in time.${RESET}" >&2
    if [[ -f "$ROOT_DIR/test-server.log" ]]; then
      echo "Last 30 lines of server log:" >&2
      tail -30 "$ROOT_DIR/test-server.log" >&2
    fi
    exit 1
  fi
fi

# Run the actual tests
echo
echo "==> Running health checks"
"$SCRIPT_DIR/test-health.sh" || true

echo
echo "==> Running full API tests"
"$SCRIPT_DIR/test-api.sh" || test_status=$?

test_status=${test_status:-0}

echo
if [[ $test_status -eq 0 ]]; then
  echo "${GREEN}==> Integration tests completed successfully.${RESET}"
else
  echo "${RED}==> Integration tests finished with failures (exit $test_status).${RESET}"
fi

if [[ $KEEP_SERVER -eq 1 ]]; then
  echo "${YELLOW}Server left running at $SERVER_URL (--keep).${RESET}"
  need_cleanup=0
fi

exit "$test_status"
