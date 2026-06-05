"""
Route package — combines all sub-routers into a single ``router`` object.

Importing :data:`router` from this package is the only import that
:mod:`app.main` needs::

    from app.routes import router
    app.include_router(router)

Sub-modules
-----------
- :mod:`app.routes.health`  — ``GET /``, ``GET /healthz``, ``HEAD /healthz``
- :mod:`app.routes.convert` — ``POST /api/v1/convert*``
"""

from fastapi import APIRouter

from app.routes import convert, health

router = APIRouter()
router.include_router(health.router)
router.include_router(convert.router)

__all__ = ['router']
