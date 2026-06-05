<?php
declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Catch-all 404 handler.
 *
 * Returns a JSON body for paths under /api/, plain text otherwise,
 * matching the behaviour of the original implementation.
 */
class NotFoundController
{
    public function __invoke(Request $req, Response $res): Response
    {
        $isApi = str_starts_with($req->getUri()->getPath(), '/api/');
        $res   = $res->withStatus(404);

        if ($isApi) {
            $res = $res->withHeader('Content-Type', 'application/json');
            $res->getBody()->write(json_encode(['error' => 'not found'], JSON_UNESCAPED_SLASHES));
        } else {
            $res->getBody()->write('not found');
        }

        return $res;
    }
}
