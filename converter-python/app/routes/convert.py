"""
Spreadsheet conversion routes.

Routes registered here
----------------------
- ``POST /api/v1/convert``               — Smart endpoint; auto-detects direction from file extension.
- ``POST /api/v1/convert/xlsb-to-xlsx``  — Typed: ``.xlsb`` → ``.xlsx`` only.
- ``POST /api/v1/convert/xlsx-to-ods``   — Typed: ``.xlsx`` → ``.ods`` only.
- ``POST /api/v1/convert/ods-to-xlsx``   — Typed: ``.ods`` → ``.xlsx`` only.

Every endpoint accepts **two content types** on the same URL:

- ``multipart/form-data`` — Upload the file; server streams back the converted binary.
- ``application/json``    — Send ``{"file": "<base64>", "filename": "..."}``;
  server returns a JSON envelope with the result base64-encoded.

Use ``?format=json`` or ``Accept: application/json`` to force JSON output from
a multipart upload.
"""

from fastapi import APIRouter, BackgroundTasks, Request
from fastapi.responses import Response

from app.handlers import dispatch
from app.schemas import ConvertJsonRequest, ConvertJsonResponse, ErrorResponse

router = APIRouter(prefix='/api/v1', tags=['conversion'])

# ---------------------------------------------------------------------------
# Shared OpenAPI fragments reused across all conversion endpoints
# ---------------------------------------------------------------------------

_REQUEST_BODY_SPEC = {
    'required': True,
    'content': {
        'multipart/form-data': {
            'schema': {
                'type': 'object',
                'required': ['file'],
                'properties': {
                    'file': {
                        'type': 'string',
                        'format': 'binary',
                        'description': 'The source spreadsheet file.',
                    }
                },
            }
        },
        'application/json': {
            'schema': ConvertJsonRequest.model_json_schema(),
        },
    },
}

_SUCCESS_RESPONSE = {
    200: {
        'description': (
            'Conversion successful. '
            'Returns the converted file as a binary download (multipart request) '
            'or a JSON envelope (JSON request or ``?format=json``).'
        ),
        'content': {
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': {
                'schema': {'type': 'string', 'format': 'binary'}
            },
            'application/vnd.oasis.opendocument.spreadsheet': {
                'schema': {'type': 'string', 'format': 'binary'}
            },
            'application/json': {
                'schema': ConvertJsonResponse.model_json_schema(),
            },
        },
    }
}

_ERROR_RESPONSES = {
    400: {
        'description': 'Bad request — missing field, invalid base64, or no filename on the smart endpoint.',
        'content': {'application/json': {'schema': ErrorResponse.model_json_schema()}},
    },
    413: {
        'description': 'File too large (exceeds ``OFFICE_MAX_UPLOAD_SIZE``).',
        'content': {'application/json': {'schema': ErrorResponse.model_json_schema()}},
    },
    415: {
        'description': 'Unsupported format — wrong extension for typed endpoint or unknown extension on smart endpoint.',
        'content': {'application/json': {'schema': ErrorResponse.model_json_schema()}},
    },
    422: {
        'description': 'LibreOffice conversion failed (likely a corrupt or unsupported file).',
        'content': {'application/json': {'schema': ErrorResponse.model_json_schema()}},
    },
    503: {
        'description': 'All conversion slots busy — no slot became available within 30 seconds.',
        'content': {'application/json': {'schema': ErrorResponse.model_json_schema()}},
    },
}


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@router.post(
    '/convert',
    summary='Smart conversion (auto-detect direction)',
    description=(
        'Convert a spreadsheet file to the appropriate format based on the file extension:\n\n'
        '| Source | Target |\n'
        '|--------|--------|\n'
        '| `.xlsb` | `.xlsx` |\n'
        '| `.xlsx` | `.ods` |\n'
        '| `.ods`  | `.xlsx` |\n\n'
        'Accepts both `multipart/form-data` and `application/json` (base64) on the same URL.\n'
        'Append `?format=json` or send `Accept: application/json` to receive a JSON envelope '
        'instead of a binary download.'
    ),
    responses={**_SUCCESS_RESPONSE, **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _REQUEST_BODY_SPEC},
)
async def convert_smart(request: Request, background_tasks: BackgroundTasks) -> Response:
    """Auto-detect the conversion direction from the uploaded file extension.

    Args:
        request: Incoming HTTP request (multipart or JSON).
        background_tasks: Queue used to clean up temporary files after response.

    Returns:
        Binary file download or JSON envelope depending on the request content type
        and presence of ``?format=json`` / ``Accept: application/json``.
    """
    return await dispatch(request, background_tasks, from_ext=None, to_format=None)


@router.post(
    '/convert/xlsb-to-xlsx',
    summary='Convert XLSB → XLSX',
    description=(
        'Convert a `.xlsb` (Excel Binary Workbook) file to `.xlsx` (Excel Open XML).\n\n'
        'The uploaded file **must** have the `.xlsb` extension; any other extension returns HTTP 415.\n\n'
        'Accepts both `multipart/form-data` and `application/json` (base64) on the same URL.'
    ),
    responses={**_SUCCESS_RESPONSE, **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _REQUEST_BODY_SPEC},
)
async def convert_xlsb_to_xlsx(request: Request, background_tasks: BackgroundTasks) -> Response:
    """Convert a ``.xlsb`` file to ``.xlsx``.

    Args:
        request: Incoming HTTP request; rejects non-``.xlsb`` uploads with HTTP 415.
        background_tasks: Queue used to clean up temporary files after response.

    Returns:
        Binary ``.xlsx`` download or JSON envelope.
    """
    return await dispatch(request, background_tasks, from_ext='xlsb', to_format='xlsx')


@router.post(
    '/convert/xlsx-to-ods',
    summary='Convert XLSX → ODS',
    description=(
        'Convert a `.xlsx` (Excel Open XML) file to `.ods` (OpenDocument Spreadsheet).\n\n'
        'The uploaded file **must** have the `.xlsx` extension; any other extension returns HTTP 415.\n\n'
        'Accepts both `multipart/form-data` and `application/json` (base64) on the same URL.'
    ),
    responses={**_SUCCESS_RESPONSE, **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _REQUEST_BODY_SPEC},
)
async def convert_xlsx_to_ods(request: Request, background_tasks: BackgroundTasks) -> Response:
    """Convert a ``.xlsx`` file to ``.ods``.

    Args:
        request: Incoming HTTP request; rejects non-``.xlsx`` uploads with HTTP 415.
        background_tasks: Queue used to clean up temporary files after response.

    Returns:
        Binary ``.ods`` download or JSON envelope.
    """
    return await dispatch(request, background_tasks, from_ext='xlsx', to_format='ods')


@router.post(
    '/convert/ods-to-xlsx',
    summary='Convert ODS → XLSX',
    description=(
        'Convert a `.ods` (OpenDocument Spreadsheet) file to `.xlsx` (Excel Open XML).\n\n'
        'The uploaded file **must** have the `.ods` extension; any other extension returns HTTP 415.\n\n'
        'Accepts both `multipart/form-data` and `application/json` (base64) on the same URL.'
    ),
    responses={**_SUCCESS_RESPONSE, **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _REQUEST_BODY_SPEC},
)
async def convert_ods_to_xlsx(request: Request, background_tasks: BackgroundTasks) -> Response:
    """Convert a ``.ods`` file to ``.xlsx``.

    Args:
        request: Incoming HTTP request; rejects non-``.ods`` uploads with HTTP 415.
        background_tasks: Queue used to clean up temporary files after response.

    Returns:
        Binary ``.xlsx`` download or JSON envelope.
    """
    return await dispatch(request, background_tasks, from_ext='ods', to_format='xlsx')
