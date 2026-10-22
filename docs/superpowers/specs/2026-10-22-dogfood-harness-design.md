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

## Outcome

Status: built and measured. The corpus scores and the live run below are the measured facts (Task 6 filled them in from the controller's runs).

### What was built

Plan `docs/superpowers/plans/2026-10-22-dogfood-harness.md`. Dev tooling only; `src/`, `wp-plugin/` and the tracked zip are untouched.
`tests/Support/Dogfood/` (`Prepare`, `Scorer`, `Page`, `Expected`, `Mutate`), `scripts/dogfood-prepare.php`, `scripts/dogfood-score.php`,
`scripts/dogfood-render.cjs` with `scripts/lib/dogfood-render.cjs` and `scripts/test-dogfood-render.cjs`, the TASK templates in
`scripts/dogfood/`, the corpus in `fixtures/dogfood/`, `docs/dogfood.md`, the Makefile targets `dogfood-prepare`, `dogfood-score` and
`dogfood-render`. Two amendments to the text above: the scorer exits 2 (not 0) on a usage error or a page that does not parse, because
that is not a measurement (exit 0 after measuring, 1 only with `--min`); `dogfood-render` takes no `--kit` option (the spec line
`dogfood-render.cjs <page.html> [--kit ...]` is dropped).

### Decisions (D1 to D10)

- D1 Seven categories in fixed order: `button`, `rule`, `chip`, `framed`, `item`, `form`, `image`; checks are named `<category>.<check>` (`Scorer::CHECKS`, 33).
- D2 A block several roles could produce is judged against the best-matching role (most passing checks; a tie goes to the first in `KitAdapters::APPLIED` order, grounds after the plain card roles); the chosen role is reported per block.
- D3 Bands of a freehand page come from a reverse palette lookup in the fixed priority `bg, surface, surface-glass, accent, text, overlay, inverse` (mapped to light, surface, accent, panel, dark); a background image or an overlay gradient is dark; anything else is `unknown` (judged against the union of the role's bands); the nearest painting ancestor wins.
- D4 Colours are role-set membership (normalised, alpha to two decimals); lengths are compared in px with tolerance 0; an absent value fails except where a check says otherwise.
- D5 Scorer exit codes: 0 after measuring, 1 only with `--min`, 2 on a usage error or an unparseable page (amended from "exit 0 always").
- D6 `--language` defaults to the language whose own kit is the given kit, else `editorial`; the output names the language used.
- D7 Default prepare output `dist/dogfood/<kit>-<language>` (freehand) or `...-edit` (edit); `prepare.json` records the file sizes (TASK.md excluded, its length depends on the out path) and `guide_bytes`.
- D8 TASK templates live in `scripts/dogfood/TASK-freehand.md` and `TASK-edit.md` (the prototype texts, `%KIT%` / `%LANGUAGE%` / `%OUT%` placeholders).
- D9 `dogfood-render`: output dir `dist/dogfood/<name>/` (the page's parent directory name, plus `-<stem>` when the file is not `page.html`); exit 1 on an invalid layout, overflow at either width, a console error, a PHP error text or an incomplete cleanup; the plan's `--kit` label is dropped (ruling 7).
- D10 The fresh run of the last task uses kit `double-frame` under `corporate-trust` (not in the corpus); its score is recorded here only, never pinned.

### Spec issues found (with the controller's ruling)

1. Palette hex collisions make bands depend on the fixed priority of D3; accepted.
2. Framed groups are judged against the card radius, tags against the control radius; accepted.
3. A card with neither a frame nor a radius is unclassified (not scored); accepted.
4. Images pass the media radius or 0px, shaped images are unclassified; accepted.
5. A rule on a framed block: only width and style are judged; accepted.
6. Edit runs are scored on the whole page; a base-aware mode is a possible follow-up, not built.
7. `dogfood-render` has no `--kit` option; ruling 7.
8. The edit base's images stay `https://example.com/...`; documented in `docs/dogfood.md`.
9. Exit 2 for usage errors and an unparseable page; ruling 9.
10. The edit task's default output gets `-edit`; ruling 10.

### Review minors folded in with Task 5

`Prepare::write()` refreshes the files of an earlier run (a directory is ours when it holds a valid `prepare.json`: that file, TASK.md and the names in its old `files` map) and refuses (nothing written) any other target that exists with different content, or a directory in the way; `dogfood-prepare.php` catches that, rejects an
option value that starts with `--` and exits 2; `prepare.json` no longer records TASK.md's size; the TASK templates' bias guards are
pinned by a test; `dogfood-render` truncates its printed error, fails the summary after an internal error, clears stale `shot-*.png` in a
reused output directory and guards `browser.close()`; the sweep of the shared throwaway-pages library is covered by a pure test with a
fake `wp`. The wp-eval transport of the render script limits a page to about 95 KB.

### Corpus scores (measured in Task 3)

Scores are passed/checked checks, overall, as the scorer reproduces them (pinned by `DogfoodCorpusTest`, data in `fixtures/dogfood/corpus.json`):

| Page | Score |
|---|---|
| hairline-dashed | 114/119 |
| heavy-block | 98/104 |
| dotted-pill | 80/81 |
| underlined-label | 85/91 |
| soft-rounded | 93/98 |
| gilt-hairline | 101/101 |
| edit base (recipe-built, glass-edge / glass-dark) | 77/77 |
| edited page | 111/111 |

Each page's own failures: hairline-dashed `chip.frame-colour` x5 (the tags are in the text colour instead of the border colour);
underlined-label `chip.frame` x5 (1px against the kit's 3px) and `framed.frame` x1 (a 4px top rule where this underline kit's card draws a
3px bottom underline); the failures of the other pages are in `fixtures/dogfood/corpus.json`.

### Live render (Task 6)

2026-10-22, run by the controller. `make dogfood-render` validated the page through the real `LayoutGate`, rendered it at 1280 and 400 px
with 0 overflow and no console error, and deleted the throwaway draft; `--clean-orphans` found 0.

### Fresh run (Task 6)

2026-10-22, controller. `make dogfood-prepare KIT=double-frame LANGUAGE=corporate-trust` (a kit not in the corpus) wrote the guide files and
TASK.md. A fresh sonnet assistant given exactly the documented prompt built the page (7 sections, valid, one h1). `make dogfood-score` gave
button 19/19, rule 15/15, chip 24/28, framed 20/20, item 4/4, form 7/7, image n/a, overall 89/93 (95.7%).

The 4 misses are one finding: the tag frame is 3px where the adapter writes the kit's 4px double. The guide's tag clause says "1px at least"
and never says a tag takes the kit's border width. A guide gap the harness found, to be fixed in a follow-up wording task.

The assistant also reported (not scored): kit rule widths for a double kit were ambiguous (the quiet range 1-2px against "a double is never
under 3px"; it chose 3px quiet / 4px strong, which matches the adapter); "which rule weight where" is not stated; chips and tags have no
sizing guidance.
