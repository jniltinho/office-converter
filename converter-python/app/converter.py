"""
LibreOffice subprocess wrapper with async concurrency control.

The public interface is a single coroutine, :func:`convert`, which:

1. Acquires a slot from the shared :class:`asyncio.Semaphore` (bounded by
   ``OFFICE_MAX_CONCURRENT_CONVERSIONS``).
2. Creates an isolated LibreOffice user-profile directory so parallel
   conversions never interfere with each other.
3. Runs ``soffice --headless --convert-to <format>`` under ``coreutils timeout``
   so runaway processes are killed after ``OFFICE_CONVERSION_TIMEOUT``.
4. Releases the slot and cleans up the profile directory unconditionally in a
   ``finally`` block.

The semaphore is lazily initialised inside the running event loop via
:func:`get_semaphore`.  The entrypoint starts uvicorn with ``--workers 1``
so the semaphore is the sole global concurrency cap.  If multiple workers
were used, each would have its own semaphore of size
``max_concurrent_conversions``, giving ``N × cap`` total slots.
"""

import asyncio
import os
import secrets
import shutil
import tempfile

from app.config import ACQUIRE_TIMEOUT, get_settings

# ---------------------------------------------------------------------------
# MIME type map for supported output formats
# ---------------------------------------------------------------------------

CONTENT_TYPES: dict[str, str] = {
    'xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ods': 'application/vnd.oasis.opendocument.spreadsheet',
    'xlsb': 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
}

# ---------------------------------------------------------------------------
# Semaphore — one per event loop, lazily created
# ---------------------------------------------------------------------------

_semaphore: asyncio.Semaphore | None = None


def get_semaphore() -> asyncio.Semaphore:
    """Return the process-wide conversion semaphore, creating it on first call.

    The semaphore size is taken from :attr:`~app.config.Settings.max_concurrent_conversions`.
    Lazy initialisation ensures it is created inside the running event loop,
    which is required for ``asyncio.Semaphore`` to work correctly.
    """
    global _semaphore
    if _semaphore is None:
        _semaphore = asyncio.Semaphore(get_settings().max_concurrent_conversions)
    return _semaphore


# ---------------------------------------------------------------------------
# Public coroutine
# ---------------------------------------------------------------------------

async def convert(src: str, out_dir: str, to_format: str) -> str:
    """Convert a spreadsheet file using LibreOffice in headless mode.

    Acquires a concurrency slot, runs ``soffice``, then releases the slot and
    removes the temporary LibreOffice profile directory — even on failure.

    Args:
        src: Absolute path to the source spreadsheet (e.g. ``/tmp/foo/report.xlsb``).
        out_dir: Directory where LibreOffice will write the output file.
        to_format: Target format — ``'xlsx'`` or ``'ods'``.

    Returns:
        Absolute path to the converted output file inside *out_dir*.

    Raises:
        ValueError: *to_format* is not ``'xlsx'`` or ``'ods'``.
        RuntimeError: All conversion slots were busy for :data:`~app.config.ACQUIRE_TIMEOUT`
            seconds (message starts with ``"too many concurrent conversions"``).
        RuntimeError: ``soffice`` exited with a non-zero code.
        RuntimeError: The expected output file was not found after a successful
            ``soffice`` run (should not happen in practice).
    """
    if to_format not in ('xlsx', 'ods'):
        raise ValueError(f"unsupported target format: {to_format!r}")

    sem = get_semaphore()
    try:
        await asyncio.wait_for(sem.acquire(), timeout=ACQUIRE_TIMEOUT)
    except asyncio.TimeoutError:
        raise RuntimeError("too many concurrent conversions")

    settings = get_settings()
    profile = os.path.join(tempfile.gettempdir(), f'lo-profile-{secrets.token_hex(8)}')
    os.makedirs(profile, mode=0o700)

    try:
        output_path = await _run_soffice(src, out_dir, to_format, profile, settings.conversion_timeout_seconds)
    finally:
        shutil.rmtree(profile, ignore_errors=True)
        sem.release()

    return output_path


async def _run_soffice(src: str, out_dir: str, to_format: str, profile: str, timeout_seconds: int) -> str:
    """Spawn ``soffice`` as an async subprocess and wait for it to finish.

    Uses ``coreutils timeout`` with a 5-second SIGKILL grace period so that
    a runaway LibreOffice process is always cleaned up.

    Args:
        src: Path to the source file.
        out_dir: Directory for LibreOffice output.
        to_format: Target format string (``'xlsx'`` or ``'ods'``).
        profile: Path to the isolated LibreOffice user-profile directory.
        timeout_seconds: Hard deadline in seconds; soffice is SIGTERM'd at this
            point and SIGKILL'd 5 seconds later.

    Returns:
        Absolute path to the generated output file.

    Raises:
        RuntimeError: ``soffice`` exited non-zero or the output file is missing.
    """
    cmd = [
        'timeout', '--foreground', '--kill-after=5s', f'{timeout_seconds}s',
        'soffice', '--headless', '--nologo', '--nofirststartwizard',
        f'-env:UserInstallation=file://{profile}',
        '--convert-to', to_format,
        '--outdir', out_dir,
        src,
    ]

    proc = await asyncio.create_subprocess_exec(
        *cmd,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    stdout, stderr = await proc.communicate()

    if proc.returncode != 0:
        output = (stdout + stderr).decode(errors='replace').strip()
        raise RuntimeError(f"conversion failed (exit {proc.returncode}): {output}")

    stem = os.path.splitext(os.path.basename(src))[0]
    dst = os.path.join(out_dir, f'{stem}.{to_format}')
    if not os.path.isfile(dst):
        raise RuntimeError(f"expected output file was not generated: {dst}")

    return dst
