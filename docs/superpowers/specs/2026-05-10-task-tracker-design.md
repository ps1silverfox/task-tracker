# Task Tracker v1.0 — Design Spec

| Field | Value |
|---|---|
| Spec date | 2026-05-10 |
| Status | Approved by user, pending spec-review |
| Target version | v1.0 |
| Deployment target | Windows Server 2016 + Apache 2.4 |
| Author context | Internal team tool, 2–10 users, single shared backlog |

---

## §1 Problem statement

A small team (2–10 people) needs a shared task tracker installed on a Windows Server 2016 host running Apache 2.4. The host operator owns the data; the rest of the team consumes it over the corporate LAN. Existing systems on the workspace (BIS overseer markdown, Mira agency goals, per-project `TASK.md` files) are not suitable for cross-cutting team work because they are project-scoped and lack a unified view.

The product must support a **single shared backlog** with **sub-projects** (parent/child tasks) and **prerequisites** (a task can block any other task regardless of tree position), and must surface an **executive time-series summary** (daily / monthly / quarterly / yearly) of task lifecycle events filterable by person and team.

## §2 Scope

### In scope (v1.0)

- Single shared backlog with sub-project hierarchy and prerequisite dependencies
- Manually maintained roster of team members (no authentication system)
- Teams as a grouping abstraction over roster
- Task fields: title, body, status, priority, due date, effort estimate, URL, parent, assignee, team, tags
- Status enum: `open`, `in_progress`, `blocked`, `done`
- Priority enum: `low`, `med`, `high`, `critical`
- Activity feed / change log per task (rendered from event log)
- Saved views (team artifacts; shared filter definitions)
- Dependency visualizer (cytoscape.js, dagre layout)
- Executive time-series summary with CSV export
- Backlog list CSV export
- Email notifications via SMTP relay (assignment, due-soon, overdue, daily digest)
- Mobile-responsive read view
- Audit trail: NDJSON daily-rotated change-history sidecar log

### Out of scope (deferred to v1.1)

- Actual time spent (clock-in/out)
- Recurring tasks
- File attachments (URL-only in v1.0)
- Browser/push notifications
- Per-viewer personal saved filters (v1.0 filters are team-global)
- APCu cache layer for read latency

### Explicit non-goals

- Multi-user authentication / sessions / RBAC — by design
- Plugin system
- Multi-tenant isolation
- SaaS deployment
- Database engine (SQLite / MariaDB / MSSQL) — by design, data is text files

## §3 Security model

**Access control is the network bind**, not a permission system:

- **Admin surface** binds `127.0.0.1:8080`. Anyone running on the host can create, edit, delete tasks/roster/teams/saved-views. There is no login form, no session, no CSRF token. Apache `Require local` directive provides belt-and-suspenders enforcement.
- **Public surface** binds `*:80`. Anyone reachable on the corporate LAN can view tasks, summary, and graph. No POST/PUT/DELETE routes exist on this surface — the controllers for those operations are physically absent from the public app's source tree.

**Implications:**

1. The host operator is the trust anchor. Trust = filesystem access to `C:\inetpub\task-tracker\apps\admin\`.
2. "Assignee" and "person filter" are roster identities (rows in `roster.csv`), not authenticated users. Roster is editable from the admin surface only.
3. CSRF, session fixation, password reset, account recovery, and 2FA are not applicable.
4. Two threat-model invariants must not be weakened:
   - **Bind-level separation**: admin code never reachable from the public listener
   - **Code-presence separation**: write controllers physically absent from `apps/public/src/`

## §4 Architecture

### Component layout

```
I:/work folder/projects/task-tracker/                   # source repository
├── apps/
│   ├── admin/                          # Apache vhost: 127.0.0.1:8080
│   │   ├── public/index.php            # DocumentRoot, Slim front controller
│   │   ├── src/Controllers/            # full CRUD controllers
│   │   ├── src/Templates/              # Twig
│   │   ├── src/routes.php
│   │   └── composer.json
│   └── public/                         # Apache vhost: *:80
│       ├── public/index.php
│       ├── src/Controllers/            # read-only controllers
│       ├── src/Templates/
│       ├── src/routes.php
│       └── composer.json
├── lib/                                # shared library, composer path repo
│   ├── src/
│   │   ├── Storage/
│   │   │   ├── CsvStore.php            # flock + atomic rename
│   │   │   └── EventLog.php            # NDJSON append-only
│   │   ├── Models/                     # Task, RosterMember, Team, Event, SavedView
│   │   ├── Repositories/               # TaskRepository, RosterRepository, …
│   │   ├── Aggregations/
│   │   │   ├── SubProjectRollup.php
│   │   │   ├── ExecutiveSummary.php
│   │   │   └── DependencyGraph.php
│   │   ├── Mail/SmtpMailer.php
│   │   └── Util/{Uuid7.php, CsvInjectionGuard.php, IsoTime.php}
│   └── composer.json                   # package name: task-tracker/core
├── data/                               # filesystem data store (path is env-overridable)
│   ├── tasks.csv
│   ├── roster.csv
│   ├── teams.csv
│   ├── dependencies.csv
│   ├── tags.csv
│   ├── saved_views.csv
│   └── _schema_version.txt
├── logs/
│   ├── changes-YYYY-MM-DD.ndjson       # daily-rotated audit log
│   ├── admin-error.log
│   ├── admin-access.log
│   ├── public-error.log
│   └── public-access.log
├── deploy/
│   ├── httpd-task-tracker.conf
│   └── README.md
├── tests/
│   ├── unit/
│   ├── integration/
│   └── fixtures/data/
├── tools/
│   ├── seed.php                        # creates empty CSVs + example team/member
│   ├── migrate.php                     # schema migration runner
│   ├── rotate-logs.php                 # compress logs > N days old
│   └── reconcile.php                   # boot-time CSV ↔ NDJSON reconciliation
├── docs/superpowers/specs/
├── composer.json                       # workspace root; declares lib/ path repo
├── phpunit.xml
└── README.md
```

### Process model

Single Apache 2.4 process tree with two `<VirtualHost>` blocks. PHP runs under `mod_php` (no separate FPM process to manage on Windows). Two physically separate document roots (`apps/admin/public/` and `apps/public/public/`) so file-system access controls plus the absence of write controllers in the public tree make write-via-public impossible regardless of router misconfiguration.

### Configuration

Environment variables (read at boot from `php.ini` `env` directives or per-vhost `SetEnv`):

| Var | Default | Notes |
|---|---|---|
| `DATA_DIR` | `<project>/data` | All CSV files |
| `LOG_DIR` | `<project>/logs` | Includes NDJSON change history |
| `SMTP_HOST` | (none) | If unset, email features no-op |
| `SMTP_PORT` | `25` | |
| `SMTP_FROM` | `task-tracker@<corp-domain>` | |
| `BASE_URL` | `http://localhost` | For email links |
| `TIMEZONE` | `UTC` | All ISO 8601 timestamps in UTC, rendered in this TZ |

## §5 Data model

All data lives as RFC 4180 CSV files in `DATA_DIR/`. Format: CRLF line endings, every field quoted (regardless of need), UTF-8 with BOM rejected, header row required.

### `tasks.csv`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `ID` | UUID v7 | no | Time-ordered, primary key |
| `SLUG` | string | no | Unique across non-deleted rows; auto-from-title, admin-editable |
| `TITLE` | string | no | |
| `BODY` | string | yes | Markdown; rendered with CommonMark + HTML sanitizer |
| `STATUS` | enum | no | `open` \| `in_progress` \| `blocked` \| `done` \| `deleted` |
| `PRIORITY` | enum | no | `low` \| `med` \| `high` \| `critical`; default `med` |
| `DUE_DATE` | ISO 8601 date | yes | |
| `EFFORT_HOURS` | decimal | yes | Two decimal places |
| `URL` | string | yes | External link |
| `PARENT_ID` | UUID | yes | Self-FK to `tasks.ID` |
| `ASSIGNEE_ID` | UUID | yes | FK to `roster.ID` |
| `TEAM_ID` | UUID | yes | FK to `teams.ID` |
| `CREATED_AT` | ISO 8601 ts | no | Immutable |
| `UPDATED_AT` | ISO 8601 ts | no | Bumped on any field change |
| `FIRST_ASSIGNED_AT` | ISO 8601 ts | yes | Set once when ASSIGNEE_ID goes null→non-null |
| `LAST_ASSIGNMENT_CHANGE_AT` | ISO 8601 ts | yes | Bumped on any ASSIGNEE_ID change |
| `COMPLETED_AT` | ISO 8601 ts | yes | Set on STATUS→`done`; cleared on un-done |

### `roster.csv`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `ID` | UUID v7 | no | |
| `NAME` | string | no | Display name |
| `EMAIL` | string | yes | For SMTP notifications |
| `TEAM_ID` | UUID | yes | FK to `teams.ID` |
| `ACTIVE` | bool | no | `true`/`false`; soft-delete via false |

### `teams.csv`

| Column | Type | Nullable |
|---|---|---|
| `ID` | UUID v7 | no |
| `NAME` | string | no |
| `DESCRIPTION` | string | yes |

### `dependencies.csv` (B prereq of A; B blocks A)

| Column | Type | Notes |
|---|---|---|
| `TASK_ID` | UUID | The blocked task |
| `PREREQ_ID` | UUID | The blocking task |

Composite key `(TASK_ID, PREREQ_ID)` unique. Cycle prevention enforced at write time.

### `tags.csv`

| Column | Type |
|---|---|
| `TASK_ID` | UUID |
| `TAG` | string (lowercase, slugified) |

Composite key `(TASK_ID, TAG)` unique.

### `saved_views.csv`

| Column | Type | Notes |
|---|---|---|
| `ID` | UUID v7 | |
| `NAME` | string | Unique |
| `CREATED_AT` | ISO 8601 ts | |
| `FILTER_JSON` | string | JSON-encoded filter blob, quoted as one CSV field |

`FILTER_JSON` shape:

```json
{
  "status": ["open", "in_progress"],
  "priority": ["high", "critical"],
  "team_id": "uuid-or-null",
  "assignee_id": "uuid-or-null",
  "tags": ["urgent"],
  "due_within_days": 7,
  "parent_id": "uuid-or-null",
  "include_done": false
}
```

All fields optional; absent = no filter on that axis.

### `_schema_version.txt`

Single integer line. Bumped by `tools/migrate.php` when schema changes. v1.0 = `1`.

## §6 HTTP surface

### Admin (127.0.0.1:8080)

```
GET    /                         backlog with edit affordances
GET    /tasks/new                create form
POST   /tasks                    create task
GET    /tasks/{id}               detail + edit form
POST   /tasks/{id}               update fields
POST   /tasks/{id}/assign        body: {assignee_id|null}
POST   /tasks/{id}/status        body: {status}
POST   /tasks/{id}/dependencies  body: {prereq_id}
DELETE /tasks/{id}/dependencies/{prereq_id}
POST   /tasks/{id}/tags          body: {tag}
DELETE /tasks/{id}/tags/{tag}
DELETE /tasks/{id}               soft-delete (STATUS=deleted)

GET    /roster                   list
POST   /roster                   add
POST   /roster/{id}              update
POST   /roster/{id}/deactivate

GET    /teams                    list
POST   /teams                    create
POST   /teams/{id}               update

GET    /saved-views              list
POST   /saved-views              create from current filter
DELETE /saved-views/{id}

GET    /health                   "OK"
```

### Public (`*:80`)

```
GET    /                         backlog list (filterable via query string)
GET    /tasks/{id}               read-only detail with activity feed
GET    /summary                  executive time-series HTML
GET    /summary.csv              CSV export of summary
GET    /tasks.csv                CSV export of filtered list
GET    /graph                    dependency visualizer page
GET    /graph.json               graph data
GET    /views/{id}               apply saved view (redirect to / with filter)
GET    /health                   "OK"
```

Public surface registers no non-GET routes. The Slim router has no entries for POST/PUT/DELETE/PATCH; an attempt returns 405 from Slim's default handler.

### Response formats

- HTML: Twig-rendered, Bootstrap 5 (CDN-loaded from local copy), no client-side framework
- JSON: only `graph.json` and structured error responses
- CSV: `text/csv; charset=utf-8`, CRLF, RFC 4180, CSV-injection-safe (cells starting with `=`, `+`, `-`, `@`, tab, CR get prefixed with a single quote)
- Errors: HTTP status + JSON body `{"error": "code", "message": "human-readable"}` for JSON requests; HTML error page otherwise

## §7 Executive summary algorithm

**Endpoint:** `GET /summary?period={day|month|quarter|year}&from=2026-01-01&to=2026-05-10&person={member_id}&team={team_id}`

Parameters:

- `period` (required): bucket granularity
- `from`, `to` (required): inclusive date range; ISO 8601 dates
- `person` (optional): filter to events touching tasks where `assignee_id == person` *at event time*
- `team` (optional): filter to events touching tasks where `team_id == team` *at event time*

**Tracked actions** (only these contribute to counts):

| Metric | Action |
|---|---|
| `added` | `task.created` |
| `completed` | `task.completed` |
| `assigned` | `task.assigned` + `task.reassigned` |
| `unassigned` | `task.unassigned` |

**Algorithm:**

1. Generate bucket boundaries for `period` between `from` and `to`
2. Determine the set of NDJSON files covering that range: `changes-YYYY-MM-DD.ndjson` where date ∈ [from, to]
3. Stream-read each file line by line (no full-load); parse JSON
4. Filter by `action ∈ {tracked_actions}`
5. If `person` is set: skip events whose payload `assignee_id_snapshot != person`
6. If `team` is set: skip events whose payload `team_id_snapshot != team`
7. Increment the bucket counter for `(period_bucket, metric)`
8. Render: HTML table + Chart.js stacked bar (one bar per period, four series)
9. CSV export: same bucket rows, `text/csv` response

**Event-time snapshot:** every event includes `assignee_id_snapshot` and `team_id_snapshot` denormalized at write time, so historical filtering is correct even when assignment/team changes later. Cost: ~80 bytes per event.

## §8 Change-history flat file format

**Path pattern:** `LOG_DIR/changes-YYYY-MM-DD.ndjson` (UTC date)

**Format:** NDJSON — one JSON object per line, LF terminator, UTF-8 no BOM. File is append-only at runtime; rotation is by date (new file at UTC midnight on first write of the new day).

**Per-line shape:**

```json
{
  "ts": "2026-05-10T14:23:11.482Z",
  "actor": "localhost",
  "ip": "127.0.0.1",
  "user_agent": "Mozilla/5.0 …",
  "action": "task.created",
  "task_id": "018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e21",
  "data": { … action-specific payload … },
  "assignee_id_snapshot": "018e…",
  "team_id_snapshot": "018e…"
}
```

**Action enum (closed set; expansion bumps `_schema_version.txt`):**

```
task.created
task.updated         (data.fields: [list of changed column names])
task.deleted
task.assigned        (data.from: null, data.to: uuid)
task.unassigned      (data.from: uuid, data.to: null)
task.reassigned      (data.from: uuid, data.to: uuid)
task.status_changed  (data.from, data.to)
task.completed
task.priority_changed
task.due_date_changed
dependency.added     (data.prereq_id)
dependency.removed
tag.added            (data.tag)
tag.removed
roster.added
roster.updated
roster.deactivated
team.created
team.updated
saved_view.created
saved_view.deleted
system.reconcile     (boot-time reconciliation diff)
```

**Append mechanics:** `file_put_contents($path, $line, FILE_APPEND | LOCK_EX)`. On Windows NTFS, this is atomic for writes ≤ 4KB (typical event line is <1KB). Each append fsyncs via `fflush` after the call.

**Rotation policy:** new file per UTC day, opened lazily on first write. `tools/rotate-logs.php` (scheduled task, daily) gzips files older than 30 days into `logs/archive/changes-YYYY-MM.tar.gz`.

## §9 Concurrency, file locking, and failure modes

### Single-writer, many-readers model

- Only the admin surface writes (it's the only place the write code exists).
- Apache `mod_php` may spawn multiple worker threads, so even the admin surface can have concurrent in-flight writes.
- Public surface reads concurrently.

### Write transaction

```php
public function txn(string $csvPath, callable $mutator, array $events): void {
    $fh = fopen($csvPath, 'c+');                     // create+read+write, no truncate
    if (!flock($fh, LOCK_EX)) throw new RuntimeException('lock failed');
    try {
        $rows = $this->readCsvRows($fh);
        $rows = $mutator($rows);
        $tmp  = $csvPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $this->formatCsv($rows));
        rename($tmp, $csvPath);                      // atomic on NTFS same-volume
        foreach ($events as $event) {
            $this->eventLog->append($event);         // NDJSON append, separate lock
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
```

### Read pattern

```php
public function readAll(string $csvPath): array {
    $fh = fopen($csvPath, 'r');
    flock($fh, LOCK_SH);                             // shared lock; blocks during write
    try {
        return iterator_to_array($this->parseCsv($fh));
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
```

### Failure modes

| Scenario | Resulting state | Detection / recovery |
|---|---|---|
| Crash before `rename` | CSV unchanged, `.tmp.*` orphan file | `tools/reconcile.php` (boot-time, also runnable on demand) deletes `.tmp.*` files older than 60s |
| Crash between `rename` and NDJSON append | CSV updated, audit missing 1 event | Boot-time reconcile compares last `tasks.UPDATED_AT` against latest NDJSON `ts`; if drift detected, emit `system.reconcile` event with diff to current NDJSON |
| Concurrent reader during write | Reader's `flock(LOCK_SH)` waits | Acceptable for <50ms typical writes |
| Concurrent write (two admin workers) | Second writer's `flock(LOCK_EX)` waits | Acceptable for <50ms typical writes |
| NDJSON disk full | `file_put_contents` returns false | Log to PHP error log; CSV write still succeeds (audit is corroborative, not authoritative) |
| Power loss mid-write | `tmp` file half-written, never renamed | Boot-time reconcile cleans tmp; CSV intact |

### Cycle prevention for dependencies

On `POST /tasks/{id}/dependencies`:

1. Load `dependencies.csv`
2. Build adjacency: `task_id → set(prereq_id)`
3. Add proposed edge
4. DFS from new `prereq_id`; if `task_id` reachable, reject with 422 and message "would create cycle"
5. Otherwise, write the edge

## §10 Saved views, dependency visualizer, exports

### Saved views

Team artifacts (no per-viewer scoping in v1.0). Admin creates them from the current backlog filter (`POST /saved-views` with the filter JSON in the body). Public surface URL `GET /views/{id}` looks up the view, applies its filter, and renders the backlog page.

### Dependency visualizer

`GET /graph` returns an HTML page with cytoscape.js (MIT license, served from `apps/public/public/vendor/cytoscape/`) and the dagre layout extension. Page JS fetches `GET /graph.json` and renders.

Visual encoding:

- Node fill color: status (`open`=gray, `in_progress`=blue, `blocked`=red, `done`=green)
- Node border color: priority (`low`=gray, `med`=black, `high`=orange, `critical`=red)
- Node label: title (truncated to 40 chars)
- Edge: arrow from prereq → blocked task
- Click node: navigate to `/tasks/{id}` read view

Query strings: `?status=open,in_progress`, `?team_id=...`, `?root=...` (subtree filter).

Performance: up to ~500 nodes renders cleanly; beyond that, the page emits a banner suggesting a tighter filter.

### Exports

- `GET /tasks.csv` — current backlog list with same query-string filters as `GET /`
- `GET /summary.csv` — executive summary as CSV (one row per period bucket)
- Both apply CSV-injection guard: cells starting with `=`, `+`, `-`, `@`, `\t`, `\r` get prefixed with `'`

## §11 Stack & dependencies

### Server runtime

- Windows Server 2016
- Apache HTTP Server 2.4.x (Windows binary)
- PHP 8.3 NTS for Windows (`mod_php`)

### PHP extensions required

`mbstring`, `intl`, `openssl`, `apcu`, `fileinfo`, `curl` (for SMTP if STARTTLS)

### Composer dependencies (admin app; public app is subset)

| Package | Version | Purpose |
|---|---|---|
| `slim/slim` | `^4.13` | HTTP framework |
| `slim/psr7` | `^1.6` | PSR-7 impl |
| `slim/twig-view` | `^3.4` | Twig integration |
| `twig/twig` | `^3.10` | Templating |
| `ramsey/uuid` | `^4.7` | UUID v7 generation |
| `monolog/monolog` | `^3.5` | App logging |
| `league/commonmark` | `^2.5` | Markdown rendering (task body) |
| `symfony/mailer` | `^7.0` | SMTP |
| `vlucas/phpdotenv` | `^5.6` | `.env` loader |
| `task-tracker/core` | `dev-main` | Local path repo → `lib/` |

### Public app composer (subset)

Excludes `symfony/mailer` (no notifications from public surface).

### Browser-side

- Bootstrap 5 CSS (local copy under `apps/{admin,public}/public/vendor/bootstrap/`)
- Chart.js (local copy, MIT)
- cytoscape.js + cytoscape-dagre (public only, local copy, MIT)

No build step. No npm. CSS/JS served as-is.

### License policy

MIT / Apache-2.0 / BSD-2 / BSD-3 / ISC / 0BSD only. GPL/LGPL/AGPL/SSPL rejected. Documented in `docs/license-policy.md`.

## §12 Deploy on Windows Server 2016 + Apache 2.4

1. **Install PHP 8.3 NTS Windows** to `C:\php\`; copy `php.ini-production` → `php.ini`; enable extensions listed in §11
2. **Install Apache 2.4** (already in stack assumption)
3. **Copy project** to `C:\inetpub\task-tracker\` (or other path; update vhost accordingly)
4. **NTFS permissions:**
   - Apache service account: R+W on `data/` and `logs/`
   - Apache service account: R-only on `apps/`, `lib/`, `tools/`
   - Administrators: full control
5. **Apache config** — drop `deploy/httpd-task-tracker.conf` into `C:\Apache24\conf\extra\` and `Include` from `httpd.conf`:

   ```apache
   LoadModule php_module "C:/php/php8apache2_4.dll"
   AddHandler application/x-httpd-php .php
   PHPIniDir "C:/php"

   Listen 127.0.0.1:8080
   Listen 80

   <VirtualHost 127.0.0.1:8080>
     ServerName task-tracker-admin.local
     DocumentRoot "C:/inetpub/task-tracker/apps/admin/public"
     <Directory "C:/inetpub/task-tracker/apps/admin/public">
       AllowOverride All
       Require local
     </Directory>
     SetEnv DATA_DIR "C:/inetpub/task-tracker/data"
     SetEnv LOG_DIR  "C:/inetpub/task-tracker/logs"
     ErrorLog  "C:/inetpub/task-tracker/logs/admin-error.log"
     CustomLog "C:/inetpub/task-tracker/logs/admin-access.log" combined
   </VirtualHost>

   <VirtualHost *:80>
     ServerName task-tracker.<corp-domain>
     DocumentRoot "C:/inetpub/task-tracker/apps/public/public"
     <Directory "C:/inetpub/task-tracker/apps/public/public">
       AllowOverride All
       Require all granted
     </Directory>
     SetEnv DATA_DIR "C:/inetpub/task-tracker/data"
     SetEnv LOG_DIR  "C:/inetpub/task-tracker/logs"
     ErrorLog  "C:/inetpub/task-tracker/logs/public-error.log"
     CustomLog "C:/inetpub/task-tracker/logs/public-access.log" combined
   </VirtualHost>
   ```

6. **Composer install** in `apps/admin/`, `apps/public/`, and `lib/`
7. **Seed:** `php tools/seed.php` — creates empty CSVs with headers, one example team, one example roster member
8. **Migrate:** `php tools/migrate.php` — writes `_schema_version.txt`
9. **Scheduled tasks** (Windows Task Scheduler):
   - Daily 02:00: `php tools/rotate-logs.php`
   - Hourly: `php tools/reconcile.php --quiet` (catches any drift)
10. **Restart Apache:** `Restart-Service Apache2.4`
11. **Smoke tests:**
    - `curl http://127.0.0.1:8080/health` → `OK`
    - `curl http://127.0.0.1:8080/` → admin backlog HTML
    - `curl http://localhost/health` → `OK`
    - `curl http://localhost/` → public backlog HTML
    - `curl -X POST http://localhost/tasks` → 405 (no route on public)
    - From another host on LAN: same as above for `http://<server>/`
    - From another host on LAN: `curl http://<server>:8080/` → connection refused or 403 (Apache `Require local` blocks)

## §13 Testing strategy

### Unit (PHPUnit 11)

Target: `lib/`. One spec per class. Mock filesystem via `org/bovigo/vfs`.

- `CsvStore`: parse, serialize, lock, atomic-rename, recovery from orphan tmp
- `EventLog`: append, daily rotation at UTC midnight, NDJSON round-trip
- `Uuid7`: monotonic ordering across rapid calls
- `CsvInjectionGuard`: prefix cells starting with `=`, `+`, `-`, `@`, `\t`, `\r`
- `SubProjectRollup`: tree traversal, cycle handling (defensive — should never happen in tasks tree)
- `ExecutiveSummary`: bucketing for each `period`, filter application, event-time snapshot semantics
- `DependencyGraph`: cycle detection, JSON serialization

### Integration

Boot Slim apps in-process via PHPUnit, hit endpoints with PSR-7 request objects, assert:

1. CSV state after admin operations
2. NDJSON contents after admin operations
3. Public endpoint responses (read-only behavior)
4. 405 from public for any non-GET method
5. 404 from public for admin-only paths

### Invariant tests (property-style)

- **Audit completeness:** every state-changing admin route produces ≥1 NDJSON event with matching `task_id`
- **Sub-project rollup:** rollup totals = sum of recursive leaf counts/effort
- **Dependency cycle prevention:** adding any edge that closes a cycle returns 422 and does not modify `dependencies.csv`
- **CSV injection:** every exported CSV passes the injection guard (test with malicious task titles)
- **Public surface immutability:** all admin routes return 404 or 405 on the public app

### Fixtures

Golden CSVs in `tests/fixtures/data/` covering: empty state, single task, sub-project hierarchy (3 levels), dependency chain (5 nodes), cycle attempt, malicious titles.

### Real-deploy smoke

`deploy/smoke.ps1` runs the §12 step-11 curl commands and asserts exit codes.

## §14 v1.1 deferred items

- Time tracking (clock-in/out and "actual time spent" field on tasks)
- Recurring tasks (template + scheduler)
- File attachments (local disk under `data/attachments/<task-id>/`, with size + MIME limits)
- Browser/push notifications (requires viewer pseudo-identity via cookie)
- Per-viewer personal saved filters (requires cookie-based pseudo-identity)
- APCu cache layer (only if read latency degrades)
- Bulk operations (multi-select edit/assign)
- Slack/Teams webhook integration
- Audit log UI on public surface (currently NDJSON only via filesystem access)

## §15 Open questions

None requiring resolution before implementation planning. Possible adjustments at plan time:

- **Markdown renderer choice** for task body: spec says `league/commonmark`. Reasonable alternatives are `erusev/parsedown` (smaller, less spec-compliant) or rendering on the client via a CDN library (avoided here for no-build-step consistency).
- **Cytoscape vs alternative graph libs**: vis-network is comparable in feature set with a simpler API but heavier bundle. Cytoscape wins on layout-extension ecosystem.

## §16 Acceptance criteria

v1.0 is shippable when:

1. All §13 unit and integration tests pass
2. All §13 invariant tests pass
3. `deploy/smoke.ps1` passes on a fresh Win Server 2016 + Apache 2.4 + PHP 8.3 host
4. A 24-hour soak with synthetic traffic (1 write/min, 10 reads/min) produces no orphan tmp files, no NDJSON drift, no Apache errors
5. CSV exports open cleanly in Excel 2016 without macro warnings (verifies injection guard works in practice)
6. Mobile-responsive read view passes manual check on a phone-width viewport
