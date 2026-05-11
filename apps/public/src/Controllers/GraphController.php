<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TaskTracker\Aggregations\DependencyGraph;
use TaskTracker\Models\Enums;
use Twig\Environment;

/**
 * Public dependency visualizer — GET /graph (HTML) and GET /graph.json (data),
 * spec §10 / PUB-06.
 *
 * The HTML page is a thin shell: it loads cytoscape.js + cytoscape-dagre from
 * the local vendor copy, then issues a same-origin XHR to /graph.json with the
 * URL's query string passed through verbatim. All graph projection logic lives
 * in DependencyGraph; the controller only translates query → filter array and
 * translates the aggregator's InvalidArgumentException into HTTP 400.
 *
 * Query strings (spec §10):
 *   - `status=open,in_progress` — comma-separated subset of STATUSES_LIVE
 *   - `team_id=...`             — exact match against task.teamId
 *   - `root=...`                — restrict to a parent_id subtree
 *
 * Invariant: GET-only. PUB-09 enforces 405 on any other verb against /graph
 * and /graph.json.
 */
final class GraphController
{
    public function __construct(
        private readonly DependencyGraph $graph,
        private readonly Environment $twig,
    ) {
    }

    public function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $filters = self::parseFilters($params, $request);

        $jsonUrl = '/graph.json';
        $qs = http_build_query(self::stringParams($params));
        if ($qs !== '') {
            $jsonUrl .= '?' . $qs;
        }

        $html = $this->twig->render('graph.twig', [
            'status_filter' => $filters['status'] ?? [],
            'team_id'       => $filters['team_id'] ?? '',
            'root'          => $filters['root'] ?? '',
            'statuses_live' => Enums::STATUSES_LIVE,
            'json_url'      => $jsonUrl,
            'node_ceiling'  => DependencyGraph::RECOMMENDED_NODE_CEILING,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function json(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $filters = self::parseFilters($request->getQueryParams(), $request);

        try {
            $payload = $this->graph->serialize($filters);
        } catch (InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status?: list<string>, team_id?: string, root?: string}
     */
    private static function parseFilters(array $params, ServerRequestInterface $request): array
    {
        $out = [];

        $statusRaw = self::str($params, 'status');
        if ($statusRaw !== null) {
            $statuses = [];
            foreach (explode(',', $statusRaw) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                if (!in_array($piece, Enums::STATUSES_LIVE, true)) {
                    throw new HttpBadRequestException(
                        $request,
                        "invalid status filter: '{$piece}' (expected one of: "
                            . implode(', ', Enums::STATUSES_LIVE) . ')',
                    );
                }
                $statuses[] = $piece;
            }
            if ($statuses !== []) {
                $out['status'] = $statuses;
            }
        }

        $teamId = self::str($params, 'team_id');
        if ($teamId !== null) {
            $out['team_id'] = $teamId;
        }

        $root = self::str($params, 'root');
        if ($root !== null) {
            $out['root'] = $root;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function str(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return $raw;
    }

    /**
     * Filter query params down to scalars so http_build_query never encodes
     * unexpected array shapes into the data-page-to-data-endpoint hop.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private static function stringParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
