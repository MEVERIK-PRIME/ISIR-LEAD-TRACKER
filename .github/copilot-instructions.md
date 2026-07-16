# Copilot instructions for ISIR-LEAD-TRACKER

This repository is built in an agent-first mode with explicit human approval gates.

## Core operating rules

- prioritize delivery of version 1 exactly as confirmed in planning
- keep the user informed from the active Copilot environment
- do not widen scope to future scaling work
- optimize for a working end-to-end system within 12-24 hours

## Approval gates

Always stop and ask before finalizing:

- stack changes
- product-rule changes
- penetration-key changes
- lead identity or deduplication changes
- operator workflow changes
- cost-increasing infrastructure changes

## Working journal

After each completed logical chunk, append a short entry to `WORK_JOURNAL.md`.

Each entry should state:

- what changed
- why it mattered
- whether approvals were needed
- what comes next

## Version 1 constraints

Build only the confirmed workflow:

- ISIR_PUBLIC_WS ingestion
- Hlidac statu support in MVP
- section B / konkurs / final report filtering
- secured and unsecured claim extraction
- penetration-key qualification
- Postgres persistence
- Redis queue orchestration
- Google Sheets sync and reverse status sync
- Railway deployment baseline

## Secrets

- never commit real credentials
- use `.env` locally
- use Railway Variables in hosted environments
- prefer `GOOGLE_CREDS_JSON` for Sheets auth, with split Google fields as fallback
