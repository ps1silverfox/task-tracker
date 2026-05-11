<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Repositories\TeamRepository;

/**
 * Admin teams controller — create + update (ADMIN-06).
 *
 * Routes:
 *   GET  /teams        — list teams
 *   POST /teams        — create; body: {name, description?}
 *   POST /teams/{id}   — sparse update of name/description
 *
 * No delete route is exposed by design: the EventLog action enum has no
 * `team.deleted` value (TeamRepository docblock §17-23), and historical events
 * reference team IDs by snapshot, so destruction would orphan audit data.
 *
 * Inline HTML rendering matches RosterController until Twig lands in ADMIN-09.
 */
final class TeamsController
{
    public function __construct(
        private readonly TeamRepository $teams,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $teams = $this->teams->listAll();

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
              .  '<title>Teams</title></head><body>';
        $html .= '<h1>Teams</h1>';
        $html .= '<table><thead><tr>'
              .  '<th>ID</th><th>Name</th><th>Description</th>'
              .  '</tr></thead><tbody>';
        foreach ($teams as $t) {
            $html .= sprintf(
                '<tr data-id="%s"><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::esc($t->id),
                self::esc($t->id),
                self::esc($t->name),
                self::esc($t->description ?? ''),
            );
        }
        $html .= '</tbody></table></body></html>';

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $this->teams->create(self::normalizeInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/teams');
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->teams->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "team not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $this->teams->update($id, self::normalizeUpdateInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
            }
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/teams');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $body): array
    {
        return [
            'name'        => $body['name']        ?? null,
            'description' => $body['description'] ?? null,
        ];
    }

    /**
     * Sparse: only keys actually present in the form body are forwarded so
     * unspecified fields stay untouched and produce no audit event.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeUpdateInput(array $body): array
    {
        $out = [];
        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $body)) {
                $out[$key] = $body[$key];
            }
        }
        return $out;
    }

    private function jsonError(ResponseInterface $response, string $code, string $message): ResponseInterface
    {
        $payload = json_encode(['error' => $code, 'message' => $message], JSON_THROW_ON_ERROR);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
