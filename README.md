# Divi 5 Deterministic Validator

Deterministic, pure-PHP validator for Divi 5 layout JSON. No AI. No inference.
Same input always yields the same verdict.

---

## Prerequisites

- Docker Desktop (or Docker Engine + Compose v2)
- PHP 8.1+ and Composer (for running tests locally)
- `make`
- A Divi 5 theme zip from your Elegant Themes account (see below)

---

## The one manual step

Divi 5 is commercial software. You must provide it:

1. Log in to your Elegant Themes account at elegantthemes.com
2. Download the Divi theme zip
3. Place it at:

   ```
   divi/Divi.zip
   ```

   **Exact filename required: `Divi.zip` (capital D, no version number)**

If this file is missing, `make up` will stop and print these same instructions.

---

## Setup

```bash
make up
```

This is idempotent. It:
1. Checks for `divi/Divi.zip` and stops with instructions if missing
2. Creates `.env` from `.env.example` if absent
3. Starts Docker containers (WordPress + MariaDB)
4. Installs WordPress via WP-CLI
5. Installs and activates Divi 5
6. Prints the local URL and admin credentials

---

## Commands

| Command | What it does |
|---|---|
| `make up` | Bootstrap the full environment (idempotent) |
| `make down` | Stop containers (data preserved) |
| `make clean` | Stop and delete all volumes (destructive — prompts for confirmation) |
| `make export-layouts` | Capture real Divi 5 layout JSON into `fixtures/valid/` |
| `make test` | Run the full PHPUnit suite; exits non-zero on any failure |
| `make validate FILE=path/to/layout.json` | Validate an arbitrary layout file |
| `make install` | Install Composer dependencies locally |

---

## Workflow after first `make up`

```bash
# 1. Capture real layout data (ground truth)
make export-layouts

# 2. Review fixtures/valid/ and docs/SCHEMA.md, fill in SchemaRules.php

# 3. Run tests
make test
```

---

## Cleaning up

To fully reset (including database):

```bash
make clean
# Type 'yes' when prompted
make up
```

To nuke Docker volumes manually:

```bash
docker volume rm divi5val_db divi5val_wp
```

---

## Project structure

```
docker-compose.yml   — three services: wordpress, db (MariaDB), wpcli
Makefile             — all commands live here
.env.example         — required environment variables (copy to .env)
src/                 — the validator (pure PHP, no dependencies beyond PHP 8.1)
  Validator.php      — main entry point
  ValidationResult.php
  Violation.php
  SchemaRules.php    — schema constants (updated after make export-layouts)
tests/               — PHPUnit test suite
fixtures/
  valid/             — real Divi 5 exports (captured by make export-layouts)
  invalid/           — deliberately broken layouts for negative testing
docs/SCHEMA.md       — empirical Divi 5 schema documentation
scripts/
  bootstrap.sh       — invoked by make up
  export-layouts.sh  — invoked by make export-layouts
  self-test.sh       — invoked by make test
  validate.php       — invoked by make validate
```

---

## The verdict

`make test` exits 0 if every valid fixture passes and every invalid fixture
is rejected with the specific expected violation code. That green light is the
MVP definition-of-done.
