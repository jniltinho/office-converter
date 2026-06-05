# Makefile for office-converter (Python + FastAPI)
#
# The application runs via uvicorn locally and in Docker on debian:12-slim.
#
# Common usage:
#   make docker-build            # build the Docker image
#   make serve                   # run uvicorn locally (requires soffice in PATH)
#   make docker-run              # run the container (PORT=9000 make docker-run)
#   make clean                   # remove temp artifacts
#   make test-integration        # run full integration tests against local uvicorn
#
# Environment variables (passed through to the server):
#   See env.example for the full list with explanations.

APP_NAME     := office-converter
DOCKER_IMAGE := office-converter

# Default target
.PHONY: all
all: help

# Install Python dependencies locally.
.PHONY: build
build:
	pip install --quiet -r requirements.txt

.PHONY: build-debug
build-debug: build

# Run uvicorn locally (development, with auto-reload).
# Requires: soffice (LibreOffice) in $PATH, python3 + uvicorn in $PATH.
# Usage:
#   make serve
#   OFFICE_PORT=9000 make serve
.PHONY: serve
serve:
	python3 -m uvicorn app.main:app --host 0.0.0.0 --port $${OFFICE_PORT:-8080} --reload

# Run via the entrypoint wrapper (respects all OFFICE_* env vars exactly like Docker).
.PHONY: run
run: serve

.PHONY: generate
generate:
	@echo "OpenAPI code generation is not used. Edit app/ manually."

# Python syntax check
.PHONY: fmt
fmt:
	@python3 -m py_compile app/*.py && echo "Python syntax OK"

.PHONY: lint
lint: fmt

# Run tests (integration tests are the authoritative suite)
.PHONY: test
test:
	@echo "No unit tests. Use 'make test-integration' or 'make test-integration-docker'."

# --- Integration tests (bash + curl against real LibreOffice) ----------------

# Generate (or refresh) sample spreadsheet files used by the integration tests.
# Requires soffice (LibreOffice) in PATH.
.PHONY: generate-samples
generate-samples:
	./DOCUMENTS/scripts/generate-samples.sh

# Run the full integration test suite against the local uvicorn server.
# Starts uvicorn on a test port, waits for /healthz, runs the test scripts,
# then stops the server.
# Usage:
#   PORT=18180 make test-integration
.PHONY: test-integration
test-integration: test-integration-python

.PHONY: test-integration-python
test-integration-python:
	@PORT=$${PORT:-18180} ./DOCUMENTS/scripts/run-integration-tests.sh --python

# Same as above but using the Docker image (validates the Dockerfile + entrypoint).
.PHONY: test-integration-docker
test-integration-docker: docker-build
	@PORT=$${PORT:-18180} ./DOCUMENTS/scripts/run-integration-tests.sh --docker

# Convenience
.PHONY: test-all
test-all: generate-samples test-integration-python

# Remove temporary artifacts left by the server or tests.
.PHONY: clean
clean:
	rm -f test-server.log
	rm -rf /tmp/convert-req-* /tmp/xlsb-to-xlsx-req-* /tmp/xlsx-to-ods-req-* /tmp/ods-to-xlsx-req-* /tmp/lo-profile-* 2>/dev/null || true
	find app -name '__pycache__' -type d -exec rm -rf {} + 2>/dev/null || true
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
	@echo "Available targets (Python + FastAPI + uvicorn):"
	@echo "  make build                    Install Python dependencies (pip install -r requirements.txt)"
	@echo "  make serve                    Start uvicorn locally (:8080 or OFFICE_PORT=...)"
	@echo "  make run                      Alias for serve"
	@echo "  make fmt / make lint          Run Python syntax check (py_compile)"
	@echo "  make clean                    Remove temp conversion dirs and logs"
	@echo ""
	@echo "  make generate-samples         Create testdata/ samples (needs soffice)"
	@echo "  make test-integration         Run full integration suite against local uvicorn"
	@echo "  make test-integration-docker  Run full integration suite inside the Docker image"
	@echo "  make test-all                 generate-samples + test-integration"
	@echo ""
	@echo "Docker:"
	@echo "  make docker-build             Build the debian:12-slim + Python + LibreOffice image"
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
        test-integration test-integration-python test-integration-docker test-all \
        docker-build docker-run docker-up help
