# run_ralph.ps1
# =============
# Launches Ralph against the task-tracker project.
# On successful completion (all tasks [x]) creates a GitHub repo
# called "task-tracker" and pushes main.
#
# Usage:
#   .\run_ralph.ps1                    # run / resume
#   .\run_ralph.ps1 -MaxIterations 10  # limit iterations
#   .\run_ralph.ps1 -SkipGitHub        # skip push step
#   .\run_ralph.ps1 -RalphScript "C:\path\to\ralph_opus.ps1"

param(
    [int]$MaxIterations    = 40,
    [switch]$DryRun,
    [switch]$UseAPI,
    [switch]$WaitOnCredits,
    [int]$CreditWaitMinutes = 30,
    [switch]$SkipGitHub,
    [string]$GitHubUser    = "ps1silverfox",
    [string]$RepoName      = "task-tracker",
    [string]$RalphScript   = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectDir = (Resolve-Path $ScriptDir).Path   # script lives inside the project

# ---------------------------------------------------------------------------
# Locate ralph_opus.ps1
# ---------------------------------------------------------------------------
if (-not $RalphScript) {
    $ralphCandidates = @(
        (Join-Path $ScriptDir "..\ralph\ralph_opus.ps1"),
        (Join-Path $ScriptDir "..\..\ralph\ralph_opus.ps1"),
        (Join-Path $env:USERPROFILE "projects\ralph\ralph_opus.ps1"),
        (Join-Path $env:USERPROFILE "Documents\projects\ralph\ralph_opus.ps1")
    )
    foreach ($c in $ralphCandidates) {
        if (Test-Path $c) { $RalphScript = (Resolve-Path $c).Path; break }
    }
}
if (-not $RalphScript -or -not (Test-Path $RalphScript)) {
    Write-Host "Could not find ralph_opus.ps1. Pass it explicitly:" -ForegroundColor Yellow
    Write-Host "  .\run_ralph.ps1 -RalphScript 'C:\path\to\ralph_opus.ps1'" -ForegroundColor Cyan
    exit 1
}

$TaskFile = "TASK.md"
if (-not (Test-Path (Join-Path $ProjectDir $TaskFile))) {
    Write-Error "TASK.md not found in: $ProjectDir"
    exit 1
}

Write-Host "task-tracker Ralph Agent" -ForegroundColor Cyan
Write-Host "  Ralph    : $RalphScript"
Write-Host "  Project  : $ProjectDir"
Write-Host "  TaskFile : $TaskFile"
Write-Host "  MaxIter  : $MaxIterations"
Write-Host "  GitHub   : $GitHubUser/$RepoName (push on completion)"
Write-Host ""

# ---------------------------------------------------------------------------
# Run Ralph
# ---------------------------------------------------------------------------
$ralphParams = @{
    ProjectDir    = $ProjectDir
    TaskFile      = $TaskFile
    MaxIterations = $MaxIterations
}
if ($DryRun)        { $ralphParams['DryRun']            = $true }
if ($UseAPI)        { $ralphParams['UseAPI']            = $true }
if ($WaitOnCredits) { $ralphParams['WaitOnCredits']     = $true
                      $ralphParams['CreditWaitMinutes'] = $CreditWaitMinutes }

# Always resume — 21 tasks already done
$ralphParams['Resume'] = $true

& $RalphScript @ralphParams
$ralphExit = $LASTEXITCODE

# ---------------------------------------------------------------------------
# Post-completion: verify all tasks done then push to GitHub
# ---------------------------------------------------------------------------
if ($ralphExit -ne 0) {
    Write-Host ""
    Write-Host "Ralph exited with code $ralphExit — skipping GitHub push." -ForegroundColor Yellow
    Write-Host "Fix any remaining issues and re-run to retry." -ForegroundColor Yellow
    exit $ralphExit
}

# Count remaining incomplete tasks
$taskContent = Get-Content (Join-Path $ProjectDir $TaskFile) -Raw
$remaining   = ([regex]::Matches($taskContent, '###\s+\[\s\]')).Count

if ($remaining -gt 0) {
    Write-Host ""
    Write-Host "Ralph finished but $remaining task(s) still incomplete — skipping GitHub push." -ForegroundColor Yellow
    Write-Host "Re-run to continue." -ForegroundColor Yellow
    exit 1
}

if ($SkipGitHub) {
    Write-Host ""
    Write-Host "All tasks complete. -SkipGitHub set — not pushing." -ForegroundColor Cyan
    exit 0
}

# ---------------------------------------------------------------------------
# GitHub push
# ---------------------------------------------------------------------------
Write-Host ""
Write-Host "==========================================="
Write-Host "  All tasks complete — pushing to GitHub"
Write-Host "==========================================="

Push-Location $ProjectDir
try {
    # Check if gh CLI is available
    if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
        Write-Host "gh CLI not found. Install from https://cli.github.com or push manually:" -ForegroundColor Yellow
        Write-Host "  git remote add origin https://github.com/$GitHubUser/$RepoName.git" -ForegroundColor Cyan
        Write-Host "  git push -u origin main" -ForegroundColor Cyan
        exit 0
    }

    # Check if remote already exists
    $remoteExists = (git remote | Where-Object { $_ -eq "origin" }) -ne $null
    if (-not $remoteExists) {
        Write-Host "  Creating GitHub repo: $GitHubUser/$RepoName (private)..."
        gh repo create "$GitHubUser/$RepoName" --private --source=. --remote=origin
        Write-Host "  Repo created." -ForegroundColor Green
    } else {
        Write-Host "  Remote origin already exists — skipping repo creation."
    }

    Write-Host "  Pushing main branch..."
    git push -u origin main
    Write-Host ""
    Write-Host "  Pushed: https://github.com/$GitHubUser/$RepoName" -ForegroundColor Green
    Write-Host "==========================================="
} finally {
    Pop-Location
}
