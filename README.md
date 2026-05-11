# task-tracker

Flat-file team task tracker for Windows Server 2016 + Apache 2.4 + PHP 8.3 NTS + Slim 4.
Single shared backlog with sub-projects, dependencies, time-series executive summary, and
NDJSON audit trail. No database engine, no auth system — security is enforced by Apache
bind-level separation.

| Field | Value |
|---|---|
| Version | v1.0 |
| Spec | [`docs/superpowers/specs/2026-05-10-task-tracker-design.md`](docs/superpowers/specs/2026-05-10-task-tracker-design.md) |
| Stack | PHP 8.3 NTS, Slim 4, Twig, Apache 2.4 `mod_php`, flat CSV + NDJSON |
| Storage | RFC 4180 CSV (CRLF, all-quoted, UTF-8 no BOM) + daily-rotated NDJSON change log |
| License | MIT (workspace) / per-component see [`docs/license-policy.md`](docs/license-policy.md) |

## Architecture

Two physically separate Slim apps share `data/` and `logs/` through a common `lib/` package:

```
task-tracker/
├── lib/                  task-tracker/core — storage, models, repositories, aggregations
├── apps/
│   ├── admin/            Slim app, full CRUD, bind: 127.0.0.1:8080 (loopback only)
│   └── public/           Slim app, read-only, bind: *:80 (corporate LAN)
├── data/                 CSV files (tasks, roster, teams, dependencies, tags, saved_views)
├── logs/                 NDJSON event log + Apache access/error logs
├── tools/                seed.php, migrate.php, reconcile.php, rotate-logs.php
├── tests/integration/    cross-app integration suite (admin write → public read)
├── deploy/               Apache vhost + Windows runbook + smoke.ps1
└── docs/                 spec, license policy, design notes
```

### Three hard invariants

1. **Bind-level separation.** Admin is reachable only on `127.0.0.1:8080`; public on `*:80`.
   Apache's `Listen` + `Require local` is the entire access-control mechanism — there are
   no user accounts, sessions, or CSRF tokens.
2. **Code-presence separation.** `apps/public/src/Controllers/` contains zero write
   controllers. PUB-09 (`tests/Invariants/ReadOnlyTest.php`) asserts every non-GET method
   on every public route returns 405.
3. **Two-write audit.** Every state-changing admin operation writes CSV first
   (authoritative) and NDJSON second (corroborative). NDJSON failure logs an alarm; the
   CSV write is never rolled back.

## Quickstart (developer host)

PHP 8.3 NTS and Composer must be on `PATH`. On the current Windows dev host, PHP lives at
`/c/php/php.exe` and Composer at `/c/php/composer` — prepend `PATH="/c/php:$PATH"` if your
shell does not already resolve them.

```bash
# 1. Install per-component dependencies
(cd lib          && composer install --no-interaction)
(cd apps/admin   && composer install --no-interaction)
(cd apps/public  && composer install --no-interaction)
composer install --no-interaction   # root, for cross-suite phpunit

# 2. Seed empty CSVs + schema version marker
php tools/seed.php
php tools/migrate.php

# 3. Run the full suite (unit + integration)
./vendor/bin/phpunit
```

Local web servers (PHP built-in):

```bash
# Admin (loopback only — mirrors production bind)
php -S 127.0.0.1:8080 -t apps/admin/public

# Public (read-only)
php -S 127.0.0.1:8081 -t apps/public/public
```

## Deploy

Production deployment to Windows Server 2016 + Apache 2.4 is documented in
[`deploy/README.md`](deploy/README.md). Includes NTFS permissions, vhost wiring, Task
Scheduler entries for `rotate-logs.php` and `reconcile.php`, and the `deploy/smoke.ps1`
end-to-end validation script.

Apache vhost: [`deploy/httpd-task-tracker.conf`](deploy/httpd-task-tracker.conf).

## Working in this repo

- `TASK.md` — the build task list (Ralph-driven, one task per iteration).
- `AGENTS.md` — patterns future iterations must know (PHP path, gitignore scoping,
  Windows `rename()` semantics, phpdotenv adapter quirk, etc.). Read before touching code.
- `CLAUDE-CONTEXT.md` — invariants, conventions, and the "don't" list, auto-injected into
  every Ralph iteration.
- Conventional commits: `feat(scope):`, `fix(scope):`, `test(scope):`, `chore(scope):`,
  `docs(scope):`, `deploy:`.
- License policy: MIT / Apache-2.0 / BSD-2 / BSD-3 / ISC / 0BSD only. No GPL family, no SSPL.
