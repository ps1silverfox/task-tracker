<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TaskTracker\Aggregations\ExecutiveSummary;
use Twig\Environment;

/**
 * Public executive summary — GET /summary (spec §7, PUB-04).
 *
 * Time-series rollup of task lifecycle events, bucketed by `period` over
 * [from, to] with optional event-time `person` / `team` filters. Renders an
 * HTML table plus a Chart.js stacked-bar embedding the same series as JSON.
 *
 * Defaults are forgiving so the page loads as a useful starting view even
 * with no query string:
 *   - `period`: month
 *   - `to`:     today (UTC)
 *   - `from`:   to − 11 months (rolling 12-period window)
 *
 * Validation errors raise HTTP 400 via Slim's HttpBadRequestException so the
 * Slim error middleware emits the JSON-shaped error body. The aggregation
 * itself validates the strict YYYY-MM-DD format and period membership.
 *
 * Invariant: GET-only. No state mutation, no body parsing — the route is
 * registered exclusively as `$app->get(...)` and PUB-09 enforces 405 on any
 * other verb against this path.
 */
final class ExecutiveSummaryController
{
    private const DEFAULT_PERIOD = ExecutiveSummary::PERIOD_MONTH;
    private const DEFAULT_LOOKBACK_MONTHS = 11;

    public function __construct(
        private readonly ExecutiveSummary $summary,
        private readonly Environment $twig,
    ) {
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $period = self::str($params, 'period') ?? self::DEFAULT_PERIOD;
        if (!in_array($period, ExecutiveSummary::PERIODS, true)) {
            throw new HttpBadRequestException(
                $request,
                "invalid period: '{$period}' (expected one of: "
                    . implode(', ', ExecutiveSummary::PERIODS) . ')',
            );
        }

        [$from, $to] = self::resolveRange(
            self::str($params, 'from'),
            self::str($params, 'to'),
            $request,
        );

        $person = self::str($params, 'person');
        $team   = self::str($params, 'team');

        $rows = $this->summary->summarize($period, $from, $to, $person, $team);

        $html = $this->twig->render('summary.twig', [
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            'person' => $person,
            'team'   => $team,
            'rows'   => $rows,
            'series' => self::seriesForChart($rows),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return array{0:string,1:string}  [from, to] as YYYY-MM-DD UTC
     */
    private static function resolveRange(?string $from, ?string $to, ServerRequestInterface $request): array
    {
        $utc = new DateTimeZone('UTC');
        $todayIso = (new DateTimeImmutable('now', $utc))->format('Y-m-d');

        $to ??= $todayIso;
        if (!self::isIsoDate($to)) {
            throw new HttpBadRequestException($request, "invalid 'to' date (expected YYYY-MM-DD): {$to}");
        }

        if ($from === null) {
            $base = new DateTimeImmutable($to . 'T00:00:00', $utc);
            $from = $base->modify('-' . self::DEFAULT_LOOKBACK_MONTHS . ' months')
                ->format('Y-m-d');
        } elseif (!self::isIsoDate($from)) {
            throw new HttpBadRequestException($request, "invalid 'from' date (expected YYYY-MM-DD): {$from}");
        }

        if ($from > $to) {
            throw new HttpBadRequestException($request, "'from' ({$from}) is after 'to' ({$to})");
        }

        return [$from, $to];
    }

    /**
     * @param list<array{period:string,added:int,completed:int,assigned:int,unassigned:int}> $rows
     * @return array{labels:list<string>,added:list<int>,completed:list<int>,assigned:list<int>,unassigned:list<int>}
     */
    private static function seriesForChart(array $rows): array
    {
        $labels = $added = $completed = $assigned = $unassigned = [];
        foreach ($rows as $r) {
            $labels[]     = $r['period'];
            $added[]      = $r[ExecutiveSummary::METRIC_ADDED];
            $completed[]  = $r[ExecutiveSummary::METRIC_COMPLETED];
            $assigned[]   = $r[ExecutiveSummary::METRIC_ASSIGNED];
            $unassigned[] = $r[ExecutiveSummary::METRIC_UNASSIGNED];
        }
        return [
            'labels'     => $labels,
            'added'      => $added,
            'completed'  => $completed,
            'assigned'   => $assigned,
            'unassigned' => $unassigned,
        ];
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

    private static function isIsoDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
