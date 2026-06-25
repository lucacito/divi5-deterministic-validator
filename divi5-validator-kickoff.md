# Divi 5 Deterministic Validator — MVP Kickoff

> Paste this whole file to Claude Code as the project brief. Work through it top to bottom. Stop and ask me only when a step is blocked on something I alone can provide (flagged below). Otherwise proceed without checking in.

---

## 1. What we are building and why

We are testing the single riskiest assumption behind a future product: **can we deterministically tell a valid native Divi 5 layout apart from a broken one, with zero AI inference?**

If yes, that validator becomes the moat for a tool that lets AI agents edit Divi sites without mangling layouts. If no, nothing else matters. So we build the validator and the ground-truth environment around it, and nothing else.

**Out of scope for this MVP. Do not build any of these:**
- No admin UI, no settings page, no React.
- No MCP server, no Abilities API registration, no agent integration.
- No AI/LLM calls anywhere. The validator is pure deterministic PHP. If you are tempted to "use AI to check the layout," stop. That defeats the entire point.
- No SaaS, no licensing, no pricing logic.

We are proving one thing. Resist scope creep hard.

---

## 2. Definition of done

This MVP is complete when all of the following are true and I can verify each with one command:

1. `make up` brings up a working WordPress + Divi 5 + MariaDB environment from a cold start.
2. Divi 5 is installed and active (from the zip I provide, see section 4).
3. At least one **real** Divi 5 layout has been exported to JSON and saved as a canonical fixture. This is captured from a live Divi 5 install, not hand-written, not pulled from your training data.
4. `docs/SCHEMA.md` documents the actual observed Divi 5 layout JSON structure, derived empirically from the export.
5. A PHP validator exists that takes a Divi 5 layout JSON file and returns pass/fail plus a structured list of violations.
6. A PHPUnit suite covers known-good and deliberately-broken fixtures. The validator passes every good fixture and rejects every bad one, naming the specific violation.
7. `make test` runs the full suite and exits non-zero on any failure.
8. `make validate FILE=path/to/layout.json` runs the validator against an arbitrary file and prints a human-readable report.
9. `README.md` documents setup and every command in under a page.

---

## 3. Hard technical decisions (made, do not re-litigate)

- **Validator language: PHP.** It will live inside a WordPress plugin later and must run where the real Divi data lives. This also matches the deterministic-PHP approach already used in my SitePort project. Do not write the core validator in Node.
- **Tests: PHPUnit.** Composer-managed.
- **Containers: Docker Compose** with three services: `wordpress`, `db` (MariaDB), `wpcli`.
- **Database: MariaDB, not MySQL.** I have hit MariaDB volume conflicts before, so name the volume explicitly and uniquely to this project, and document how to nuke it cleanly.
- **Env file: `.env`** at project root. I have been bitten by `.env` naming and load-order before, so make the compose file read it explicitly and fail loudly with a clear message if a required var is missing.
- **Orchestration: a `Makefile`** as the single entry point. Every action I take is a `make` target.

---

## 4. The one thing only I can provide (BLOCKER)

Divi 5 is commercial software behind a paywall. You cannot download it. I will provide it. Set the project up so this is the **only** manual thing I ever do:

- Create a `divi/` directory at project root, gitignored.
- I will drop `Divi.zip` (the Divi 5 theme zip from my Elegant Themes account) into `divi/`.
- If `divi/Divi.zip` is missing when the environment boots, **stop and print exact instructions** telling me to put it there. Do not try to fetch it. Do not substitute Divi 4 or a free theme.

Tell me clearly, in the README and in the boot output, the exact filename and path you expect. Make this idiot-proof so I do not have to read code to figure out what you want.

---

## 5. Critical warning about Divi 5

**Your training data is almost certainly wrong about Divi's format.** Divi 4 stored layouts as shortcodes (`[et_pb_section]...`). Divi 5 (shipped late 2025) uses a completely different JSON-based data structure. If you generate a "Divi layout" from memory, you will produce Divi 4 shortcode soup and the whole exercise is worthless.

**Rule: the only source of truth for the schema is a real export from the running Divi 5 install.** Build the env first, capture a real layout, then read what is actually there. Document what you observe, not what you expect.

Things to discover empirically and write into `docs/SCHEMA.md` (these are hypotheses to verify, not facts):
- How Divi 5 stores a page layout (post content? post meta? a dedicated table? a JSON blob?).
- The structural hierarchy (sections, rows, columns, modules, or whatever Divi 5 actually calls them).
- The shape of a single module: its type identifier, its attribute groups, where text/content lives (e.g. nested `innerContent` style structures), responsive variants.
- Which attribute groups are universal vs module-specific.
- Where things fatal when malformed (e.g. a field that expects an object but receives a scalar string).

---

## 6. Build plan (work in this order, commit after each phase)

### Phase 0 — Scaffold
- Initialize git. Add a sensible `.gitignore` (ignore `divi/`, `wp-data/`, db volume, vendor, `.env`).
- Create the directory layout:
  ```
  /docker-compose.yml
  /Makefile
  /.env.example
  /composer.json
  /divi/            (gitignored, I drop Divi.zip here)
  /src/             (the validator)
  /tests/           (PHPUnit)
  /fixtures/
     /valid/        (real exported layouts, known good)
     /invalid/      (deliberately broken layouts)
  /docs/SCHEMA.md
  /scripts/         (bootstrap, export, self-test helpers)
  /README.md
  ```
- Write `.env.example` with every required variable documented inline. The real `.env` is created by the bootstrap if missing.

### Phase 1 — Environment up
- `docker-compose.yml` with `wordpress`, `db` (MariaDB, named volume), and a `wpcli` service.
- A `scripts/bootstrap.sh` invoked by `make up` that:
  1. Checks for `divi/Divi.zip`. If missing, prints the exact instruction from section 4 and exits non-zero.
  2. Creates `.env` from `.env.example` if absent.
  3. Brings up containers, waits for the DB to be healthy (proper healthcheck, not a blind sleep).
  4. Installs WordPress via WP-CLI (non-interactive: site title, admin user, password from `.env`).
  5. Installs and activates Divi 5 from `divi/Divi.zip` via WP-CLI.
  6. Prints the local URL and admin login at the end.
- Make it idempotent. Running `make up` twice must not corrupt state.
- Add `make down` (stop) and `make clean` (stop and remove volumes, with a confirmation prompt) that reliably clears the MariaDB volume so I never get stuck in a dirty-volume state again.

### Phase 2 — Capture ground truth
- Get at least one real Divi 5 layout into the system and export it to JSON. Prefer an automated path over me clicking in the builder. Options, in order of preference:
  1. Import a Divi prebuilt/premade layout via WP-CLI or the Divi Library import, then read its stored representation.
  2. If Divi exposes a layout export (Divi Library export to JSON), script that.
  3. Only if neither works, give me a 3-line instruction to build a trivial page in the builder once, then you script the export.
- Save the raw export to `fixtures/valid/` with a descriptive name.
- Capture 2 to 3 different layouts if you can (a simple one, a richer one with nested modules) so the schema doc is not based on a single sample.

### Phase 3 — Schema discovery
- Write `docs/SCHEMA.md` describing the real observed structure, with annotated excerpts from the actual export. Call out the render-critical pieces: what must be present and well-formed for Divi to render the layout without fataling or silently dropping content.

### Phase 4 — The validator
- In `src/`, build a deterministic validator. Input: a Divi 5 layout JSON file (or string). Output: a result object with `valid: bool` and `violations: []`, each violation having a code, a human message, and a path to the offending node.
- Check categories (refine against what you learned in Phase 3):
  1. **Well-formedness**: valid JSON, decodes to the expected top-level shape.
  2. **Schema conformance**: every node is a known structural type; required attribute groups present; no scalar-where-object-expected.
  3. **Hierarchy integrity**: nesting follows Divi 5's real rules (no module where a section is expected, etc.).
  4. **Referential integrity**: any internal IDs/references resolve; no orphans.
  5. **Render-critical attributes**: the fields you identified in Phase 3 as required for a clean render are present and correctly typed.
- Pure functions, no side effects, no network, no AI. Deterministic: same input always yields the same verdict.

### Phase 5 — Self-tests
- Build the negative fixtures in `fixtures/invalid/` by taking a real valid export and breaking it in one specific way each. At minimum:
  - Invalid/unknown module type.
  - Missing required attribute group.
  - Malformed nesting (wrong node type in a slot).
  - A field that should be an object set to a scalar string (the deep-merge fatal case).
  - Truncated/corrupt JSON.
  - Orphaned internal reference.
- Each invalid fixture gets a PHPUnit test asserting the validator rejects it **with the specific expected violation code**, not just a generic failure.
- Each valid fixture gets a test asserting it passes clean.
- Add a `scripts/self-test.sh` wired to `make test` that runs the whole suite and exits non-zero on any failure. This is the green-light check for the whole MVP.

### Phase 6 — Wrap
- `README.md`: prerequisites, the one manual step (drop Divi.zip), and every `make` command. Keep it tight.
- Final commit.

---

## 7. Working agreement for Claude Code

- Commit in logical chunks with clear messages, one per phase minimum.
- Prefer scripted/automated paths over anything that makes me click in a GUI. The goal is least effort from me.
- When you hit the Divi-zip blocker or genuinely cannot proceed without my input, stop and tell me the single specific thing you need. Do not guess around a paywall.
- Do not pad the codebase. No speculative abstractions, no config for features we are not building. This is a focused proof, not a framework.
- If reality contradicts this brief (e.g. Divi 5 stores layouts somewhere you did not expect), follow reality and note the deviation in `docs/SCHEMA.md`. Do not force the real format to match my assumptions.
- Set your edit/permission mode so you can run the build steps without prompting me on every file write.

## 8. The test that decides everything

When you are done, the verdict I care about is one sentence: **does `make test` pass, proving the validator accepts every real layout and rejects every broken one by name?** If yes, the core bet holds and we build the product on top of it. If you discover it cannot be done deterministically, that is also a valid and important result. Say so plainly and explain exactly where determinism breaks down.
