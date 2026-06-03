# office-converter — Full Reference

Complete documentation for running, configuring, and deploying office-converter.

## Running

### Binary flags (`serve` sub-command)

```bash
./office-converter serve [flags]
```

| Flag                           | Description                                             | Default          | Env var                            |
|--------------------------------|---------------------------------------------------------|------------------|------------------------------------|
| `--port`                       | TCP port to listen on                                   | `8080`           | `OFFICE_PORT`                      |
| `--host`                       | Host/interface to bind to                               | (all interfaces) | `OFFICE_HOST`                      |
| `--conversion-timeout`         | Per-conversion deadline after acquiring a slot          | `60s`            | `OFFICE_CONVERSION_TIMEOUT`        |
| `--max-upload-size`            | Maximum upload size in bytes                            | `104857600` (100 MiB) | `OFFICE_MAX_UPLOAD_SIZE`      |
| `--max-concurrent-conversions` | Maximum parallel LibreOffice instances                  | `2`              | `OFFICE_MAX_CONCURRENT_CONVERSIONS`|
| `--tls`                        | Enable HTTPS                                            | `false`          | `OFFICE_TLS_ENABLED`               |
| `--tls-cert`                   | Path to TLS certificate file                            | —                | `OFFICE_TLS_CERT`                  |
| `--tls-key`                    | Path to TLS private key file                            | —                | `OFFICE_TLS_KEY`                   |
| `--swagger`                    | Enable `/docs` and `/api/v1/openapi.json`               | `false`          | `OFFICE_SWAGGER_ENABLED`           |
| `--config`                     | Path to `config.toml` (auto-detected in cwd if absent) | —                | `OFFICE_CONFIG`                    |

Examples:

```bash
./office-converter serve --port 9000
./office-converter serve --host 127.0.0.1 --port 8080
./office-converter serve --conversion-timeout 120s
./office-converter serve --max-upload-size 209715200   # 200 MiB
./office-converter serve --max-concurrent-conversions 4
./office-converter serve --tls --tls-cert /certs/server.crt --tls-key /certs/server.key
./office-converter serve --swagger
./office-converter serve --config /etc/office-converter/config.toml
```

### Using Make

```bash
make run          # runs bin/office-converter serve
make build        # optimized static binary
make build-debug  # binary with debug symbols
make build-all    # cross-compile for all platforms
```

---

## Docker

### Basic run

```bash
docker run --rm -p 8080:8080 office-converter
```

### Custom port

```bash
docker run --rm -p 9000:9000 -e OFFICE_PORT=9000 office-converter
# or
PORT=9000 make docker-run
```

### All supported environment variables

| Variable                             | Description                           | Default             |
|--------------------------------------|---------------------------------------|---------------------|
| `OFFICE_PORT`                        | HTTP port                             | `8080`              |
| `OFFICE_HOST`                        | Bind address                          | (all)               |
| `OFFICE_CONVERSION_TIMEOUT`          | Per-conversion deadline               | `60s`               |
| `OFFICE_MAX_UPLOAD_SIZE`             | Maximum upload size in bytes          | `104857600` (100 MiB)|
| `OFFICE_MAX_CONCURRENT_CONVERSIONS`  | Maximum parallel LibreOffice instances| `2`                 |
| `OFFICE_TLS_ENABLED`                 | Enable HTTPS (`true`/`false`)         | `false`             |
| `OFFICE_TLS_CERT`                    | Path to TLS certificate file          | —                   |
| `OFFICE_TLS_KEY`                     | Path to TLS private key file          | —                   |
| `OFFICE_SWAGGER_ENABLED`             | Enable Swagger UI and OpenAPI JSON    | `false`             |
| `OFFICE_CONFIG`                      | Path to a `config.toml` file          | —                   |

### Enable TLS

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

### Mount a `config.toml`

```bash
docker run --rm -p 8080:8080 \
  -v "$(pwd)/config.toml:/home/appuser/config.toml:ro" \
  -e OFFICE_CONFIG=/home/appuser/config.toml \
  office-converter
```

### Docker Compose

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

```bash
docker compose up -d
docker compose logs -f
```

### Health check

The `/healthz` endpoint is suitable for Docker, Kubernetes probes, and load balancers:

```bash
curl http://localhost:8080/healthz   # returns 200 OK with body "ok"
curl -I http://localhost:8080/healthz
```

Kubernetes liveness probe example:

```yaml
livenessProbe:
  httpGet:
    path: /healthz
    port: 8080
  initialDelaySeconds: 10
  periodSeconds: 30
```

The container runs as a non-root user (`uid 10001`).

---

## Configuration file (`config.toml`)

A `config.toml` in the working directory is loaded automatically. Use `--config` or `OFFICE_CONFIG` to specify a different path.

```toml
[server]
host = ""
port = 8080
graceful_timeout = "15s"
conversion_timeout = "60s"
max_upload_size = 104857600   # bytes (100 MiB)
max_concurrent_conversions = 2

[tls]
enabled = false
cert_file = ""
key_file  = ""

[swagger]
enabled = false
```

Priority order (highest wins): CLI flags → environment variables → config file → defaults.

---

## API Reference

### Request format (`multipart/form-data`)

```bash
curl -F "file=@spreadsheet.xlsb" http://localhost:8080/api/v1/convert -o out.xlsx
# force JSON response
curl -F "file=@spreadsheet.xlsb" "http://localhost:8080/api/v1/convert?format=json"
```

### Request format (`application/json` with base64)

```json
{
  "file": "<base64-encoded file content>",
  "filename": "spreadsheet.xlsb"
}
```

- `file` (required): Base64 string of the original spreadsheet.
- `filename` (recommended): Used for format detection on the smart endpoint and for the output filename.

### Success response

```json
{
  "success": true,
  "filename": "spreadsheet.xlsx",
  "content_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "size": 45231,
  "data": "<base64-encoded converted file>"
}
```

### Error response

```json
{
  "error": "missing 'file' form field"
}
```

---

## Limits and behavior

| Setting               | Default                                          | Configurable via                       |
|-----------------------|--------------------------------------------------|----------------------------------------|
| Maximum file size     | 100 MiB                                          | `--max-upload-size` / `OFFICE_MAX_UPLOAD_SIZE` |
| Concurrent conversions| 2 (each uses its own LibreOffice user profile)   | `--max-concurrent-conversions` / `OFFICE_MAX_CONCURRENT_CONVERSIONS` |
| Conversion timeout    | 60 s (after acquiring a worker slot)             | `--conversion-timeout` / `OFFICE_CONVERSION_TIMEOUT` |
| Graceful shutdown     | 15 s to finish in-flight requests                | `server.graceful_timeout` in config    |

---

## Makefile targets

| Target                   | Description                                      |
|--------------------------|--------------------------------------------------|
| `make build`             | Optimized static binary                          |
| `make build-debug`       | Binary with debug symbols                        |
| `make build-all`         | Cross-compile for Linux/macOS/Windows (amd64)    |
| `make run`               | Run `bin/office-converter serve`                 |
| `make fmt`               | Format Go source                                 |
| `make docker-build`      | Build Docker image                               |
| `make docker-run`        | Run container (`PORT=9000 make docker-run`)      |
| `make docker-up`         | `docker compose up -d`                           |
| `make test-integration`  | Integration tests via local binary               |
| `make generate-samples`  | Generate test sample files                       |
| `make clean`             | Remove build artifacts                           |
| `make help`              | Show all targets                                 |
