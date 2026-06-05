# Makefile for office-converter (PHP port)
#
# The application is a pure-PHP project. Locally it runs under the PHP
# built-in server (php -S). In Docker it runs under FrankenPHP in worker
# mode (worker at /app/api/worker.php using Slim Framework) on a debian:12-slim
# base. No compilation step is required for normal development or Docker
# usage.
#
# Common usage:
#   make docker-build   # build the Docker image (debian:12-slim + frankenphp + libreoffice)
#   make serve          # run the PHP built-in server locally (requires soffice in PATH)
#   make docker-run     # run the container (PORT=9000 make docker-run)
#   make clean          # remove temp artifacts
#   make test-integration-php   # run full integration tests against php -S (api/router.php)
#
# Environment variables (passed through to the server):
#   See env.example for the full list with explanations.

APP_NAME     := office-converter
DOCKER_IMAGE := office-converter

# Default target
.PHONY: all
all: help

# No build step is required for the PHP version.
# This target exists for compatibility with the integration test harness.
.PHONY: build
build:
	@echo "PHP application — nothing to compile. Use 'make docker-build' or 'make serve'."

.PHONY: build-debug
build-debug: build

# Run the PHP built-in server directly (development) using the Slim app in api/.
# Requires: soffice (LibreOffice) in $PATH, php in $PATH.
# Usage:
#   make serve
#   OFFICE_PORT=9000 make serve
.PHONY: serve
serve:
	@php -d upload_max_filesize=100M -d post_max_size=101M -d memory_limit=256M -S 0.0.0.0:$${OFFICE_PORT:-8080} api/router.php

# Run via the entrypoint wrapper (respects all OFFICE_* env vars exactly like Docker).
.PHONY: run
run: serve

# The old Go oapi-codegen target is no longer used.
.PHONY: generate
generate:
	@echo "OpenAPI code generation is not used in the PHP port. Edit api/openapi.yaml manually if needed."

# PHP lint (syntax check)
.PHONY: fmt
fmt:
	@find api -name "*.php" -not -path "*/vendor/*" | xargs php -l 2>/dev/null || true
	@echo "PHP syntax check done."

.PHONY: lint
lint: fmt

# Run tests (PHP has no unit tests; integration tests are the authoritative suite)
.PHONY: test
test:
	@echo "No unit tests in this project. Use 'make test-integration-php' or 'make test-integration-docker'."

# --- Integration tests (bash + curl against real LibreOffice) ----------------

# Generate (or refresh) sample spreadsheet files used by the integration tests.
# Requires soffice (LibreOffice) in PATH.
.PHONY: generate-samples
generate-samples:
	./DOCUMENTS/scripts/generate-samples.sh

# Run the full integration test suite against the PHP built-in server.
# Starts php -S on a test port, waits for /healthz, runs the test scripts,
# then stops the server.
# Usage:
#   PORT=18180 make test-integration-php
.PHONY: test-integration
test-integration: test-integration-php

.PHONY: test-integration-php
test-integration-php:
	@PORT=$${PORT:-18180} ./DOCUMENTS/scripts/run-integration-tests.sh --php

# Same as above but using the Docker image (validates the Dockerfile + entrypoint).
.PHONY: test-integration-docker
test-integration-docker: docker-build
	@PORT=$${PORT:-18180} ./DOCUMENTS/scripts/run-integration-tests.sh --docker

# Convenience
.PHONY: test-all
test-all: generate-samples test-integration-php

# Remove temporary artifacts left by the server or tests.
.PHONY: clean
clean:
	rm -f test-server.log
	rm -rf /tmp/convert-req-* /tmp/xlsb-to-xlsx-req-* /tmp/xlsx-to-ods-req-* /tmp/ods-to-xlsx-req-* /tmp/lo-profile-* 2>/dev/null || true
	@echo "Cleanup complete."

# --- Docker ---

.PHONY: docker-build
docker-build:
	docker build -t $(DOCKER_IMAGE):latest .

# Run the container locally.
# Usage:
#   make docker-run
#   PORT=9000 make docker-run
PORT ?= 8080
.PHONY: docker-run
docker-run:
	docker run --rm -p $(PORT):$(PORT) -e OFFICE_PORT=$(PORT) $(DOCKER_IMAGE):latest

# Build + run in sequence
.PHONY: docker-up
docker-up: docker-build docker-run

# --- Utilities (final help target) ---

# Show this help
.PHONY: help
help:
	@echo "Available targets (Slim + FrankenPHP PHP port):"
	@echo "  make build                    No-op (PHP does not require compilation)"
	@echo "  make serve                    Start php -S on api/router.php (:8080 or OFFICE_PORT=...)"
	@echo "  make run                      Alias for serve"
	@echo "  make fmt / make lint          Run php -l on the PHP sources (api/ + legacy)"
	@echo "  make clean                    Remove temp conversion dirs and logs"
	@echo ""
	@echo "  make generate-samples         Create testdata/ samples (needs soffice)"
	@echo "  make test-integration-php     Run full integration suite against local php -S (api/)"
	@echo "  make test-integration-docker  Run full integration suite inside the Docker image"
	@echo "  make test-all                 generate-samples + test-integration-php"
	@echo ""
	@echo "Docker:"
	@echo "  make docker-build             Build the debian:12-slim + FrankenPHP + Slim image"
	@echo "  make docker-run               Run container (PORT=9000 make docker-run)"
	@echo "  make docker-up                Build + run"
	@echo ""
	@echo "  make help                     Show this message"
	@echo ""
	@echo "Environment (all respected by serve and the Docker image):"
	@echo "  OFFICE_PORT OFFICE_HOST OFFICE_CONVERSION_TIMEOUT"
	@echo "  OFFICE_MAX_UPLOAD_SIZE OFFICE_MAX_CONCURRENT_CONVERSIONS"
	@echo ""
	@echo "  See env.example for a full documented template."

# Prevent make from trying to create files with these names
.PHONY: all build build-debug run serve generate fmt lint test clean generate-samples \
        test-integration test-integration-php test-integration-docker test-all \
        docker-build docker-run docker-up help
