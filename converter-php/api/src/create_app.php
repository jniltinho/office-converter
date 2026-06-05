<?php
declare(strict_types=1);

namespace App;

use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

/**
 * Creates and configures the Slim application.
 * All routes, error behavior, and UI preserved for API compatibility.
 */
return function (): \Slim\App {
    $app = AppFactory::create();

    // Body parsing for JSON and form posts
    $app->addBodyParsingMiddleware();

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------
    $maxUploadBytes = (int)(getenv('OFFICE_MAX_UPLOAD_SIZE') ?: (100 << 20));

    // -------------------------------------------------------------------------
    // Small helpers (preserve original behavior and messages)
    // -------------------------------------------------------------------------
    $wantsJson = static function (Request $req): bool {
        if (($req->getQueryParams()['format'] ?? '') === 'json') {
            return true;
        }
        $accept = $req->getHeaderLine('Accept');
        return stripos($accept, 'application/json') !== false;
    };

    $isJsonContent = static function (Request $req): bool {
        $ct = $req->getHeaderLine('Content-Type');
        return stripos($ct, 'application/json') === 0;
    };

    $contentTypeFor = static function (string $format): string {
        $f = strtolower($format);
        if ($f === 'xlsx') {
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        if ($f === 'ods') {
            return 'application/vnd.oasis.opendocument.spreadsheet';
        }
        $mt = @mime_content_type('.' . $f);
        return $mt ?: 'application/octet-stream';
    };

    $sendError = static function (Response $res, int $status, string $msg, bool $asJson): Response {
        $res = $res->withStatus($status);
        if ($asJson) {
            $res = $res->withHeader('Content-Type', 'application/json');
            $res->getBody()->write(json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES));
        } else {
            $res->getBody()->write($msg);
        }
        return $res;
    };

    // Early upload size guard middleware (matches old behavior)
    $sizeGuard = static function (Request $req, RequestHandler $handler) use ($maxUploadBytes, $sendError): Response {
        $cl = (int)($req->getServerParams()['CONTENT_LENGTH'] ?? 0);
        if ($cl > 0 && $cl > $maxUploadBytes) {
            $path = $req->getUri()->getPath();
            $isApi = str_starts_with($path, '/api/');
            $res = new SlimResponse();
            return $sendError($res, 413, 'request entity too large', $isApi);
        }
        return $handler->handle($req);
    };
    $app->add($sizeGuard);

    // -------------------------------------------------------------------------
    // Health
    // -------------------------------------------------------------------------
    $health = function (Request $req, Response $res) use ($sendError): Response {
        if ($req->getMethod() === 'HEAD') {
            return $res->withStatus(200);
        }
        $res->getBody()->write('ok');
        return $res->withStatus(200);
    };
    $app->map(['GET', 'HEAD'], '/healthz', $health);

    // -------------------------------------------------------------------------
    // UI (exact same inline HTML + JS as before)
    // -------------------------------------------------------------------------
    $indexHtml = function (Request $req, Response $res): Response {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Spreadsheet Converter</title>
<style>
  * { box-sizing: border-box; border-radius: 0; }
  body {
    font-family: ui-monospace, "Cascadia Code", monospace;
    background: #f5f5f5; color: #171717;
    min-height: 100vh; margin: 0;
    display: flex; align-items: flex-start; justify-content: center; padding-top: 10vh; padding-left: 24px; padding-right: 24px;
  }
  main { width: 100%; max-width: 560px; border: 2px solid #171717; background: #fff; }
  header { border-bottom: 2px solid #171717; background: #171717; padding: 16px 24px; }
  header h1 { margin: 0; font-size: 18px; text-transform: uppercase; color: #fff; }
  header p { margin: 4px 0 0; font-size: 12px; color: #a3a3a3; }
  .body { padding: 24px; }
  label.drop {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    border: 2px dashed #a3a3a3; padding: 48px 24px; cursor: pointer;
    text-align: center; transition: border-color .15s, background .15s;
  }
  label.drop:hover, label.drop.over { border-color: #171717; background: #fafafa; }
  .drop strong { font-size: 14px; }
  .drop span { font-size: 12px; color: #737373; }
  input[type=file] { display: none; }
  button {
    width: 100%; margin-top: 16px; border: 2px solid #171717; background: #171717;
    color: #fff; padding: 12px 16px; font: inherit; font-weight: bold;
    text-transform: uppercase; font-size: 13px; cursor: pointer;
    transition: background .15s, color .15s;
  }
  button:hover:not(:disabled) { background: #fff; color: #171717; }
  button:disabled { opacity: .3; cursor: not-allowed; }
  #status { margin: 12px 0 0; font-size: 12px; color: #525252; min-height: 16px; }
  .hint { font-size: 11px; color: #737373; margin-top: 8px; }
  .api-note { font-size: 10px; color: #525252; margin-top: 12px; line-height: 1.3; }
  .api-note code { background: #f5f5f5; padding: 1px 4px; }
</style>
</head>
<body>
<main>
  <header>
    <h1>Spreadsheet Converter</h1>
    <p>XLSB &rarr; XLSX | XLSX &rarr; ODS | ODS &rarr; XLSX</p>
  </header>
  <div class="body">
    <label class="drop" id="drop" for="file">
      <strong>Click or drop a file</strong>
      <span id="fname">no file selected</span>
      <input id="file" type="file" accept=".xlsb,.xlsx,.ods">
    </label>
    <div class="hint">Formats: .xlsb, .xlsx, .ods — the UI uses the matching explicit endpoint</div>
    <button id="btn" disabled>Convert</button>
    <p id="status"></p>
    <div class="api-note">Explicit endpoints: <code>/api/v1/convert/xlsb-to-xlsx</code>, <code>/api/v1/convert/xlsx-to-ods</code>, <code>/api/v1/convert/ods-to-xlsx</code><br>JSON API: send <code>Content-Type: application/json</code> with <code>{"file": "base64..."}</code> or append <code>?format=json</code></div>
  </div>
</main>
<script>
var file=document.getElementById('file'),fname=document.getElementById('fname'),
    btn=document.getElementById('btn'),status=document.getElementById('status'),
    drop=document.getElementById('drop');
function getTargetExt(name){
  var n=name.toLowerCase();
  if(n.endsWith('.xlsb')||n.endsWith('.ods')) return '.xlsx';
  if(n.endsWith('.xlsx')) return '.ods';
  return null;
}
function getConvertEndpoint(name){
  var n=name.toLowerCase();
  if(n.endsWith('.xlsb')) return '/api/v1/convert/xlsb-to-xlsx';
  if(n.endsWith('.xlsx')) return '/api/v1/convert/xlsx-to-ods';
  if(n.endsWith('.ods')) return '/api/v1/convert/ods-to-xlsx';
  return '/api/v1/convert';
}
function pick(f){
  if(!f)return;
  var tgt=getTargetExt(f.name);
  if(!tgt){status.textContent='Error: select .xlsb, .xlsx or .ods';btn.disabled=true;return;}
  var dt=new DataTransfer();dt.items.add(f);file.files=dt.files;
  fname.textContent=f.name;btn.disabled=false;
  var ep = getConvertEndpoint(f.name);
  status.textContent = 'Ready to convert via ' + ep;
}
file.addEventListener('change',function(){pick(file.files[0]);});
drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('over');});
drop.addEventListener('dragleave',function(){drop.classList.remove('over');});
drop.addEventListener('drop',function(e){e.preventDefault();drop.classList.remove('over');pick(e.dataTransfer.files[0]);});
btn.addEventListener('click',async function(){
  if(!file.files[0])return;
  var f=file.files[0];
  var tgt=getTargetExt(f.name);
  var ep=getConvertEndpoint(f.name);
  btn.disabled=true;status.textContent='Converting via '+ep+' ... (LibreOffice may take a few seconds)';
  var form=new FormData();form.append('file',f);
  try{
    var res=await fetch(ep,{method:'POST',body:form});
    if(!res.ok){var m=await res.text().catch(function(){return res.statusText;});status.textContent='Failure: '+m;btn.disabled=false;return;}
    var blob=await res.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download=f.name.replace(/\.[^.]+$/i, tgt);a.click();URL.revokeObjectURL(url);
    status.textContent='Ready. Download started.';btn.disabled=false;
  }catch(err){status.textContent='Network error: '+err.message;btn.disabled=false;}
});
</script>
</body>
</html>
HTML;
        $res = new SlimResponse();
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
    };
    $app->get('/', $indexHtml);

    // -------------------------------------------------------------------------
    // Core conversion logic (adapted from old perform* functions)
    // -------------------------------------------------------------------------
    $performConversion = function (Request $req, Response $res, string $toFormat, string $tempPrefix, bool $expectExtCheck = false, string $expectedExt = '') use ($wantsJson, $sendError, $contentTypeFor): Response {
        $uploadedFiles = $req->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $sendError($res, 400, "missing 'file' form field", true);
        }

        $origName = $file->getClientFilename() ?: 'input.bin';
        $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $inputExt = '.' . $origExt;

        $workDir = sys_get_temp_dir() . '/' . $tempPrefix . bin2hex(random_bytes(6));
        if (!@mkdir($workDir, 0700, true)) {
            return $sendError($res, 500, 'failed to prepare workspace', true);
        }

        $srcPath = $workDir . '/input' . $inputExt;
        try {
            $file->moveTo($srcPath);
        } catch (\Throwable $e) {
            Converter::rrmdir($workDir);
            return $sendError($res, 500, 'failed to save upload', true);
        }

        $outDir = $workDir . '/out';
        if (!@mkdir($outDir, 0755, true)) {
            Converter::rrmdir($workDir);
            return $sendError($res, 500, 'failed to create output directory', true);
        }

        try {
            $dstPath = Converter::convertTo($srcPath, $outDir, $toFormat);
        } catch (\Throwable $e) {
            Converter::rrmdir($workDir);
            error_log('conversion failed: ' . $e->getMessage());
            return $sendError($res, 422, 'could not convert the file', true);
        }

        $dlName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $toFormat;

        if ($wantsJson($req)) {
            $data = @file_get_contents($dstPath);
            if ($data === false) {
                Converter::rrmdir($workDir);
                return $sendError($res, 500, 'failed to read conversion result', true);
            }
            Converter::rrmdir($workDir);
            $payload = [
                'success'      => true,
                'filename'     => $dlName,
                'content_type' => $contentTypeFor($toFormat),
                'size'         => strlen($data),
                'data'         => base64_encode($data),
            ];
            $res = $res->withHeader('Content-Type', 'application/json');
            $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
            return $res;
        }

        // direct download
        $stream = new \Slim\Psr7\Stream(fopen($dstPath, 'rb'));
        $res = $res
            ->withHeader('Content-Type', $contentTypeFor($toFormat))
            ->withHeader('Content-Disposition', 'attachment; filename=' . $dlName);
        $size = filesize($dstPath);
        if ($size !== false) {
            $res = $res->withHeader('Content-Length', (string)$size);
        }
        $res = $res->withBody($stream);
        // Cleanup after the response is fully emitted (for direct download streaming).
        register_shutdown_function(function () use ($workDir) {
            Converter::rrmdir($workDir);
        });
        return $res;
    };

    $performJsonConversion = function (Request $req, Response $res, string $toFormat, string $expectedExt, string $tempPrefix) use ($wantsJson, $sendError, $contentTypeFor): Response {
        $raw = (string)$req->getBody();
        $parsed = $req->getParsedBody();
        if (is_array($parsed) && isset($parsed['file'])) {
            $reqData = $parsed;
        } else {
            $reqData = json_decode($raw, true);
        }

        if (!is_array($reqData)) {
            return $sendError($res, 400, 'invalid JSON body', true);
        }
        if (!isset($reqData['file']) || !is_string($reqData['file']) || $reqData['file'] === '') {
            return $sendError($res, 400, "missing 'file' field in JSON body", true);
        }

        $decoded = base64_decode($reqData['file'], true);
        if ($decoded === false) {
            return $sendError($res, 400, "invalid base64 in 'file' field", true);
        }

        $origName = $reqData['filename'] ?? '';
        if ($origName === '') {
            if ($expectedExt !== '') {
                $origName = 'input' . $expectedExt;
            } else {
                return $sendError($res, 400, 'filename is required in JSON body for the smart /api/v1/convert endpoint', true);
            }
        }

        $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $inputExt = '.' . $origExt;

        $finalToFormat = $toFormat;
        if ($finalToFormat === '') {
            if ($inputExt === '.xlsb' || $inputExt === '.ods') {
                $finalToFormat = 'xlsx';
            } elseif ($inputExt === '.xlsx') {
                $finalToFormat = 'ods';
            } else {
                return $sendError($res, 415, 'supported formats: .xlsb, .xlsx, .ods', true);
            }
        }

        if ($expectedExt !== '' && strcasecmp($origExt, ltrim($expectedExt, '.')) !== 0) {
            return $sendError($res, 415, "this route accepts only $expectedExt files (use the appropriate /api/v1/convert/... endpoint)", true);
        }

        $workDir = sys_get_temp_dir() . '/' . $tempPrefix . bin2hex(random_bytes(6));
        if (!@mkdir($workDir, 0700, true)) {
            return $sendError($res, 500, 'failed to prepare workspace', true);
        }

        $srcPath = $workDir . '/input' . $inputExt;
        if (file_put_contents($srcPath, $decoded) === false) {
            Converter::rrmdir($workDir);
            return $sendError($res, 500, 'failed to write uploaded file', true);
        }

        $outDir = $workDir . '/out';
        if (!@mkdir($outDir, 0755, true)) {
            Converter::rrmdir($workDir);
            return $sendError($res, 500, 'failed to create output directory', true);
        }

        try {
            $dstPath = Converter::convertTo($srcPath, $outDir, $finalToFormat);
        } catch (\Throwable $e) {
            Converter::rrmdir($workDir);
            error_log('conversion failed: ' . $e->getMessage());
            return $sendError($res, 422, 'could not convert the file', true);
        }

        $dlName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $finalToFormat;

        $outData = @file_get_contents($dstPath);
        if ($outData === false) {
            Converter::rrmdir($workDir);
            return $sendError($res, 500, 'failed to read conversion result', true);
        }
        Converter::rrmdir($workDir);

        $res = $res->withHeader('Content-Type', 'application/json');
        $payload = [
            'success'      => true,
            'filename'     => $dlName,
            'content_type' => $contentTypeFor($finalToFormat),
            'size'         => strlen($outData),
            'data'         => base64_encode($outData),
        ];
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $res;
    };

    // Expose a public rrmdir for the handlers above (we call Converter::rrmdir)
    // We will make it public static in Converter.

    // -------------------------------------------------------------------------
    // Routes
    // -------------------------------------------------------------------------
    $app->post('/api/v1/convert', function (Request $req, Response $res) use ($performConversion, $performJsonConversion, $isJsonContent, $sendError): Response {
        if ($isJsonContent($req)) {
            return $performJsonConversion($req, $res, '', '', 'convert-req-');
        }
        // multipart auto-detect
        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $sendError($res, 400, "missing 'file' form field", true);
        }
        $name = $uploaded['file']->getClientFilename() ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $to = null;
        if ($ext === 'xlsb' || $ext === 'ods') $to = 'xlsx';
        elseif ($ext === 'xlsx') $to = 'ods';
        if ($to === null) {
            return $sendError($res, 415, 'supported formats: .xlsb, .xlsx, .ods', true);
        }
        return $performConversion($req, $res, $to, 'convert-req-');
    });

    $app->post('/api/v1/convert/xlsb-to-xlsx', function (Request $req, Response $res) use ($performConversion, $performJsonConversion, $isJsonContent, $sendError): Response {
        if ($isJsonContent($req)) {
            return $performJsonConversion($req, $res, 'xlsx', '.xlsb', 'xlsb-to-xlsx-req-');
        }
        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $sendError($res, 400, "missing 'file' form field", true);
        }
        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'xlsb') {
            return $sendError($res, 415, 'this route accepts only .xlsb files (use /api/v1/convert/xlsb-to-xlsx)', true);
        }
        return $performConversion($req, $res, 'xlsx', 'xlsb-to-xlsx-req-');
    });

    $app->post('/api/v1/convert/xlsx-to-ods', function (Request $req, Response $res) use ($performConversion, $performJsonConversion, $isJsonContent, $sendError): Response {
        if ($isJsonContent($req)) {
            return $performJsonConversion($req, $res, 'ods', '.xlsx', 'xlsx-to-ods-req-');
        }
        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $sendError($res, 400, "missing 'file' form field", true);
        }
        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return $sendError($res, 415, 'this route accepts only .xlsx files (use /api/v1/convert/xlsx-to-ods)', true);
        }
        return $performConversion($req, $res, 'ods', 'xlsx-to-ods-req-');
    });

    $app->post('/api/v1/convert/ods-to-xlsx', function (Request $req, Response $res) use ($performConversion, $performJsonConversion, $isJsonContent, $sendError): Response {
        if ($isJsonContent($req)) {
            return $performJsonConversion($req, $res, 'xlsx', '.ods', 'ods-to-xlsx-req-');
        }
        $uploaded = $req->getUploadedFiles();
        if (!isset($uploaded['file']) || $uploaded['file']->getError() !== UPLOAD_ERR_OK) {
            return $sendError($res, 400, "missing 'file' form field", true);
        }
        $ext = strtolower(pathinfo($uploaded['file']->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'ods') {
            return $sendError($res, 415, 'this route accepts only .ods files (use /api/v1/convert/ods-to-xlsx)', true);
        }
        return $performConversion($req, $res, 'xlsx', 'ods-to-xlsx-req-');
    });

    // 404 for everything else (match old behavior: json error if /api/* )
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], '/{routes:.+}', function (Request $req, Response $res) use ($sendError): Response {
        $path = $req->getUri()->getPath();
        $isApi = str_starts_with($path, '/api/');
        return $sendError($res, 404, 'not found', $isApi);
    });

    return $app;
};
