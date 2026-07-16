# Agent operating rules for ISIR-LEAD-TRACKER

## Mission

Deliver version 1 of ISIR-LEAD-TRACKER as a fully functional end-to-end system within the confirmed scope and time window.

## Default execution mode

- build primarily agentically
- keep the user informed from this environment
- move fast on implementation details that do not change product direction
- stop for approval on critical decisions only

## Critical decisions that require explicit approval

The agent must ask for approval before finalizing any of the following:

- architecture changes that contradict the confirmed stack
- changes to version 1 scope
- changes to penetration-key rules
- changes to lead deduplication or lead identity
- changes to operator workflow semantics in Google Sheets
- production deployment decisions that increase cost or risk materially
- replacing a confirmed third-party provider

## Changes the agent may do autonomously

- scaffolding project structure
- writing documentation
- implementing standard config and integration layers
- adding tests
- refining internal code organization
- preparing deployment files and runbooks

## Graphify usage rule

Graphify is approved in this repository as an **agent and developer navigation aid**, not as a runtime dependency.

- use Graphify when the task is exploratory:
  - tracing parser flow across Python and Laravel
  - mapping ISIR, ARES, Hlídac státu, Sheets, and Railway integration seams
  - understanding cross-file ownership before a larger refactor
- do not add Graphify to production services, queues, deployment images, or version 1 runtime paths
- prefer Graphify outputs for orientation, then implement changes directly in the real code paths

Recommended local usage:

1. build or refresh the graph on the repo root
2. query the graph for architecture or call-flow questions
3. use the result as navigation support, not as source-of-truth over the code

Practical commands:

- `graphify . --no-viz`
- `graphify . --update --no-viz`
- `graphify query "How does the ISIR document pipeline reach Google Sheets?"`
- `graphify query "Which files own creditor enrichment and qualification?"`

Project-scoped references for Graphify live in:

- `.copilot/skills/graphify/SKILL.md`
- `.copilot/skills/graphify/references/*`

## Working journal rule

After every completed logical chunk, the agent must append a short entry to `WORK_JOURNAL.md` with:

- timestamp or milestone label
- what was completed
- key decisions made
- blockers or approvals needed
- next intended step

## Pause and resume protocol

Before stopping work for a pause or overnight handoff, the agent must:

1. update `WORK_JOURNAL.md` with the latest completed logical chunk
2. refresh the session `plan.md` if the open priorities or technical state changed materially
3. update repo docs when runtime behavior, commands, or operator workflow changed
4. leave the next concrete step explicit, so the next session can resume without re-discovery

When resuming, start from:

1. the latest `WORK_JOURNAL.md` entry
2. the open priorities in session `plan.md`
3. only then perform new investigation or code changes

## Implementation priorities

1. repository and process foundation
2. Laravel orchestrator scaffold
3. Python worker scaffold
4. data contracts and database schema
5. integrations
6. end-to-end validation
7. Railway readiness

## Quality bar

- no secrets in repo
- explicit config for all external services
- no hidden magic in sync rules
- auditable lead lifecycle
- operator-visible outcomes
- version 1 optimized for reliability over elegance
