param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Arguments
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$composerPhar = Join-Path $repoRoot 'tools\composer.phar'

if (-not (Test-Path $composerPhar)) {
    throw 'Local Composer was not found in tools\composer.phar. Bootstrap it before running this script.'
}

& (Join-Path $PSScriptRoot 'php.ps1') $composerPhar @Arguments
