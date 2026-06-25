# Divi 5 Deterministic Validator — Claude Instructions

## What this project is

A deterministic, pure-PHP validator for Divi 5 layout JSON. Proves the core bet: can we tell a valid Divi 5 layout from a broken one with zero AI inference?

## Hard constraints — do not violate

- **No AI/LLM calls anywhere in the validator.** Deterministic PHP only. Same input always yields the same verdict.
- **No Divi schema from memory.** Divi 4 used shortcodes; Divi 5 (late 2025) uses JSON. All schema knowledge must come from real exports via `make export-layouts`. Never invent structure.
- **No scope creep.** No admin UI, no MCP server, no React, no SaaS, no licensing logic.
- **PHP validator only.** Do not rewrite the core in Node or another language.

## Entry points

All actions go through `make`:

| Command | What it does |
|---|---|
| `make up` | Bootstrap environment (idempotent) |
| `make test` | Run PHPUnit suite — the green-light check |
| `make validate FILE=x` | Validate an arbitrary layout file |
| `make export-layouts` | Capture real Divi 5 JSON into fixtures/valid/ |
| `make clean` | Destroy volumes (prompts for confirmation) |

## Current state

- Phases 0+1 committed: scaffold, Docker env, validator skeleton, PHPUnit suite
- Phase 2 blocked on user dropping `divi/Divi.zip` and running `make up`
- Phases 3–6 pending real export data

## The one manual blocker

`divi/Divi.zip` must be placed by the user. Do not attempt to download it. If missing, `make up` stops with exact instructions.

## Schema rules

`src/SchemaRules.php` is the single source of truth for all Divi 5 schema knowledge. It starts as stubs. Populate it **only** from real `make export-layouts` output documented in `docs/SCHEMA.md`.

## Test verdict

`make test` exits 0 = MVP is proven. Any exit code != 0 = something is broken or incomplete.
