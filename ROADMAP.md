# ROADMAP - Version 1

## Goal

Ship a functional end-to-end version 1 of ISIR-LEAD-TRACKER within 12-24 hours.

## Phase 0 - Repository foundation

- create repo documentation
- establish agent operating rules
- define approval gates
- create working journal
- establish local and Railway config baseline

## Phase 1 - Laravel orchestrator

- initialize Laravel app
- configure Postgres and Redis
- add queue and command entrypoints
- define application config for external services
- prepare lead orchestration flows

## Phase 2 - Python worker

- initialize Python service
- add settings management
- add connectors for ISIR and Hlidac statu
- prepare document fetch, text extraction, and structured output pipeline

## Phase 3 - Shared domain model

- model case, document, creditor, claim, lead, sync run, and audit entities
- implement deduplication by `spisova_znacka + veritel`
- define lead status transitions

## Phase 4 - Integrations

- ISIR_PUBLIC_WS incremental sync
- backfill mode
- Hlidac statu enrichment or fallback
- ARES validation
- Gemini and Groq structured extraction
- Google Sheets sync in both directions

## Phase 5 - Penetration key

- section B / konkurs filter
- robust final-report matching using `konec*`
- 300k-600k amount filter
- blacklist and ARES or fallback filtering
- significant-change detection for lead status resets

## Phase 6 - End-to-end flow

- ingest event
- fetch document
- parse claims
- qualify lead
- persist results
- sync to Sheets
- pull back operator state

## Phase 7 - Railway readiness

- deployment config
- cron wiring
- environment variable checklist
- runbook for first production execution

## Optional developer tooling

- Graphify is installed project-scoped for repo knowledge-graph navigation
- keep Graphify out of the runtime critical path for version 1
- use it only as a local/agent exploration accelerator once the core flow is stable

## Acceptance target for version 1

Version 1 is done when one full production-shaped workflow works from ingestion to qualified lead visibility in Google Sheets with persistent storage and repeatable reruns.
