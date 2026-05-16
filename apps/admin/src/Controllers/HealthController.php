<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin liveness probe (ADMIN-08, spec §277).
 *
 * Returns the literal body "OK" with HTTP 200 and `text/plain; charset=utf-8`.
 * Used by `deploy/smoke.ps1` (INT-05) and by Apache's external monitoring on
 * `127.0.0.1:8084` to confirm the bound admin process is alive without
 * exercising the storage layer. The endpoint deliberately does no I/O.
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
