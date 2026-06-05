# office-converter — Full Reference

Complete documentation for running, configuring, and deploying **office-converter** — a Python 3 HTTP service that converts spreadsheet files between XLSB, XLSX, and ODS formats using LibreOffice in headless mode.

Stack: **FastAPI** + **uvicorn** on a `debian:12-slim` Docker image.

## Running

The application is configured **exclusively via environment variables** (no config file, no CLI flags beyond `make`/`docker run`).

| Variable                             | Description                                        | Default              |
|--------------------------------------|----------------------------------------------------|----------------------|
| `OFFICE_PORT`                        | TCP port uvicorn binds to                          | `8080`               |
| `OFFICE_HOST`                        | Bind address                                       | `0.0.0.0`            |
| `OFFICE_CONVERSION_TIMEOUT`          | Per-conversion deadline (after acquiring slot)     | `60s`                |
| `OFFICE_MAX_UPLOAD_SIZE`             | Maximum upload size in bytes                       | `104857600` (100 MiB)|
| `OFFICE_MAX_CONCURRENT_CONVERSIONS`  | Maximum parallel LibreOffice instances             | `2`                  |

A complete and well-commented template is available at the root of the repository: [`env.example`](../env.example).

**Notes**
- `OFFICE_CONVERSION_TIMEOUT` accepts values like `30s`, `2m`, `1h`.
- `OFFICE_MAX_UPLOAD_SIZE` is enforced by the FastAPI middleware before the request body is read.
- `OFFICE_MAX_CONCURRENT_CONVERSIONS` is enforced by an `asyncio.Semaphore` within the single uvicorn worker process. Running multiple uvicorn workers would give each its own semaphore — use `--workers 1` (the default in `entrypoint.sh`) to keep a single shared pool.
- Interactive API docs are available at `/docs` (Swagger UI), `/redoc`, and `/openapi.json`.

---

### Local development (uvicorn with auto-reload)

Requires Python 3.11+, `soffice` in `PATH`, and the dependencies installed:

```bash
pip install -r requirements.txt
make serve
# or directly:
OFFICE_PORT=8080 python3 -m uvicorn app.main:app --host 0.0.0.0 --port 8080 --reload
```

The `--reload` flag restarts uvicorn automatically whenever a source file changes.

### Using Make

```bash
make serve                   # starts uvicorn with --reload on :8080 (or OFFICE_PORT=...)
make build                   # pip install -r requirements.txt
make docker-build
make docker-run
make test-integration        # runs uvicorn locally + full curl test suite
make test-integration-docker # runs tests inside the Docker image
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

| Variable                             | Description                                        | Default              |
|--------------------------------------|----------------------------------------------------|----------------------|
| `OFFICE_PORT`                        | TCP port the server listens on                     | `8080`               |
| `OFFICE_HOST`                        | Network interface to bind to                       | `0.0.0.0`            |
| `OFFICE_CONVERSION_TIMEOUT`          | Max duration for one LibreOffice conversion        | `60s`                |
| `OFFICE_MAX_UPLOAD_SIZE`             | Maximum file upload size (bytes)                   | `104857600` (100 MiB)|
| `OFFICE_MAX_CONCURRENT_CONVERSIONS`  | Max parallel conversions (asyncio.Semaphore)       | `2`                  |

---

## API Reference

### Endpoints

| Method    | Path                            | Description                             |
|-----------|---------------------------------|-----------------------------------------|
| `POST`    | `/api/v1/convert`               | Smart endpoint — auto-detects direction |
| `POST`    | `/api/v1/convert/xlsb-to-xlsx`  | `.xlsb` → `.xlsx` only                 |
| `POST`    | `/api/v1/convert/xlsx-to-ods`   | `.xlsx` → `.ods` only                  |
| `POST`    | `/api/v1/convert/ods-to-xlsx`   | `.ods` → `.xlsx` only                  |
| `GET`     | `/healthz`                      | Health check — returns `200 ok`         |
| `HEAD`    | `/healthz`                      | Health check without body               |
| `GET`     | `/`                             | Web UI (drag & drop)                    |
| `GET`     | `/docs`                         | Swagger UI — interactive API explorer   |
| `GET`     | `/redoc`                        | ReDoc — readable API reference          |
| `GET`     | `/openapi.json`                 | Raw OpenAPI 3 schema                    |

### Request format: `multipart/form-data`

Upload the file directly — the server streams back the converted file as a binary download.

```bash
curl -F "file=@spreadsheet.xlsb" http://localhost:8080/api/v1/convert -o out.xlsx
# force JSON response instead of binary download
curl -F "file=@spreadsheet.xlsb" "http://localhost:8080/api/v1/convert?format=json"
```

Appending `?format=json` or sending `Accept: application/json` returns a JSON envelope instead of a binary download.

### Request format: `application/json` with base64

```json
{
  "file": "<base64-encoded file content>",
  "filename": "spreadsheet.xlsb"
}
```

- `file` (required): Base64-encoded bytes of the source spreadsheet.
- `filename` (required for `/api/v1/convert`): Used to detect the source format and set the output filename. Optional on typed endpoints (e.g. `/api/v1/convert/xlsb-to-xlsx`).

### Success response (JSON mode)

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

### HTTP status codes

| Code | Meaning |
|------|---------|
| `200` | Conversion successful |
| `400` | Bad request (missing field, invalid base64, no filename on smart JSON) |
| `404` | Route not found |
| `413` | File too large (exceeds `OFFICE_MAX_UPLOAD_SIZE`) |
| `415` | Unsupported format (wrong extension for typed endpoint, or unknown extension on smart endpoint) |
| `422` | LibreOffice conversion failed (likely a corrupt or unsupported file) |
| `503` | All conversion slots busy — retry after a moment |

---

## Limits and behavior

| Setting                | Default                                        | Configured via                          |
|------------------------|------------------------------------------------|-----------------------------------------|
| Maximum file size      | 100 MiB                                        | `OFFICE_MAX_UPLOAD_SIZE`                |
| Concurrent conversions | 2 (each uses its own LibreOffice user profile) | `OFFICE_MAX_CONCURRENT_CONVERSIONS`     |
| Slot-acquisition wait  | 30 s (returns 503 if no slot becomes free)     | hardcoded                               |
| Conversion timeout     | 60 s (after acquiring a slot)                  | `OFFICE_CONVERSION_TIMEOUT`             |

---

## Code structure

```
app/
├── __init__.py      Package marker.
├── config.py        Settings loaded from OFFICE_* env vars via pydantic-settings BaseSettings.
│                    Exposes get_settings() (lru_cache singleton) and ACQUIRE_TIMEOUT constant.
├── schemas.py       Pydantic request/response models used for validation and OpenAPI docs:
│                    ConvertJsonRequest, ConvertJsonResponse, ErrorResponse.
├── ui.py            Embedded drag-and-drop web UI HTML served at GET /.
├── converter.py     LibreOffice subprocess wrapper.
│                    Public API: convert(src, out_dir, to_format) → output path.
│                    Concurrency via asyncio.Semaphore (get_semaphore()).
├── handlers.py      Request-processing logic shared by all conversion routes:
│                    dispatch(), handle_multipart(), handle_json_body(), run_conversion(),
│                    wants_json(), is_json_request(), make_work_dir().
├── routes/
│   ├── __init__.py  Combines sub-routers; exports single `router` used by main.py.
│   ├── health.py    GET / (web UI) + GET/HEAD /healthz.
│   └── convert.py   POST /api/v1/convert* — route decorators + OpenAPI specs.
└── main.py          FastAPI factory (create_app()), upload-size middleware,
                     404 handler, module-level app instance, __main__ entry point.
```

### Dependencies (`requirements.txt`)

| Package | Purpose |
|---|---|
| `fastapi` | Web framework — routing, OpenAPI generation, dependency injection |
| `uvicorn[standard]` | ASGI server with `uvloop` + `httptools` for performance |
| `python-multipart` | Required by FastAPI to parse `multipart/form-data` uploads |
| `pydantic-settings` | `BaseSettings` — maps `OFFICE_*` env vars to typed Python attributes |

---

## Makefile targets

| Target                          | Description                                              |
|---------------------------------|----------------------------------------------------------|
| `make serve`                    | Start uvicorn with `--reload` on :8080 (or OFFICE_PORT=…)|
| `make run`                      | Alias for `serve`                                        |
| `make build`                    | `pip install -r requirements.txt`                        |
| `make fmt` / `make lint`        | Python syntax check (`py_compile`) on `app/*.py`         |
| `make clean`                    | Remove temp conversion artifacts and `__pycache__`       |
| `make generate-samples`         | Generate `testdata/sample.*` (needs `soffice`)           |
| `make test-integration`         | Full integration tests against local uvicorn             |
| `make test-integration-docker`  | Full integration tests inside the Docker image           |
| `make test-all`                 | `generate-samples` + `test-integration`                  |
| `make docker-build`             | Build the `debian:12-slim` + Python + LibreOffice image  |
| `make docker-run`               | Run container (`PORT=9000 make docker-run`)              |
| `make docker-up`                | Build + run                                              |
| `make help`                     | Show all targets                                         |
