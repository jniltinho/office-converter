<?php
declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET|HEAD /healthz — liveness probe.
 *
 * HEAD returns 200 with no body (used by load balancers).
 * GET  returns 200 with the plain-text body "ok".
 */
class HealthController
{
    public function __invoke(Request $req, Response $res): Response
    {
        if ($req->getMethod() === 'HEAD') {
            return $res->withStatus(200);
        }

        $res->getBody()->write('ok');
        return $res->withStatus(200);
    }
}
