# AGENTS.md

Working agreement for AI agents (and humans) modifying this codebase.

## Project summary

`office-converter` is a small HTTP service that converts spreadsheet files
between **XLSB**, **XLSX**, and **ODS** using **LibreOffice** (`soffice`) in
headless mode. It is a Python 3.11+ / FastAPI / uvicorn application that runs
inside a `debian:12-slim` Docker image and is the Python sibling of a PHP
implementation in the parent workspace.

The repo name is `office-converter`; the Docker image, FastAPI title, and
OpenAPI metadata all match.

## Repository layout

```
.
├── app/                          # Python application package
│   ├── main.py                   # FastAPI factory, middleware, 404 handler
│   ├── config.py                 # pydantic-settings (OFFICE_* env vars)
│   ├── converter.py              # soffice subprocess wrapper + semaphore
│   ├── handlers.py               # multipart / JSON request processing
│   ├── schemas.py                # Pydantic request/response models
│   ├── ui.py                     # Inline drag-and-drop HTML for GET /
│   ├── __init__.py               # (empty)
│   └── routes/
│       ├── __init__.py           # Combines sub-routers into `router`
│       ├── convert.py            # POST /api/v1/convert*
│       └── health.py             # GET /, GET/HEAD /healthz
├── DOCUMENTS/
│   ├── README.md                 # Detailed reference (env vars, Docker, Make)
│   ├── curl.md / axios.md / postman.md   # Client integration examples
│   ├── postman_collection.json   # Importable Postman collection
│   ├── screenshot/               # Web UI screenshot
│   └── scripts/
│       ├── generate-samples.sh   # Produces testdata/sample.{xlsx,ods}
│       ├── run-integration-tests.sh  # Test runner (--python or --docker)
│       ├── test-api.sh           # curl-based API tests
│       └── test-health.sh        # /healthz probe test
├── testdata/                     # Sample files (sample.xlsx, sample.ods, …)
├── Dockerfile                    # debian:12-slim + libreoffice-calc + venv
├── entrypoint.sh                 # Starts uvicorn (workers=1) with OFFICE_* env
├── env.example                   # Documented env-var template
├── Makefile                      # build / serve / docker-* / test-integration
├── requirements.txt              # fastapi, uvicorn[standard], multipart, pydantic-settings
├── README.md / README.pt-BR.md
└── AGENTS.md                     # This file
```

## Runtime model

- **Server**: uvicorn serving `app.main:app`, **single worker** (`--workers 1`).
  Do not change this without re-reading the concurrency note below.
- **Conversion engine**: `soffice --headless` invoked via
  `asyncio.create_subprocess_exec`, wrapped in `coreutils timeout` with a
  5-second SIGKILL grace. Each call gets its own isolated
  `-env:UserInstallation=file://…/lo-profile-<rand>` directory so parallel
  conversions never collide.
- **Concurrency cap**: one process-wide `asyncio.Semaphore` sized to
  `OFFICE_MAX_CONCURRENT_CONVERSIONS` (default 2). Requests that find the
  semaphore full wait up to **30 s** (`ACQUIRE_TIMEOUT` in
  `app/config.py`); after that the handler raises `RuntimeError` and the
  route returns **HTTP 503**.
- **Temp dirs**: every request creates `/tmp/convert-req-<rand>/`; the
  semaphore wrapper cleans up its `lo-profile-*`; the response layer uses
  FastAPI `BackgroundTasks` to remove the work dir after the body is
  streamed.

## HTTP surface

| Method | Path                            | Description                                  |
|--------|---------------------------------|----------------------------------------------|
| GET    | `/`                             | Drag-and-drop web UI (HTML, no JS deps)      |
| GET    | `/healthz`                      | Liveness probe → `200 ok` (text/plain)       |
| HEAD   | `/healthz`                      | Same probe, body-less                        |
| POST   | `/api/v1/convert`               | Smart endpoint (auto-detect direction)       |
| POST   | `/api/v1/convert/xlsb-to-xlsx`  | `.xlsb` → `.xlsx` (typed)                    |
| POST   | `/api/v1/convert/xlsx-to-ods`   | `.xlsx` → `.ods` (typed)                     |
| POST   | `/api/v1/convert/ods-to-xlsx`   | `.ods`  → `.xlsx` (typed)                    |
| GET    | `/docs`                         | Swagger UI                                   |
| GET    | `/redoc`                        | ReDoc                                        |
| GET    | `/openapi.json`                 | Raw OpenAPI 3 schema                         |

Every `POST /api/v1/convert*` URL accepts **two** content types:

- `multipart/form-data` with field `file=<binary>` → binary download.
- `application/json` with body `{"file": "<base64>", "filename": "..."}`
  → JSON envelope (always).

You can also force a JSON envelope from a multipart upload with
`?format=json` or `Accept: application/json`.

### Auto-detect map (smart endpoint)

```
xlsb → xlsx
xlsx → ods
ods  → xlsx
```

Anything else returns **HTTP 415**.

### HTTP status codes

| Code | When |
|------|------|
| 200  | Successful conversion (binary or JSON body) |
| 400  | Missing `file` field, invalid JSON, invalid base64, JSON body without `filename` on the smart endpoint |
| 404  | Unknown path (JSON for `/api/*`, plain text otherwise) |
| 413  | `Content-Length > OFFICE_MAX_UPLOAD_SIZE` |
| 415  | Extension mismatch on typed endpoint, or unsupported source on smart endpoint |
| 422  | LibreOffice exited non-zero (corrupt / unsupported file) |
| 503  | Semaphore full for > 30 s |

## Configuration

All knobs are environment variables with an `OFFICE_` prefix; defaults live in
both `app/config.py` and `entrypoint.sh` and must stay in sync.

| Variable                            | Default                | Notes |
|-------------------------------------|------------------------|-------|
| `OFFICE_HOST`                       | `0.0.0.0`              | Bind interface |
| `OFFICE_PORT`                       | `8080`                 | TCP port |
| `OFFICE_MAX_UPLOAD_SIZE`            | `104857600` (100 MiB)  | Enforced via `Content-Length` middleware before body read |
| `OFFICE_MAX_CONCURRENT_CONVERSIONS` | `2`                    | Clamped to ≥ 1 |
| `OFFICE_CONVERSION_TIMEOUT`         | `60s`                  | `30s` / `2m` / `1h`; passed to `coreutils timeout` (with `+5s` SIGKILL grace) |

Use `get_settings()` (`app.config`) — it is `lru_cache`-memoised; do **not**
instantiate `Settings()` directly in handlers.

## Coding conventions

- **Async-first**: route handlers, request parsers, and the conversion call
  are all coroutines. Do not block the event loop with sync I/O.
- **One module, one job**:
  - `config.py` — settings only.
  - `converter.py` — subprocess + concurrency control only.
  - `handlers.py` — content-type parsing + response shaping.
  - `schemas.py` — Pydantic models only (used for OpenAPI).
  - `routes/` — wire URLs to `handlers.dispatch`.
- **Return types**: handlers return `fastapi.responses.Response` (concrete
  `FileResponse` or `JSONResponse`); never return a dict from a route.
- **Errors**: raise `HTTPException` with the right code (see table above).
  Do not return error envelopes manually.
- **No comments unless asked** — the codebase is fully typed and self-
  documenting; docstrings are present where it helps. Do not add inline
  narration in new code.
- **Type hints**: required for public functions. Use `from __future__ import
  annotations` only if the file already does.
- **Imports**: stdlib, then third-party, then `app.*` (alphabetical within
  each group). One blank line between groups.
- **Paths**: prefer `pathlib.Path` for filename inspection; use `os.path.join`
  only when constructing absolute paths inside a function that already uses
  it (consistency with existing code).
- **No new dependencies** without a deliberate change to `requirements.txt`
  + `Dockerfile` venv install. The runtime image is intentionally lean.

## Concurrency contract — read before changing

`OFFICE_MAX_CONCURRENT_CONVERSIONS` is enforced by a process-local
`asyncio.Semaphore`. The entrypoint starts uvicorn with `--workers 1`; the
in-source comment in `app/converter.py` calls this out explicitly. The
consequences:

- Adding `--workers N` (N > 1) silently multiplies the cap to `N × cap`.
  If you do this, document it in the README and update the env example.
- Do not replace the semaphore with a `multiprocessing` primitive unless
  you also revisit the single-worker assumption.
- The semaphore is created lazily inside the running event loop
  (`get_semaphore()` in `app/converter.py`); do not move it to module
  import time.

## Build, run, test

Local (requires `soffice` in `PATH` and `python3 -m venv`):

```bash
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
make serve                 # uvicorn --reload on :8080 (or OFFICE_PORT=...)
```

Docker:

```bash
make docker-build
PORT=8080 make docker-run  # exposes :8080
```

Integration tests (the authoritative suite — there are no unit tests):

```bash
make generate-samples                  # one-time; needs soffice
make test-integration                  # against local uvicorn on :18180
make test-integration-docker           # build + run inside the image
make test-all                          # samples + integration
```

`make fmt` / `make lint` run `python3 -m py_compile` on `app/*.py`; this
is the only static check defined. There is no type checker, formatter, or
unit test framework wired up — do not introduce one without discussion.

`make clean` removes `test-server.log`, `/tmp/convert-req-*`,
`/tmp/lo-profile-*`, and `__pycache__/`.

## Adding a new conversion direction

Example: add `ods-to-xlsb`.

1. `app/converter.py` — extend the `to_format` guard in `convert()` to
   accept `'xlsb'`, and add `'xlsb'` to `CONTENT_TYPES` with the correct
   MIME (`application/vnd.ms-excel.sheet.binary.macroEnabled.12`).
2. `app/handlers.py` — add `'ods': 'xlsb'` to `AUTO_DETECT` if you want
   the smart endpoint to pick it up automatically.
3. `app/routes/convert.py` — register a new `@router.post('/convert/ods-to-xlsb', …)`
   handler that calls `dispatch(..., from_ext='ods', to_format='xlsb')`
   and add the matching `_req_body('ods')` + `_success('xlsb')` extras.
4. `README.md` / `README.pt-BR.md` — add the row to the endpoints table.
5. `DOCUMENTS/scripts/test-api.sh` — add a multipart + JSON test mirroring
   the existing patterns; update `DOCUMENTS/scripts/generate-samples.sh`
   only if you also need a new sample file.

## Adding a new endpoint variant

Prefer reusing `app.handlers.dispatch` (it already handles multipart vs
JSON content-type branching, the `?format=json` override, and the
extension guards). Each route in `app/routes/convert.py` is a thin
adapter; copy one of them and change the `from_ext` / `to_format`
arguments.

## What not to do

- Do not write unit tests against this codebase; the project intentionally
  tests through real HTTP + real LibreOffice. If a unit test seems
  necessary, raise it before adding it.
- Do not add new third-party packages casually. `requirements.txt` is 4
  lines on purpose.
- Do not commit secrets, sample spreadsheets that contain PII, or large
  binaries. `testdata/` is git-ignored except for `README-xlsb.txt`.
- Do not enable multi-worker uvicorn without re-reading the concurrency
  contract above.
- Do not split the inline `HOME_HTML` (in `app/ui.py`) into a templating
  system. The page is intentionally asset-free and self-contained.
