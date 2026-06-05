"""
Pydantic models for request and response bodies used by the conversion API.

These models serve two purposes:
- Runtime validation of JSON request payloads.
- OpenAPI schema generation for the Swagger / ReDoc documentation UI.
"""

from typing import Annotated, Optional

from pydantic import BaseModel, Field


class ConvertJsonRequest(BaseModel):
    """JSON request body accepted by all ``POST /api/v1/convert*`` endpoints.

    The server also accepts ``multipart/form-data`` on the same endpoints;
    this model is used only when ``Content-Type: application/json`` is sent.
    """

    file: Annotated[
        str,
        Field(description='Base64-encoded bytes of the source spreadsheet file.'),
    ]
    filename: Annotated[
        Optional[str],
        Field(
            default=None,
            description=(
                'Original filename including extension (e.g. ``report.xlsb``). '
                'Required on ``/api/v1/convert`` for format auto-detection; '
                'optional on typed endpoints such as ``/api/v1/convert/xlsb-to-xlsx``.'
            ),
        ),
    ]

    model_config = {
        'json_schema_extra': {
            'example': {
                'file': 'UEsDBBQAAAAIAA==',
                'filename': 'report.xlsb',
            }
        }
    }


class ConvertJsonResponse(BaseModel):
    """JSON response returned when the client requests JSON output.

    JSON output is triggered by:

    - Sending ``Accept: application/json``
    - Appending ``?format=json`` to the request URL
    - Sending a JSON request body (``Content-Type: application/json``)
    """

    success: bool = Field(default=True, description='Always ``true`` on a successful conversion.')
    filename: Annotated[str, Field(description='Output filename with the converted extension.')]
    content_type: Annotated[
        str,
        Field(description='MIME type of the converted file (e.g. ``application/vnd.openxmlformats-officedocument.spreadsheetml.sheet``).'),
    ]
    size: Annotated[int, Field(description='Size of the converted file in bytes.')]
    data: Annotated[str, Field(description='Base64-encoded bytes of the converted file.')]

    model_config = {
        'json_schema_extra': {
            'example': {
                'success': True,
                'filename': 'report.xlsx',
                'content_type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size': 45231,
                'data': 'UEsDBBQAAAAIAA==',
            }
        }
    }


class ErrorResponse(BaseModel):
    """Standard error envelope returned for all ``4xx`` and ``5xx`` responses on API routes."""

    error: Annotated[str, Field(description='Human-readable description of the error.')]

    model_config = {
        'json_schema_extra': {
            'example': {'error': "missing 'file' form field"}
        }
    }
