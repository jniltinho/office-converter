"""
Health-check and web-UI routes.

Routes registered here
----------------------
- ``GET /``       — Drag-and-drop web UI (HTML, no JavaScript dependencies).
- ``GET /healthz``  — Liveness probe; returns ``200 ok``.
- ``HEAD /healthz`` — Same probe, body-less.
"""

from fastapi import APIRouter
from fastapi.responses import HTMLResponse, Response

from app.ui import HOME_HTML

router = APIRouter()


@router.get(
    '/',
    response_class=HTMLResponse,
    include_in_schema=False,
)
async def home() -> HTMLResponse:
    """Serve the drag-and-drop web UI.

    Returns:
        The full HTML page as an :class:`~fastapi.responses.HTMLResponse`.
    """
    return HTMLResponse(content=HOME_HTML)


@router.get(
    '/healthz',
    tags=['health'],
    summary='Liveness probe',
    description='Returns HTTP 200 with body ``ok``. Used by Docker HEALTHCHECK, Kubernetes liveness probes, and load balancers.',
    response_description='Service is healthy',
    responses={200: {'content': {'text/plain': {'example': 'ok'}}}},
)
async def healthz_get() -> Response:
    """Return a plain-text ``ok`` to signal the service is running.

    Returns:
        :class:`~fastapi.responses.Response` with status 200 and body ``ok``.
    """
    return Response(content='ok', media_type='text/plain')


@router.head(
    '/healthz',
    tags=['health'],
    summary='Liveness probe (HEAD)',
    description='Same as ``GET /healthz`` but without a response body. Useful for minimal-overhead health polling.',
    include_in_schema=True,
)
async def healthz_head() -> Response:
    """HEAD variant of the liveness probe — returns no body.

    Returns:
        Empty :class:`~fastapi.responses.Response` with status 200.
    """
    return Response(status_code=200)
