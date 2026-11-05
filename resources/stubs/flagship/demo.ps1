#Requires -Version 5.1
# One-command demo on Windows (parity with demo.sh).
#
#   .\demo.ps1              install (first run) and serve on :8000
#   .\demo.ps1 -Port 7002   evaluator default from IMPROVEMENT-REGISTER
#   .\demo.ps1 -NoServe     install and configure only
#
# Extract zip: tar -xf flagship-erp-v2.0.2.zip  (not Expand-Archive)
param(
    [int] $Port = 8000,
    [switch] $NoServe
)

$ErrorActionPreference = 'Stop'
$DemoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $DemoRoot

$sqlite = (Join-Path $DemoRoot 'database\erp-clone.sqlite').Replace('\', '/')

if (-not (Test-Path 'vendor')) {
    Write-Host 'Installing dependencies (first run only)…'
    composer install --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'composer install failed.' }
}

if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
}

function Set-DotEnvLine {
    param([string] $Key, [string] $Value)
    $pattern = "^\s*$([regex]::Escape($Key))="
    $line = "$Key=$Value"
    $content = Get-Content '.env' -Raw
    if ($content -match "(?m)$pattern") {
        $content = [regex]::Replace($content, "(?m)$pattern.*", $line)
    } else {
        $content = $content.TrimEnd() + "`r`n$line`r`n"
    }
    Set-Content -Path '.env' -Value $content -NoNewline
}

Set-DotEnvLine 'DB_DATABASE' $sqlite
Set-DotEnvLine 'APP_URL' "http://127.0.0.1:$Port"

$envContent = Get-Content '.env' -Raw
if ($envContent -notmatch '(?m)^APP_KEY=base64:') {
    php artisan key:generate --force
}

php artisan slice:cache 2>$null | Out-Null
php artisan config:clear 2>$null | Out-Null

Write-Host ''
Write-Host '  Flagship demo ready.'
Write-Host '  Logins (all / password):  admin@laraslice.com (install super admin)'
Write-Host '           admin@example.com · employee@example.com · client@example.com'
Write-Host ''

if ($NoServe) { exit 0 }

Write-Host "  Serving on http://127.0.0.1:$Port  (Ctrl+C to stop)"
php artisan serve --host=127.0.0.1 --port=$Port
