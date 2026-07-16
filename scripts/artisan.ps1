param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Arguments
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$appRoot = Join-Path $repoRoot 'apps\orchestrator'
$artisan = Join-Path $appRoot 'artisan'

if (-not (Test-Path $artisan)) {
    throw 'Laravel artisan entrypoint was not found in apps\orchestrator.'
}

Push-Location $appRoot

try {
    & (Join-Path $PSScriptRoot 'php.ps1') $artisan @Arguments
} finally {
    Pop-Location
}
