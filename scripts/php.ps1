param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Arguments
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$phpExe = Join-Path $repoRoot 'tools\php\php.exe'

if (-not (Test-Path $phpExe)) {
    throw 'Local PHP runtime was not found in tools\php. Bootstrap it before running this script.'
}

$env:PATH = "$(Split-Path -Parent $phpExe);$env:PATH"

& $phpExe @Arguments
