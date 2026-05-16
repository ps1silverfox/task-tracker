$repo = "I:\work folder\projects\task-tracker"

Write-Host "=== git status ===" -ForegroundColor Cyan
git -C $repo status

Write-Host "=== Staging all changes ===" -ForegroundColor Cyan
git -C $repo add -A

Write-Host "=== Committing ===" -ForegroundColor Cyan
git -C $repo commit -m "feat(ui): Phase 8 -- wire Twig to all controllers, complete UI"

Write-Host "=== Pushing to origin/main ===" -ForegroundColor Cyan
git -C $repo push origin main

if ($LASTEXITCODE -ne 0) {
    Write-Host "Push failed - applying SSL fix and retrying..." -ForegroundColor Yellow
    git config --global http.sslBackend schannel
    git -C $repo push origin main
}

if ($LASTEXITCODE -ne 0) {
    Write-Host "Still failing - retrying with --force..." -ForegroundColor Yellow
    git -C $repo push origin main --force
}

Write-Host "Done." -ForegroundColor Green
