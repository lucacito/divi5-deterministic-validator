# Dogfood harness: measure what an assistant builds from the served guide

Status: APPROVED BY DELEGATION (owner 2026-10-21/22: "do #2 and #3, go autonomously"; every call below is a Ruling the owner may
overrule). Branch `feat/dogfood-and-html-styles` from main 7c1a679 (worktrees per task). No release, version bump or zip rebuild; the
validator and `wp-plugin/` are untouched (dev tooling only).

## Why

During the 4.14.0 pre-release work, six fresh assistants (and one edit run) were given ONLY the served guide for a style kit and built
Divi pages freehand. That "dogfood" found every real guide gap of the release (no worked shapes for rules and chips, an exact width rule
missing, a top-edge path, a root-wrapper contradiction, a frame-keeping rule, band names) while 2,700 pure tests found none. It was done by
hand with throwaway scripts. The routine is valuable and cheap to repeat, but each repeat today re-creates the scripts, and the judgement
"did the page follow the kit" was done by reading dumps. This harness makes the routine repeatable and the judgement measurable, so a
guide change is checked by evidence, not by feel.

## What it is (and is not)

Dev-only tooling in `scripts/` plus one doc, never shipped in `wp-plugin/`:
1. `scripts/dogfood-prepare.php <kit> <language> [--out dist/dogfood/<kit>-<language>] [--task freehand|edit]`: writes the served guide
   files (the default guide and the topics a kit page needs: `kit-rules`, `style-kit:<kit>`, `design-language:<language>`, `controls`, `forms`) and a
   `TASK.md` for a fresh assistant (the freehand brief used in the manual runs; for `--task edit`, a page built from shipped recipes under the
   kit and an edit brief). Pure PHP, deterministic, no network, no site.
2. `scripts/dogfood-score.php <page.html> <kit> [--language <id>] [--json]`: a static, deterministic conformance scorer. It parses the page's
   blocks and, for every block kind a kit styles, compares what the page wrote with what the kit adapter says (values, not colours of a
   freehand palette): buttons (radius, border width and style, fill type), dividers and group/column rules (line style and the width rule),
   tags and icon chips (control radius), framed groups (card or control radius, border width and style), accordion/toggle items, contact forms,
   images. Output: per category `checked/passed`, every failing check with block key and expected/observed, an overall score, and a list of
   blocks it could not classify. Exit 0 always (it measures); `--min <pct>` makes it exit 1 below a threshold.
3. `scripts/dogfood-render.cjs <page.html> [--kit ...]`: validates through the real `LayoutGate` on the dev site, saves ONE throwaway draft
   (title prefix `aied dogfood <run token>`, deleted by that exact prefix, `--clean-orphans`), screenshots at 1280 and 400 px, reports
   horizontal overflow and console errors, deletes the page. Uses the same throwaway-page library as the other gates (dev site only).
4. `docs/dogfood.md`: the routine, including the exact prompt to start a fresh assistant (Claude Code `Agent` tool, or pasting TASK.md into
   any assistant), how to read the score, what to do with a gap (guide wording, a probe, a pin), and the costs (tokens per run).
5. A regression corpus: `fixtures/dogfood/` holds the pages the assistants produced in the 4.14.0 work (kept small: the final pages only),
   each with its kit and language and the SCORE the scorer gives today, pinned by a test, so scorer changes are visible and the corpus
   documents what a real assistant gets right and wrong.

It does NOT call an LLM: the assistant step is run by the person (or by Claude Code's `Agent` tool) outside the scripts; the repo
stays free of model calls and keys. It is not part of `make test` (it needs an assistant) and not a release gate; it is the pre-release
routine for guide and adapter changes.

## Scorer design

- Expected values come from the REAL adapters for the named kit: `KitAdapters::patch()` for `divi/button` (`control`, `control-secondary`),
  `divi/group`/`divi/column` (`card`, `panel`, `rule`, `rule-strong`, `chip`), `divi/divider` (`rule`, `rule-strong`), `divi/icon` (`chip`),
  `divi/accordion`/`divi/toggle` (`item`, `item-rule`), `divi/contact-form` (`form`), and `StyleKits::get($kit)` for radii, border width and
  style. Colour roles are resolved with the language's palette (`RecipeTokens`) so a colour is judged as "one of the language's role colours
  for this surface" (a token-set membership check), never as an exact hex of a freehand choice.
- Classification of a block is conservative and structural (a `divi/button` is a button; a group with a radius and a frame is a framed group; a
  group with only a top side is a rule; an icon with a module background is a chip). A block the scorer cannot place is listed as
  "unclassified", never counted as passed or failed.
- Every check has a name and a tolerance (a width within 0px; a radius exact; a width rule judged against `KitAdapters::ruleWidth()`), and
  the scorer's own tests plant a pass and a fail for each check.
- The score is `passed / checked` per category and overall; categories with nothing to check say "n/a".

## Rulings

1. *Static scoring only, no pixel judgement.* A rendered look is judged by a person from the screenshots; the scorer judges attributes.
   Cost if wrong: a defect that only shows in pixels is missed (the render gate in `verify-kits` already covers kit-built output).
2. *Colours are checked as role-set membership, not exact values.* A freehand page picks its own hex; the guide says "swap each colour for
   its role". Cost if wrong: a wrong-but-valid colour passes.
3. *The corpus holds the final pages only (6 pages + the edited one), pinned scores included.* Cost: a few hundred KB in `fixtures/`; if the
   owner dislikes it, move the pages to `dist/` and keep only the pinned numbers.
4. *No model calls, no keys, no network in the repo.* Cost if wrong: the routine stays semi-manual.
5. *The harness reads the plugin only through its public classes (`StyleGuide`, `KitAdapters`, `StyleKits`, `SectionRecipes`, `RecipeTokens`);*
   it adds nothing to `wp-plugin/` and no test pins a score of a LIVE assistant run.

## Done means

Free suite green with the new scorer tests; the scorer reproduces, for the pinned corpus, the scores recorded in the Outcome; a planted
mutation of a conformant page (a radius, a border style, a rule width, a missing frame) is caught per category; `dogfood-prepare` output is
byte-identical on repeat; `dogfood-render` validates, renders and deletes a corpus page on the dev site with 0 orphan pages;
`docs/dogfood.md` exists and a fresh assistant run using only it (one run, a kit not in the corpus) is scored end to end.
