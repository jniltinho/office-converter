<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Rejects requests whose Content-Length exceeds the configured limit
 * before the body is read, returning 413 with a JSON or plain-text body
 * depending on whether the path is under /api/.
 */
class UploadSizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $maxBytes) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $cl = (int)($request->getServerParams()['CONTENT_LENGTH'] ?? 0);

        if ($cl > 0 && $cl > $this->maxBytes) {
            $res = (new SlimResponse())->withStatus(413);
            $isApi = str_starts_with($request->getUri()->getPath(), '/api/');

            if ($isApi) {
                $res = $res->withHeader('Content-Type', 'application/json');
                $res->getBody()->write(json_encode(['error' => 'request entity too large'], JSON_UNESCAPED_SLASHES));
            } else {
                $res->getBody()->write('request entity too large');
            }

            return $res;
        }

        return $handler->handle($request);
    }
}
