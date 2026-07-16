# ISIR-LEAD-TRACKER

ISIR-LEAD-TRACKER is a focused GovTech lead mining platform for identifying commercially relevant creditor claims from Czech insolvency proceedings.

Version 1 targets one concrete end-to-end workflow:

1. monitor new ISIR events
2. isolate konkurs cases in section B with a final report
3. fetch and parse the source document
4. extract secured and unsecured creditor claims
5. apply the confirmed penetration key
6. persist and sync qualified leads into Google Sheets

## Confirmed architecture

- Laravel orchestrator
- Python ingestion and document worker
- Redis queue
- PostgreSQL
- ARES validation
- ISIR_PUBLIC_WS + Hlidac statu in MVP
- Google Sheets delivery and operator status workflow
- Railway deployment baseline for app, worker, cron, Postgres, and Redis

## ISIR integration note (2026)

- Public portal presentation moved to `eisir.justice.cz`, but v1 integration remains on the compatible SOAP/XML `isir_public_ws` contract.
- This project does **not** rely on HTML scraping from the portal UI; source acquisition is via official web services only.

## Version 1 scope

This repository is for the first complete production-capable version only.

In scope:

- incremental ISIR ingestion
- historical backfill entrypoint
- final report parsing
- LLM-assisted structured extraction
- penetration-key filtering
- lead deduplication by `spisova_znacka + veritel`
- Google Sheets synchronization
- reverse sync of operator status changes from Sheets
- Railway deployment and runbooks

Out of scope for version 1:

- broader platform scaling
- advanced multi-tenant architecture
- non-critical optimization work
- future analytics beyond the agreed lead workflow

## Working mode

The build is intentionally agent-first.

- the agent implements normal steps autonomously
- the user is informed about progress continuously
- every critical decision requires explicit approval
- each completed logical chunk is recorded in `WORK_JOURNAL.md`

## Repository documents

- `agents.md` - operating rules for agentic development in this repo
- `ROADMAP.md` - delivery phases for version 1
- `WORK_JOURNAL.md` - chronological execution log
- `.github/copilot-instructions.md` - repo-level Copilot operating instructions

## Repository structure

- `apps/orchestrator` - Laravel orchestrator, queue entrypoints, persistence, and operator-facing APIs
- `services/ingestion-worker` - Python ingestion and document-processing worker
- `scripts` - local helper scripts for PHP, Composer, Artisan, and service env synchronization
- `tools` - local, ignored toolchain bootstrap for PHP and Composer

## Secrets and local configuration

Secrets must not be committed.

Local placeholders live in the repository root:

- `.env`
- `.env.example`

Google Sheets backend auth should use:

- `GOOGLE_CREDS_JSON` as preferred input
- or fallback split fields:
  - `GOOGLE_PROJECT_ID`
  - `GOOGLE_CLIENT_EMAIL`
  - `GOOGLE_PRIVATE_KEY`

`GOOGLE_CREDS_KEY` alone is not sufficient for authenticated Sheets write access.

`GOOGLE_CREDS_JSON` must be stored as a single-line escaped JSON string. If you keep the readable multi-line JSON in the root `.env`, use the split Google fields for runtime and generate service-local env files via the sync script.

## Local bootstrap

1. Fill the root `.env` as the canonical secret inventory.
2. Run `.\scripts\sync-service-env.ps1` to generate service-local `.env` files for Laravel and the Python worker.
3. Run `.\scripts\artisan.ps1 about` to verify the Laravel app boots with the local PHP toolchain.
4. Create a worker virtualenv and install the package from `services\ingestion-worker`.

Additional internal runtime wiring now expects:

- `INTERNAL_API_TOKEN` - shared secret between the Python worker and Laravel internal import API
- `ORCHESTRATOR_IMPORT_URL` - worker callback URL, defaulting to `http://localhost/api/internal/isir/parsed-documents`
- `ISIR_PUBLIC_WS_FALLBACK_URLS` - comma-separated SOAP endpoint candidates used when the primary ISIR endpoint returns transport or HTTP errors
- `ISIR_TIMEOUT_SECONDS` - SOAP/document timeout budget; keep at least `90` for production because `:8443` responses can exceed 30 seconds

## Current cross-service flow

The repository now has a stable internal import seam:

1. Laravel dispatches JSON sync tasks into Redis.
2. Python worker validates the task envelope and fetches ISIR public events.
3. Worker prefilters section B final-report events, downloads the source document, extracts text from PDF or HTML, and builds structured parsed-document payloads.
4. Laravel imports or updates `insolvency_cases`, `case_documents`, `creditors`, `claims`, and `leads`.
5. During import, Laravel enriches creditors through Hlídač státu and ARES before final qualification hardening.
6. A scheduled creditor backfill command re-enriches older records and re-qualifies dependent leads.

Key runtime entrypoints now include:

- `isir:dispatch-sync` - schedules incremental or backfill worker tasks
- `isir-worker --consume-queue` - runs the Python worker consumer that drains `isir:tasks` from Redis
- `creditors:enrich --limit=100` - re-enriches existing creditors and re-qualifies their leads
- `leads:sync-sheet --direction=both` - keeps the Google Sheet synchronized with DB state and operator changes

## Optional developer tooling

`Graphify` is installed project-scoped as a **repo knowledge-graph aid** for faster code and document traversal. It remains an optional developer tool, not a version 1 runtime dependency.

## Immediate next build goals

1. resolve the live public ISIR document retrieval pattern against real production documents
2. harden claim extraction on real final-report samples
3. run the first full end-to-end happy path with real payloads
4. prepare Railway runbook and deployment validation

## Railway deployment answers (current state)

- **Project type:** Laravel (PHP 8.4) web app in `apps/orchestrator`
- **Dockerfile:** yes, at `apps/orchestrator/Dockerfile`
- **Worker Dockerfile:** yes, at `services/ingestion-worker/Dockerfile`
- **docker-compose:** not required for Railway baseline
- **Monorepo note:** for web service use repo-root build context (`rootDirectory = null`) and `dockerfilePath = apps/orchestrator/Dockerfile`
  - for worker service use repo-root build context (`rootDirectory = null`) and `dockerfilePath = services/ingestion-worker/Dockerfile`

### Required Railway env baseline for `isir-lead-tracker`

Set these values directly on the Laravel service (do not reference `todo-api`):

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY=base64:...` (generate once via `php artisan key:generate --show`)
- `APP_URL=https://isir-lead-tracker-production.up.railway.app`
- `DATABASE_URL` (Railway Postgres reference)
- `REDIS_URL` (Railway Redis reference)
- `QUEUE_CONNECTION=redis`
- `INTERNAL_API_TOKEN=<shared-secret>`
- `ISIR_TIMEOUT_SECONDS=90`

Optional integration vars (only if used in this phase): `HLIDAC_STATU_API_KEY`, `GOOGLE_*`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `ARES_BASE_URL`.

If public URL returns 502, first verify the latest deploy is running this commit and check service logs for missing `APP_KEY` / invalid env references.
