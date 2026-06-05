"""
Request-processing logic shared by all conversion route handlers.

This module contains the pure async functions that handle the two supported
request content types (``multipart/form-data`` and ``application/json``) and
build the HTTP response.  Route registration lives in :mod:`app.routes.convert`.

Flow
----
Every conversion endpoint calls :func:`dispatch`, which branches on
``Content-Type``:

.. code-block:: text

    dispatch()
    ├── _is_json_request() → True  → handle_json_body()  ─┐
    └── _is_json_request() → False → handle_multipart()   ─┤
                                                            ▼
                                                    run_conversion()
                                                            │
                                                    FileResponse  or  JSONResponse
"""

import base64
import os
import shutil
import tempfile
from pathlib import Path

from fastapi import BackgroundTasks, HTTPException, Request
from fastapi.responses import FileResponse, JSONResponse, Response

from app.converter import CONTENT_TYPES, convert
from app.schemas import ConvertJsonResponse, ErrorResponse  # noqa: F401 (re-exported for routes)

# Maps each source extension to its default output format on the smart endpoint.
AUTO_DETECT: dict[str, str] = {
    'xlsb': 'xlsx',
    'xlsx': 'ods',
    'ods': 'xlsx',
}


# ---------------------------------------------------------------------------
# Small predicates
# ---------------------------------------------------------------------------

def wants_json(request: Request) -> bool:
    """Return ``True`` when the client expects a JSON envelope response.

    Triggered by ``Accept: application/json`` header or ``?format=json`` query param.
    """
    return (
        request.query_params.get('format') == 'json'
        or 'application/json' in request.headers.get('accept', '')
    )


def is_json_request(request: Request) -> bool:
    """Return ``True`` when the request body is ``application/json``."""
    return request.headers.get('content-type', '').lower().startswith('application/json')


def make_work_dir() -> str:
    """Create and return a unique temporary directory for one conversion request."""
    return tempfile.mkdtemp(prefix='convert-req-')


# ---------------------------------------------------------------------------
# Content-type handlers
# ---------------------------------------------------------------------------

async def handle_multipart(
    request: Request,
    from_ext: str | None,
    to_format: str | None,
    as_json: bool,
    background_tasks: BackgroundTasks,
) -> Response:
    """Process a ``multipart/form-data`` upload and convert the file.

    Args:
        request: Incoming request containing the ``file`` form field.
        from_ext: Required source extension for typed endpoints (e.g. ``'xlsb'``),
            or ``None`` for the smart endpoint.
        to_format: Fixed target format for typed endpoints (``'xlsx'`` / ``'ods'``),
            or ``None`` to auto-detect from the file extension.
        as_json: When ``True``, return a :class:`~app.schemas.ConvertJsonResponse`
            instead of a binary download.
        background_tasks: Queue for post-response cleanup of *work_dir*.

    Returns:
        :class:`~fastapi.responses.FileResponse` or
        :class:`~fastapi.responses.JSONResponse`.

    Raises:
        HTTPException 400: ``file`` field is missing.
        HTTPException 415: Extension mismatch or unrecognised format.
    """
    form = await request.form()
    upload = form.get('file')
    if upload is None or not hasattr(upload, 'filename'):
        raise HTTPException(status_code=400, detail="missing 'file' form field")

    orig_name = upload.filename or 'file'
    ext = Path(orig_name).suffix.lower().lstrip('.')

    if from_ext is not None and ext != from_ext:
        raise HTTPException(
            status_code=415,
            detail=f"this route accepts only .{from_ext} files; got .{ext}",
        )

    if to_format is None:
        to_format = AUTO_DETECT.get(ext)
        if to_format is None:
            raise HTTPException(
                status_code=415,
                detail=f"unsupported source format: .{ext}. Supported: xlsb, xlsx, ods",
            )

    work_dir = make_work_dir()
    background_tasks.add_task(shutil.rmtree, work_dir, True)

    src_path = os.path.join(work_dir, orig_name)
    with open(src_path, 'wb') as fh:
        fh.write(await upload.read())

    return await run_conversion(src_path, orig_name, to_format, as_json, work_dir, background_tasks)


async def handle_json_body(
    request: Request,
    from_ext: str | None,
    to_format: str | None,
    background_tasks: BackgroundTasks,
) -> Response:
    """Process an ``application/json`` request body and convert the file.

    The body must conform to :class:`~app.schemas.ConvertJsonRequest`.
    The response is always a :class:`~app.schemas.ConvertJsonResponse`.

    Args:
        request: Incoming request whose body is a JSON object.
        from_ext: Required source extension for typed endpoints, or ``None``.
        to_format: Fixed target format, or ``None`` to auto-detect.
        background_tasks: Queue for post-response cleanup of *work_dir*.

    Returns:
        :class:`~fastapi.responses.JSONResponse`.

    Raises:
        HTTPException 400: Invalid JSON, missing ``file`` field, invalid base64,
            or missing ``filename`` on the smart endpoint.
        HTTPException 415: Extension mismatch or unrecognised format.
    """
    try:
        body = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail='invalid JSON body')

    file_b64: str | None = body.get('file')
    if not file_b64:
        raise HTTPException(status_code=400, detail="missing 'file' field in JSON body")

    orig_name: str = body.get('filename') or 'file'
    ext = Path(orig_name).suffix.lower().lstrip('.')

    if from_ext is not None and ext != from_ext:
        raise HTTPException(
            status_code=415,
            detail=f"this route accepts only .{from_ext} files; got .{ext}",
        )

    if to_format is None:
        if not ext:
            raise HTTPException(
                status_code=400,
                detail="'filename' with a supported extension is required for format auto-detection",
            )
        to_format = AUTO_DETECT.get(ext)
        if to_format is None:
            raise HTTPException(
                status_code=415,
                detail=f"unsupported source format: .{ext}. Supported: xlsb, xlsx, ods",
            )

    try:
        file_bytes = base64.b64decode(file_b64)
    except Exception:
        raise HTTPException(status_code=400, detail="invalid base64 in 'file' field")

    work_dir = make_work_dir()
    background_tasks.add_task(shutil.rmtree, work_dir, True)

    safe_name = orig_name if orig_name != 'file' else f'file.{from_ext or ext or "bin"}'
    src_path = os.path.join(work_dir, safe_name)
    with open(src_path, 'wb') as fh:
        fh.write(file_bytes)

    return await run_conversion(src_path, orig_name, to_format, True, work_dir, background_tasks)


async def run_conversion(
    src_path: str,
    orig_name: str,
    to_format: str,
    as_json: bool,
    work_dir: str,
    background_tasks: BackgroundTasks,
) -> Response:
    """Invoke :func:`~app.converter.convert` and build the HTTP response.

    Shared final step for both :func:`handle_multipart` and :func:`handle_json_body`.

    Args:
        src_path: Absolute path to the source file inside *work_dir*.
        orig_name: Original filename used to derive the output filename stem.
        to_format: Target format — ``'xlsx'`` or ``'ods'``.
        as_json: Return a base64 JSON envelope instead of a binary download.
        work_dir: Temporary directory holding input and output files.
        background_tasks: Queue; *work_dir* is scheduled for deletion after the
            response is sent when returning a binary :class:`~fastapi.responses.FileResponse`.

    Returns:
        :class:`~fastapi.responses.FileResponse` (binary) or
        :class:`~fastapi.responses.JSONResponse`.

    Raises:
        HTTPException 415: Unsupported *to_format*.
        HTTPException 422: LibreOffice conversion failed.
        HTTPException 503: No conversion slot became available within the timeout.
    """
    try:
        dst_path = await convert(src_path, work_dir, to_format)
    except ValueError as exc:
        raise HTTPException(status_code=415, detail=str(exc))
    except RuntimeError as exc:
        msg = str(exc)
        if 'too many concurrent conversions' in msg:
            raise HTTPException(status_code=503, detail=msg)
        raise HTTPException(status_code=422, detail=msg)

    dl_name = f'{Path(orig_name).stem}.{to_format}'
    content_type = CONTENT_TYPES[to_format]

    if as_json:
        with open(dst_path, 'rb') as fh:
            data = base64.b64encode(fh.read()).decode()
        return JSONResponse(
            ConvertJsonResponse(
                filename=dl_name,
                content_type=content_type,
                size=os.path.getsize(dst_path),
                data=data,
            ).model_dump()
        )

    return FileResponse(
        path=dst_path,
        filename=dl_name,
        media_type=content_type,
        background=background_tasks,
    )


async def dispatch(
    request: Request,
    background_tasks: BackgroundTasks,
    from_ext: str | None,
    to_format: str | None,
) -> Response:
    """Route a conversion request to the appropriate content-type handler.

    Args:
        request: Incoming HTTP request.
        background_tasks: FastAPI background-task queue.
        from_ext: Expected source extension for typed endpoints, or ``None``.
        to_format: Fixed target format for typed endpoints, or ``None``.

    Returns:
        The response produced by :func:`handle_multipart` or :func:`handle_json_body`.
    """
    if is_json_request(request):
        return await handle_json_body(request, from_ext, to_format, background_tasks)
    return await handle_multipart(request, from_ext, to_format, wants_json(request), background_tasks)
