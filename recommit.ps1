$repo = "I:\work folder\projects\task-tracker"

# Apply SSL fix upfront (prevents port 443 timeout on home PC)
git config --global http.sslBackend schannel

Write-Host "=== git status ===" -ForegroundColor Cyan
git -C $repo status

Write-Host "`n=== Staging all changes ===" -ForegroundColor Cyan
git -C $repo add -A

Write-Host "`n=== Committing ===" -ForegroundColor Cyan
$msg = Read-Host "Commit message (leave blank for default)"
if ([string]::IsNullOrWhiteSpace($msg)) {
    $msg = "fix: recommit corrected source files"
}
git -C $repo commit -m $msg

Write-Host "`n=== Pushing to origin/main ===" -ForegroundColor Cyan
git -C $repo push origin main

if ($LASTEXITCODE -ne 0) {
    Write-Host "Push failed - retrying..." -ForegroundColor Yellow
    Start-Sleep -Seconds 3
    git -C $repo push origin main
}

if ($LASTEXITCODE -eq 0) {
    Write-Host "`nDone." -ForegroundColor Green
} else {
    Write-Host "`nPush failed after retry. Check your connection or run: git -C '$repo' push origin main" -ForegroundColor Red
}
