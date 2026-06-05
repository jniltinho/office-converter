<?php
declare(strict_types=1);

namespace App\Controller;

use App\Converter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Handles all /api/v1/convert routes.
 *
 * Each public method is an invokable Slim route handler.
 * Both multipart/form-data uploads and JSON (base64-encoded file) requests
 * are supported; the Content-Type header determines which path is taken.
 *
 * Supported conversions:
 *   POST /api/v1/convert              — auto-detect from file extension
 *   POST /api/v1/convert/xlsb-to-xlsx — XLSB  → XLSX
 *   POST /api/v1/convert/xlsx-to-ods  — XLSX  → ODS
 *   POST /api/v1/convert/ods-to-xlsx  — ODS   → XLSX
 *
 * JSON request body:
 *   { "file": "<base64>", "filename": "name.xlsb" }
 *
 * JSON response body (when Content-Type: application/json or ?format=json):
 *   { "success": true, "filename": "...", "content_type": "...", "size": N, "data": "<base64>" }
 */
class ConvertController
{
    /**
     * POST /api/v1/convert — auto-detect target format from file extension.
     *
     * .xlsb → xlsx, .ods → xlsx, .xlsx → ods
     */
    public function autoConvert(Request $req, Response $res): Response
    {
        if ($this->isJsonContent($req)) {
            return $this->performJson($req, $res, '', '', 'convert-req-');
        }

        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $this->sendError($res, 400, "missing 'file' form field");
        }

        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        $to  = match ($ext) {
            'xlsb', 'ods' => 'xlsx',
            'xlsx'        => 'ods',
            default       => null,
        };

        if ($to === null) {
            return $this->sendError($res, 415, 'supported formats: .xlsb, .xlsx, .ods');
        }

        return $this->performMultipart($req, $res, $to, 'convert-req-');
    }

    /**
     * POST /api/v1/convert/xlsb-to-xlsx — XLSB → XLSX only.
     */
    public function xlsbToXlsx(Request $req, Response $res): Response
    {
        if ($this->isJsonContent($req)) {
            return $this->performJson($req, $res, 'xlsx', '.xlsb', 'xlsb-to-xlsx-req-');
        }

        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $this->sendError($res, 400, "missing 'file' form field");
        }

        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'xlsb') {
            return $this->sendError($res, 415, 'this route accepts only .xlsb files (use /api/v1/convert/xlsb-to-xlsx)');
        }

        return $this->performMultipart($req, $res, 'xlsx', 'xlsb-to-xlsx-req-');
    }

    /**
     * POST /api/v1/convert/xlsx-to-ods — XLSX → ODS only.
     */
    public function xlsxToOds(Request $req, Response $res): Response
    {
        if ($this->isJsonContent($req)) {
            return $this->performJson($req, $res, 'ods', '.xlsx', 'xlsx-to-ods-req-');
        }

        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $this->sendError($res, 400, "missing 'file' form field");
        }

        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return $this->sendError($res, 415, 'this route accepts only .xlsx files (use /api/v1/convert/xlsx-to-ods)');
        }

        return $this->performMultipart($req, $res, 'ods', 'xlsx-to-ods-req-');
    }

    /**
     * POST /api/v1/convert/ods-to-xlsx — ODS → XLSX only.
     */
    public function odsToXlsx(Request $req, Response $res): Response
    {
        if ($this->isJsonContent($req)) {
            return $this->performJson($req, $res, 'xlsx', '.ods', 'ods-to-xlsx-req-');
        }

        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $this->sendError($res, 400, "missing 'file' form field");
        }

        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'ods') {
            return $this->sendError($res, 415, 'this route accepts only .ods files (use /api/v1/convert/ods-to-xlsx)');
        }

        return $this->performMultipart($req, $res, 'xlsx', 'ods-to-xlsx-req-');
    }

    // -------------------------------------------------------------------------
    // Shared conversion pipelines
    // -------------------------------------------------------------------------

    /**
     * Handle a multipart/form-data upload, convert it, and respond.
     *
     * When the client sends Accept: application/json or ?format=json the
     * converted file is returned base64-encoded inside a JSON envelope;
     * otherwise a binary streaming download is started and the temporary
     * directory is cleaned up via register_shutdown_function.
     */
    private function performMultipart(Request $req, Response $res, string $toFormat, string $prefix): Response
    {
        $file     = ($req->getUploadedFiles())['file'];
        $origName = $file->getClientFilename() ?: 'input.bin';
        $origExt  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $workDir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!@mkdir($workDir, 0700, true)) {
            return $this->sendError($res, 500, 'failed to prepare workspace');
        }

        $srcPath = $workDir . '/input.' . $origExt;
        try {
            $file->moveTo($srcPath);
        } catch (\Throwable) {
            Converter::rrmdir($workDir);
            return $this->sendError($res, 500, 'failed to save upload');
        }

        $outDir = $workDir . '/out';
        if (!@mkdir($outDir, 0755, true)) {
            Converter::rrmdir($workDir);
            return $this->sendError($res, 500, 'failed to create output directory');
        }

        try {
            $dstPath = Converter::convertTo($srcPath, $outDir, $toFormat);
        } catch (\Throwable $e) {
            Converter::rrmdir($workDir);
            error_log('conversion failed: ' . $e->getMessage());
            return $this->sendError($res, 422, 'could not convert the file');
        }

        $dlName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $toFormat;

        if ($this->wantsJson($req)) {
            $data = @file_get_contents($dstPath);
            Converter::rrmdir($workDir);

            if ($data === false) {
                return $this->sendError($res, 500, 'failed to read conversion result');
            }

            $res = $res->withHeader('Content-Type', 'application/json');
            $res->getBody()->write(json_encode([
                'success'      => true,
                'filename'     => $dlName,
                'content_type' => $this->contentTypeFor($toFormat),
                'size'         => strlen($data),
                'data'         => base64_encode($data),
            ], JSON_UNESCAPED_SLASHES));
            return $res;
        }

        // Binary streaming download — clean up after the response is fully sent.
        $size   = filesize($dstPath);
        $stream = new Stream(fopen($dstPath, 'rb'));
        $res    = $res
            ->withHeader('Content-Type', $this->contentTypeFor($toFormat))
            ->withHeader('Content-Disposition', 'attachment; filename=' . $dlName)
            ->withBody($stream);

        if ($size !== false) {
            $res = $res->withHeader('Content-Length', (string) $size);
        }

        register_shutdown_function(static function () use ($workDir): void {
            Converter::rrmdir($workDir);
        });

        return $res;
    }

    /**
     * Handle a JSON request body (base64-encoded file), convert it, and
     * always return a JSON envelope with the result base64-encoded.
     *
     * @param string $toFormat    Target format, or '' to auto-detect from filename.
     * @param string $expectedExt Enforce a specific source extension (e.g. '.xlsb'),
     *                            or '' to accept any extension.
     */
    private function performJson(Request $req, Response $res, string $toFormat, string $expectedExt, string $prefix): Response
    {
        $raw    = (string) $req->getBody();
        $parsed = $req->getParsedBody();

        $reqData = is_array($parsed) && isset($parsed['file'])
            ? $parsed
            : json_decode($raw, true);

        if (!is_array($reqData)) {
            return $this->sendError($res, 400, 'invalid JSON body');
        }
        if (!isset($reqData['file']) || !is_string($reqData['file']) || $reqData['file'] === '') {
            return $this->sendError($res, 400, "missing 'file' field in JSON body");
        }

        $decoded = base64_decode($reqData['file'], true);
        if ($decoded === false) {
            return $this->sendError($res, 400, "invalid base64 in 'file' field");
        }

        $origName = $reqData['filename'] ?? '';
        if ($origName === '') {
            if ($expectedExt !== '') {
                $origName = 'input' . $expectedExt;
            } else {
                return $this->sendError($res, 400, 'filename is required in JSON body for the smart /api/v1/convert endpoint');
            }
        }

        $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($expectedExt !== '' && strcasecmp($origExt, ltrim($expectedExt, '.')) !== 0) {
            return $this->sendError($res, 415, "this route accepts only $expectedExt files (use the appropriate /api/v1/convert/... endpoint)");
        }

        $finalFormat = $toFormat !== '' ? $toFormat : match ('.' . $origExt) {
            '.xlsb', '.ods' => 'xlsx',
            '.xlsx'         => 'ods',
            default         => null,
        };

        if ($finalFormat === null) {
            return $this->sendError($res, 415, 'supported formats: .xlsb, .xlsx, .ods');
        }

        $workDir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!@mkdir($workDir, 0700, true)) {
            return $this->sendError($res, 500, 'failed to prepare workspace');
        }

        $srcPath = $workDir . '/input.' . $origExt;
        if (file_put_contents($srcPath, $decoded) === false) {
            Converter::rrmdir($workDir);
            return $this->sendError($res, 500, 'failed to write uploaded file');
        }

        $outDir = $workDir . '/out';
        if (!@mkdir($outDir, 0755, true)) {
            Converter::rrmdir($workDir);
            return $this->sendError($res, 500, 'failed to create output directory');
        }

        try {
            $dstPath = Converter::convertTo($srcPath, $outDir, $finalFormat);
        } catch (\Throwable $e) {
            Converter::rrmdir($workDir);
            error_log('conversion failed: ' . $e->getMessage());
            return $this->sendError($res, 422, 'could not convert the file');
        }

        $outData = @file_get_contents($dstPath);
        Converter::rrmdir($workDir);

        if ($outData === false) {
            return $this->sendError($res, 500, 'failed to read conversion result');
        }

        $dlName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $finalFormat;

        $res = $res->withHeader('Content-Type', 'application/json');
        $res->getBody()->write(json_encode([
            'success'      => true,
            'filename'     => $dlName,
            'content_type' => $this->contentTypeFor($finalFormat),
            'size'         => strlen($outData),
            'data'         => base64_encode($outData),
        ], JSON_UNESCAPED_SLASHES));

        return $res;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** True when the client wants a JSON response (Accept header or ?format=json). */
    private function wantsJson(Request $req): bool
    {
        if (($req->getQueryParams()['format'] ?? '') === 'json') {
            return true;
        }
        return stripos($req->getHeaderLine('Accept'), 'application/json') !== false;
    }

    /** True when the request body is JSON (Content-Type: application/json). */
    private function isJsonContent(Request $req): bool
    {
        return stripos($req->getHeaderLine('Content-Type'), 'application/json') === 0;
    }

    /** Returns the MIME type for a spreadsheet format, falling back to octet-stream. */
    private function contentTypeFor(string $format): string
    {
        return match (strtolower($format)) {
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
            default => @mime_content_type('.' . $format) ?: 'application/octet-stream',
        };
    }

    /** Writes a JSON error envelope and returns the response. */
    private function sendError(Response $res, int $status, string $msg): Response
    {
        $res = $res->withStatus($status)->withHeader('Content-Type', 'application/json');
        $res->getBody()->write(json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES));
        return $res;
    }
}
