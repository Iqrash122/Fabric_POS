$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '../..')
function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed with exit code $LASTEXITCODE" }
}
Invoke-Docker info
Invoke-Docker run --rm --mount "type=bind,source=$((Get-Location).Path),target=/workspace" -w /workspace node:24-bookworm-slim node scripts/workspace/configure.mjs
Invoke-Docker compose up -d --build
Invoke-Docker compose exec -T workspace bash scripts/workspace/install.sh
Invoke-Docker compose exec -T workspace bash scripts/workspace/check.sh
$OwnerEmail = Read-Host 'Owner email (leave blank if already provisioned)'
if ($OwnerEmail) { Invoke-Docker compose exec workspace php artisan fabric:owner $OwnerEmail }
Write-Host 'Starting development services at http://localhost:8000. Keep this window open.'
Invoke-Docker compose exec workspace bash scripts/workspace/start.sh
