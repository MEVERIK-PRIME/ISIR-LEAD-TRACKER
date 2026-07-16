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
