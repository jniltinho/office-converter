"""
FastAPI application factory and entry point.

Responsibilities
----------------
- Build the :class:`~fastapi.FastAPI` instance with OpenAPI metadata and tags.
- Register the upload-size guard middleware (returns HTTP 413 before reading
  the request body when ``Content-Length`` exceeds ``OFFICE_MAX_UPLOAD_SIZE``).
- Register a custom 404 handler that returns JSON for ``/api/`` routes and
  plain text for all other paths.
- Mount the router from :mod:`app.routes`.
- Expose a ``__main__`` entry point for running directly with
  ``python -m app.main`` during local development.
"""

import uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, PlainTextResponse

from app.config import get_settings
from app.routes import router

# ---------------------------------------------------------------------------
# OpenAPI / Swagger metadata
# ---------------------------------------------------------------------------

_DESCRIPTION = """\
HTTP service for converting spreadsheet files between **XLSB**, **XLSX**, and
**ODS** formats using [LibreOffice](https://www.libreoffice.org/) in headless mode.

## Request formats

Every conversion endpoint accepts **two content types** on the same URL:

| Content-Type | Response |
|---|---|
| `multipart/form-data` | Binary file download (`Content-Disposition: attachment`) |
| `application/json` | JSON envelope with base64-encoded result |

Force a JSON response from a multipart upload by appending **`?format=json`**
or sending **`Accept: application/json`**.

## Supported conversions

| Source | Target |
|--------|--------|
| `.xlsb` | `.xlsx` |
| `.xlsx` | `.ods` |
| `.ods`  | `.xlsx` |

## Concurrency

Up to `OFFICE_MAX_CONCURRENT_CONVERSIONS` (default: **2**) LibreOffice processes
run in parallel.  Requests that arrive while all slots are busy wait up to
**30 seconds**; if no slot frees up they receive **HTTP 503**.
"""

_TAGS_METADATA = [
    {
        'name': 'conversion',
        'description': 'Spreadsheet conversion endpoints. Accept multipart or JSON requests.',
    },
    {
        'name': 'health',
        'description': 'Liveness probe used by Docker, Kubernetes, and load balancers.',
    },
]


# ---------------------------------------------------------------------------
# Application factory
# ---------------------------------------------------------------------------

def create_app() -> FastAPI:
    """Create and configure the FastAPI application.

    Registers middleware, exception handlers, and the API router.
    Called once at module import time; the resulting ``app`` object is what
    uvicorn serves.

    Returns:
        A fully configured :class:`~fastapi.FastAPI` instance.
    """
    settings = get_settings()

    app = FastAPI(
        title='office-converter',
        description=_DESCRIPTION,
        version='1.0.0',
        docs_url='/docs',
        redoc_url='/redoc',
        openapi_url='/openapi.json',
        openapi_tags=_TAGS_METADATA,
        license_info={
            'name': 'MIT',
        },
    )

    # ------------------------------------------------------------------
    # Middleware: upload size guard
    # ------------------------------------------------------------------

    @app.middleware('http')
    async def upload_size_guard(request: Request, call_next):
        """Reject requests whose ``Content-Length`` exceeds the configured limit.

        Checking the header before reading the body avoids allocating memory
        for oversized uploads.  Returns HTTP 413 with a JSON body for ``/api/``
        routes and plain text for other paths (e.g. the web UI).
        """
        cl_header = request.headers.get('content-length')
        if cl_header:
            try:
                content_length = int(cl_header)
            except ValueError:
                content_length = 0
            if content_length > settings.max_upload_size:
                is_api = request.url.path.startswith('/api/')
                if is_api:
                    return JSONResponse({'error': 'request entity too large'}, status_code=413)
                return PlainTextResponse('request entity too large', status_code=413)
        return await call_next(request)

    # ------------------------------------------------------------------
    # Exception handler: 404
    # ------------------------------------------------------------------

    @app.exception_handler(404)
    async def not_found_handler(request: Request, _exc):
        """Return a structured JSON error for ``/api/`` routes, plain text otherwise."""
        is_api = request.url.path.startswith('/api/')
        if is_api:
            return JSONResponse({'error': 'not found'}, status_code=404)
        return PlainTextResponse('not found', status_code=404)

    # ------------------------------------------------------------------
    # Router
    # ------------------------------------------------------------------

    app.include_router(router)
    return app


# ---------------------------------------------------------------------------
# Module-level application instance (consumed by uvicorn)
# ---------------------------------------------------------------------------

app: FastAPI = create_app()
"""The FastAPI application instance.

Import this object in ``entrypoint.sh`` / uvicorn::

    uvicorn app.main:app --host 0.0.0.0 --port 8080
"""


# ---------------------------------------------------------------------------
# Direct execution entry point
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    settings = get_settings()
    uvicorn.run(
        'app.main:app',
        host=settings.host,
        port=settings.port,
        access_log=True,
    )
