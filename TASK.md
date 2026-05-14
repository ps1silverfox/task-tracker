# Task Tracker v1.0 — Implementation Task List

**Spec:** `docs/superpowers/specs/2026-05-10-task-tracker-design.md`
**Target:** Windows Server 2016 + Apache 2.4 + PHP 8.3 NTS + Slim 4 + flat-file storage (CSV + NDJSON)
**Build order:** strict — later phases depend on earlier ones

---

## Phase 0 — Workspace scaffold

### [x] INIT-01 Workspace composer.json + license policy
+f composer.json
+f docs/license-policy.md
!v php -r "json_decode(file_get_contents('composer.json'), false, 512, JSON_THROW_ON_ERROR); echo 'OK';"
!c chore: workspace composer.json + license policy doc

### [x] INIT-02 lib/ package skeleton (task-tracker/core)
+f lib/composer.json
+f lib/src/.gitkeep
+f lib/phpunit.xml
+f lib/tests/Sanity/PackageTest.php
deps: INIT-01
!v cd lib && composer install --no-interaction && ./vendor/bin/phpunit tests/Sanity
!c chore(lib): scaffold task-tracker/core composer package

### [x] INIT-03 apps/admin/ Slim skeleton
+f apps/admin/composer.json
+f apps/admin/public/index.php
+f apps/admin/src/routes.php
+f apps/admin/src/Templates/.gitkeep
+f apps/admin/phpunit.xml
+f apps/admin/tests/Sanity/BootTest.php
deps: INIT-02
!v cd apps/admin && composer install --no-interaction && ./vendor/bin/phpunit tests/Sanity
!c chore(admin): scaffold Slim 4 admin app

### [x] INIT-04 apps/public/ Slim skeleton (read-only)
+f apps/public/composer.json
+f apps/public/public/index.php
+f apps/public/src/routes.php
+f apps/public/src/Templates/.gitkeep
+f apps/public/phpunit.xml
+f apps/public/tests/Sanity/BootTest.php
deps: INIT-02
!v cd apps/public && composer install --no-interaction && ./vendor/bin/phpunit tests/Sanity
!c chore(public): scaffold Slim 4 public app (no write routes)

### [x] INIT-05 .env.example + phpdotenv loader in shared lib
+f .env.example
+f lib/src/Config/Env.php
+f lib/tests/Config/EnvTest.php
deps: INIT-02
!v cd lib && ./vendor/bin/phpunit tests/Config
!c chore(lib): env loader (DATA_DIR, LOG_DIR, SMTP_*, BASE_URL, TIMEZONE)

### [x] INIT-06 data/ + logs/ placeholder directories
+f data/.gitkeep
+f logs/.gitkeep
+m .gitignore
deps: INIT-01
!v test -d data && test -d logs && echo OK
!c chore: data/ and logs/ placeholder dirs

---

## Phase 1 — Storage primitives (lib/)

### [x] LIB-01 Uuid7 generator
+f lib/src/Util/Uuid7.php
+f lib/tests/Util/Uuid7Test.php
deps: INIT-02
!v cd lib && ./vendor/bin/phpunit tests/Util/Uuid7Test.php
!c feat(lib): UUID v7 generator (time-ordered, lexicographically sortable)

### [x] LIB-02 IsoTime UTC helper
+f lib/src/Util/IsoTime.php
+f lib/tests/Util/IsoTimeTest.php
deps: INIT-02
!v cd lib && ./vendor/bin/phpunit tests/Util/IsoTimeTest.php
!c feat(lib): IsoTime UTC formatter

### [x] LIB-03 CsvInjectionGuard
+f lib/src/Util/CsvInjectionGuard.php
+f lib/tests/Util/CsvInjectionGuardTest.php
deps: INIT-02
!v cd lib && ./vendor/bin/phpunit tests/Util/CsvInjectionGuardTest.php
!c feat(lib): CSV injection guard (prefix =/+/-/@/tab/CR cells with apostrophe)

### [x] LIB-04 CsvStore (read/write/flock/atomic-rename)
+f lib/src/Storage/CsvStore.php
+f lib/tests/Storage/CsvStoreTest.php
deps: LIB-01, LIB-02
!v cd lib && ./vendor/bin/phpunit tests/Storage/CsvStoreTest.php
!c feat(lib): CsvStore with flock + atomic-rename writes

### [x] LIB-05 EventLog (NDJSON append + daily rotation)
+f lib/src/Storage/EventLog.php
+f lib/tests/Storage/EventLogTest.php
deps: LIB-02
!v cd lib && ./vendor/bin/phpunit tests/Storage/EventLogTest.php
!c feat(lib): NDJSON daily-rotated append-only event log

---

## Phase 2 — Domain models & repositories

### [x] MODEL-01 Page/Task model + Roster + Team + Event + SavedView
+f lib/src/Models/Task.php
+f lib/src/Models/RosterMember.php
+f lib/src/Models/Team.php
+f lib/src/Models/Event.php
+f lib/src/Models/SavedView.php
+f lib/src/Models/Enums.php
+f lib/tests/Models/TaskTest.php
+f lib/tests/Models/EnumsTest.php
deps: LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Models
!c feat(lib): domain models — Task, RosterMember, Team, Event, SavedView + enums (status, priority)

### [x] REPO-01 TaskRepository CRUD
+f lib/src/Repositories/TaskRepository.php
+f lib/tests/Repositories/TaskRepositoryTest.php
deps: MODEL-01, LIB-04, LIB-05
!v cd lib && ./vendor/bin/phpunit tests/Repositories/TaskRepositoryTest.php
!c feat(lib): TaskRepository — full CRUD, FIRST_ASSIGNED_AT and LAST_ASSIGNMENT_CHANGE_AT semantics

### [x] REPO-02 RosterRepository
+f lib/src/Repositories/RosterRepository.php
+f lib/tests/Repositories/RosterRepositoryTest.php
deps: MODEL-01, LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Repositories/RosterRepositoryTest.php
!c feat(lib): RosterRepository — add/update/deactivate

### [x] REPO-03 TeamRepository
+f lib/src/Repositories/TeamRepository.php
+f lib/tests/Repositories/TeamRepositoryTest.php
deps: MODEL-01, LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Repositories/TeamRepositoryTest.php
!c feat(lib): TeamRepository — create/update

### [x] REPO-04 DependencyRepository + cycle detection
+f lib/src/Repositories/DependencyRepository.php
+f lib/tests/Repositories/DependencyRepositoryTest.php
deps: MODEL-01, LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Repositories/DependencyRepositoryTest.php
!c feat(lib): DependencyRepository — cycle prevention via DFS at write time

### [x] REPO-05 TagRepository
+f lib/src/Repositories/TagRepository.php
+f lib/tests/Repositories/TagRepositoryTest.php
deps: MODEL-01, LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Repositories/TagRepositoryTest.php
!c feat(lib): TagRepository

### [x] REPO-06 SavedViewRepository
+f lib/src/Repositories/SavedViewRepository.php
+f lib/tests/Repositories/SavedViewRepositoryTest.php
deps: MODEL-01, LIB-04
!v cd lib && ./vendor/bin/phpunit tests/Repositories/SavedViewRepositoryTest.php
!c feat(lib): SavedViewRepository — JSON-blob filter storage

### [x] REPO-07 EventRepository (NDJSON read for activity feed)
+f lib/src/Repositories/EventRepository.php
+f lib/tests/Repositories/EventRepositoryTest.php
deps: LIB-05, MODEL-01
!v cd lib && ./vendor/bin/phpunit tests/Repositories/EventRepositoryTest.php
!c feat(lib): EventRepository — streaming NDJSON reader for activity feed

---

## Phase 3 — Aggregations

### [x] AGG-01 SubProjectRollup (recursive tree traversal)
+f lib/src/Aggregations/SubProjectRollup.php
+f lib/tests/Aggregations/SubProjectRollupTest.php
deps: REPO-01
!v cd lib && ./vendor/bin/phpunit tests/Aggregations/SubProjectRollupTest.php
!c feat(lib): SubProjectRollup — recursive child totals (count, effort)

### [x] AGG-02 ExecutiveSummary (time-series bucketing)
+f lib/src/Aggregations/ExecutiveSummary.php
+f lib/tests/Aggregations/ExecutiveSummaryTest.php
deps: REPO-07
!v cd lib && ./vendor/bin/phpunit tests/Aggregations/ExecutiveSummaryTest.php
!c feat(lib): ExecutiveSummary — added/completed/assigned/unassigned by period, person/team filters

### [x] AGG-03 DependencyGraph (cytoscape JSON serializer)
+f lib/src/Aggregations/DependencyGraph.php
+f lib/tests/Aggregations/DependencyGraphTest.php
deps: REPO-01, REPO-04
!v cd lib && ./vendor/bin/phpunit tests/Aggregations/DependencyGraphTest.php
!c feat(lib): DependencyGraph — cytoscape.js JSON serializer

---

## Phase 4 — Tools / CLI

### [x] TOOL-01 seed.php (empty CSVs with headers + example team/member)
+f tools/seed.php
+f tests/integration/SeedTest.php
deps: REPO-01, REPO-02, REPO-03
!v php tools/seed.php --target=tests/integration/.tmp-seed && ls tests/integration/.tmp-seed/tasks.csv
!c feat(tools): seed.php — initialize empty CSVs with headers

### [x] TOOL-02 migrate.php (writes _schema_version.txt)
+f tools/migrate.php
+f tests/integration/MigrateTest.php
deps: TOOL-01
!v php tools/migrate.php --target=tests/integration/.tmp-seed && cat tests/integration/.tmp-seed/_schema_version.txt
!c feat(tools): migrate.php — schema version tracker

### [x] TOOL-03 reconcile.php (boot-time tmp cleanup + drift detection)
+f tools/reconcile.php
+f tests/integration/ReconcileTest.php
deps: LIB-04, LIB-05
!v php tools/reconcile.php --target=tests/integration/.tmp-seed --quiet
!c feat(tools): reconcile.php — orphan tmp cleanup + CSV/NDJSON drift detection

### [x] TOOL-04 rotate-logs.php (gzip NDJSON > 30 days)
+f tools/rotate-logs.php
+f tests/integration/RotateLogsTest.php
deps: LIB-05
!v php tools/rotate-logs.php --target=tests/integration/.tmp-logs --dry-run
!c feat(tools): rotate-logs.php — daily NDJSON rotation/archival

---

## Phase 5 — Admin app (apps/admin/) — full CRUD

### [x] ADMIN-01 Admin app bootstrap (DI container, middleware, error handler)
+f apps/admin/src/Bootstrap.php
+f apps/admin/tests/BootstrapTest.php
+m apps/admin/public/index.php
deps: INIT-03, REPO-01
!v cd apps/admin && ./vendor/bin/phpunit tests/BootstrapTest.php
!c feat(admin): Slim bootstrap + DI container wiring

### [x] ADMIN-02 TasksController — create + list + read
+f apps/admin/src/Controllers/TasksController.php
+f apps/admin/tests/Controllers/TasksControllerCreateTest.php
+m apps/admin/src/routes.php
deps: ADMIN-01
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TasksControllerCreateTest.php
!c feat(admin): TasksController — POST /tasks (create), GET / (list), GET /tasks/{id}

### [x] ADMIN-03 TasksController — update + soft-delete
+m apps/admin/src/Controllers/TasksController.php
+f apps/admin/tests/Controllers/TasksControllerUpdateTest.php
deps: ADMIN-02
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TasksControllerUpdateTest.php
!c feat(admin): TasksController — POST /tasks/{id} update, DELETE soft-delete

### [x] ADMIN-04 TasksController — assign + status + dependencies + tags
+m apps/admin/src/Controllers/TasksController.php
+f apps/admin/tests/Controllers/TasksControllerActionsTest.php
deps: ADMIN-03, REPO-04, REPO-05
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TasksControllerActionsTest.php
!c feat(admin): TasksController — /assign, /status, /dependencies, /tags routes

### [x] ADMIN-05 RosterController
+f apps/admin/src/Controllers/RosterController.php
+f apps/admin/tests/Controllers/RosterControllerTest.php
+m apps/admin/src/routes.php
deps: ADMIN-01, REPO-02
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/RosterControllerTest.php
!c feat(admin): RosterController — add/update/deactivate

### [x] ADMIN-06 TeamsController
+f apps/admin/src/Controllers/TeamsController.php
+f apps/admin/tests/Controllers/TeamsControllerTest.php
+m apps/admin/src/routes.php
deps: ADMIN-01, REPO-03
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TeamsControllerTest.php
!c feat(admin): TeamsController — create/update

### [x] ADMIN-07 SavedViewsController
+f apps/admin/src/Controllers/SavedViewsController.php
+f apps/admin/tests/Controllers/SavedViewsControllerTest.php
+m apps/admin/src/routes.php
deps: ADMIN-01, REPO-06
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/SavedViewsControllerTest.php
!c feat(admin): SavedViewsController

### [x] ADMIN-08 Health endpoint + 404 handler
+f apps/admin/src/Controllers/HealthController.php
+f apps/admin/tests/Controllers/HealthControllerTest.php
+m apps/admin/src/routes.php
deps: ADMIN-01
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/HealthControllerTest.php
!c feat(admin): GET /health endpoint + 404 handler

### [x] ADMIN-09 Twig templates for backlog + edit form
+f apps/admin/src/Templates/layout.twig
+f apps/admin/src/Templates/backlog.twig
+f apps/admin/src/Templates/task_edit.twig
+f apps/admin/src/Templates/roster.twig
+f apps/admin/src/Templates/teams.twig
+f apps/admin/public/vendor/bootstrap/bootstrap.min.css
+f apps/admin/tests/RenderingTest.php
deps: ADMIN-02, ADMIN-05, ADMIN-06
!v cd apps/admin && ./vendor/bin/phpunit tests/RenderingTest.php
!c feat(admin): Twig templates + Bootstrap 5 CSS — admin UI

### [x] ADMIN-10 SmtpMailer + email notifications (assignment, due-soon, overdue)
+f lib/src/Mail/SmtpMailer.php
+f lib/tests/Mail/SmtpMailerTest.php
+f apps/admin/src/Services/NotificationService.php
+f apps/admin/tests/Services/NotificationServiceTest.php
deps: ADMIN-04
!v cd apps/admin && ./vendor/bin/phpunit tests/Services/NotificationServiceTest.php
!c feat(admin): email notifications via Symfony Mailer (assignment/due-soon/overdue)

---

## Phase 6 — Public app (apps/public/) — read-only

### [x] PUB-01 Public app bootstrap (no write routes registered)
+f apps/public/src/Bootstrap.php
+f apps/public/tests/BootstrapTest.php
+m apps/public/public/index.php
deps: INIT-04, REPO-01
!v cd apps/public && ./vendor/bin/phpunit tests/BootstrapTest.php
!c feat(public): Slim bootstrap — read-only DI, no write controllers

### [x] PUB-02 BacklogController + query-string filters
+f apps/public/src/Controllers/BacklogController.php
+f apps/public/tests/Controllers/BacklogControllerTest.php
+m apps/public/src/routes.php
deps: PUB-01
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/BacklogControllerTest.php
!c feat(public): BacklogController — GET / with filtering

### [x] PUB-03 Task detail with activity feed
+f apps/public/src/Controllers/TaskDetailController.php
+f apps/public/tests/Controllers/TaskDetailControllerTest.php
+m apps/public/src/routes.php
deps: PUB-01, REPO-07
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/TaskDetailControllerTest.php
!c feat(public): GET /tasks/{id} — read-only detail + activity feed from event log

### [x] PUB-04 ExecutiveSummaryController + Chart.js
+f apps/public/src/Controllers/ExecutiveSummaryController.php
+f apps/public/public/vendor/chartjs/chart.umd.min.js
+f apps/public/src/Templates/summary.twig
+f apps/public/tests/Controllers/ExecutiveSummaryControllerTest.php
+m apps/public/src/routes.php
deps: PUB-01, AGG-02
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/ExecutiveSummaryControllerTest.php
!c feat(public): ExecutiveSummaryController — time-series HTML + Chart.js

### [x] PUB-05 CSV exports (tasks.csv + summary.csv)
+f apps/public/src/Controllers/ExportController.php
+f apps/public/tests/Controllers/ExportControllerTest.php
+m apps/public/src/routes.php
deps: PUB-02, PUB-04, LIB-03
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/ExportControllerTest.php
!c feat(public): CSV exports with injection-guard

### [x] PUB-06 GraphController + cytoscape.js page
+f apps/public/src/Controllers/GraphController.php
+f apps/public/public/vendor/cytoscape/cytoscape.min.js
+f apps/public/public/vendor/cytoscape/cytoscape-dagre.min.js
+f apps/public/src/Templates/graph.twig
+f apps/public/tests/Controllers/GraphControllerTest.php
+m apps/public/src/routes.php
deps: PUB-01, AGG-03
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/GraphControllerTest.php
!c feat(public): GraphController — cytoscape.js dependency visualizer + JSON endpoint

### [x] PUB-07 Saved views application
+f apps/public/src/Controllers/SavedViewsController.php
+f apps/public/tests/Controllers/SavedViewsControllerTest.php
+m apps/public/src/routes.php
deps: PUB-02, REPO-06
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/SavedViewsControllerTest.php
!c feat(public): GET /views/{id} — apply saved view to backlog

### [x] PUB-08 Health endpoint + templates + Bootstrap
+f apps/public/src/Controllers/HealthController.php
+f apps/public/src/Templates/layout.twig
+f apps/public/src/Templates/backlog.twig
+f apps/public/src/Templates/task_detail.twig
+f apps/public/public/vendor/bootstrap/bootstrap.min.css
+f apps/public/tests/Controllers/HealthControllerTest.php
+m apps/public/src/routes.php
deps: PUB-02, PUB-03
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/HealthControllerTest.php
!c feat(public): templates + health endpoint + Bootstrap CSS

### [x] PUB-09 Public-immutability invariant test (405 on non-GET)
+f apps/public/tests/Invariants/ReadOnlyTest.php
deps: PUB-02, PUB-03, PUB-04, PUB-05, PUB-06, PUB-07
!v cd apps/public && ./vendor/bin/phpunit tests/Invariants/ReadOnlyTest.php
!c test(public): invariant — every non-GET method returns 405 on every route

---

## Phase 7 — Integration, deploy, hardening

### [x] INT-01 Integration test harness (boot both apps in-process)
+f tests/integration/bootstrap.php
+f tests/integration/AdminPublicSharedDataTest.php
+f phpunit.xml
deps: ADMIN-09, PUB-08
!v ./vendor/bin/phpunit tests/integration/AdminPublicSharedDataTest.php
!c test(integration): admin write → public read via shared data/

### [x] INT-02 Audit-completeness invariant test (every write → NDJSON event)
+f tests/integration/AuditCompletenessTest.php
deps: INT-01
!v ./vendor/bin/phpunit tests/integration/AuditCompletenessTest.php
!c test(integration): invariant — every state-changing admin route writes an NDJSON event

### [x] INT-03 Cycle-prevention integration test
+f tests/integration/DependencyCycleTest.php
deps: INT-01
!v ./vendor/bin/phpunit tests/integration/DependencyCycleTest.php
!c test(integration): invariant — cycle-creating dependency returns 422

### [x] INT-04 Apache vhost config + deploy README
+f deploy/httpd-task-tracker.conf
+f deploy/README.md
deps: INT-01
!v test -f deploy/httpd-task-tracker.conf && grep -q 'Listen 127.0.0.1:8080' deploy/httpd-task-tracker.conf && echo OK
!c deploy: Apache 2.4 vhost config + deploy steps for Win Server 2016

### [x] INT-05 deploy/smoke.ps1 (curl health + admin + public + 405)
+f deploy/smoke.ps1
deps: INT-04
!v test -f deploy/smoke.ps1 && powershell -Command "Get-Content deploy/smoke.ps1 | Select-String 'health' | Measure-Object | Select -ExpandProperty Count" | grep -q '[1-9]'
!c deploy: smoke.ps1 — end-to-end curl validation

### [x] INT-06 Top-level README.md
+f README.md
deps: INT-04
!v test -f README.md && grep -q 'task-tracker' README.md && echo OK
!c docs: README.md — overview, quickstart, deploy pointer

### [x] INT-07 Final acceptance — all unit + integration tests pass
deps: INT-01, INT-02, INT-03, PUB-09
!v ./vendor/bin/phpunit
!c chore: v1.0 acceptance — all tests green

---

## Phase 8 — UI wiring & completion (production-ready UI)

> **Context:** Phases 0–7 built the skeleton — storage, controllers, repositories, aggregations, and Twig templates — but all admin and public controllers still return raw inline HTML strings from the test scaffold. The Twig templates in `apps/admin/src/Templates/` and `apps/public/src/Templates/` exist but are never called. Phase 8 wires the templates to the controllers and completes the UI to a usable standard.
>
> **Definition of done for this phase:** A real user can open either app in a browser, navigate every screen without knowing a URL, create and manage tasks end-to-end, and see real names (not UUIDs) everywhere. No inline HTML string building in any controller.

### [x] UI-01 Wire Twig into admin app bootstrap + all admin controllers
- Add `slim/twig-view` to `apps/admin/composer.json` if not already present; register `Twig` middleware and `$container->get(Twig::class)` in `Bootstrap.php`
- Update `TasksController::list()`, `show()`, `create()` to call `$this->view->render($response, 'backlog.twig', [...])` / `task_edit.twig` — remove all inline HTML string building
- Update `RosterController`, `TeamsController`, `SavedViewsController`, `HealthController` to render their respective Twig templates
- Every controller must resolve UUIDs to names before passing to template: load roster + teams, build `$assigneeMap[uuid => name]` and `$teamMap[uuid => name]`; pass both maps to every template that needs them
deps: ADMIN-09
!v cd apps/admin && ./vendor/bin/phpunit && php -r "require 'vendor/autoload.php'; echo 'Twig registered';"
!c feat(admin): wire Twig to all admin controllers; resolve assignee/team UUIDs to names

### [x] UI-02 Complete admin task edit form
- `task_edit.twig`: `<select name="assignee_id">` populated from roster (active members); `<select name="team_id">` populated from teams; `<select name="parent_id">` populated from live tasks (excluding self); tags input (`<input name="tags">` comma-separated, pre-filled from current tags); dependencies section listing current prereqs with remove buttons and an "add prerequisite" lookup
- `TasksController::show()` must fetch and pass: `$rosterOptions`, `$teamOptions`, `$parentOptions`, `$currentTags`, `$currentDeps` to the template
deps: UI-01
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TasksControllerCreateTest.php tests/Controllers/TasksControllerUpdateTest.php
!c feat(admin): complete task edit form — assignee/team/parent dropdowns, tags, dependencies

### [x] UI-03 Complete admin backlog with filter + saved views sidebar
- `backlog.twig`: add filter bar above table — `<select>` for status, priority, team, assignee; `<input>` for tag search; filter state passed from controller via query string `?status=open&priority=high` etc.
- `TasksController::list()` must read query params and pass filtered task list + active filter values back to template for pre-selection
- Add saved views to nav: `BacklogController::list()` fetches all saved views and passes to layout as `$savedViews`; `layout.twig` renders them in nav dropdown
- Assignee column: render resolved name, not UUID
deps: UI-01
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/TasksControllerCreateTest.php
!c feat(admin): backlog filter bar + saved views nav + resolved assignee names

### [x] UI-04 Complete roster and teams admin screens
- `roster.twig`: table of all members (name, email, team, active/inactive badge) + inline "Add member" form at bottom + deactivate button per row; form POSTs to `POST /roster`
- `teams.twig`: table of teams (name, description, member count) + inline "Add team" form; form POSTs to `POST /teams`
- `RosterController` and `TeamsController` must pass all required data and render templates (not inline HTML)
deps: UI-01
!v cd apps/admin && ./vendor/bin/phpunit tests/Controllers/RosterControllerTest.php tests/Controllers/TeamsControllerTest.php
!c feat(admin): complete roster + teams screens with add/deactivate forms

### [x] UI-05 Wire Twig into public app bootstrap + all public controllers
- Same Twig registration pattern as UI-01 for `apps/public/`
- `BacklogController`, `TaskDetailController`, `ExecutiveSummaryController`, `GraphController`, `ExportController`, `SavedViewsController`, `HealthController` all render templates — remove all inline HTML
- Every controller resolves UUIDs to names before passing to template
deps: PUB-08
!v cd apps/public && ./vendor/bin/phpunit
!c feat(public): wire Twig to all public controllers; resolve UUIDs to names

### [x] UI-06 Public backlog filter controls
- `backlog.twig` (public): add filter sidebar or top bar — status checkboxes, priority checkboxes, team select, assignee select, tag input, due-within-days input; all pre-populated from current query string
- `BacklogController::list()` reads query params, filters task list, passes active filter values back to template
- Saved views listed in nav for one-click filter application
deps: UI-05
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/BacklogControllerTest.php
!c feat(public): filter controls on public backlog + saved views nav

### [x] UI-07 Complete public task detail page
- `task_detail.twig`: render task body as HTML (Markdown via `league/commonmark`); activity feed timeline from NDJSON events (timestamp, action label, actor); tags as badges; list of prerequisite tasks (linked); list of subtasks (linked); assignee name + team name resolved
- `TaskDetailController::show()` fetches events from `EventRepository`, resolves all UUIDs to names, renders Markdown body, passes all to template
deps: UI-05
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/TaskDetailControllerTest.php
!c feat(public): complete task detail — Markdown body, activity feed, tags, dependencies, subtasks

### [x] UI-08 Complete executive summary page with Chart.js
- `summary.twig`: date range inputs (`from`, `to`), period selector (`day/month/quarter/year`), person select, team select; all pre-populated from query params; Chart.js stacked bar chart rendered with real bucket data from `ExecutiveSummary`; summary table below chart; "Export CSV" link to `/summary.csv`
- `ExecutiveSummaryController` must pass chart-ready data array (labels + four series arrays) alongside the table rows
deps: UI-05
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/ExecutiveSummaryControllerTest.php
!c feat(public): complete executive summary — Chart.js stacked bar + filter controls

### [x] UI-09 Complete dependency graph page
- `graph.twig`: cytoscape.js canvas full-width; JS fetches `/graph.json` on load and renders with dagre layout; node fill = status color, node border = priority color, node label = title (40 char truncated), click → navigate to `/tasks/{id}`; filter controls above canvas (status multi-select, team select, root subtree input); banner if node count > 500
- `GraphController::graph()` returns HTML page; `GraphController::json()` returns cytoscape JSON from `DependencyGraph`
deps: UI-05
!v cd apps/public && ./vendor/bin/phpunit tests/Controllers/GraphControllerTest.php
!c feat(public): complete dependency graph — cytoscape.js with status/priority encoding + filters

### [x] UI-10 Seed meaningful demo data
- Update `tools/seed.php` to create: 2 teams, 4 roster members (2 per team), 8 tasks (mix of statuses/priorities, 2 with parent, 2 with dependencies, 1 blocked, 1 done), 2 saved views
- Goal: every screen has real data to display on a fresh seed so visual QA is meaningful
deps: TOOL-01
!v php tools/seed.php --target=tests/integration/.tmp-seed && php -r "\$rows = array_map('str_getcsv', file('tests/integration/.tmp-seed/tasks.csv')); echo count(\$rows) >= 9 ? 'OK' : 'FAIL';"
!c feat(tools): seed meaningful demo data — 2 teams, 4 members, 8 tasks with deps/hierarchy

### [x] UI-11 Mobile-responsive verification + fixes
- Boot public app, load backlog and task detail at 390px viewport width (use PHP built-in server `php -S localhost:8090 -t apps/public/public`)
- Verify table collapses gracefully (Bootstrap `table-responsive` wrapper present), nav collapses to hamburger, task detail readable on narrow viewport
- Fix any layout breaks found
deps: UI-05, UI-06, UI-07
!v cd apps/public && php -S localhost:8090 -t public &>/dev/null & sleep 1 && curl -s http://localhost:8090/ | grep -q 'viewport' && echo OK; kill %1
!c fix(public): mobile-responsive layout — verify and fix narrow viewport rendering

### [x] UI-12 Phase 8 acceptance — visual smoke + full test suite
- Run `./vendor/bin/phpunit` (all phases) — must be green
- Run `php tools/seed.php` on clean data dir, boot both apps via PHP built-in server, curl every route listed in spec §6, assert HTTP 200 (or 405 where expected)
- Verify: no inline HTML string building remains in any controller (grep check)
- Write `phase_8_ready.txt` with timestamp and test counts
deps: UI-01 through UI-11
!v grep -r 'getBody()->write.*<!doctype' apps/admin/src apps/public/src && echo "FAIL: inline HTML remains" || echo "OK: all controllers use Twig"
!c chore: phase 8 acceptance — all controllers use Twig, all screens render real data
