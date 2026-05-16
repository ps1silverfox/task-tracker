<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public liveness probe (PUB-08, spec §6).
 *
 * Returns the literal body "OK" with HTTP 200 and `text/plain; charset=utf-8`.
 * Bound to GET /health on the public app (`*:8083`). Exists as a parallel of the
 * admin probe so external monitoring can distinguish bind 8083 from 127.0.0.1:8084
 * without sharing transport layers between the two apps.
 *
 * Does no I/O — liveness is decoupled from disk/CSV/NDJSON health by design.
 */
final class HealthController
{
    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('OK');
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
