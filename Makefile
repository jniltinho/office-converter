# Makefile for office-converter
#
# Common usage:
#   make build          # build the optimized binary (matching the Dockerfile)
#   make run            # run via 'go run' (useful for dev; ARGS='--port 9000')
#   make docker-build   # build the Docker image
#   make clean          # remove the binary and temporary artifacts

APP_NAME   := office-converter
BIN_DIR    := bin
BIN        := $(BIN_DIR)/$(APP_NAME)
PREFIX     := office-converter/cmd
GO         := go
GOFLAGS    := -trimpath
VERSION    := $(shell git describe --tags --abbrev=0 2>/dev/null || echo "dev")
BUILD_TIME := $(shell date -u +"%Y-%m-%dT%H:%M:%SZ")
GIT_COMMIT := $(shell git rev-parse --short HEAD 2>/dev/null || echo "unknown")

LDFLAGS    := -ldflags "-s -w -X $(PREFIX).Version=$(VERSION) -X $(PREFIX).BuildDate=$(BUILD_TIME) -X $(PREFIX).GitCommit=$(GIT_COMMIT)"

DOCKER_IMAGE := office-converter

# Default target
.PHONY: all
all: build

# Build the binary for the current platform (CGO disabled, static binary).
# Uses the same flags as the Dockerfile for reproducibility.
.PHONY: build
build:
	@mkdir -p $(BIN_DIR)
	CGO_ENABLED=0 $(GO) build $(GOFLAGS) $(LDFLAGS) -o $(BIN) .
	upx --best $(BIN)

# Build with debug symbols (useful for investigation)
.PHONY: build-debug
build-debug:
	@mkdir -p $(BIN_DIR)
	$(GO) build $(GOFLAGS) -o $(BIN) .

# Run the application directly via 'go run'. Requires 'soffice' (LibreOffice) in PATH.
# Usage: make run
#        make run ARGS="--port 9000"
.PHONY: run
run:
	bin/office-converter serve

# Generate code from OpenAPI spec (requires oapi-codegen: go install github.com/oapi-codegen/oapi-codegen/v2/cmd/oapi-codegen@latest)
.PHONY: generate
generate:
	oapi-codegen --config api/oapi-codegen.yaml api/openapi.yaml

# Format code and tidy dependencies
.PHONY: fmt
fmt:
	$(GO) fmt ./...
	$(GO) mod tidy

# Run tests (if any)
.PHONY: test
test:
	$(GO) test -v ./...

# --- Integration tests (bash + curl against real LibreOffice) ----------------

# Generate (or refresh) sample spreadsheet files used by the integration tests.
# Requires soffice (LibreOffice) in PATH.
.PHONY: generate-samples
generate-samples:
	./scripts/generate-samples.sh

# Run the full integration test suite.
# Starts the locally built binary on a test port (18180 by default), waits for /healthz,
# executes test-health.sh + test-api.sh, then stops the server.
# Port can be overridden: PORT=19000 make test-integration
.PHONY: test-integration
test-integration: build
	@PORT=$${PORT:-18180} ./scripts/run-integration-tests.sh

# Same as test-integration but forces the docker image path.
.PHONY: test-integration-docker
test-integration-docker: docker-build
	@PORT=$${PORT:-18180} ./scripts/run-integration-tests.sh --docker

# Convenience: generate samples + run integration tests in one step.
.PHONY: test-all
test-all: generate-samples test-integration

# Remove the generated binary and conversion temporary directories
.PHONY: clean
clean:
	rm -rf $(BIN_DIR)
	rm -f test-server.log
	rm -rf /tmp/convert-req-* /tmp/xlsb-to-xlsx-req-* /tmp/xlsx-to-ods-req-* /tmp/ods-to-xlsx-req-* /tmp/lo-profile-* 2>/dev/null || true
	@echo "Cleanup complete."

# --- TLS ---

SSL_DIR  ?= certs
SSL_CERT := $(SSL_DIR)/server.crt
SSL_KEY  := $(SSL_DIR)/server.key

# Generate a self-signed certificate valid for 3650 days (≈10 years).
# Output: certs/server.crt and certs/server.key (override with SSL_DIR=<path>).
.PHONY: gen-ssl
gen-ssl:
	@mkdir -p $(SSL_DIR)
	openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
	  -keyout $(SSL_KEY) \
	  -out    $(SSL_CERT) \
	  -subj   "/CN=localhost" \
	  -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
	@echo "Certificate : $(SSL_CERT)"
	@echo "Private key : $(SSL_KEY)"
	@echo "Run with TLS: ./$(BIN) serve --tls --tls-cert $(SSL_CERT) --tls-key $(SSL_KEY)"

# --- Docker ---

# Build the Docker image (same process as the Dockerfile)
.PHONY: docker-build
docker-build:
	docker build -t $(DOCKER_IMAGE):latest .

# Run the container locally.
# Usage: make docker-run
#        PORT=9000 make docker-run
PORT ?= 8080
.PHONY: docker-run
docker-run:
	docker run --rm -p $(PORT):$(PORT) $(DOCKER_IMAGE):latest serve --port $(PORT)

# Build + run in sequence
.PHONY: docker-up
docker-up: docker-build docker-run

# --- Cross-compilation (examples) ---

.PHONY: build-linux-amd64
build-linux-amd64:
	@mkdir -p $(BIN_DIR)
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 $(GO) build $(GOFLAGS) $(LDFLAGS) -o $(BIN_DIR)/$(APP_NAME)-linux-amd64 .



# Build for the most common platforms
.PHONY: build-all
build-all: build-linux-amd64

# --- Utilities ---

# Show this help
.PHONY: help
help:
	@echo "Available targets:"
	@echo "  make build              Build the optimized binary (default)"
	@echo "  make build-debug        Build with debug symbols"
	@echo "  make run                Run with 'go run .' (use ARGS='serve --port 9000')"
	@echo "  make generate           Regenerate code from api/openapi.yaml (needs oapi-codegen)"
	@echo "  make fmt                Format code + go mod tidy"
	@echo "  make test               Run the (Go) tests"
	@echo "  make clean              Remove binary and temps"
	@echo ""
	@echo "  make generate-samples   Create/refresh testdata/sample.xlsx + sample.ods (needs soffice)"
	@echo "  make test-integration   Build + run full bash integration tests (local binary)"
	@echo "  make test-integration-docker  Same but using docker image"
	@echo "  make test-all           generate-samples + test-integration"
	@echo ""
	@echo "  make docker-build       Build the Docker image"
	@echo "  make docker-run         Run the container (use PORT=9000 for a different port)"
	@echo "  make docker-up          Build + run the Docker image"
	@echo ""
	@echo "  make build-linux-amd64  Cross-compile for Linux amd64"
	@echo "  make build-all          Cross-compile for common platforms"
	@echo "  make gen-ssl            Generate self-signed TLS cert (3650 days) in certs/"
	@echo "  make help               Show this message"

# Prevent make from trying to create files with these names
.PHONY: all build build-debug run generate fmt test clean generate-samples test-integration test-integration-docker test-all \
        docker-build docker-run docker-up \
        build-linux-amd64 build-all gen-ssl help
