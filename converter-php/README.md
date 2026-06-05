# office-converter

HTTP server for converting spreadsheet files between **XLSB**, **XLSX**, and **ODS** formats using LibreOffice in headless mode.

PHP implementation running under [FrankenPHP](https://frankenphp.dev/) in worker mode inside a `debian:12-slim` based Docker image, or under the PHP built-in server (`php -S`) for local development.

![Web Interface](DOCUMENTS/screenshot/screenshot_home.png)

## Features

- Smart endpoint with automatic format detection
- Explicit typed endpoints for reliable integrations
- Web UI with drag & drop
- Two API styles: `multipart/form-data` (returns file) and `application/json` with base64 (returns JSON)
- Fully configurable via environment variables — see [`env.example`](env.example) for a documented template
- Ready for Docker and Kubernetes (health check endpoint)
- 100 MiB upload limit, 2 concurrent conversion slots (effective concurrency is 2 by default under FrankenPHP workers; multiple workers run in parallel)
- No Swagger UI (feature removed)

## Quick Start

### Docker (recommended)

```bash
docker build -t office-converter .
docker run --rm -p 8080:8080 office-converter
```

The container starts FrankenPHP in worker mode (Slim app):

```bash
frankenphp run --config /tmp/Caddyfile --adapter caddyfile
# (Caddyfile loads worker at /app/api/worker.php)
```

Open `http://localhost:8080` in your browser.

### PHP built-in server (local development)

```bash
# requires soffice in PATH
php -d upload_max_filesize=100M -d post_max_size=101M -S 0.0.0.0:8080 api/router.php
```

Or simply:

```bash
make serve
```

## API Endpoints

| Method | Path                            | Description                             |
|--------|---------------------------------|-----------------------------------------|
| POST   | `/api/v1/convert`               | Smart endpoint (auto-detects direction) |
| POST   | `/api/v1/convert/xlsb-to-xlsx`  | `.xlsb` → `.xlsx`                       |
| POST   | `/api/v1/convert/xlsx-to-ods`   | `.xlsx` → `.ods`                        |
| POST   | `/api/v1/convert/ods-to-xlsx`   | `.ods` → `.xlsx`                        |
| GET    | `/healthz`                      | Health check                            |

## Documentation

| Guide | Description |
|-------|-------------|
| [DOCUMENTS/README.md](DOCUMENTS/README.md) | Full reference: environment variables, Docker, Makefile |
| [DOCUMENTS/curl.md](DOCUMENTS/curl.md) | `curl` examples |
| [DOCUMENTS/axios.md](DOCUMENTS/axios.md) | Axios (Node.js) examples |
| [DOCUMENTS/postman.md](DOCUMENTS/postman.md) | Postman setup guide |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Ready-to-import Postman collection |
| [DOCUMENTS/scripts/README.md](DOCUMENTS/scripts/README.md) | Integration test scripts |

---

Also available in [Português (pt-BR)](README.pt-BR.md).
