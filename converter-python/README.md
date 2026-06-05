# office-converter

HTTP server for converting spreadsheet files between **XLSB**, **XLSX**, and **ODS** formats using LibreOffice in headless mode.

Python 3 implementation using [FastAPI](https://fastapi.tiangolo.com/) and [uvicorn](https://www.uvicorn.org/), running inside a `debian:12-slim` Docker image.

![Web Interface](DOCUMENTS/screenshot/screenshot_home.png)

## Features

- Smart endpoint with automatic format detection
- Explicit typed endpoints for reliable integrations
- Web UI with drag & drop
- Two API styles: `multipart/form-data` (returns file) and `application/json` with base64 (returns JSON)
- Fully configurable via environment variables — see [`env.example`](env.example) for a documented template
- Ready for Docker and Kubernetes (health check endpoint)
- 100 MiB upload limit, 2 concurrent conversion slots (enforced via `asyncio.Semaphore`; single uvicorn worker)
- Interactive API docs at `/docs` (Swagger UI) and `/redoc`

## Quick Start

### Docker (recommended)

```bash
docker build -t office-converter .
docker run --rm -p 8080:8080 office-converter
```

To pass configuration via an env file:

```bash
cp env.example .env   # edit as needed
docker run --rm -p 8080:8080 --env-file .env office-converter
```

Open `http://localhost:8080` in your browser.

### Local development (uvicorn)

Requires Python 3.11+, `soffice` in `PATH`, and the dependencies installed:

```bash
pip install -r requirements.txt
make serve
# or
OFFICE_PORT=8080 python3 -m uvicorn app.main:app --host 0.0.0.0 --port 8080 --reload
```

## API Endpoints

| Method | Path                            | Description                             |
|--------|---------------------------------|-----------------------------------------|
| POST   | `/api/v1/convert`               | Smart endpoint (auto-detects direction) |
| POST   | `/api/v1/convert/xlsb-to-xlsx`  | `.xlsb` → `.xlsx`                       |
| POST   | `/api/v1/convert/xlsx-to-ods`   | `.xlsx` → `.ods`                        |
| POST   | `/api/v1/convert/ods-to-xlsx`   | `.ods` → `.xlsx`                        |
| GET    | `/healthz`                      | Health check                            |
| GET    | `/docs`                         | Swagger UI (interactive API docs)       |
| GET    | `/redoc`                        | ReDoc (readable API reference)          |
| GET    | `/openapi.json`                 | Raw OpenAPI 3 schema                    |

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
