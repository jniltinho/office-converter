#!/usr/bin/env bash
#
# entrypoint.sh
#
# Starts the FrankenPHP application server in worker mode using the
# standalone binary. The Slim-based application lives under /app/api/
# (public/index.php, app/). Worker mode keeps the PHP runtime in
# memory between requests.
#
# Configuration is read from the same OFFICE_* environment variables used
# by the previous php-cli-based image, so existing deployments do not need
# to change anything.
#
set -eu

# --- Read configuration (same env names as the Go version) -------------------
HOST="${OFFICE_HOST:-0.0.0.0}"
PORT="${OFFICE_PORT:-8080}"

MAX_UPLOAD="${OFFICE_MAX_UPLOAD_SIZE:-104857600}"   # bytes
MAX_CONC="${OFFICE_MAX_CONCURRENT_CONVERSIONS:-2}"
CONV_TIMEOUT="${OFFICE_CONVERSION_TIMEOUT:-60s}"

# --- Compute php.ini values for upload limits --------------------------------
# Round UP to the next MiB for safety. The `php -d` mechanism is not
# available on the FrankenPHP standalone binary, so we write a php.ini
# fragment and point PHP_INI_SCAN_DIR at it.
UPLOAD_MIB=$(( (MAX_UPLOAD + 1048575) / 1048576 ))
POST_MIB=$(( UPLOAD_MIB + 2 ))

INI_DIR="/tmp/office-converter-ini"
mkdir -p "$INI_DIR"
cat > "$INI_DIR/99-office-converter.ini" <<EOF
; Generated at container start by entrypoint.sh.
; Values are derived from OFFICE_* environment variables.
upload_max_filesize = ${UPLOAD_MIB}M
post_max_size       = ${POST_MIB}M
max_execution_time  = 0
memory_limit        = 256M
EOF
export PHP_INI_SCAN_DIR="$INI_DIR"

# --- Export the runtime config so the Slim app can read it ------
export OFFICE_HOST="$HOST"
export OFFICE_PORT="$PORT"
export OFFICE_MAX_UPLOAD_SIZE="$MAX_UPLOAD"
export OFFICE_MAX_CONCURRENT_CONVERSIONS="$MAX_CONC"
export OFFICE_CONVERSION_TIMEOUT="$CONV_TIMEOUT"

echo "==> office-converter (PHP + FrankenPHP worker + Slim Framework)"
echo "    frankenphp run --config /tmp/Caddyfile --adapter caddyfile"
echo "    (global auto_https off; access log to stdout in JSON; worker /app/api/public/index.php)"
echo "    max upload: ~${UPLOAD_MIB} MiB (post_max_size=${POST_MIB}M)"
echo "    max concurrent conversions: ${MAX_CONC}"
echo "    conversion timeout: ${CONV_TIMEOUT}"
echo "    PHP INI scan dir: ${PHP_INI_SCAN_DIR}"
echo

# --- Generate Caddyfile with global TLS disabled -----------------------------
# We use a full Caddyfile + `frankenphp run` (instead of the php-server
# convenience subcommand) so that the global options block with
# `auto_https off` is honored. This globally disables FrankenPHP's
# automatic HTTPS / TLS / cert automation / redirects.
#
# Worker script is at /app/api/public/index.php (Slim Framework app).
cat > /tmp/Caddyfile <<EOF
{
	auto_https off
	order php_server before file_server
}

:${PORT} {
	bind ${HOST}

	# Access logs to stdout (visible via "docker logs" / container logging)
	log {
		output stdout
		format json
	}

	php_server {
		file_server off
		worker {
			file /app/api/public/index.php
			match *
		}
	}
}
EOF

# --- Exec FrankenPHP using the explicit Caddyfile ----------------------------
# This ensures TLS is disabled at the global Caddy/FrankenPHP level.
exec frankenphp run --config /tmp/Caddyfile --adapter caddyfile
