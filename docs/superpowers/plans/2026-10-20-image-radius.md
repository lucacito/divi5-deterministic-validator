# Image Radius Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax.

**Goal:** 11 recipe images take the kit's media radius through the existing token; the guide and the render gate know it.

**Architecture:** No adapter. Recipes switch literal corners to `{{aied:kit:radius-media}}`; a classification test pins every image radius; `KitAdapters::MEDIA_RADIUS_BLOCKS` drives one generated guide paragraph; the render gate measures one image per cell.

**Tech Stack:** PHP 8 (`wp-plugin/src/`), PHPUnit, Node + Playwright gate scripts.

**Spec:** `docs/superpowers/specs/2026-10-20-image-radius-design.md`.

## Global Constraints

- Branch `feat/image-radius`; no push, release or version bump; root zip, `src/`, `wp-plugin/validator/` untouched; no new MCP tool, REST route or OpenAPI operation; no new guide topic name; recipes keep tokens only (no hex); never weaken or delete a test; goldens (button, card, item, form) byte-identical; no live probe or render by implementers (the controller runs every live gate); commit only your own files by explicit path.
- Sizes: kit-rules <= 4,301 + 400 B, default guide exactly 28,975 B, every kit topic unchanged in size, `topicParameterDescription()` 258 chars, `section-recipes.json` must not grow (now 671,687 B; bound 716,800).

## Review Focus

1. A circle, pill or flush image changed by mistake, or a kit-like image left literal. 2. The token written at a path other than `image.decoration.border.{bp}.value.radius` or together with leftover corner keys (merge). 3. Guide text still saying the media radius is "recorded but no module takes it". 4. A gate that passes when the image is not rounded (a null expectation must not skip).

### Task 1: Recipes, classification test, guide paragraph (pure PHP)

**Files:** Modify `wp-plugin/data/section-recipes.json`, `wp-plugin/src/KitAdapters.php` (new const `MEDIA_RADIUS_BLOCKS`), `wp-plugin/src/StyleGuide.php` (`kitRules()` Images paragraph; remove the "recorded in the kit but no module takes it yet" clause when the const is non-empty), `tests/StyleGuideKitRulesTest.php`, `tests/RecipeLibraryTest.php` or a new `tests/ImageRadiusMigrationTest.php`, `tests/KitResolverTest.php` (token resolves for all 40 kits at the image path).
**Interfaces:** Produces `KitAdapters::MEDIA_RADIUS_BLOCKS` (list of block names) read by the guide and Task 2.
- [ ] Failing tests first: `testEveryImageRadiusIsATokenACircleOrFlush` (classification of all 25 radii; BESPOKE_IMAGES = [] cap 0), `testTheElevenKitLikeImagesCarryTheMediaToken` (names and indexes from the spec), `testTheTokenResolvesToTheKitsMediaRadiusForEveryKit` (40 kits x the 11 images: four corners equal `kit.radius.media`, no `sync` key left), `testKitRulesHasAnImagesParagraphGeneratedFromTheConst` (planted empty const keeps the old "recorded" sentence, non-empty drops it), size budgets as assertions.
- [ ] Implement; `vendor/bin/phpunit`; `make test-pro`; commit.

### Task 2: Render gate (scripts only)

**Files:** Modify `scripts/lib/kit-checks.cjs`, `scripts/verify-kits.cjs`, `scripts/lib/kit-sheet-offline.php`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `Makefile` comment; create `scripts/lib/kit-media-run.cjs` if the card/form runners do (mirror `kit-form-run.cjs`).
- [ ] Sheet: one `divi/image` (an image-pack token `{{aied:image:...}}` as the recipes use) carrying `"radius":"{{aied:kit:radius-media}}"` per cell on the light band; expectation read from the RESOLVED markup (four corners); code `media-radius` (computed border radius of the rendered picture or its wrapper, whichever the render shows, per corner), plus clipping and overflow at 1280/900/400; a run that checked no image fails (`FAIL media:`); show RED then GREEN for the pure tests (`node scripts/test-verify-kits.cjs`) and a synthetic Chromium departure per failure kind in `scripts/test-kit-card-run.cjs`. Smoke command for the controller in the report.
- [ ] Commit.

### Task 3: Docs

Spec Outcome (measured), CLAUDE.md "Image radius" paragraph and the verify-kits row, the Known-limits sentences "card media radius and image radius are still not applied" corrected, `docs/token-usage.md` via `php scripts/measure-tokens.php`. Tasks 1 and 2 are independent and may run in parallel worktrees.

## Gates (controller, live, serial)

`node scripts/verify-kits.cjs --kits quiet-solid,heavy-block --languages editorial,glass-dark` smoke; full `make verify-kits`; narrowed `verify-recipes --names <the migrated recipes + every marker recipe + 10 guard>`; `make verify-shapes`; final review.
