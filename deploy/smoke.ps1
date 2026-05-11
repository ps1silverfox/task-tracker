# task-tracker v1.0 — deployment smoke test (INT-05)
#
# Runs end-to-end curl-equivalent checks against the live Apache 2.4 instance
# after deploy/httpd-task-tracker.conf is loaded and the service is restarted.
# Asserts the contract in deploy/README.md §6 and spec §12 step-11.
#
# Invocation (from the deploy host, any shell):
#     powershell -ExecutionPolicy Bypass -File .\deploy\smoke.ps1
#
# Exit codes:
#     0  all checks passed
#     1  one or more checks failed (each failure printed with context)
#
# Compatible with stock Windows Server 2016 PowerShell 5.1 — no PS7/Core deps.
# `Invoke-WebRequest -UseBasicParsing` avoids the legacy IE rendering engine
# (absent on Server Core) so this works on headless installs.

[CmdletBinding()]
param(
    [string]$AdminBase  = 'http://127.0.0.1:8080',
    [string]$PublicBase = 'http://localhost'
)

$ErrorActionPreference = 'Stop'
$script:Failures = New-Object System.Collections.ArrayList

function Write-Step {
    param([string]$Name)
    Write-Host ("[ .. ] {0}" -f $Name) -ForegroundColor Cyan
}

function Write-Pass {
    param([string]$Name)
    Write-Host ("[ OK ] {0}" -f $Name) -ForegroundColor Green
}

function Write-Fail {
    param([string]$Name, [string]$Detail)
    Write-Host ("[FAIL] {0} -- {1}" -f $Name, $Detail) -ForegroundColor Red
    [void]$script:Failures.Add($Name)
}

function Assert-Get {
    param(
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] [string]$Url,
        [Parameter(Mandatory)] [int]$ExpectedStatus,
        [string]$BodyContains
    )
    Write-Step $Name
    try {
        $resp = Invoke-WebRequest -Uri $Url -Method GET -UseBasicParsing -TimeoutSec 10
    } catch {
        Write-Fail $Name ("GET {0} threw: {1}" -f $Url, $_.Exception.Message)
        return
    }
    if ($resp.StatusCode -ne $ExpectedStatus) {
        Write-Fail $Name ("expected HTTP {0}, got {1} for {2}" -f $ExpectedStatus, $resp.StatusCode, $Url)
        return
    }
    if ($PSBoundParameters.ContainsKey('BodyContains') -and -not ($resp.Content -match [regex]::Escape($BodyContains))) {
        $preview = ($resp.Content -replace '\s+', ' ').Substring(0, [Math]::Min(120, $resp.Content.Length))
        Write-Fail $Name ("body did not contain '{0}'. First 120 chars: {1}" -f $BodyContains, $preview)
        return
    }
    Write-Pass $Name
}

function Assert-Status {
    # Used when we expect a 4xx/5xx — Invoke-WebRequest throws on those, so we
    # unwrap WebException and read the StatusCode off the inner response.
    param(
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] [string]$Url,
        [Parameter(Mandatory)] [string]$Method,
        [Parameter(Mandatory)] [int]$ExpectedStatus
    )
    Write-Step $Name
    $actual = $null
    try {
        $resp = Invoke-WebRequest -Uri $Url -Method $Method -UseBasicParsing -TimeoutSec 10
        $actual = [int]$resp.StatusCode
    } catch [System.Net.WebException] {
        if ($_.Exception.Response -ne $null) {
            $actual = [int]$_.Exception.Response.StatusCode
        } else {
            Write-Fail $Name ("{0} {1} threw with no response: {2}" -f $Method, $Url, $_.Exception.Message)
            return
        }
    } catch {
        # PS 7 throws Microsoft.PowerShell.Commands.HttpResponseException instead.
        if ($_.Exception.PSObject.Properties.Name -contains 'Response' -and $_.Exception.Response -ne $null) {
            $actual = [int]$_.Exception.Response.StatusCode
        } else {
            Write-Fail $Name ("{0} {1} threw: {2}" -f $Method, $Url, $_.Exception.Message)
            return
        }
    }
    if ($actual -ne $ExpectedStatus) {
        Write-Fail $Name ("expected HTTP {0}, got {1} for {2} {3}" -f $ExpectedStatus, $actual, $Method, $Url)
        return
    }
    Write-Pass $Name
}

Write-Host ""
Write-Host "task-tracker smoke test" -ForegroundColor White
Write-Host ("  admin base : {0}" -f $AdminBase)
Write-Host ("  public base: {0}" -f $PublicBase)
Write-Host ""

# Admin (loopback only) -------------------------------------------------------
Assert-Get -Name 'admin health'    -Url "$AdminBase/health" -ExpectedStatus 200 -BodyContains 'OK'
Assert-Get -Name 'admin backlog'   -Url "$AdminBase/"       -ExpectedStatus 200 -BodyContains '<html'

# Public (LAN-reachable) ------------------------------------------------------
Assert-Get -Name 'public health'   -Url "$PublicBase/health" -ExpectedStatus 200 -BodyContains 'OK'
Assert-Get -Name 'public backlog'  -Url "$PublicBase/"       -ExpectedStatus 200 -BodyContains '<html'

# Read-only invariant: public app must reject every state-changing method on
# /tasks with HTTP 405. Mirrors the PUB-09 unit invariant against the live
# Apache stack.
Assert-Status -Name 'public POST /tasks   -> 405' -Url "$PublicBase/tasks" -Method POST   -ExpectedStatus 405
Assert-Status -Name 'public PUT /tasks/x  -> 405' -Url "$PublicBase/tasks/x" -Method PUT  -ExpectedStatus 405
Assert-Status -Name 'public DELETE /tasks/x -> 405' -Url "$PublicBase/tasks/x" -Method DELETE -ExpectedStatus 405

# Summary ---------------------------------------------------------------------
Write-Host ""
if ($script:Failures.Count -eq 0) {
    Write-Host "ALL CHECKS PASSED" -ForegroundColor Green
    exit 0
}
Write-Host ("FAILED: {0} check(s)" -f $script:Failures.Count) -ForegroundColor Red
foreach ($f in $script:Failures) { Write-Host ("  - {0}" -f $f) -ForegroundColor Red }
exit 1
