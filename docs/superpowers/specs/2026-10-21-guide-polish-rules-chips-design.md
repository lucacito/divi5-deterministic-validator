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
