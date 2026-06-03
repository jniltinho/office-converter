# office-converter

HTTP server for converting spreadsheet files between **XLSB**, **XLSX**, and **ODS** formats using LibreOffice in headless mode.

![Web Interface](DOCUMENTS/screenshot/screenshot_home.png)

## Features

- Smart endpoint with automatic format detection
- Explicit typed endpoints for reliable integrations
- Web UI with drag & drop
- Two API styles: `multipart/form-data` (returns file) and `application/json` with base64 (returns JSON)
- TLS/HTTPS support
- Configurable via flags, environment variables, or `config.toml`
- Ready for Docker and Kubernetes (health check endpoint)
- Graceful shutdown, 100 MiB upload limit, 2 concurrent conversions

## Requirements

- **LibreOffice** (`soffice` in `$PATH`) — the official Docker image already includes it.
- Go 1.26+ (only to build from source).

## Quick Start

### Binary

```bash
make build
./office-converter serve
```

### Docker

```bash
docker build -t office-converter .
docker run --rm -p 8080:8080 office-converter
```

Open `http://localhost:8080` in your browser.

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
| [DOCUMENTS/README.md](DOCUMENTS/README.md) | Full reference: flags, env vars, Docker, config, Makefile |
| [DOCUMENTS/curl.md](DOCUMENTS/curl.md) | `curl` examples |
| [DOCUMENTS/axios.md](DOCUMENTS/axios.md) | Axios (Node.js) examples |
| [DOCUMENTS/postman.md](DOCUMENTS/postman.md) | Postman setup guide |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Ready-to-import Postman collection |
| [DOCUMENTS/scripts/README.md](DOCUMENTS/scripts/README.md) | Integration test scripts |

---

Also available in [Português (pt-BR)](README.pt-BR.md).
