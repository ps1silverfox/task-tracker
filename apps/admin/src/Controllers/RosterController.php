<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Repositories\RosterRepository;

/**
 * Admin roster controller — add/update/deactivate (ADMIN-05).
 *
 * Routes:
 *   GET    /roster          — list members (active + inactive, marked)
 *   POST   /roster          — add member; body: {name, email?, team_id?}
 *   POST   /roster/{id}     — sparse update of name/email/team_id
 *   DELETE /roster/{id}     — deactivate (soft-delete; idempotent)
 *
 * No reactivation route is exposed by design: the EventLog action enum has no
 * `roster.reactivated` value (RosterRepository docblock §32-36), so allowing
 * reactivation would produce an unauditable state transition. ACTIVE is therefore
 * not a mutable field through POST /roster/{id} — update() in the repository will
 * reject any attempt to change it (the mapper here simply does not forward it).
 *
 * Inline HTML rendering matches TasksController until Twig lands in ADMIN-09.
 */
final class RosterController
{
    public function __construct(
        private readonly RosterRepository $roster,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $members = $this->roster->listAll();

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
              .  '<title>Roster</title></head><body>';
        $html .= '<h1>Roster</h1>';
        $html .= '<table><thead><tr>'
              .  '<th>ID</th><th>Name</th><th>Email</th><th>Team</th><th>Active</th>'
              .  '</tr></thead><tbody>';
        foreach ($members as $m) {
            $html .= sprintf(
                '<tr data-id="%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::esc($m->id),
                self::esc($m->id),
                self::esc($m->name),
                self::esc($m->email ?? ''),
                self::esc($m->teamId ?? ''),
                $m->active ? 'true' : 'false',
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
            $this->roster->add(self::normalizeInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/roster');
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->roster->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "roster member not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $this->roster->update($id, self::normalizeUpdateInput($body));
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
            ->withHeader('Location', '/roster');
    }

    /**
     * @param array<string, string> $args
     */
    public function deactivate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->roster->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "roster member not found: {$id}");
        }

        try {
            $this->roster->deactivate($id);
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/roster');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $body): array
    {
        return [
            'name'   => $body['name']    ?? null,
            'email'  => $body['email']   ?? null,
            'teamId' => $body['team_id'] ?? null,
        ];
    }

    /**
     * Sparse: only keys actually present in the form body are forwarded.
     * ACTIVE is intentionally excluded — see class docblock.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeUpdateInput(array $body): array
    {
        $map = [
            'name'    => 'name',
            'email'   => 'email',
            'team_id' => 'teamId',
        ];
        $out = [];
        foreach ($map as $formKey => $repoKey) {
            if (array_key_exists($formKey, $body)) {
                $out[$repoKey] = $body[$formKey];
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
