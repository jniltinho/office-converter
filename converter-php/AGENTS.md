# AGENTS.md

Working agreement for AI agents (and humans) modifying this codebase.

## Project summary

`office-converter` is a small HTTP service that converts spreadsheet files
between **XLSB**, **XLSX**, and **ODS** using **LibreOffice** (`soffice`) in
headless mode. It is a PHP 8.1+ / **Slim Framework v4** application that runs
inside a `debian:12-slim` Docker image under **FrankenPHP standalone** in
**worker mode**. It is the PHP sibling of a Python/FastAPI implementation
in the parent workspace.

The repo name is `office-converter`; the Docker image, the Slim app title
(implicit via OpenAPI description in the sister project), and Caddyfile all
match.

## Repository layout

```
.
├── api/                          # PHP application (composer root)
│   ├── app/
│   │   ├── AppFactory.php        # Slim wiring: middleware + routes + controllers
│   │   ├── Converter.php         # soffice subprocess wrapper + flock slots
│   │   ├── Controller/
│   │   │   ├── ConvertController.php   # All 4 /api/v1/convert* routes
│   │   │   ├── HealthController.php    # GET/HEAD /healthz
│   │   │   ├── HomeController.php      # GET /  (drag-and-drop HTML)
│   │   │   └── NotFoundController.php  # Catch-all 404
│   │   └── Middleware/
│   │       └── UploadSizeMiddleware.php  # Content-Length guard → 413
│   ├── composer.json             # slim/slim, slim/psr7, nyholm/psr7-server
│   ├── composer.lock             # Pinned versions
│   ├── public/
│   │   └── index.php             # FrankenPHP worker loop OR php -S front-controller
│   └── vendor/                   # Composer install (overwritten at build time)
├── DOCUMENTS/
│   ├── README.md                 # Detailed reference (env vars, Docker, Make)
│   ├── curl.md / axios.md / postman.md   # Client integration examples
│   ├── postman_collection.json
│   ├── screenshot/
│   └── scripts/
│       ├── generate-samples.sh   # Produces testdata/sample.{xlsx,ods}
│       ├── run-integration-tests.sh  # Test runner (--php or --docker)
│       ├── test-api.sh           # curl-based API tests
│       └── test-health.sh        # /healthz probe test
├── testdata/                     # sample.xlsx, sample.ods, README-xlsb.txt
├── Dockerfile                    # 2-stage: composer build + debian:12-slim + FrankenPHP (UPX-compressed)
├── entrypoint.sh                 # Generates php.ini fragment + Caddyfile; starts `frankenphp run`
├── env.example                   # Documented env-var template
├── Makefile                      # serve / docker-* / test-integration-*
├── README.md / README.pt-BR.md
└── AGENTS.md                     # This file
```

## Runtime model

- **Production server**: FrankenPHP standalone binary (UPX-compressed, embeds
  PHP 8.5 and Caddy 2.11) running `frankenphp run --config /tmp/Caddyfile
  --adapter caddyfile`. The Caddyfile loads the Slim app in **worker mode**
  at `/app/api/public/index.php` — the worker loops via
  `frankenphp_handle_request()` and runs `gc_collect_cycles()` after every
  request to keep memory bounded.
- **Dev server**: `php -S 0.0.0.0:8080 api/public/index.php` (no worker loop;
  one process per request). Or `frankenphp php-server --listen :8080 --root
  api/public/`.
- **Conversion engine**: `soffice --headless` invoked via PHP `proc_open`,
  wrapped in `coreutils timeout --signal=TERM --kill-after=5s`. Each call
  gets its own isolated `-env:UserInstallation=file://…/lo-profile-<rand>`
  directory so parallel conversions never collide.
- **Concurrency cap**: `OFFICE_MAX_CONCURRENT_CONVERSIONS` (default 2),
  enforced via **non-blocking `flock` on `/tmp/.lo-slot-<N>.lock` files**.
  The handler spins for up to **30 s** (150 ms sleep per iteration) trying
  to grab one of the N slots; after that it returns **HTTP 503** (the
  converter throws and `ConvertController` does not currently map that to
  503 — see "What not to do" below).
- **Temp dirs**: every request creates `<prefix>-<rand>/` in
  `sys_get_temp_dir()`; the four prefixes are `convert-req-`,
  `xlsb-to-xlsx-req-`, `xlsx-to-ods-req-`, `ods-to-xlsx-req-`. Cleanup
  uses `Converter::rrmdir()` and is called inline on the JSON path, or
  via `register_shutdown_function` on the binary streaming path.
- **Upload size**: enforced by **two layers**:
  - PHP runtime: `upload_max_filesize` (≈ `OFFICE_MAX_UPLOAD_SIZE`
    rounded up to MiB) and `post_max_size = upload_max_filesize + 2 MiB`,
    set via a php.ini fragment at `/tmp/office-converter-ini/99-office-
    converter.ini` and the `PHP_INI_SCAN_DIR` env var (the FrankenPHP
    standalone binary does not honour `-d` flags).
  - `UploadSizeMiddleware`: re-checks `CONTENT_LENGTH` and returns 413
    before any body is read (this also covers chunked uploads where
    `CONTENT_LENGTH` is sent as a header).

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

Every `POST /api/v1/convert*` URL accepts **two** content types:

- `multipart/form-data` with field `file=<binary>` → binary download.
- `application/json` with body `{"file": "<base64>", "filename": "..."}`
  → JSON envelope (always).

You can also force a JSON envelope from a multipart upload with
`?format=json` or `Accept: application/json`.

A catch-all `/{routes:.+}` route renders a 404 (JSON for `/api/*`, plain
text otherwise).

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
| 400  | Missing `file` field, invalid JSON, invalid base64, missing `filename` on the smart JSON endpoint |
| 404  | Unknown path (JSON for `/api/*`, plain text otherwise) |
| 413  | `Content-Length > OFFICE_MAX_UPLOAD_SIZE` (enforced twice) |
| 415  | Extension mismatch on typed endpoint, or unsupported source on smart endpoint |
| 422  | LibreOffice conversion failed |
| 500  | Internal error (workspace prep, file read, etc.) |
| 503  | Slot timeout (currently surfaces as 500 — see "What not to do") |

## Configuration

All knobs are environment variables with an `OFFICE_` prefix; defaults live
in `entrypoint.sh` and are read in PHP via `getenv()` (no centralised
config class).

| Variable                            | Default                | Notes |
|-------------------------------------|------------------------|-------|
| `OFFICE_HOST`                       | `0.0.0.0`              | Bind interface (consumed by entrypoint.sh; the Caddyfile binds here) |
| `OFFICE_PORT`                       | `8080`                 | TCP port |
| `OFFICE_MAX_UPLOAD_SIZE`            | `104857600` (100 MiB)  | Rounded up to MiB for the PHP ini; checked as bytes in middleware |
| `OFFICE_MAX_CONCURRENT_CONVERSIONS` | `2`                    | Clamped to ≥ 1; controls the number of `/tmp/.lo-slot-N.lock` files |
| `OFFICE_CONVERSION_TIMEOUT`         | `60s`                  | `30s` / `2m` / `1h`; parsed in `Converter::getTimeoutSeconds()` |

Keep the defaults in sync between `app/Converter.php`,
`app/Middleware/UploadSizeMiddleware.php` (indirectly, via the
`OFFICE_MAX_UPLOAD_SIZE` env var), and `entrypoint.sh`.

## Coding conventions

- **Strict types everywhere**: every PHP file starts with
  `<?php declare(strict_types=1);` — do not omit it on new files.
- **PSR-4 + Slim PSR-7**: `App\\` → `app/`. Controllers receive
  `Psr\Http\Message\ServerRequestInterface` and return
  `Psr\Http\Message\ResponseInterface`. Never echo / `die` / `exit` from
  a controller — return a `Response`.
- **Static `Converter`**: `App\Converter` is a class of static methods
  (`convertTo`, `convertXlsb`, `rrmdir`, …). No instances. Configuration
  is cached in `private static ?int` properties on first call.
- **One class, one job**:
  - `AppFactory` — Slim wiring only.
  - `Converter` — subprocess + concurrency + temp cleanup only.
  - `Controller/*` — one controller per route group; `ConvertController`
    exposes four public action methods (one per URL).
  - `Middleware/*` — PSR-15 `MiddlewareInterface` implementations.
- **Response shaping**: controllers use `withHeader` /
  `$res->getBody()->write(json_encode(...))`. JSON is encoded with
  `JSON_UNESCAPED_SLASHES`. Errors always go through
  `ConvertController::sendError()` (writes `{"error": "..."}` with the
  given status).
- **No comments unless asked** — types and method names carry the
  meaning; docblocks are kept short and only where they earn their
  place (e.g. describing the FrankenPHP worker loop in
  `public/index.php`).
- **Temp prefixes**: the four request prefixes (`convert-req-`,
  `xlsb-to-xlsx-req-`, `xlsx-to-ods-req-`, `ods-to-xlsx-req-`) are wired
  to the corresponding route — keep them in sync with `Makefile`'s
  `clean` target when you add a new one.
- **No new composer dependencies** without a deliberate change to
  `composer.json` + `Dockerfile` composer stage + the committed
  `composer.lock`. The runtime image rebuilds the optimised classmap in
  the build stage, so adding a class under `app/` does **not** require
  touching `composer.json` — but a new PSR-4 namespace does.

## Concurrency contract — read before changing

`OFFICE_MAX_CONCURRENT_CONVERSIONS` is enforced by **non-blocking
`flock`** on N lock files (`/tmp/.lo-slot-0.lock` … `/tmp/.lo-slot-(N-1).lock`).
This is **deliberately different from the Python sibling** and the
consequences are:

- The cap is **process-local to the host filesystem** — it works
  correctly across **multiple FrankenPHP workers**, php-fpm pools, and
  even across containerised instances that share `/tmp` (do not rely on
  the latter). Each worker tries each slot until it grabs one.
- The spin-wait budget is **30 s** in
  `Converter::acquireSlot()` (`usleep(150_000)` between attempts). If
  the budget is exceeded the converter returns `null` and the caller
  throws — `ConvertController` currently maps that to the generic 500
  via `$this->sendError($res, 422, 'could not convert the file')` path
  when called from `performJson`, but the `performMultipart` path also
  catches the same exception and returns 422, not 503. If you change
  this, surface it as a proper 503 in both pipelines.
- `max()` clamps `OFFICE_MAX_CONCURRENT_CONVERSIONS` to a minimum of 1.
- Do not replace the lock-file scheme with an in-memory primitive
  (e.g. a static counter) — that would silently regress in multi-worker
  setups.

## Build, run, test

Local (requires `soffice` in `PATH` and `php` ≥ 8.1):

```bash
cd api
composer install
cd ..
make serve                 # php -S on :8080 (or OFFICE_PORT=...)
# or, with FrankenPHP locally:
frankenphp php-server --listen :8080 --root api/public/
```

Docker:

```bash
make docker-build          # 2-stage build (composer → debian:12-slim + FrankenPHP + LibreOffice)
PORT=8080 make docker-run  # exposes :8080
```

Integration tests (the authoritative suite — there are no unit tests):

```bash
make generate-samples                 # one-time; needs soffice
make test-integration-php             # against local php -S on :18180
make test-integration-docker          # build + run inside the image
make test-all                         # samples + integration-php
```

`make fmt` / `make lint` run `php -l` on `api/**/*.php` (excluding
`vendor/`); this is the only static check defined. There is no
PHPStan/Psalm/PHPUnit wired up — do not introduce one without
discussion.

`make clean` removes `test-server.log`, `/tmp/convert-req-*`,
`/tmp/{xlsb-to-xlsx,xlsx-to-ods,ods-to-xlsx}-req-*`, `/tmp/lo-profile-*`,
and `/tmp/.lo-slot-*.lock`.

## Adding a new conversion direction

Example: add `ods-to-xlsb`.

1. `api/app/Converter.php` — extend the `$toFormat !== 'xlsx' &&
   $toFormat !== 'ods'` guard in `convertTo()` to accept `'xlsb'`.
2. `api/app/Controller/ConvertController.php`:
   - Add a public method (mirror `odsToXlsx`) that calls
     `performJson` / `performMultipart` with the new format.
   - Update the `match` arms in **both** `autoConvert()` and
     `performJson()` to include the new source extension.
   - Add a new `MIME_XLSB` to `contentTypeFor()`.
3. `api/app/AppFactory.php` — register a new `$app->post('/api/v1/convert/ods-to-xlsb', [$convert, 'odsToXlsb'])` line.
4. `README.md` / `README.pt-BR.md` — add the row to the endpoints table.
5. `DOCUMENTS/scripts/test-api.sh` — add a multipart + JSON test mirroring
   the existing patterns. Update `DOCUMENTS/scripts/generate-samples.sh`
   only if you also need a new sample file. Add the new
   `<new-format>-to-xlsb-req-` prefix to `Makefile`'s `clean` target.

## Adding a new endpoint variant

Each `ConvertController` action is a thin dispatcher: branch on
`isJsonContent()`, validate the extension, then delegate to
`performMultipart()` or `performJson()`. Both helpers already handle the
`?format=json` / `Accept: application/json` override and the upload/
decode/cleanup pipeline. Copy an existing action and change the
`$toFormat` / `$expectedExt` / `$prefix` arguments.

## What not to do

- Do not write unit tests against this codebase; the project
  intentionally tests through real HTTP + real LibreOffice. If a unit
  test seems necessary, raise it before adding it.
- Do not add new composer packages casually. `composer.json` is
  intentionally tiny (Slim + PSR-7 factories + the nyholm server
  request creator).
- Do not commit secrets, sample spreadsheets that contain PII, or large
  binaries. `testdata/` is git-ignored except for `README-xlsb.txt`.
- Do not replace the `flock`-based concurrency control with an
  in-process counter or a `Semaphore`-style primitive — the whole point
  is that it works across multiple PHP processes. Re-read the
  "Concurrency contract" section above.
- Do not split the inline HTML in `HomeController.php` into a
  templating system. The page is intentionally asset-free and
  self-contained.
- Do not modify the committed `vendor/` directly — `Dockerfile` rebuilds
  the optimised classmap from source in the composer build stage and
  overwrites it at copy time. New files under `app/` Just Work; new
  PSR-4 namespaces need a `composer.json` update.
- Do not assume `Converter::convertTo()` throwing "too many concurrent
  conversions" maps to 503. Today it does not — fix it in both
  pipelines if you change that contract.
