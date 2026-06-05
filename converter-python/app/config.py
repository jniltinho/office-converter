"""
Application settings loaded from OFFICE_* environment variables.

All variables are optional — defaults match entrypoint.sh exactly.
Use ``get_settings()`` to access a cached singleton in application code.
"""

import re
from functools import lru_cache

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# Seconds to wait for a free conversion slot before returning HTTP 503.
# Not user-configurable; matches the original PHP spin-wait behaviour.
ACQUIRE_TIMEOUT: int = 30


def _parse_duration(raw: str) -> int:
    """Convert a duration string to an integer number of seconds.

    Accepted formats: ``'30s'``, ``'2m'``, ``'1h'`` (case-insensitive).
    Returns ``60`` when the string cannot be parsed.

    Examples::

        >>> _parse_duration('90s')
        90
        >>> _parse_duration('2m')
        120
        >>> _parse_duration('1h')
        3600
    """
    match = re.fullmatch(r'(\d+)(s|m|h)?', raw.strip().lower())
    if not match:
        return 60
    multiplier = {'s': 1, 'm': 60, 'h': 3600}[match.group(2) or 's']
    return int(match.group(1)) * multiplier


class Settings(BaseSettings):
    """Typed configuration for office-converter.

    Fields map 1-to-1 to ``OFFICE_*`` environment variables via the
    ``OFFICE_`` prefix configured in ``model_config``.

    Example — override at runtime::

        OFFICE_PORT=9000 OFFICE_MAX_CONCURRENT_CONVERSIONS=4 uvicorn app.main:app
    """

    model_config = SettingsConfigDict(env_prefix='OFFICE_', case_sensitive=False)

    host: str = '0.0.0.0'
    """Network interface uvicorn binds to (``OFFICE_HOST``)."""

    port: int = 8080
    """TCP port uvicorn listens on (``OFFICE_PORT``)."""

    max_upload_size: int = 100 * 1024 * 1024
    """Maximum upload size in bytes — enforced before the body is read (``OFFICE_MAX_UPLOAD_SIZE``)."""

    max_concurrent_conversions: int = 2
    """Maximum simultaneous LibreOffice processes; backed by ``asyncio.Semaphore`` (``OFFICE_MAX_CONCURRENT_CONVERSIONS``)."""

    conversion_timeout: str = '60s'
    """Per-conversion wall-clock deadline passed to ``coreutils timeout`` (``OFFICE_CONVERSION_TIMEOUT``).
    Accepts ``'30s'``, ``'2m'``, ``'1h'``."""

    @field_validator('max_concurrent_conversions')
    @classmethod
    def at_least_one(cls, value: int) -> int:
        """Clamp ``max_concurrent_conversions`` to a minimum of 1."""
        return max(1, value)

    @property
    def conversion_timeout_seconds(self) -> int:
        """Return ``conversion_timeout`` parsed to an integer number of seconds."""
        return _parse_duration(self.conversion_timeout)


@lru_cache
def get_settings() -> Settings:
    """Return the cached :class:`Settings` instance.

    Environment variables are read exactly once on the first call; subsequent
    calls return the same object.  Use this function everywhere in application
    code instead of constructing ``Settings()`` directly.
    """
    return Settings()
