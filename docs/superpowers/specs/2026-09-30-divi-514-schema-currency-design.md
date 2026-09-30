# 3.4.0 — Divi 5.14 schema currency

Status: draft for review · Date: 2026-09-30 · Target release: AI Editor for Divi 5 3.4.0

## Goal

Make the validator recognise every module Divi 5.14 ships, so AI edits to real
customer pages are not rejected for containing a legitimate module, without ever
weakening the deterministic gate.

## Why

The validator whitelists 58 block types. Divi 5.14 defines 115 modules in
`includes/builder-5/visual-builder/packages/module-library/src/components/*/module.json`.
Comparing the two (2026-09-30) shows **58 modules Divi ships that the validator
rejects** (Portfolio, Post Slider, Video Slider, Lottie, SVG, Link, Tooltip,
Dropdown, Charts, Table of Contents, Comments, all `fullwidth-*`, Contact Form 7,
Gravity Forms, Payment Button, Post Filter, and ~30 WooCommerce template modules).
A page using any of them cannot be edited through the plugin. `divi/placeholder`
is whitelisted but has no `module.json`; its status must be confirmed, not assumed.

## Amended grounding rule (needs owner approval, recorded in CLAUDE.md)

Old: all schema knowledge comes from real exports.
New: schema knowledge comes from **real exports, or from the shipped Divi theme's
own module definitions, in either case verified by a live render on the real Divi
version**. Nothing from memory. Every added block type must have a recorded
verification result.

## Approach: derive, then verify, then promote

1. **Derive** (`scripts/derive-schema.php`): read every `module.json` from a Divi
   theme directory and emit a proposal per module: name, `category`
   (module / fullwidth-module / other), `childModuleName`, `childrenName`, and
   whether it has children. Output is a reviewable JSON file; nothing is applied
   automatically.
2. **Verify** (`scripts/verify-modules.sh`, runs against the Docker WP): for each
   proposed module, create a scratch page via WP-CLI with minimal block markup at
   the proposed placement (leaf in a column; fullwidth module directly in a
   section; parent with its child module), then fetch the front end and require:
   HTTP 200, no PHP error/notice in the response, and the module's own markup
   present. Scratch pages are deleted afterwards. Result per module:
   `pass | fail | needs-real-export`.
3. **Promote**: only `pass` modules are added to `SchemaRules`
   (LEAF_MODULES / STRUCTURAL_BLOCKS / ALLOWED_CHILDREN). `needs-real-export`
   modules stay rejected and are listed in `docs/SCHEMA.md` as known gaps.
4. **Keep in sync**: mirror `src/` to `wp-plugin/validator/` (CLAUDE.md rule).

## Scope

Tier 1 (this release, ~15 general-purpose modules): Portfolio, Post Slider,
Video Slider (+ item), Lottie, SVG, Link, Tooltip, Dropdown, Charts, Table of
Contents, Comments, Fullwidth set (image, code, map, menu, portfolio, slider,
post-slider, post-title, post-content), Contact Form 7, Gravity Forms, Payment
Button, Post Filter (+ item), Map Pin, Signup Custom Field.

Tier 2 (only if Tier 1 is clean): WooCommerce template modules
(`divi/woocommerce-*`), which live in Theme Builder templates, not normal pages.

Out of scope: attribute-level validation of the new modules beyond what the
existing generic rules already enforce; Theme Builder authoring tools (3.5+).

## Also in 3.4.0

- `make schema-gap`: re-runs step 1 and prints modules Divi ships that the
  validator lacks, so the next Divi release surfaces as a diff.
- Admin notice / status line showing installed Divi version vs tested version.
- New fixtures for each promoted module (the scratch-page markup that passed
  verification, stamped with the Divi version).
- Re-validate all 17 section recipes and all fixtures (must stay green).
- Plugin readme changelog, version bump to 3.4.0, rebuild `ai-editor-for-divi-5.zip`,
  Plugin Check clean under the real slug.

## Testing

- PHPUnit: each promoted module has a fixture that validates; each structural
  addition has a nesting test (valid child accepted, invalid child rejected).
- Existing suite stays green (100 tests today).
- Verification harness output is committed as `docs/module-verification-5.14.json`
  so the evidence for every addition is reviewable.
- Determinism: validating the same fixture twice gives identical verdicts
  (existing property, unchanged).

## Risks

- A module can render 200 while still being placed where the builder would not
  allow it. Mitigation: placement is taken from `category`, and any module whose
  placement is not unambiguous is marked `needs-real-export` rather than guessed.
- Scratch-page renders depend on optional plugins (Gravity Forms, CF7,
  WooCommerce). Modules whose plugin is absent are marked `needs-real-export`,
  not `pass`.
- Divi may change `module.json` shape in future releases; the derive script fails
  loudly on unknown shapes instead of guessing.
