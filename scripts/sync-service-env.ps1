param()

$ErrorActionPreference = 'Stop'

function Read-RootEnv {
    param(
        [string]$Path
    )

    $values = @{}
    $skipGoogleJson = $false

    foreach ($line in Get-Content $Path) {
        if ($skipGoogleJson) {
            if ($line.Trim() -eq '}') {
                $skipGoogleJson = $false
            }

            continue
        }

        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) {
            continue
        }

        if ($line.StartsWith('GOOGLE_CREDS_JSON=')) {
            $skipGoogleJson = $true
            continue
        }

        $parts = $line -split '=', 2

        if ($parts.Count -ne 2) {
            continue
        }

        $values[$parts[0].Trim()] = $parts[1]
    }

    return $values
}

function Write-KeyValues {
    param(
        [string]$Path,
        [hashtable]$Values
    )

    $content = foreach ($key in $Values.Keys | Sort-Object) {
        $value = $Values[$key]

        if ($null -eq $value) {
            $value = ''
        } elseif ($value -match '\s' -or $value.StartsWith('-') -or $value.Contains('"')) {
            $value = '"' + $value.Replace('"', '\"') + '"'
        }

        '{0}={1}' -f $key, $value
    }

    Set-Content -Path $Path -Value $content -Encoding ASCII
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$rootEnv = Join-Path $repoRoot '.env'

if (-not (Test-Path $rootEnv)) {
    throw 'Root .env was not found.'
}

$values = Read-RootEnv -Path $rootEnv
$orchestratorEnv = Join-Path $repoRoot 'apps\orchestrator\.env'
$workerRoot = Join-Path $repoRoot 'services\ingestion-worker'
$workerEnv = Join-Path $workerRoot '.env'

New-Item -ItemType Directory -Force -Path $workerRoot | Out-Null

$appKey = ''

if (Test-Path $orchestratorEnv) {
    foreach ($line in Get-Content $orchestratorEnv) {
        if ($line.StartsWith('APP_KEY=')) {
            $appKey = ($line -split '=', 2)[1]
            break
        }
    }
}

$appEnv = [ordered]@{
    APP_NAME = if ($values.ContainsKey('APP_NAME')) { $values['APP_NAME'] } else { '"ISIR Lead Tracker"' }
    APP_ENV = if ($values.ContainsKey('APP_ENV')) { $values['APP_ENV'] } else { 'local' }
    APP_KEY = $appKey
    APP_DEBUG = if ($values.ContainsKey('APP_DEBUG')) { $values['APP_DEBUG'] } else { 'true' }
    APP_URL = if ($values.ContainsKey('APP_URL')) { $values['APP_URL'] } else { 'http://localhost:8000' }
    DB_CONNECTION = 'pgsql'
    DB_URL = if ($values.ContainsKey('DATABASE_URL')) { $values['DATABASE_URL'] } elseif ($values.ContainsKey('DB_URL')) { $values['DB_URL'] } else { '' }
    DB_SCHEMA = if ($values.ContainsKey('DB_SCHEMA')) { $values['DB_SCHEMA'] } else { 'public' }
    DB_SSLMODE = if ($values.ContainsKey('DB_SSLMODE')) { $values['DB_SSLMODE'] } else { 'prefer' }
    QUEUE_CONNECTION = if ($values.ContainsKey('QUEUE_CONNECTION')) { $values['QUEUE_CONNECTION'] } else { 'redis' }
    CACHE_STORE = if ($values.ContainsKey('CACHE_STORE')) { $values['CACHE_STORE'] } else { 'redis' }
    SESSION_DRIVER = 'database'
    REDIS_CLIENT = if ($values.ContainsKey('REDIS_CLIENT')) { $values['REDIS_CLIENT'] } else { 'predis' }
    REDIS_URL = if ($values.ContainsKey('REDIS_URL')) { $values['REDIS_URL'] } else { '' }
    REDIS_QUEUE = if ($values.ContainsKey('REDIS_QUEUE')) { $values['REDIS_QUEUE'] } else { 'isir-leads' }
    ISIR_PUBLIC_WS_URL = if ($values.ContainsKey('ISIR_PUBLIC_WS_URL')) { $values['ISIR_PUBLIC_WS_URL'] } else { 'https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService' }
    ISIR_PUBLIC_WS_FALLBACK_URLS = if ($values.ContainsKey('ISIR_PUBLIC_WS_FALLBACK_URLS')) { $values['ISIR_PUBLIC_WS_FALLBACK_URLS'] } else { 'https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService,https://isir.justice.cz/isir_public_ws/IsirWsPublicService' }
    ISIR_DOCUMENT_BASE_URL = if ($values.ContainsKey('ISIR_DOCUMENT_BASE_URL')) { $values['ISIR_DOCUMENT_BASE_URL'] } else { 'https://isir.justice.cz/isir/common/stat.do' }
    ISIR_FINAL_REPORT_TOKEN = if ($values.ContainsKey('ISIR_FINAL_REPORT_TOKEN')) { $values['ISIR_FINAL_REPORT_TOKEN'] } else { 'konec' }
    ISIR_SYNC_PROVIDER = if ($values.ContainsKey('ISIR_SYNC_PROVIDER')) { $values['ISIR_SYNC_PROVIDER'] } else { 'isir_public_ws' }
    ISIR_SYNC_STREAM = if ($values.ContainsKey('ISIR_SYNC_STREAM')) { $values['ISIR_SYNC_STREAM'] } else { 'events' }
    ISIR_SYNC_BATCH_SIZE = if ($values.ContainsKey('ISIR_SYNC_BATCH_SIZE')) { $values['ISIR_SYNC_BATCH_SIZE'] } else { '250' }
    ISIR_ORCHESTRATOR_QUEUE = if ($values.ContainsKey('ISIR_ORCHESTRATOR_QUEUE')) { $values['ISIR_ORCHESTRATOR_QUEUE'] } else { 'default' }
    LEAD_MIN_CLAIM_AMOUNT = if ($values.ContainsKey('LEAD_MIN_CLAIM_AMOUNT')) { $values['LEAD_MIN_CLAIM_AMOUNT'] } else { '300000' }
    LEAD_MAX_CLAIM_AMOUNT = if ($values.ContainsKey('LEAD_MAX_CLAIM_AMOUNT')) { $values['LEAD_MAX_CLAIM_AMOUNT'] } else { '600000' }
    WORKER_TASK_REDIS_CONNECTION = if ($values.ContainsKey('WORKER_TASK_REDIS_CONNECTION')) { $values['WORKER_TASK_REDIS_CONNECTION'] } else { 'default' }
    WORKER_TASK_QUEUE = if ($values.ContainsKey('WORKER_TASK_QUEUE')) { $values['WORKER_TASK_QUEUE'] } else { 'isir:tasks' }
    INTERNAL_API_TOKEN = if ($values.ContainsKey('INTERNAL_API_TOKEN')) { $values['INTERNAL_API_TOKEN'] } else { '' }
    GOOGLE_SHEETS_SPREADSHEET_ID = if ($values.ContainsKey('GOOGLE_SHEETS_SPREADSHEET_ID')) { $values['GOOGLE_SHEETS_SPREADSHEET_ID'] } else { '' }
    GOOGLE_SHEETS_WORKSHEET_NAME = if ($values.ContainsKey('GOOGLE_SHEETS_WORKSHEET_NAME')) { $values['GOOGLE_SHEETS_WORKSHEET_NAME'] } else { 'Dashboard / Leady' }
    GOOGLE_PROJECT_ID = if ($values.ContainsKey('GOOGLE_PROJECT_ID')) { $values['GOOGLE_PROJECT_ID'] } else { '' }
    GOOGLE_CLIENT_EMAIL = if ($values.ContainsKey('GOOGLE_CLIENT_EMAIL')) { $values['GOOGLE_CLIENT_EMAIL'] } else { '' }
    GOOGLE_PRIVATE_KEY = if ($values.ContainsKey('GOOGLE_PRIVATE_KEY')) { $values['GOOGLE_PRIVATE_KEY'] } else { '' }
    ARES_BASE_URL = if ($values.ContainsKey('ARES_BASE_URL')) { $values['ARES_BASE_URL'] } else { 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty' }
    HLIDAC_STATU_BASE_URL = if ($values.ContainsKey('HLIDAC_STATU_BASE_URL')) { $values['HLIDAC_STATU_BASE_URL'] } else { 'https://www.hlidacstatu.cz/api/v1' }
    HLIDAC_STATU_API_KEY = if ($values.ContainsKey('HLIDAC_STATU_API_KEY')) { $values['HLIDAC_STATU_API_KEY'] } else { '' }
    GEMINI_API_KEY = if ($values.ContainsKey('GEMINI_API_KEY')) { $values['GEMINI_API_KEY'] } else { '' }
    GROQ_API_KEY = if ($values.ContainsKey('GROQ_API_KEY')) { $values['GROQ_API_KEY'] } else { '' }
    LLM_PRIMARY_PROVIDER = if ($values.ContainsKey('LLM_PRIMARY_PROVIDER')) { $values['LLM_PRIMARY_PROVIDER'] } else { 'gemini' }
    LLM_FALLBACK_PROVIDER = if ($values.ContainsKey('LLM_FALLBACK_PROVIDER')) { $values['LLM_FALLBACK_PROVIDER'] } else { 'groq' }
    ENABLE_HLIDAC_STATU = if ($values.ContainsKey('ENABLE_HLIDAC_STATU')) { $values['ENABLE_HLIDAC_STATU'] } else { 'true' }
    ENABLE_GEMINI = if ($values.ContainsKey('ENABLE_GEMINI')) { $values['ENABLE_GEMINI'] } else { 'true' }
    ENABLE_GROQ_FALLBACK = if ($values.ContainsKey('ENABLE_GROQ_FALLBACK')) { $values['ENABLE_GROQ_FALLBACK'] } else { 'true' }
}

$workerEnvValues = [ordered]@{
    APP_ENV = if ($values.ContainsKey('APP_ENV')) { $values['APP_ENV'] } else { 'local' }
    DATABASE_URL = if ($values.ContainsKey('DATABASE_URL')) { $values['DATABASE_URL'] } elseif ($values.ContainsKey('DB_URL')) { $values['DB_URL'] } else { '' }
    REDIS_URL = if ($values.ContainsKey('REDIS_URL')) { $values['REDIS_URL'] } else { '' }
    GOOGLE_SHEETS_SPREADSHEET_ID = if ($values.ContainsKey('GOOGLE_SHEETS_SPREADSHEET_ID')) { $values['GOOGLE_SHEETS_SPREADSHEET_ID'] } else { '' }
    GOOGLE_SHEETS_WORKSHEET_NAME = if ($values.ContainsKey('GOOGLE_SHEETS_WORKSHEET_NAME')) { $values['GOOGLE_SHEETS_WORKSHEET_NAME'] } else { 'Dashboard / Leady' }
    GOOGLE_PROJECT_ID = if ($values.ContainsKey('GOOGLE_PROJECT_ID')) { $values['GOOGLE_PROJECT_ID'] } else { '' }
    GOOGLE_CLIENT_EMAIL = if ($values.ContainsKey('GOOGLE_CLIENT_EMAIL')) { $values['GOOGLE_CLIENT_EMAIL'] } else { '' }
    GOOGLE_PRIVATE_KEY = if ($values.ContainsKey('GOOGLE_PRIVATE_KEY')) { $values['GOOGLE_PRIVATE_KEY'] } else { '' }
    ARES_BASE_URL = if ($values.ContainsKey('ARES_BASE_URL')) { $values['ARES_BASE_URL'] } else { 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty' }
    HLIDAC_STATU_BASE_URL = if ($values.ContainsKey('HLIDAC_STATU_BASE_URL')) { $values['HLIDAC_STATU_BASE_URL'] } else { 'https://www.hlidacstatu.cz/api/v1' }
    HLIDAC_STATU_API_KEY = if ($values.ContainsKey('HLIDAC_STATU_API_KEY')) { $values['HLIDAC_STATU_API_KEY'] } else { '' }
    GEMINI_API_KEY = if ($values.ContainsKey('GEMINI_API_KEY')) { $values['GEMINI_API_KEY'] } else { '' }
    GROQ_API_KEY = if ($values.ContainsKey('GROQ_API_KEY')) { $values['GROQ_API_KEY'] } else { '' }
    LLM_PRIMARY_PROVIDER = if ($values.ContainsKey('LLM_PRIMARY_PROVIDER')) { $values['LLM_PRIMARY_PROVIDER'] } else { 'gemini' }
    LLM_FALLBACK_PROVIDER = if ($values.ContainsKey('LLM_FALLBACK_PROVIDER')) { $values['LLM_FALLBACK_PROVIDER'] } else { 'groq' }
    ENABLE_HLIDAC_STATU = if ($values.ContainsKey('ENABLE_HLIDAC_STATU')) { $values['ENABLE_HLIDAC_STATU'] } else { 'true' }
    ENABLE_GEMINI = if ($values.ContainsKey('ENABLE_GEMINI')) { $values['ENABLE_GEMINI'] } else { 'true' }
    ENABLE_GROQ_FALLBACK = if ($values.ContainsKey('ENABLE_GROQ_FALLBACK')) { $values['ENABLE_GROQ_FALLBACK'] } else { 'true' }
    ISIR_PUBLIC_WS_URL = if ($values.ContainsKey('ISIR_PUBLIC_WS_URL')) { $values['ISIR_PUBLIC_WS_URL'] } else { 'https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService' }
    ISIR_PUBLIC_WS_FALLBACK_URLS = if ($values.ContainsKey('ISIR_PUBLIC_WS_FALLBACK_URLS')) { $values['ISIR_PUBLIC_WS_FALLBACK_URLS'] } else { 'https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService,https://isir.justice.cz/isir_public_ws/IsirWsPublicService' }
    ISIR_DOCUMENT_BASE_URL = if ($values.ContainsKey('ISIR_DOCUMENT_BASE_URL')) { $values['ISIR_DOCUMENT_BASE_URL'] } else { 'https://isir.justice.cz/isir/common/stat.do' }
    ISIR_FINAL_REPORT_TOKEN = if ($values.ContainsKey('ISIR_FINAL_REPORT_TOKEN')) { $values['ISIR_FINAL_REPORT_TOKEN'] } else { 'konec' }
    ISIR_SYNC_PROVIDER = if ($values.ContainsKey('ISIR_SYNC_PROVIDER')) { $values['ISIR_SYNC_PROVIDER'] } else { 'isir_public_ws' }
    ISIR_SYNC_STREAM = if ($values.ContainsKey('ISIR_SYNC_STREAM')) { $values['ISIR_SYNC_STREAM'] } else { 'events' }
    ISIR_SYNC_BATCH_SIZE = if ($values.ContainsKey('ISIR_SYNC_BATCH_SIZE')) { $values['ISIR_SYNC_BATCH_SIZE'] } else { '250' }
    LEAD_MIN_CLAIM_AMOUNT = if ($values.ContainsKey('LEAD_MIN_CLAIM_AMOUNT')) { $values['LEAD_MIN_CLAIM_AMOUNT'] } else { '300000' }
    LEAD_MAX_CLAIM_AMOUNT = if ($values.ContainsKey('LEAD_MAX_CLAIM_AMOUNT')) { $values['LEAD_MAX_CLAIM_AMOUNT'] } else { '600000' }
    WORKER_TASK_QUEUE = if ($values.ContainsKey('WORKER_TASK_QUEUE')) { $values['WORKER_TASK_QUEUE'] } else { 'isir:tasks' }
    ORCHESTRATOR_IMPORT_URL = if ($values.ContainsKey('ORCHESTRATOR_IMPORT_URL')) { $values['ORCHESTRATOR_IMPORT_URL'] } else { 'http://localhost/api/internal/isir/parsed-documents' }
    INTERNAL_API_TOKEN = if ($values.ContainsKey('INTERNAL_API_TOKEN')) { $values['INTERNAL_API_TOKEN'] } else { '' }
}

Write-KeyValues -Path $orchestratorEnv -Values $appEnv
Write-KeyValues -Path $workerEnv -Values $workerEnvValues

Write-Output 'Service env files generated.'
