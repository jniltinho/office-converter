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
./office-converter
```

**Flags:**

| Flag     | Description                          | Default          |
|----------|--------------------------------------|------------------|
| `--port` | TCP port to listen on                | `8080`           |
| `--host` | Host/interface to bind to            | (all interfaces) |

Examples:

```bash
./office-converter --port 9000
./office-converter --host 127.0.0.1 --port 8080
```

### Using Make

```bash
make run                    # runs with `go run`
make run ARGS="--port 9000"
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

### Curl Examples

Here are ready-to-use `curl` examples for the most common scenarios.

#### Convert and download the file directly (recommended)

```bash
# Explicit endpoint: .xlsb → .xlsx
curl -F "file=@planilha.xlsb" \
     http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
     -o planilha.xlsx

# Smart endpoint (automatically decides the output format)
curl -F "file=@dados.xlsb" \
     http://localhost:8080/api/v1/convert \
     -o dados.xlsx
```

#### Get JSON response using multipart upload (`?format=json`)

```bash
# See the full JSON response
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" | jq .

# Extract and save the file in one command
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" \
     | jq -r '.data' | base64 -d > planilha_convertida.xlsx
```

#### Pure JSON API (send file as base64)

Here are focused examples for the pure JSON API.

**Convert .xlsb → .xlsx using JSON API**

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 planilha.xlsb)\",\"filename\":\"planilha.xlsb\"}" \
  | jq -r '.data' | base64 -d > planilha.xlsx
```

**Using the smart endpoint `/api/convert` (auto-detects direction)**

```bash
curl -X POST http://localhost:8080/api/v1/convert \
  -H "Content-Type: application/json" \
  -d @- <<EOF | jq -r '.data' | base64 -d > saida.ods
{
  "file": "$(base64 -w0 planilha.xlsx)",
  "filename": "planilha.xlsx"
}
EOF
```

**Convert .xlsx → .ods**

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsx-to-ods \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 dados.xlsx)\",\"filename\":\"dados.xlsx\"}" \
  | jq -r '.data' | base64 -d > dados.ods
```

**See the full response (useful for debugging)**

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" | jq .
```

**With custom host/port**

```bash
curl -X POST http://127.0.0.1:9000/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 input.xlsb)\",\"filename\":\"input.xlsb\"}" \
  | jq -r '.data' | base64 -d > output.xlsx
```

#### Using a custom port

```bash
curl -F "file=@planilha.xlsb" http://localhost:9000/api/v1/convert -o out.xlsx
```

#### Health check

```bash
curl -s http://localhost:8080/healthz
# HEAD request (no body)
curl -sI http://localhost:8080/healthz
```

> **Tip**: Install `jq` (`sudo apt install jq` or `brew install jq`) — it makes working with the JSON API much easier.

### Axios Examples (JavaScript/Node.js)

Install: `npm install axios form-data`

#### Multipart upload — download the converted file

```js
import axios from 'axios';
import FormData from 'form-data';
import fs from 'fs';

const form = new FormData();
form.append('file', fs.createReadStream('planilha.xlsb'), 'planilha.xlsb');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx',
  form,
  { headers: form.getHeaders(), responseType: 'arraybuffer' }
);

fs.writeFileSync('planilha.xlsx', response.data);
```

#### Multipart upload — get JSON envelope (`?format=json`)

```js
const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json',
  form,
  { headers: form.getHeaders() }
);

const converted = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', converted);
```

#### Pure JSON API (base64)

```js
const fileBuffer = fs.readFileSync('planilha.xlsb');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx',
  {
    file: fileBuffer.toString('base64'),
    filename: 'planilha.xlsb',
  },
  { headers: { 'Content-Type': 'application/json' } }
);

const converted = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', converted);
```

#### Smart endpoint (auto-detects format)

```js
const form = new FormData();
form.append('file', fs.createReadStream('dados.xlsx'), 'dados.xlsx');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert',
  form,
  { headers: form.getHeaders(), responseType: 'arraybuffer' }
);

fs.writeFileSync('dados.ods', response.data);
```

---

### Postman

#### Import the collection

1. Open Postman → **Import** → paste the JSON below or save it as `office-converter.postman_collection.json`.

```json
{
  "info": {
    "name": "office-converter",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "variable": [
    { "key": "base_url", "value": "http://localhost:8080" }
  ],
  "item": [
    {
      "name": "Convert XLSB → XLSX (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsb-to-xlsx",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/path/to/planilha.xlsb" }]
        }
      }
    },
    {
      "name": "Convert XLSX → ODS (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsx-to-ods",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/path/to/dados.xlsx" }]
        }
      }
    },
    {
      "name": "Convert ODS → XLSX (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/ods-to-xlsx",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/path/to/tabela.ods" }]
        }
      }
    },
    {
      "name": "Smart convert (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/path/to/arquivo.xlsb" }]
        }
      }
    },
    {
      "name": "Convert XLSB → XLSX (JSON/base64)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsb-to-xlsx",
        "header": [{ "key": "Content-Type", "value": "application/json" }],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"file\": \"<base64-encoded content>\",\n  \"filename\": \"planilha.xlsb\"\n}"
        }
      }
    },
    {
      "name": "Health Check",
      "request": {
        "method": "GET",
        "url": "{{base_url}}/healthz"
      }
    }
  ]
}
```

#### Manual setup (without importing)

1. Set **base URL** variable: `http://localhost:8080`
2. Create a **POST** request to `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
3. **Body** tab → select **form-data**
4. Add key `file`, change type to **File**, pick your `.xlsb` file
5. Click **Send** — the response body is the converted `.xlsx` file (set **Save to a file** in the response panel)

For JSON/base64:
1. **Body** tab → select **raw** → set type to **JSON**
2. Paste: `{ "file": "<base64>", "filename": "planilha.xlsb" }`
3. The response JSON contains the converted file in the `data` field (base64)

---

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