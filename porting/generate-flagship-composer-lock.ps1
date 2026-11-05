#Requires -Version 5.1
<#
  Maintainer: generate composer.lock for the next flagship zip cut.

  1. Copy resources/stubs/flagship/composer.json into a clean Laravel 13 tree (or flagship repo root).
  2. Run this script from that app root (folder containing artisan + composer.json).

  Ships lock + vendor optional — evaluators should prefer composer install with the lock.
#>
param(
    [string] $AppRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
Set-Location $AppRoot

if (-not (Test-Path 'composer.json')) {
    throw 'composer.json not found in AppRoot.'
}

Write-Host 'Resolving dependencies from Packagist (no VCS repositories in stub)…'
composer update --no-interaction --no-dev
if ($LASTEXITCODE -ne 0) { throw 'composer update failed.' }

if (-not (Test-Path 'composer.lock')) {
    throw 'composer.lock was not created.'
}

Write-Host 'OK — commit composer.lock beside composer.json in the flagship release artifact.'
