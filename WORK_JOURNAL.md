# WORK_JOURNAL

## Entry 001 - Repository foundation

- Completed:
  - created README, agents.md, ROADMAP, and repo-level Copilot instructions baseline
  - established working-journal rule for every completed logical chunk
  - aligned repository process with approval-gated agentic delivery
- Key decisions:
  - critical product and architecture decisions require explicit approval
  - normal implementation proceeds autonomously
  - version 1 is constrained to the confirmed end-to-end ISIR lead workflow
- Approvals needed:
  - none for this chunk
- Next step:
  - scaffold the Laravel orchestrator and Python worker foundations

## Entry 002 - Multi-service scaffold baseline

- Completed:
  - bootstrapped a local PHP + Composer toolchain and created the Laravel orchestrator in `apps/orchestrator`
  - added repository helper scripts for PHP, Composer, Artisan, and service env synchronization
  - scaffolded the Python ingestion worker with typed settings and a runnable entrypoint
  - aligned Laravel config with PostgreSQL, Redis, ISIR, Google Sheets, ARES, Hlídac státu, Gemini, and Groq
- Key decisions:
  - the repository root `.env` remains the canonical local secret inventory
  - service-local `.env` files are generated from the root inventory instead of duplicating manual edits across services
  - Laravel uses `predis` as the baseline Redis client to avoid depending on a system PHP Redis extension
- Approvals needed:
  - none for this chunk
- Next step:
  - model the shared persistence schema and first orchestration contracts between the Laravel app and Python worker

## Entry 003 - Persistence schema baseline

- Completed:
  - generated first Laravel domain models for insolvency cases, documents, creditors, claims, leads, lead status history, and sync checkpoints
  - designed the initial PostgreSQL schema with relationships, indexes, JSON metadata fields, and lead lifecycle columns
  - validated the new PHP files through syntax linting and refreshed the Laravel autoload/bootstrap state
- Key decisions:
  - `leads` represent the creditor-case aggregate and stay unique on `insolvency_case_id + creditor_id`
  - `claims` remain a lower-level extracted record layer, so multiple claim rows can feed one lead
  - sync progress is tracked explicitly in `sync_checkpoints` to support incremental ingestion and backfill
- Approvals needed:
  - none for this chunk
- Next step:
  - implement the first orchestration contract for incremental sync jobs and worker payload handoff

## Entry 004 - Orchestration and qualification contract

- Completed:
  - implemented `isir:dispatch-sync` as the first orchestration entrypoint for incremental and backfill dispatch
  - separated cross-language handoff from Laravel queue internals by pushing explicit JSON payloads into a Redis worker queue
  - added the shared payload contract on both sides: Laravel DTO + Python Pydantic envelope validator
  - formalized blacklist, amount-range, legal-form, CZ-NACE, and natural-person fallback rules in worker runtime code
- Key decisions:
  - Laravel keeps its own orchestration queue, but Python receives only clean JSON envelopes via `WORKER_TASK_QUEUE`
  - `sync_checkpoints` now track both queued and dispatched sync state for auditability
  - qualification rules live in code defaults first, not in ad-hoc spreadsheets or prompt text
- Approvals needed:
  - none for this chunk
- Next step:
  - implement the actual ISIR acquisition client and persist the first batch of source events/documents into `sync_checkpoints` and `case_documents`

## Entry 005 - Official ISIR acquisition and parsing contract

- Completed:
  - aligned the Python ISIR acquisition client with the official `IsirWsPublic.zip` WSDL/XSD bundle
  - switched the client to the real `getIsirWsPublicPosledniIdDataRequest` + `getIsirWsPublicIdDataRequest` request model
  - added a validated parsed-document contract for extracted claims, lead keys, claim fingerprints, and qualification snapshots
  - extended the worker CLI so both task payloads and parsed-document JSON artifacts can be validated locally
- Key decisions:
  - incremental ingestion now follows the official `latest-id + fetch-by-id` pattern instead of a guessed bulk event operation
  - final-report matching is normalized through the same diacritics-safe text normalization used by qualification rules
  - parsed document outputs are now treated as a strict typed contract, not as loose OCR/LLM JSON
- Approvals needed:
  - none for this chunk
- Next step:
  - import parsed documents into Laravel persistence and project creditor aggregates into `leads`

## Entry 006 - Laravel persistence import loop

- Completed:
  - implemented a Laravel import service that upserts insolvency cases, case documents, creditors, claims, and leads from a parsed-document payload
  - mirrored the approved qualification rules in the import path so imported leads immediately receive `qualified` or `rejected` state
  - added feature coverage for first import and idempotent re-import against the in-memory SQLite test database
- Key decisions:
  - debtor name falls back to `case_reference` when the current ISIR public event payload does not provide a real debtor label yet
  - lead aggregation is keyed by `spisova_znacka + veritel`, while claim rows remain fingerprinted lower-level records
  - re-import keeps business status stable for now and only increments `business_state_version` on material technical changes
- Approvals needed:
  - none for this chunk
- Next step:
  - add Google Sheets delivery and back-sync around the newly imported `leads` table

## Entry 007 - Worker runtime callback loop

- Completed:
  - added a protected Laravel internal API endpoint for parsed-document imports
  - added a Python orchestrator callback client and worker runtime executor for sync task payloads
  - wired worker execution to fetch ISIR events, prefilter section B final reports, and submit parsed-document payloads into Laravel
  - extended local env templates and sync script with `INTERNAL_API_TOKEN` and `ORCHESTRATOR_IMPORT_URL`
- Key decisions:
  - Laravel remains the only persistence writer; the worker now submits into a stable internal import seam instead of duplicating DB writes
  - runtime document submission currently uses a stub parsed-document builder so source documents can already persist before full claim extraction is finished
  - Graphify is worth keeping on the repo radar as optional developer tooling, but not as a version 1 runtime dependency
- Approvals needed:
  - none for this chunk
- Next step:
  - replace the stub parsed-document builder with real document download + extraction and then add Google Sheets sync

## Entry 008 - Google Sheets sync baseline

- Completed:
  - added a Laravel Google Sheets sync service with push and pull directions
  - implemented a row contract that preserves operator-owned columns during export
  - added a scheduled Artisan command for bidirectional sheet sync on Railway
- Key decisions:
  - export rewrites the worksheet from the DB source of truth but preserves existing `sheet_status`, `sheet_note`, and `sheet_owner` values per `lead_key`
  - reverse sync imports operator status changes back into `leads` and writes `LeadStatusHistory` entries with source `sheet`
  - Google auth stays service-account based and is resolved from the existing root env inventory
- Approvals needed:
  - none for this chunk
- Next step:
  - connect real claim extraction and then enrich imported creditors through ARES / Hlídac státu before qualification hardening

## Entry 009 - Creditor enrichment and requalification

- Completed:
  - added Laravel-side creditor enrichment through Hlídač státu exact-name lookup and ARES subject detail lookup
  - wired enrichment directly into parsed-document import so missing IČO can be resolved before qualification
  - added `creditors:enrich` for backfilling older creditors and re-qualifying dependent leads
  - scheduled the enrichment command and covered both inline import enrichment and backfill requalification with feature tests
- Key decisions:
  - Hlídač státu is now used primarily for exact-name IČO discovery through `FindCompanyId`, while ARES remains the authoritative source for legal form and CZ-NACE
  - qualification hardening stays inside Laravel import so the worker remains focused on acquisition and parsing
  - requalification updates `business_state_version` and `last_material_change_at` when enrichment materially changes a lead's qualification state
- Approvals needed:
  - none for this chunk
- Next step:
  - resolve live ISIR document retrieval against real public document responses and validate extraction on production-shaped samples

## Entry 010 - Graphify playbook and safe pause point

- Completed:
  - documented Graphify usage in `agents.md` as an approved repo-level navigation aid
  - added a pause and resume protocol so future sessions restart from `WORK_JOURNAL.md` and session `plan.md` instead of reconstructing context from chat
  - confirmed that today’s implementation state is persisted across repo docs and the session plan
- Key decisions:
  - Graphify stays strictly outside the runtime critical path and is used only for architecture and codebase navigation
  - a stop point is considered safe only after journal, plan, and directly affected docs are updated
- Approvals needed:
  - none for this chunk
- Next step:
  - continue with live ISIR public document retrieval validation and first production-shaped end-to-end prove-out

## Entry 011 - ISIR SOAP endpoint fallback hardening

- Completed:
  - added worker-level fallback endpoint strategy for ISIR SOAP calls with automatic failover and endpoint caching
  - added config support for `ISIR_PUBLIC_WS_FALLBACK_URLS` and propagated it through root env template, worker env template, orchestrator env template, and env sync script
  - covered failover behavior with a dedicated worker test and kept the full worker test suite green
- Key decisions:
  - primary endpoint remains configurable, but runtime now retries candidate endpoints in deterministic order and surfaces a single diagnostic error with per-endpoint failure details
  - default fallback candidates include both `:8443/.../IsirWsPublicService` and `.../IsirWsPublicService` variants
- Approvals needed:
  - none for this chunk
- Next step:
  - run a production-shaped retrieval probe from Railway network context and finalize the stable endpoint order for deployment env variables

## Entry 012 - Private GitHub repository and first push

- Completed:
  - initialized local git repository on branch `main` and created initial baseline commit
  - hardened root `.gitignore` to exclude Python cache/build artifacts from future commits
  - created private GitHub repo `MEVERIK-PRIME/ISIR-LEAD-TRACKER` and pushed `main` with tracking to `origin/main`
- Key decisions:
  - repository publishing was executed now so Railway GitHub deployment can proceed against a stable private remote
  - secret files remain untracked (`.env` still ignored; only `.env.example` is committed)
- Approvals needed:
  - none for this chunk
- Next step:
  - trigger Railway deployment from `main`, capture build/runtime logs, and run live ISIR retrieval + full E2E prove-out

## Entry 013 - Railway web container baseline

- Completed:
  - added Docker deployment baseline for Laravel orchestrator (`apps/orchestrator/Dockerfile`)
  - added service-local docker ignore file (`apps/orchestrator/.dockerignore`)
  - documented exact Railway answers and root-directory setup in repo README
- Key decisions:
  - Railway web service should be configured from monorepo path `apps/orchestrator` (not from repo root)
  - docker-compose is not required for the baseline Railway deploy path
- Approvals needed:
  - none for this chunk
- Next step:
  - deploy web service on Railway from `apps/orchestrator`, then capture logs and continue with worker/runtime prove-out

## Entry 014 - Railway build context fix for Laravel Dockerfile

- Completed:
  - fixed `apps/orchestrator/Dockerfile` to copy `composer.json` and `composer.lock` from monorepo path `apps/orchestrator/*`
  - adjusted Dockerfile working directory and source copy paths so build succeeds when Railway uses repo-root build context
  - updated README deployment note to keep Railway service build context at repo root
- Key decisions:
  - monorepo Dockerfile now assumes repo-root context and explicit path copies, which is robust against Railway rootDirectory mismatch
- Approvals needed:
  - none for this chunk
- Next step:
  - rerun Railway deploy and verify the container reaches healthy state on Laravel web service

## Entry 015 - Railway rootDirectory alignment

- Completed:
  - aligned `apps/orchestrator/Dockerfile` to build with context rooted in `apps/orchestrator` (`COPY composer.json composer.lock ./`, `COPY . .`)
  - updated README deployment note to enforce `Root Directory = apps/orchestrator`
- Key decisions:
  - Docker build is now explicitly tied to the service rootDirectory to remove ambiguity between repo-root and subdirectory contexts
- Approvals needed:
  - none for this chunk
- Next step:
  - rerun Railway deploy for web service and verify health, then continue with worker/runtime prove-out

## Entry 016 - Railway repo-root context fallback

- Completed:
  - switched `apps/orchestrator/Dockerfile` back to monorepo-prefixed copy paths (`COPY apps/orchestrator/...`) for repo-root build context
  - updated README deployment note to `rootDirectory = null` and `dockerfilePath = apps/orchestrator/Dockerfile`
- Key decisions:
  - Railway deployment is now standardized on repo-root context because service behavior consistently resolves paths there
- Approvals needed:
  - none for this chunk
- Next step:
  - redeploy service with repo-root context and verify Laravel container starts healthy

## Entry 017 - Railway Docker WORKDIR correction

- Completed:
  - corrected both Docker stages to use `WORKDIR /app` for repo-root build context
  - aligned vendor copy path to `COPY --from=vendor /app/vendor ./vendor`
- Key decisions:
  - keep monorepo-prefixed `COPY apps/orchestrator/...` paths with repo-root context, but avoid nested workdir to prevent path mismatch
- Approvals needed:
  - none for this chunk
- Next step:
  - rerun Railway deploy and verify startup reaches healthy web container state

## Entry 018 - Missing composer manifest tracked

- Completed:
  - identified root cause of Railway `/composer.json not found`: `apps/orchestrator/composer.json` existed locally but was excluded by root `.gitignore` (`*.json`)
  - narrowed ignore behavior by adding explicit allow rules for `apps/orchestrator/composer.json` and `apps/orchestrator/package.json`
  - staged both manifest files for repository tracking so Railway archive includes them
- Key decisions:
  - keep generic JSON ignore for secret-safety, but whitelist required build manifests for Laravel service
- Approvals needed:
  - none for this chunk
- Next step:
  - push manifest fix and rerun Railway deploy on current Dockerfile

## Entry 019 - Runtime hardening for Railway 502

- Completed:
  - simplified Laravel container start command to `php artisan serve` only (removed `config:clear` + `route:clear` pre-steps that can crash startup)
  - documented explicit Railway env baseline for `isir-lead-tracker` with direct variables (no `todo-api` references)
  - recorded current deployment reality check: public URL probe still returns 502 until env/deploy alignment is corrected
- Key decisions:
  - prioritize runtime reliability over pre-start cache cleanup in container entrypoint
  - treat `todo-api` linkage as non-v1 drift and keep Laravel service configuration self-contained
- Approvals needed:
  - pending async user confirmation; proceeded autonomously due offline mode
- Next step:
  - redeploy from latest commit, verify HTTP 200 on public URL, then continue live ISIR retrieval proof

## Entry 020 - eISIR compatibility defaults refresh

- Completed:
  - switched default `ISIR_PUBLIC_WS_URL` to `https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService` across worker settings, Laravel config, and all env templates
  - updated env sync script defaults to the same primary SOAP endpoint while keeping fallback list in place
  - aligned unit/runtime tests and README notes with the official web-service-first integration strategy (no HTML scraping)
- Key decisions:
  - keep SOAP/XML compatibility as primary integration path despite portal UI redesign
  - retain fallback endpoint chain for resilience when one public endpoint degrades
- Approvals needed:
  - none for this chunk
- Next step:
  - redeploy with refreshed defaults and continue live retrieval + runtime E2E proof

## Entry 021 - Live ISIR timeout hardening

- Completed:
  - reproduced live `ISIR_PUBLIC_WS` probe against `https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService` and confirmed successful SOAP response for `getIsirWsPublicPosledniIdDataRequest`
  - identified root cause of worker live-retrieval failure as timeout budget (`ISIR_TIMEOUT_SECONDS=30`) being too low for real endpoint latency
  - raised default timeout to `90` in worker settings and propagated `ISIR_TIMEOUT_SECONDS` through root/service env templates and env sync script
  - updated README runtime contract and Railway baseline env list with the timeout requirement
- Key decisions:
  - keep `:8443` endpoint as primary and treat higher timeout as baseline production stability guardrail
  - codify timeout in env contracts so Railway and local environments stay aligned
- Approvals needed:
  - none for this chunk
- Next step:
  - redeploy worker/runtime with `ISIR_TIMEOUT_SECONDS=90` and run full runtime E2E prove-out (worker task -> Laravel import -> DB/Sheets sync)

## Entry 022 - Runtime E2E prove-out closure

- Completed:
  - fixed ISIR SOAP event-by-id request payload so `idPodnetu` is sent as unqualified XML element (required by live WS schema validation)
  - fixed document URL normalization to preserve explicit `:8443` port, which resolved live document download 404 on final-report documents
  - exempted internal import endpoint `api/internal/isir/parsed-documents` from CSRF while keeping token auth (`X-Internal-Token`)
  - executed production-shaped local runtime flow with live ISIR event `79487007` and confirmed worker -> orchestrator import handoff succeeded (`submitted_documents=1`, `document_id=1`)
- Key decisions:
  - keep ISIR WS contract strict to observed runtime schema behavior (latest-id + event-by-id with unqualified checkpoint field)
  - keep internal ingest endpoint in web routing but enforce auth by shared token and explicit CSRF exception for machine-to-machine calls
- Approvals needed:
  - none for this chunk
- Next step:
  - propagate the same env baseline (`ISIR_TIMEOUT_SECONDS=90`, `INTERNAL_API_TOKEN`) to Railway worker/web services and run hosted E2E with Sheets-enabled credentials

## Entry 023 - Railway 500 mitigation for root and internal ingest

- Completed:
  - moved internal ingest endpoint registration from `routes/web.php` to new `routes/api.php` and enabled API routing in `bootstrap/app.php`
  - removed now-unneeded CSRF exception for `api/internal/isir/parsed-documents` because API middleware path is stateless
  - replaced root `/` page rendering with a lightweight JSON status response to avoid Blade/Vite runtime coupling on production startup
- Key decisions:
  - isolate machine-to-machine ingest from web/session middleware to prevent session-store-related 500 failures on Railway
  - keep `/up` health endpoint unchanged and make `/` deterministic for external probes
- Approvals needed:
  - none for this chunk
- Next step:
  - deploy this revision to Railway and verify `/` returns 200 plus `/api/internal/isir/parsed-documents` returns 401 for invalid token and 200 for valid token

## Entry 024 - Google Sheets push 400 fix

- Completed:
  - fixed Google Sheets API request construction so `valueInputOption=USER_ENTERED` is sent only on values update calls, not globally on all requests
  - removed the invalid query parameter from `values:clear` requests, which was causing HTTP 400 (`Unknown name "valueInputOption"`)
- Key decisions:
  - keep clear calls minimal and API-compliant; apply write options only where supported by the endpoint
- Approvals needed:
  - none for this chunk
- Next step:
  - deploy this revision and re-run `php artisan leads:sync-sheet --direction=push` in Railway shell

## Entry 025 - Google Sheets request hardening

- Completed:
  - replaced shared HTTP request helper in `GoogleSheetsClient` with explicit per-endpoint request construction
  - forced `valueInputOption=USER_ENTERED` into true query parameters via `send('PUT', ..., ['query' => ...])` for values update calls
  - changed clear payload to explicit empty JSON object to avoid endpoint schema ambiguity
- Key decisions:
  - remove implicit request-state behavior and rely on explicit request options per Google endpoint to avoid accidental payload pollution
- Approvals needed:
  - none for this chunk
- Next step:
  - redeploy and rerun `php artisan leads:sync-sheet --direction=push` to verify Google API 400 is cleared

## Entry 026 - Worker Redis consumer implementation

- Completed:
  - added a Redis queue consumer for the Python worker (`RedisQueueWorker`) that continuously reads `isir:tasks` using `BRPOP`
  - wired CLI flag `--consume-queue` into `isir-worker` so Railway worker service can run as a long-lived consumer process
  - added `redis` Python dependency and documented the new runtime entrypoint
- Key decisions:
  - keep queue consumption in worker process (not Laravel) so `EnqueueIsirSyncTask` remains enqueue-only and Python worker owns ISIR extraction + import cycle
- Approvals needed:
  - none for this chunk
- Next step:
  - deploy worker service with start command `isir-worker --consume-queue` and verify Redis queue length drains to 0 after dispatch

## Entry 027 - Worker runtime packaging fix

- Completed:
  - added `services/ingestion-worker/Dockerfile` to build a deterministic Python worker image that installs the package and exposes `isir-worker`
  - documented Railway worker deployment path (`dockerfilePath = services/ingestion-worker/Dockerfile`) for monorepo setup
- Key decisions:
  - prefer custom Dockerfile over implicit buildpacks so worker command availability does not depend on platform auto-detection
- Approvals needed:
  - none for this chunk
- Next step:
  - point Railway worker service to the new Dockerfile and redeploy; then verify `LLEN isir:tasks` decreases from 1 to 0
