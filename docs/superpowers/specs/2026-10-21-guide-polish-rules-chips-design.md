# Guide polish for rules and chips (from the dogfood check)

Status: APPROVED BY DELEGATION (owner 2026-10-21: "do as you recommend" = polish this adapter's guide wording and gate coverage, then
release prep). Small change, one branch `feat/guide-polish-rules-chips` from main 3184eb7. No release, version bump or zip rebuild; the
validator is untouched.

## Why

The dogfood check (2026-10-21): two fresh assistants, given ONLY the served guide for a kit (hairline-dashed with tech-mono;
heavy-block with bold-vibrant), each built a freehand landing page (no recipes). Both pages passed `LayoutGate`, rendered on the real
Divi 5.15.1 without overflow, and visibly followed their kit (dashed lines, radii, borders). Both assistants reported the same gaps
in the guide for what the rules and chips adapter added:
1. The guide names the roles but gives no shapes: no path for a divider's line, for the TOP edge of a group or column, for an icon
   chip (radius, fill, glyph colour) or for the icon's glyph itself, and no exact width rule ("1-2px quiet, 2-4px strong" is a range:
   the 3px kit got 3px strong dividers instead of the 4px the adapter writes). Both put a divider module above each plan column instead
   of a column top edge, so no rule appears between the plan columns.
2. Nothing says what wins when a design language's prose (card corners on two corners, rotate-on-hover) disagrees with the kit.
3. On a contact form the submit's padding lives at `button.decoration.spacing` (the opposite of `divi/button`); the `controls` topic says
   that path is dead on a button, the forms paragraph does not say "on a contact form it is live".
4. Known from the final review: `controlsKitSentence()` is not conditional on the proven decisions; the composition topic's "One-side
   rule" section teaches a literal `2px solid` top rule without saying a kit decides width and style.
5. Known from the final review: the render gate does not render a rule on `surface` or `accent`, a quiet rule or a group/column rule on
   `panel`, an icon chip on an accent band, or a tag on any band but light.

## What changes

### Guide (Task 1; pure PHP, generated from `KitAdapters`, no new topic, no recipe change)
- `kit-rules` "Rules and chips": a generated "Writing a rule or chip yourself" passage: the exact width rule (quiet = the kit's border width,
  at least 1px and at most 2px; strong = the kit width plus 1px, at least 2px and at most 4px; a double never under 3px), the
  shapes with their real paths (divider: `divider.advanced.line.desktop.value` with `color`, `weight`, `style`; group/column top edge:
  `module.decoration.border.desktop.value.styles` with `all` at `0px` plus `top` carrying width, style and colour, and a group or
  column rule zeroes the other three sides; tag: `module.decoration.border.desktop.value` with the four radius corners and
  `styles.all`; icon chip: the same radius, `module.decoration.background.desktop.value.color`, `icon.advanced.color.desktop.value`,
  and the glyph at `icon.innerContent.desktop.value` with `unicode`, `type`, `weight`), and the line style follows the kit's border style.
  Every statement is conditional on `RULE_PROVEN` / `CHIP_PROVEN` exactly as the existing paragraph is, and the paths used are the ones
  the probe proved on the carrier (the shape registry lint covers them). Wording stays generic: the numbers come from the kit topic.
- `kit-rules` "Reusing a kit topic's blocks": one sentence: where a design language's prose and the kit disagree about corners, borders,
  shadow or hover, the kit wins (the kit is the decision table).
- The contact-forms paragraph (and `formsKitSentence()` if that is where the path is named): say that on a contact form the submit's
  padding, shadow and lift are at `button.decoration.*`, the opposite of a `divi/button`.
- `controlsKitSentence()` prints each half only for carriers whose decisions are proven (same pattern as the kit-rules paragraph).
- The composition topic's "One-side rule" section: one clause: under a style kit the width, style and colour come from the kit
  (the example's `2px solid` is the default look).
- Budgets (asserted): default guide 28,975 B exactly; `kit-rules` at most 7,000 B (the self-imposed 5,257 B pin is replaced by this
  pin on purpose; the real bound is 10,000 B); `controls` at most 9,500 B (existing bound); composition at most 10,000 B (existing bound);
  the 40 kit topics unchanged (largest 9,599 B); the catalog unchanged (8,444 B; 9,928 B at 50 kits); `topicParameterDescription()` 258
  characters; no `aied` token in any served topic; section-recipes.json untouched.

### Gate (Task 2; dev tooling only, no plugin code)
`verify-kits` additionally renders per kit/language cell: a `rule` and a `rule-strong` divider on the surface band and on the accent band,
a `rule` (quiet) top-rule group and a `rule-strong` one on the panel (an inverted card), an icon chip on an accent band, and a tag chip on
the dark photo band (`on:"dark"`). Same codes as today (`rule`, `rule-style|width|colour|contrast`, `chip`, `chip-radius|frame|fill|contrast`),
expectations from the resolved markup, pure tests first (count, expectation, mismatch, empty selection), synthetic Chromium departures for
each new slot. Pure test count rises from 100.

## Rulings
1. *Put the shapes in `kit-rules`, not in each kit topic.* The kit topics have about 400 B of headroom; `kit-rules` has about 4.7 KB below its
   real bound. Cost if wrong: an assistant that reads only a kit topic misses the shapes; the kit topic already points to `kit-rules`.
2. *The size pin of `kit-rules` is replaced, not relaxed silently:* from "at most 5,257 B" to "at most 7,000 B", with a comment. Cost if
   wrong: change one number.
3. *No recipe, kit or probe change.* Everything written is already proven. Cost if wrong: none.
4. *The pre-existing guide contradictions the assistants noticed (root `divi/placeholder` "required" but absent from the worked examples;
   `row` vs `row-inner` nesting; accordion parent paths without a worked example; dark languages and the band names) are out of scope here
   and are listed in the Outcome as findings for a separate guide pass.* Cost if wrong: a follow-up branch.

5. *Second pass (after the dogfood re-run on dotted-pill, where the rule and chip gaps were closed but three more showed up in all three runs):* (a) the guide says a root `divi/placeholder` is "required" while the validator accepts a layout that starts with `divi/placeholder` or directly with `divi/section` ("confirmed across real site exports") and every worked example starts with a section: reword to say both are fine (the default guide may grow to its existing ceiling of 29,108 B, re-pinned on purpose); (b) the tag clause says transparent fill and own padding; (c) the Contact forms paragraph names the field padding path; (d) the Items paragraph says where an accordion parent carries the kit look, if it fits. `kit-rules` pin raised from 7,000 B to 7,600 B (real bound 10,000 B). Cost if wrong: change numbers.

## Done means
Free suite green; `node scripts/test-verify-kits.cjs` green; smoke (5 kits x editorial, glass-dark) 0 FAIL; full `make verify-kits` 0 FAIL
(the gate changed, so it is re-run in full, about 3 h 15 min); the dogfood check re-run once on a third kit (dotted-pill with a pastel
language) with the revised guide: the same gaps must no longer be reported and rules between plan columns and the 4px strong rule must
appear; the narrowed recipe render and `verify-shapes` are NOT re-run (no recipe or shape changed; the guide is not rendered).

## Outcome (2026-10-21)

Built on branch `feat/guide-polish-rules-chips` (spec 1f7d15f, plan ca82351 from main 3184eb7) in three tasks, each reviewed; Task 1 and Task 3 needed one fix round each. NO release, version bump or zip rebuild; the validator, KitAdapters, data, goldens and recipes are untouched.

**The dogfood check.** Three fresh assistants (hairline-dashed with tech-mono, heavy-block with bold-vibrant, then dotted-pill with playful-pastel after Task 1) built a freehand landing page from the served guide only. All three pages passed `LayoutGate`, rendered on the real Divi 5.15.1 without overflow at 1280 and 400 px, and followed their kit visibly. First round: no worked shapes or exact width rule for rules, dividers, tags and icon chips (the 3px kit got 3px strong dividers instead of 4px), no top-edge path (both used a divider module above each plan column), the icon glyph path undocumented (one page got default arrow icons), no word on kit versus language. Re-run with the Task 1 guide: the page used column top edges with the exact widths (quiet 2px, strong 3px dotted for the 2px dotted kit) and the control radius on tags and icon chips; the remaining gaps (root `divi/placeholder` "required" against every worked example, tag fill and padding, field padding path, accordion paths) became Task 3.

**What was built.**
- T1 (guide): `kit-rules` "Rules and chips" gained the exact width rule (tested against `KitAdapters::ruleWidth()` for widths 0 to 4px and every shipped kit), the divider path, the group/column top-edge shape (all at 0px plus top, the other three sides zero, written on the block itself; a divider module is for a separate line), the tag and icon-chip shapes and the glyph path, "the kit wins over the language's prose for a block a kit styles", and the live contact-form submit path; `controlsKitSentence()` prints each half only for proven carriers; composition says a kit decides width, style and colour. Fix round: an overbroad "not a divider module above it" sentence reworded; kit-wins qualified.
- T2 (gate): per cell 16 rule slots (was 10) and 5 chip slots (was 2): dividers on surface and accent, top-rule groups on the panel, tags on surface and photo bands, an icon chip on an accent band; pure tests 100 -> 108; synthetic Chromium departures for each. One gate bug found and fixed on the way: a 40%-alpha tag frame on the photo band was judged at 3.0 while the PHP proof treats any alpha form as quiet (1.1).
- T3 (second pass): tag fill and padding, the field padding path, the accordion parent paths (all eight proven leaves), and the Structure line `(root wrapper, optional)` (the validator accepts a layout rooted at `divi/placeholder` or `divi/section`). Fix round: the first version grew the default guide by 120 B with an extra line; the owner's standing rule is not to grow it, so the extra line was dropped and the swap is size-neutral (8 characters each).

**Measured sizes** (served): `kit-rules` 5,255 -> 7,302 B (pin 7,600; bound 10,000), composition 8,589 -> 8,706 B; unchanged: default guide 28,975 B, `controls` 8,331, forms 6,474, catalog 8,444 (9,928 at 50 kits), largest kit topic 9,599, `topicParameterDescription()` 258 characters; `section-recipes.json` 662,636 B. Suites: free 2771 (was 2758), Pro 2169, `node scripts/test-verify-kits.cjs` 108 (was 100).

**Rulings.** 1 shapes in `kit-rules`, not in each kit topic (cost if wrong: an assistant reading only a kit topic misses them; the kit topics point to `kit-rules`). 2 the `kit-rules` pin moved 5,257 -> 7,000 -> 7,600 B on purpose (cost: one number). 3 no recipe, kit or probe change. 4 the other contradictions the assistants noticed (row versus row-inner nesting, dark languages and the band names, a pink band not among the bands) are out of scope. 5 second pass after the re-run; the default guide must not grow (the owner's standing rule overrode the plan's allowance of its 29,108 B ceiling).

**Live gates** (Divi 5.15.1, 2026-10-21, one run at a time). (1) Smoke before the full run: 5 kits x editorial, glass-dark: 30 PASS, 0 FAIL, 16 rules and 5 chips per cell measured. (2) The full `make verify-kits` at ff33d94: **263 PASS, 1 FAIL** over 88 cells (15:11 to 18:22, 3 h 11 min): `dotted-pill/editorial/900 panel-button` hover read the button as transparent with the unhovered text colour (two lines, one cell/width). That check is older than this branch (kit limits); the same cell passed in the previous full run, in the smoke, and in four re-runs (three of `--kits dotted-pill --languages editorial`: 3 PASS each; one of `--kits dotted-pill`: 6 PASS). Judged a hover-timing flake (the hover needs the pointer to land and a transition to finish; the page now carries more slots), not a regression; no code change. If it recurs, harden the settle wait of the panel-button hover. (3) The narrowed recipe render and `make verify-shapes` were not re-run: no recipe, kit or shape changed and the guide is not rendered. `--clean-orphans` found 0 orphan pages.

**Known limits and findings left.** The dogfood runs still reported, for a separate guide pass: the `row` versus `row-inner` nesting shown both ways; no word on which band a dark language counts as or on a tint outside the five bands; the accordion clause says "each item gets it" including the shadow although `item-rule` writes none; no toggle path clause; only two Font Awesome codes taught for the icon glyph; a tag that must shrink to its text has no taught recipe; the kit topics themselves show no rule or chip block (about 400 B of headroom).

## Follow-ups after the Outcome (2026-10-21, same day, merged to main 896da5f)

- **Hover flake hardened** (branch `feat/left-gate-hover`, 27292ff + b58fe6c): the card hover and the panel-button hover of the render gate retry ONCE when the hover state disagrees with the expectation (pointer moved off and on, wait doubled, capped at 2000 ms); a problem is reported only if the second attempt also disagrees, with both observations in the text (`retried once: first ..., second ...`); rest problems are never retried; hover overflow of both attempts is united (a final-review style finding: the first version judged overflow on the second attempt only); persistent defects (a button that never hovers, a card that never lifts, a wrong hover colour) still fail. Pure tests 108 -> 114; the Chromium departures cover a late hover passing after the retry and each persistent defect still failing (that file now takes about 9 minutes). Worst case added time per defective slot about 12.3 s. Trade-off: a defect that appears on only some hovers would pass. Live: smoke (5 kits x editorial, glass-dark) 30 PASS, 0 FAIL on the merged head; the full 3 h run was NOT repeated (a retry can only turn a failing hover into a pass after a second look, it cannot fail a cell that passed).
- **Items wording** (branch `feat/left-guide-items`, 9c9f6ce): the accordion clause now attributes frame, radius and shadow to the `item` role only and states the rule row's bottom rule, and a toggle clause names the toggle leaves (the closed title is `closedTitle`; "the same paths except" the closed title and open icon). `kit-rules` 7,302 -> 7,864 B (pin raised to 7,900 B on purpose, real bound 10,000); every other served topic byte-identical. Findings left: "its shadow" reads as unconditional (the adapter writes a shadow for 32 of 160 `item` patches; "when the kit has one" fits in 36 B), and the role sentences are per role, not per carrier (unreachable with the shipped symmetric map).
- **Release draft** `docs/release-4.14.0-draft.md` (changelog entry, upgrade notice, owner checklist): NOT applied.
- Still open from the dogfood runs: `row` versus `row-inner` nesting taught both ways, band names for dark languages and tints, only two Font Awesome glyph codes taught, a tag that must shrink to its text.
