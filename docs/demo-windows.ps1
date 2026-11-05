#Requires -Version 5.1
<#
.SYNOPSIS
  Windows parity for flagship demo.sh (evaluator / Laragon).

.DESCRIPTION
  Run from the extracted flagship demo app root (same folder as artisan).

  Extract the release zip with tar — not Expand-Archive (partial tree on some builds):
    tar -xf flagship-erp-v2.0.2.zip -C E:\laragon\www\flagship-erp-v202-demo

  Uses Packagist-only composer.json (no SSH VCS repository). First run runs
  composer install; configure .env with absolute SQLite path, APP_URL, slice:cache.

.EXAMPLE
  .\docs\demo-windows.ps1
  Copy this script to the demo root as demo.ps1, or invoke with -DemoRoot.

.EXAMPLE
  .\demo.ps1 -Port 7002 -NoServe
#>
[CmdletBinding()]
param(
    [string] $DemoRoot = (Split-Path -Parent $MyInvocation.MyCommand.Path),
    [int] $Port = 8000,
    [switch] $NoServe
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path (Join-Path $DemoRoot 'artisan'))) {
    if (Test-Path (Join-Path (Split-Path -Parent $DemoRoot) 'artisan')) {
        $DemoRoot = Split-Path -Parent $DemoRoot
    } else {
        throw "Could not find artisan under DemoRoot: $DemoRoot. cd to the flagship demo root or pass -DemoRoot."
    }
}

Set-Location $DemoRoot
$sqlite = (Join-Path $DemoRoot 'database\erp-clone.sqlite').Replace('\', '/')

if (-not (Test-Path 'vendor')) {
    Write-Host 'Installing dependencies (first run only)…'
    composer install --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw 'composer install failed. Ensure composer.json has no VCS repositories (Packagist-only).'
    }
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
    if ($LASTEXITCODE -ne 0) { throw 'key:generate failed.' }
}

php artisan slice:cache 2>$null | Out-Null
php artisan config:clear 2>$null | Out-Null

Write-Host ''
Write-Host '  Flagship demo ready.'
Write-Host '  Logins (all / password):  admin@laraslice.com (install super admin)'
Write-Host '           admin@example.com · employee@example.com · client@example.com'
Write-Host ''
Write-Host "  APP_URL=http://127.0.0.1:$Port  DB_DATABASE=$sqlite"
Write-Host ''

if ($NoServe) {
    exit 0
}

Write-Host "  Serving on http://127.0.0.1:$Port  (Ctrl+C to stop)"
php artisan serve --host=127.0.0.1 --port=$Port
