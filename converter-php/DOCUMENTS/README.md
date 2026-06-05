# office-converter — Full Reference (PHP port)

Complete documentation for running, configuring, and deploying office-converter (PHP implementation running under FrankenPHP in worker mode on a `debian:12-slim` based Docker image, or under the PHP built-in server for local development).

## Running

The PHP port is configured exclusively via environment variables (no CLI flags, no config file parser).

| Variable                             | Description                                      | Default             |
|--------------------------------------|--------------------------------------------------|---------------------|
| `OFFICE_PORT`                        | TCP port FrankenPHP binds to                     | `8080`              |
| `OFFICE_HOST`                        | Bind address                                     | `0.0.0.0`           |
| `OFFICE_CONVERSION_TIMEOUT`          | Per-conversion deadline (after acquiring slot)   | `60s`               |
| `OFFICE_MAX_UPLOAD_SIZE`             | Maximum upload size in bytes                     | `104857600` (100 MiB)|
| `OFFICE_MAX_CONCURRENT_CONVERSIONS`  | Maximum parallel LibreOffice instances (slot pool) | `2`               |

A complete and well-commented template is available in the root of the repository: [`env.example`](env.example).

**Notes**
- The Docker image runs FrankenPHP in worker mode. FrankenPHP is built on Caddy and can natively serve HTTPS (set `SERVER_NAME` / mount certs via FrankenPHP config). The local `php -S` server does not support HTTPS — use a reverse proxy if you need it locally.
- Swagger UI and `/api/v1/openapi.json` endpoints are not provided (feature removed).

### Local (FrankenPHP — worker mode, recommended)

If you have the [FrankenPHP binary](https://frankenphp.dev/docs/install/) installed, you can run the application locally in the same persistent-worker mode used in production. The Slim app is bootstrapped once; FrankenPHP handles every subsequent request without re-loading PHP.

Create a local `Caddyfile` at the project root:

```
{
    auto_https off
}

:8080 {
    php_server {
        worker {
            file api/public/index.php
            match *
        }
    }
}
```

Then start the server:

```bash
OFFICE_MAX_UPLOAD_SIZE=104857600 OFFICE_MAX_CONCURRENT_CONVERSIONS=2 \
  frankenphp run --config Caddyfile --adapter caddyfile
```

For a lighter non-worker mode (one process per request, similar to `php -S`):

```bash
frankenphp php-server --listen :8080 --root api/public/
```

### Local (PHP built-in server — fallback)

```bash
php -d upload_max_filesize=100M -d post_max_size=101M -S 0.0.0.0:8080 api/public/index.php
# or
make serve
```

### Using Make

```bash
make serve          # starts php -S on api/public/index.php (respects OFFICE_*)
make docker-build
make docker-run
```

### Docker

```bash
docker run --rm -p 8080:8080 \
  -e OFFICE_PORT=8080 \
  -e OFFICE_MAX_UPLOAD_SIZE=104857600 \
  office-converter:latest
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

### Using an env file

Copy the template and edit the values:

```bash
cp env.example .env
# edit .env as needed
```

Then pass it to `docker run`:

```bash
docker run --rm -p 8080:8080 --env-file .env office-converter:latest
```

If the port is defined in the file, map it consistently:

```bash
# .env contains OFFICE_PORT=9000
docker run --rm -p 9000:9000 --env-file .env office-converter:latest
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

## Configuration

The application is configured **exclusively via environment variables**. There is no config file parser.

### Using `env.example`

A well-commented template is provided at the root of the repository:

```bash
cp env.example .env
# edit .env as needed
```

Then run the container with:

```bash
docker run --rm --env-file .env -p 8080:8080 office-converter
```

Or with Docker Compose:

```yaml
services:
  office-converter:
    image: office-converter:latest
    env_file:
      - .env
    ports:
      - "8080:8080"
```

See the comments inside `env.example` for detailed explanations of each variable, units, and recommendations.

### Supported Variables (summary)

| Variable                             | Description                                      | Default             |
|--------------------------------------|--------------------------------------------------|---------------------|
| `OFFICE_PORT`                        | TCP port the server listens on                   | `8080`              |
| `OFFICE_HOST`                        | Network interface to bind to                     | `0.0.0.0`           |
| `OFFICE_CONVERSION_TIMEOUT`          | Max duration for one LibreOffice conversion      | `60s`               |
| `OFFICE_MAX_UPLOAD_SIZE`             | Maximum file upload size (bytes)                 | `104857600` (100 MiB) |
| `OFFICE_MAX_CONCURRENT_CONVERSIONS`  | Max parallel conversions (flock-based slots)     | `2`                 |

**Tip:** The values above are also the ones used when no variable is provided. The `env.example` file contains the same defaults with much more context.

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

## Makefile targets (PHP port)

| Target                        | Description                                           |
|-------------------------------|-------------------------------------------------------|
| `make serve`                  | Start `php -S` on :8080 (or OFFICE_PORT=...)          |
| `make run`                    | Alias for `serve`                                     |
| `make docker-build`           | Build the `debian:12-slim` + FrankenPHP image         |
| `make docker-run`             | Run container (`PORT=9000 make docker-run`)           |
| `make docker-up`              | Build + run                                           |
| `make fmt` / `make lint`      | `php -l` on api/*.php + api/app/*.php                 |
| `make test-integration-php`   | Full integration tests against local php -S           |
| `make test-integration-docker`| Full integration tests inside the Docker image        |
| `make generate-samples`       | Generate testdata/sample.* (needs soffice)            |
| `make clean`                  | Remove temp conversion artifacts                      |
| `make help`                   | Show this help                                        |
