# CLAUDE-CONTEXT.md — Task Tracker v1.0

This file is auto-injected into every Ralph iteration. Read carefully before touching code.

## Spec & invariants

**Spec is authoritative:** `docs/superpowers/specs/2026-05-10-task-tracker-design.md`

**Three hard invariants — do not weaken:**

1. **Bind-level separation.** Admin app on `127.0.0.1:8080`; public app on `*:80`. Two physically separate Slim apps under `apps/admin/` and `apps/public/`. They share the `data/` and `logs/` directories and the `lib/` library. Never import an admin controller into the public app.
2. **Code-presence separation.** `apps/public/src/Controllers/` contains zero write controllers. No `POST` / `PUT` / `PATCH` / `DELETE` routes registered on the public Slim app. The 405 invariant test (PUB-09) enforces this.
3. **Two-write audit.** Every state-changing admin operation writes to (a) the appropriate CSV file via `CsvStore::txn()` AND (b) an NDJSON event line via `EventLog::append()`. Order: CSV first (authoritative), NDJSON second (corroborative). If NDJSON write fails, log an alarm — do NOT roll back the CSV write.

## Stack

- PHP 8.3 NTS, Slim 4, Twig, Apache 2.4 `mod_php` on Windows Server 2016
- Storage: flat CSV (RFC 4180, CRLF, all fields quoted) + daily-rotated NDJSON change log
- No database engine, no auth system, no sessions, no CSRF tokens
- UUID v7 for all primary keys (use `ramsey/uuid`)
- ISO 8601 UTC for all timestamps

## Shell + tooling

- Bash via Git Bash on Windows. Use `/` paths in shell; `\\` is fine inside PHP strings.
- PHP must be in PATH (`php --version` works).
- Composer must be in PATH (`composer --version` works). If it isn't, install via `composer-setup.php`.
- Test runner: `./vendor/bin/phpunit` (composer binstubs work on Windows without `.bat`).
- Each app and lib has its own `composer.json` and `vendor/`. The `lib/` directory is referenced as a Composer path repository named `task-tracker/core` by the apps.

## CSV format rules

- Header row required, column names UPPER_CASE
- CRLF line endings
- ALL fields quoted (even when not required) — simplifies parsing
- UTF-8, no BOM
- Cells starting with `=`, `+`, `-`, `@`, tab, or CR get prefixed with `'` on export (CSV injection guard)

## NDJSON format rules

- One JSON object per line, LF terminator
- File path: `logs/changes-YYYY-MM-DD.ndjson` (UTC date)
- Append-only at runtime. Append via `file_put_contents(..., FILE_APPEND | LOCK_EX)`
- Action enum is closed (see spec §8). Adding new actions bumps `_schema_version.txt`

## Atomic write pattern

```php
$fh = fopen($csv, 'c+');
flock($fh, LOCK_EX);
try {
    $rows = readCsvRows($fh);
    $rows = $mutator($rows);
    $tmp = $csv . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, formatCsv($rows));
    rename($tmp, $csv);          // atomic on NTFS same-volume
    foreach ($events as $e) {
        $eventLog->append($e);
    }
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
```

## Conventions

- TypeScript-style strictness in PHP: declare `strict_types=1` at the top of every PHP file.
- One class per file. PSR-4 autoload.
- Constructor injection for dependencies. No service locators.
- Comments only when WHY is non-obvious. Don't narrate WHAT.
- No backward-compat shims — this is v1.0, no prior version exists.
- Conventional commits: `feat(scope):`, `fix(scope):`, `test(scope):`, `chore(scope):`, `docs(scope):`, `deploy:`.

## Don't

- Don't add authentication/session/CSRF code. The bind is the access control.
- Don't add an ORM. CSV reads/writes go through `CsvStore`.
- Don't add a query builder or DSL. PHP array operations are sufficient at this scale.
- Don't add caching except where the spec explicitly says so (§9 mentions APCu as v1.1).
- Don't gold-plate UI. Bootstrap 5 + Twig + plain `<form>` POSTs. No client-side framework.
- Don't import vendor libraries from a CDN. Copy them into `apps/{admin,public}/public/vendor/` so the deployment is self-contained.
- Don't introduce features not in the spec. The spec's §14 explicitly defers items to v1.1.

## License policy

MIT / Apache-2.0 / BSD-2 / BSD-3 / ISC / 0BSD only. Reject GPL / LGPL / AGPL / SSPL.

## Verify-before-completion

Before marking any task `[x]`:

1. Run the task's `!v` command — it MUST exit 0
2. Confirm `git diff --cached` shows only the intended files
3. Use the `!c` commit message verbatim (or close paraphrase if scope shifted)

If `!v` fails, do NOT mark `[x]`, do NOT commit, do append a failure note to the progress file with the exact error message and a hypothesis.
