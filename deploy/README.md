# Deploy — task-tracker v1.0 on Windows Server 2016 + Apache 2.4

Operator runbook. Mirrors spec §12. Assumes a clean Windows Server 2016 host
with Administrator access and corporate-LAN connectivity.

## 0. Prerequisites

| Component | Version | Install location |
|-----------|---------|------------------|
| Apache HTTP Server (Windows binary) | 2.4.x | `C:\Apache24\` |
| PHP NTS for Windows | 8.3.x | `C:\php\` |
| Composer | 2.x | `C:\php\composer` (phar) |

PHP extensions required (uncomment in `C:\php\php.ini`):
`fileinfo`, `mbstring`, `openssl`, `intl`, `curl`, `gd` (optional, for future
avatar rendering — not used in v1.0).

## 1. Layout

```
C:\inetpub\task-tracker\         <- copy the entire repo here
├── apps\admin\public\           <- DocumentRoot for 127.0.0.1:8080
├── apps\public\public\          <- DocumentRoot for *:80
├── data\                        <- CSV files (shared, R+W for Apache)
├── logs\                        <- NDJSON event log + Apache access/error logs
├── tools\                       <- seed.php, migrate.php, rotate-logs.php, reconcile.php
└── deploy\
    ├── httpd-task-tracker.conf  <- this directory's vhost config
    ├── README.md                <- this file
    └── smoke.ps1                <- end-to-end curl validation (INT-05)
```

## 2. NTFS permissions

The Apache service account (default `LocalSystem` for the Windows MSI, or the
service user if you've reconfigured `Apache2.4` to run as a domain account):

| Path | Permission |
|------|------------|
| `apps\`, `lib\`, `tools\` | Read & Execute |
| `data\`, `logs\` | Read & Write |
| Everywhere else | Read |

Administrators retain Full Control everywhere.

```powershell
# From an elevated PowerShell prompt at C:\inetpub\task-tracker
$svc = "NT AUTHORITY\SYSTEM"   # or your service account
icacls .              /grant "$($svc):(OI)(CI)(RX)" /T
icacls .\data         /grant "$($svc):(OI)(CI)(M)"  /T
icacls .\logs         /grant "$($svc):(OI)(CI)(M)"  /T
```

## 3. Apache configuration

1. Copy `deploy\httpd-task-tracker.conf` to `C:\Apache24\conf\extra\httpd-task-tracker.conf`.
2. Append to `C:\Apache24\conf\httpd.conf`:
   ```
   Include conf/extra/httpd-task-tracker.conf
   ```
3. Update paths inside the vhost file if you deployed outside `C:\inetpub\task-tracker\`.
4. Sanity check:
   ```powershell
   & "C:\Apache24\bin\httpd.exe" -t
   ```
   Must print `Syntax OK`.
5. Restart:
   ```powershell
   Restart-Service Apache2.4
   ```

The two `Listen` directives MUST be on different binds — `127.0.0.1:8080` for
admin (loopback-only; nothing on the LAN can reach this) and `*:80` for
public. This is the entire access-control mechanism for v1.0.

## 4. Composer + first-run

```powershell
cd C:\inetpub\task-tracker\lib;         & C:\php\php.exe C:\php\composer install --no-interaction --no-dev
cd C:\inetpub\task-tracker\apps\admin;  & C:\php\php.exe C:\php\composer install --no-interaction --no-dev
cd C:\inetpub\task-tracker\apps\public; & C:\php\php.exe C:\php\composer install --no-interaction --no-dev
```

Seed empty CSVs and write the schema version marker:

```powershell
cd C:\inetpub\task-tracker
& C:\php\php.exe tools\seed.php
& C:\php\php.exe tools\migrate.php
```

## 5. Scheduled maintenance (Windows Task Scheduler)

Two recurring tasks. Both run as the Apache service account (or any account
with R+W on `data\` and `logs\`).

| Task | Trigger | Action |
|------|---------|--------|
| `task-tracker rotate-logs` | Daily 02:00 | `C:\php\php.exe C:\inetpub\task-tracker\tools\rotate-logs.php` |
| `task-tracker reconcile` | Hourly | `C:\php\php.exe C:\inetpub\task-tracker\tools\reconcile.php --quiet` |

Create via PowerShell:

```powershell
$action  = New-ScheduledTaskAction -Execute "C:\php\php.exe" `
           -Argument "C:\inetpub\task-tracker\tools\rotate-logs.php"
$trigger = New-ScheduledTaskTrigger -Daily -At 2am
Register-ScheduledTask -TaskName "task-tracker rotate-logs" `
  -Action $action -Trigger $trigger -RunLevel Highest

$action  = New-ScheduledTaskAction -Execute "C:\php\php.exe" `
           -Argument "C:\inetpub\task-tracker\tools\reconcile.php --quiet"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
           -RepetitionInterval (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName "task-tracker reconcile" `
  -Action $action -Trigger $trigger -RunLevel Highest
```

## 6. Smoke test

After restart, from the host itself:

```powershell
& .\deploy\smoke.ps1
```

`smoke.ps1` (delivered by INT-05) curls each surface and asserts the
spec §12 step-11 expected exit codes:

| Request | Expected |
|---------|----------|
| `GET http://127.0.0.1:8080/health` | `200 OK`, body `OK` |
| `GET http://127.0.0.1:8080/` | `200 OK`, backlog HTML |
| `GET http://localhost/health` | `200 OK`, body `OK` |
| `GET http://localhost/` | `200 OK`, public backlog HTML |
| `POST http://localhost/tasks` | `405 Method Not Allowed` |
| `GET http://<host>:8080/` from another LAN host | connection refused or 403 |

## 7. Operational notes

- **Storage is shared, code is split.** Admin writes go to `data\*.csv` first
  (authoritative) and `logs\changes-YYYY-MM-DD.ndjson` second (corroborative).
  Public reads both. Never write from the public app — its source tree
  contains zero write controllers, and the PUB-09 invariant test enforces 405
  on every non-GET method.
- **CSV format:** RFC 4180 with CRLF, UTF-8 no BOM, all fields quoted. CSV
  injection guard prefixes any cell starting with `=`, `+`, `-`, `@`, tab, or
  CR with an apostrophe on export.
- **NDJSON rotation.** `rotate-logs.php` gzips files older than 30 days.
  Hourly `reconcile.php` cleans up orphan `.tmp.*` files from interrupted
  atomic writes and reports CSV/NDJSON drift.
- **Log locations.** Apache writes `admin-error.log`, `admin-access.log`,
  `public-error.log`, `public-access.log` into `logs\`. Application events go
  to `logs\changes-YYYY-MM-DD.ndjson`. Tail these for incident triage.
- **Upgrades.** No DB migrations exist. `tools\migrate.php` writes
  `data\_schema_version.txt` so future versions can detect format changes;
  for v1.0 it always writes `1`.

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `500` on every page | `mod_php` not loaded or `php8apache2_4.dll` path wrong | check Apache `error.log`, fix `LoadModule` line |
| `403 Forbidden` on `127.0.0.1:8080` | `Require local` rejected the client | verify request actually comes from loopback; check `RemoteIPHeader` if behind a reverse proxy |
| Empty backlog despite seeded data | `DATA_DIR` not propagating to `mod_php` | confirm the vhost's `SetEnv DATA_DIR ...` line and that `php.ini` `variables_order` includes `E` |
| `.tmp.*` files piling up in `data\` | atomic-write interrupted by crash/restart | hourly `reconcile.php` clears them; run manually if urgent |
| Public app returns `200` on `POST /tasks` | invariant breach — investigate immediately | run `cd apps\public && .\vendor\bin\phpunit tests\Invariants\ReadOnlyTest.php` |
