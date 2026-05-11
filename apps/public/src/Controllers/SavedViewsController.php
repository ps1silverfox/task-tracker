<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TaskTracker\Repositories\SavedViewRepository;

/**
 * Apply a saved view — GET /views/{id} (spec §6, PUB-07).
 *
 * Resolves the SavedView by id, serializes its FILTER_JSON back to the same
 * query-string shape that {@see BacklogController::parseFilter()} parses, and
 * 302-redirects to `/` with that query attached. The indirection means the
 * filter-evaluation code lives in exactly one place (BacklogController); a
 * saved view is just a named pointer into that controller's query surface.
 *
 * Filter axes serialized:
 *   - status, priority, tags         list<string>  → comma-joined value
 *   - team_id, assignee_id, parent_id string|null   → literal value or "null"
 *   - due_within_days                 int           → decimal string
 *   - include_done                    bool          → "1" when true (omitted otherwise)
 *
 * Unknown keys in FILTER_JSON are dropped: only the spec §5 axes are
 * propagated, so a malformed or extended filter can't smuggle arbitrary
 * query parameters into the backlog URL.
 *
 * 404 when the id doesn't resolve. Soft-delete doesn't apply here — saved
 * views are hard-deleted by the admin app.
 */
final class SavedViewsController
{
    private const LIST_AXES   = ['status', 'priority', 'tags'];
    private const TRI_AXES    = ['team_id', 'assignee_id', 'parent_id'];

    public function __construct(
        private readonly SavedViewRepository $views,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function apply(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id = $args['id'] ?? '';
        $view = $this->views->find($id);
        if ($view === null) {
            throw new HttpNotFoundException($request, "saved view not found: {$id}");
        }

        $qs = self::filterToQueryString($view->filter);
        $location = $qs === '' ? '/' : '/?' . $qs;

        return $response
            ->withStatus(302)
            ->withHeader('Location', $location);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function filterToQueryString(array $filter): string
    {
        $params = [];

        foreach (self::LIST_AXES as $key) {
            if (!array_key_exists($key, $filter)) {
                continue;
            }
            $raw = $filter[$key];
            if (!is_array($raw) || $raw === []) {
                continue;
            }
            $parts = [];
            foreach ($raw as $piece) {
                if (is_string($piece) && $piece !== '') {
                    $parts[] = $piece;
                }
            }
            if ($parts !== []) {
                $params[$key] = implode(',', $parts);
            }
        }

        foreach (self::TRI_AXES as $key) {
            if (!array_key_exists($key, $filter)) {
                continue;
            }
            $raw = $filter[$key];
            if ($raw === null) {
                // JSON null → "match IS NULL" sentinel BacklogController recognizes.
                $params[$key] = 'null';
            } elseif (is_string($raw) && $raw !== '') {
                $params[$key] = $raw;
            }
        }

        if (array_key_exists('due_within_days', $filter)) {
            $raw = $filter['due_within_days'];
            if (is_int($raw) && $raw >= 0) {
                $params['due_within_days'] = (string) $raw;
            }
        }

        if (!empty($filter['include_done'])) {
            $params['include_done'] = '1';
        }

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
