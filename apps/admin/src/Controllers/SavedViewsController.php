<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Repositories\SavedViewRepository;

/**
 * Admin saved-views controller (ADMIN-07).
 *
 * Routes (spec §273-275):
 *   GET    /saved-views        — list
 *   POST   /saved-views        — create from current filter; body: {name, filter?}
 *   DELETE /saved-views/{id}   — remove
 *
 * No update route is exposed by design: the EventLog action enum admits only
 * `saved_view.created` and `saved_view.deleted` (spec §381-382), so renaming
 * or refiltering must round-trip through delete + recreate to keep the audit
 * trail closed.
 *
 * `filter` arrives via Slim's body-parsing middleware either as a nested form
 * array (`filter[status][]=open`) or as a decoded JSON object — both reach
 * the repository as `array<string, mixed>` unchanged.
 *
 * Inline HTML rendering matches the other Phase-5 controllers until Twig
 * lands in ADMIN-09.
 */
final class SavedViewsController
{
    public function __construct(
        private readonly SavedViewRepository $views,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $views = $this->views->listAll();

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
              .  '<title>Saved Views</title></head><body>';
        $html .= '<h1>Saved Views</h1>';
        $html .= '<table><thead><tr>'
              .  '<th>ID</th><th>Name</th><th>Created At</th><th>Filter</th>'
              .  '</tr></thead><tbody>';
        foreach ($views as $v) {
            $filterJson = json_encode(
                $v->filter,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $html .= sprintf(
                '<tr data-id="%s"><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
                self::esc($v->id),
                self::esc($v->id),
                self::esc($v->name),
                self::esc($v->createdAt),
                self::esc($filterJson),
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
            $this->views->create(self::normalizeInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/saved-views');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->views->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "saved view not found: {$id}");
        }

        try {
            $this->views->delete($id);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/saved-views');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $body): array
    {
        $out = ['name' => $body['name'] ?? null];
        if (array_key_exists('filter', $body)) {
            $out['filter'] = $body['filter'];
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
