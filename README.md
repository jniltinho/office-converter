# office-converter

HTTP server for converting spreadsheet files between **XLSB**, **XLSX**, and **ODS** formats using LibreOffice in headless mode.

It provides both a simple web user interface and a flexible REST API that supports traditional file uploads and pure JSON (base64) requests.

## Features

- Automatic format detection (smart endpoint)
- Explicit typed endpoints for reliable integrations
- Web UI with drag & drop
- **Two API styles**:
  - `multipart/form-data` → returns the file directly (or JSON with `?format=json`)
  - `application/json` with base64 → always returns JSON response
- Graceful shutdown on SIGINT/SIGTERM
- Configurable host and port
- Limited concurrent conversions (safe for small machines)
- 100 MiB maximum upload size
- Ready for Docker and orchestrators (health check endpoint)

## Requirements

- **LibreOffice** (`soffice` command must be in `$PATH`) — required for actual conversions.
- Go 1.26+ (only if building from source).

The official Docker image already includes LibreOffice.

## Installation

### Build from source

```bash
git clone https://github.com/.../office-converter.git
cd office-converter

make build          # Optimized static binary (recommended)
# or
go build -o office-converter .
```

Cross-compilation examples are available in the Makefile (`make build-linux-amd64`, `make build-all`, etc.).

### Docker

```bash
docker build -t office-converter .
# or
make docker-build
```

The image uses a two-stage build: a `golang:1.26-bookworm` builder produces a static binary, and the final stage is `debian:12-slim` with only `libreoffice-calc` and minimal fonts (~350 MB). The server runs as a non-root user (`uid 10001`).

## Running

### Binary

```bash
./office-converter serve
```

**Flags (`serve` sub-command):**

| Flag          | Description                                             | Default          | Env var                  |
|---------------|---------------------------------------------------------|------------------|--------------------------|
| `--port`      | TCP port to listen on                                   | `8080`           | `OFFICE_PORT`            |
| `--host`      | Host/interface to bind to                               | (all interfaces) | `OFFICE_HOST`            |
| `--tls`       | Enable HTTPS                                            | `false`          | `OFFICE_TLS_ENABLED`     |
| `--tls-cert`  | Path to TLS certificate file                            | —                | `OFFICE_TLS_CERT`        |
| `--tls-key`   | Path to TLS private key file                            | —                | `OFFICE_TLS_KEY`         |
| `--swagger`   | Enable `/docs` and `/api/v1/openapi.json`               | `false`          | `OFFICE_SWAGGER_ENABLED` |
| `--config`    | Path to `config.toml` (auto-detected in cwd if absent) | —                | `OFFICE_CONFIG`          |

Examples:

```bash
./office-converter serve --port 9000
./office-converter serve --host 127.0.0.1 --port 8080
./office-converter serve --tls --tls-cert /certs/server.crt --tls-key /certs/server.key
./office-converter serve --swagger
./office-converter serve --config /etc/office-converter/config.toml
```

### Using Make

```bash
make run                    # runs `bin/office-converter serve`
```

### Docker

#### Basic run

```bash
docker run --rm -p 8080:8080 office-converter
```

#### Custom port via environment variable

```bash
docker run --rm -p 9000:9000 -e OFFICE_PORT=9000 office-converter
# or using Make
PORT=9000 make docker-run
```

#### All supported environment variables

| Variable              | Description                         | Default   |
|-----------------------|-------------------------------------|-----------|
| `OFFICE_PORT`         | HTTP port                           | `8080`    |
| `OFFICE_HOST`         | Bind address                        | (all)     |
| `OFFICE_TLS_ENABLED`  | Enable HTTPS (`true`/`false`)       | `false`   |
| `OFFICE_TLS_CERT`     | Path to TLS certificate file        | —         |
| `OFFICE_TLS_KEY`      | Path to TLS private key file        | —         |
| `OFFICE_CONFIG`       | Path to a `config.toml` file        | —         |

#### Mount a `config.toml`

```bash
docker run --rm -p 8080:8080 \
  -v "$(pwd)/config.toml:/home/appuser/config.toml:ro" \
  -e OFFICE_CONFIG=/home/appuser/config.toml \
  office-converter
```

#### Enable TLS

```bash
docker run --rm -p 8443:8443 \
  -v /etc/ssl/certs/server.crt:/certs/server.crt:ro \
  -v /etc/ssl/private/server.key:/certs/server.key:ro \
  -e OFFICE_PORT=8443 \
  -e OFFICE_TLS_ENABLED=true \
  -e OFFICE_TLS_CERT=/certs/server.crt \
  -e OFFICE_TLS_KEY=/certs/server.key \
  office-converter
```

#### Docker Compose

```yaml
services:
  office-converter:
    image: office-converter:latest
    build: .
    ports:
      - "8080:8080"
    environment:
      OFFICE_PORT: "8080"
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/healthz"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
```

Run with:

```bash
docker compose up -d
docker compose logs -f
```

#### Health check

The `/healthz` endpoint is suitable for Docker, Kubernetes liveness/readiness probes, and load balancers:

```bash
# Kubernetes liveness probe example (in your Pod spec)
livenessProbe:
  httpGet:
    path: /healthz
    port: 8080
  initialDelaySeconds: 10
  periodSeconds: 30
```

The container runs as a non-root user (`uid 10001`).

## Web Interface

Browse to `http://localhost:8080`.

- Drag & drop or click to select a file.
- Supported formats: `.xlsb`, `.xlsx`, `.ods`
- The interface automatically chooses the correct conversion endpoint.

## API Reference

### Endpoints

| Method | Path                                 | Description                              |
|--------|--------------------------------------|------------------------------------------|
| POST   | `/api/v1/convert`                    | Smart endpoint (auto-detects direction)  |
| POST   | `/api/v1/convert/xlsb-to-xlsx`       | Convert `.xlsb` → `.xlsx`                |
| POST   | `/api/v1/convert/xlsx-to-ods`        | Convert `.xlsx` → `.ods`                 |
| POST   | `/api/v1/convert/ods-to-xlsx`        | Convert `.ods` → `.xlsx`                 |
| GET    | `/healthz`                           | Health check (for load balancers/K8s)    |

### Client Examples

Full examples for each client are in the [`DOCUMENTS/`](DOCUMENTS/) folder:

| Guide | Description |
|-------|-------------|
| [DOCUMENTS/curl.md](DOCUMENTS/curl.md) | `curl` examples — multipart, JSON/base64, smart endpoint, health check |
| [DOCUMENTS/axios.md](DOCUMENTS/axios.md) | Axios (Node.js) — multipart, JSON/base64, error handling |
| [DOCUMENTS/postman.md](DOCUMENTS/postman.md) | Postman setup guide |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Ready-to-import Postman collection |

### Pure JSON API (base64) – Request and Response formats

Use `Content-Type: application/json`.

**Request body (`ConvertRequest`):**

```json
{
  "file": "<base64-encoded file content>",
  "filename": "planilha.xlsb"
}
```

- `file` (required): Base64 string of the original spreadsheet.
- `filename` (recommended): Used for format detection on the smart endpoint and for the output filename.

**Success response (`ConvertResponse`):**

```json
{
  "success": true,
  "filename": "planilha.xlsx",
  "content_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "size": 45231,
  "data": "<base64-encoded converted file>"
}
```

**Error response:**

```json
{
  "error": "missing 'file' form field"
}
```

### Health Check

```bash
curl http://localhost:8080/healthz
# or
curl -I http://localhost:8080/healthz
```

Returns `200 OK` with body `ok`.

## Limits and Behavior

- **Maximum file size**: 100 MiB (enforced by middleware).
- **Concurrent conversions**: Limited to 2 by default. Each conversion uses its own temporary LibreOffice user profile.
- **Timeouts**: 60 seconds per conversion after acquiring a worker slot.
- **Graceful shutdown**: 15 seconds to finish in-flight requests.

## Makefile Targets

```bash
make build              # Build optimized binary (default)
make run                # Run with go run (ARGS="--port 9000")
make docker-build
make docker-run         # PORT=9000 make docker-run
make docker-up
make fmt
make clean
make build-all          # Cross-compile for common platforms
make help
```

## Development

```bash
make fmt
make build-debug        # Binary with debug symbols
```

## Notes

- The server requires `soffice` (LibreOffice Calc) to be installed and reachable.
- Temporary files are created under `/tmp` and cleaned up automatically.
- The embedded web UI is self-contained (single HTML + CSS + JS file).

---

This project was originally written with Brazilian Portuguese comments and UI, later fully translated to English with godoc-style documentation and a first-class JSON API.