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
# OpenAPI fragments — built per endpoint so examples match each conversion
# ---------------------------------------------------------------------------

_B64 = 'UEsDBBQAAAAIAA=='
_MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
_MIME_ODS = 'application/vnd.oasis.opendocument.spreadsheet'

_MULTIPART_SCHEMA = {
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


def _req_body(input_ext: str) -> dict:
    """Request body spec with a filename example matching the source format."""
    return {
        'required': True,
        'content': {
            'multipart/form-data': {'schema': _MULTIPART_SCHEMA},
            'application/json': {
                'schema': ConvertJsonRequest.model_json_schema(),
                'example': {'file': _B64, 'filename': f'report.{input_ext}'},
            },
        },
    }


def _success(output_ext: str, extra_binary_mime: str | None = None) -> dict:
    """200 success response with a filename/content_type example matching the output format."""
    mime = _MIME_XLSX if output_ext == 'xlsx' else _MIME_ODS
    binary_content: dict = {}
    if extra_binary_mime:
        binary_content[extra_binary_mime] = {'schema': {'type': 'string', 'format': 'binary'}}
    binary_content[mime] = {'schema': {'type': 'string', 'format': 'binary'}}
    return {
        200: {
            'description': (
                'Conversion successful. '
                'Returns the converted file as a binary download (multipart request) '
                'or a JSON envelope (JSON request or ``?format=json``).'
            ),
            'content': {
                **binary_content,
                'application/json': {
                    'schema': ConvertJsonResponse.model_json_schema(),
                    'example': {
                        'success': True,
                        'filename': f'report.{output_ext}',
                        'content_type': mime,
                        'size': 45231,
                        'data': _B64,
                    },
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
    responses={**_success('xlsx', extra_binary_mime=_MIME_ODS), **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _req_body('xlsb')},
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
    responses={**_success('xlsx'), **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _req_body('xlsb')},
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
    responses={**_success('ods'), **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _req_body('xlsx')},
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
    responses={**_success('xlsx'), **_ERROR_RESPONSES},
    openapi_extra={'requestBody': _req_body('ods')},
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
