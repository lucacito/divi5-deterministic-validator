Audience: prospective buyers and technically minded agencies (public page on divi5lab.com once the owner approves); also reviewers
Status: DRAFT, not published. Every number cites the repo file it comes from; re-check each before publishing (counts move with each release)
Date: 2026-10-25

<!--
Owner notes (remove before publishing):
- Paths in [brackets] are repo paths, for checking. On the public page, replace them with plain words or links to a public
  copy; do not publish internal paths if you prefer not to.
- The design switch (section 4, last paragraph, and the Pro suite count in section 7) is built but UNRELEASED: publish those
  lines only with the Pro release that ships it.
- Several numbers describe work merged to main AFTER 4.14.0 (the render-fatal guards, carriers/type/rhythm adapters, the
  wording gaps). Publish them only once 4.15.0 (free) is live, or label them "in the next release".
-->

# What we check, and how we know

An AI editor is only as safe as the thing that stands between the AI and your database. This page explains what
JHMG AI Editor for Divi 5 checks, where every rule comes from, and how we test that the rules are right. Nothing here is a
promise that a page can never fail; the limits are listed at the end.

## 1. The gate: a deterministic checker

Every layout an AI writes through the plugin (`update_page_layout`, `edit_page_content`, `create_page`, `html_to_divi` with
`create`, and every Pro layout write) goes through the same gate before it is saved [CLAUDE.md, "WordPress.org review answered
2026-10-02": "any future layout write path ... must go through `LayoutGate::check()`"].

- **No AI inside.** The checker is plain PHP. Same input, same verdict, every time [CLAUDE.md, "Hard constraints"].
- **It refuses with a reason.** A refused save returns exact violation codes (for example an unknown block, a block in a place
  Divi does not allow it, a value of the wrong type), so the assistant can correct itself [CLAUDE.md, "Architecture"].
- **It knows 89 Divi 5 block types** and which may sit inside which [CLAUDE.md, "Current state"; `src/SchemaRules.php`].
- **It refuses custom code** in a layout: custom-CSS fields, Divi Code modules, script/style/iframe markup, `javascript:` links
  and event attributes [`wp-plugin/readme.txt`, FAQ "Can the AI add custom CSS..."].
- **The one exception is on purpose:** restoring your own earlier version bypasses the gate, and the result says whether that
  version passes [CLAUDE.md, "Undo"].

## 2. Where the rules come from

We never write a Divi rule from memory [CLAUDE.md, "Hard constraints"]. A rule enters the checker only from one of these:

1. **Real Divi 5 exports.** Pages built in the real Divi builder and exported (`make export-layouts`, documented in
   [`docs/SCHEMA.md`]). The repo holds 47 valid layout fixtures and 18 invalid ones that must be refused
   [`fixtures/valid/`, `fixtures/invalid/`, counted 2026-10-25].
2. **Divi's own module definitions, proven by a live render.** A module is accepted only after it rendered on the real Divi
   version; the evidence is generated, never hand-edited [`docs/module-verification-5.14.json`,
   `docs/module-verification-5.15.json`, `src/VerifiedModules.php`].
3. **Real builder exports for new Divi features.** Divi 5.15 components and Slots were accepted only after real 5.15.1 builder
   exports [`docs/components-evidence-5.15.md`, `fixtures/valid/component-*-5.15.json`].
4. **Live render evidence for page-breaking values** (next section).

## 3. The render-fatal sweep (Divi 5.15.1, 2026-10-25)

A blind test assistant once built a page that the checker accepted and Divi answered with an HTTP 500 for the whole page: a
testimonial's `company` was a plain string where Divi expects an object [CLAUDE.md, "Dogfood round 2"]. So we tested every
content setting of every module, on purpose, in both directions [`docs/render-fatal-sweep.md`]:

- **115** modules defined; **62** have content keys; **133** keys (60 object, 66 scalar, 7 unclassified), read from Divi's own
  generated files [`docs/render-fatal-sweep.md`, "Counts"].
- Each key was set to a string, an object, `null` and `[]` on a real baseline block: **517** results
  [`fixtures/shapes/render-fatals.json`, `entries`].
- **92 crashed Divi**, on **80** distinct keys: 21 strings where Divi needs an object, 59 objects where it needs a string,
  12 empty arrays [`docs/render-fatal-sweep.md`; `fixtures/shapes/render-fatals.json`, `counts`].
- The in-process verdict was checked against the real front end in **9 of 9** HTTP cases (500 where it crashed, 200 where not)
  [`docs/render-fatal-sweep.md`, "Calibration"].
- Two runs give byte-identical results [`docs/render-fatal-sweep.md`, "Method"].
- **All 80 keys are now guarded:** the checker refuses a string on the 21 object keys and an object or array on the 59 scalar
  keys. The guard list is generated from the evidence file, and no real export or recipe was refused by it
  [`src/VerifiedContentTypes.php`, `scripts/promote-render-fatals.php`; CLAUDE.md, "Dogfood round 2"].

A related live finding: a style value with an unclosed quote or bracket (for example `calc(10px`) made every section on a
Divi 5.15.1 page lose its styling. The checker refuses it; 0 of 5,332 real style values were unclosed, so the rule refuses
nothing real [CLAUDE.md, "Divi 5.15 Components and the 4.12.0 additions"; `make verify-style-values`].

## 4. Design consistency by construction, with proofs

The plugin does not ask the AI to "be consistent". It gives the AI a design language (10) and a style kit (40), and adapters
turn them into the exact Divi settings for each kind of block [CLAUDE.md, "Style kits", "Card adapter", "Accordion adapter",
"Forms adapter", "Rules and chips adapter", "Type adapter", "Rhythm adapter", "Carriers adapter"].

**Every setting taught was rendered first.** A registry of 606 attribute paths, each proven by a render on the real Divi in a
real browser (PASS, DEAD, css-only), lints every path the guides and recipes teach
[`fixtures/shapes/verified-shapes.json`, `docs/shape-evidence-5.15.1.md`].

**Contrast proofs, zero exceptions, no baseline file** (a frozen failure would be a shipped unreadable element):

| What | Cells checked | Source |
|---|---|---|
| Cards and panels: 40 kits x 10 languages x 2 roles x 4 bands x rest/hover | 3,200 | CLAUDE.md "Card adapter" |
| Inverted and accent grounds | 6,400 | CLAUDE.md "Kit limits" |
| Buttons inside inverted panels | 1,600 | CLAUDE.md "Kit limits" |
| Accordion and toggle items | 3,200 + 1,600 | CLAUDE.md "Accordion adapter" |
| Contact forms (fields, submit, captcha) | 3,200 | CLAUDE.md "Forms adapter" |
| Rules (dividers, top rules) | 4,000 | CLAUDE.md "Rules and chips adapter" |
| Tags and icon chips | 1,600 | CLAUDE.md "Rules and chips adapter" |
| The featured pricing plan stands out from its neighbour | 400 of 400 | CLAUDE.md "Kit limits" |

Buttons were proven on every kit x language x role x surface x state, with zero exceptions after the role table was amended
when the first run found 58 failing cells [CLAUDE.md, "Style kits"]. Where a colour is decorative and cannot reach 3:1 in all
ten languages (the accent rule), the floor is the measured minimum of the shipped library (2.3) and is never lowered
[CLAUDE.md, "Carriers adapter"].

**Live render gate.** `make verify-kits` builds a throwaway page per kit/language cell (88 cells) and measures borders, radii,
fills, labels, padding, shadows, real hovers and clicks, overflow and painted contrast at 1280, 900 and 400 px: **264 PASS,
0 FAIL** on 2026-10-25 [CLAUDE.md, "Wording gaps"].

**Recipes.** 71 section recipes, each validated in the test suite; all 71 rendered on the real Divi at three widths under the
default look and the languages: **426 PASS, 0 FAIL** [CLAUDE.md, "Rules and chips adapter", guide polish pre-release round].

**Switching a finished page to another design (Pro `switch_design`; built, unreleased: ships with the next Pro release).**
A page built under one design and switched to another is compared with the same page built directly under the new design.
Between the ten default designs, **6,549 of 7,100** switched recipes are byte-identical to the direct build; the rest differ
in four named classes whose counts are pinned (458, 48, 36 and 9; none unclassified). Across all 40 kits, **4,800 of 4,800**
and **4,480 of 4,480** kit-layer switches are byte-identical. All 913 sampled outputs pass the layout gate, and all 710
sampled pairs match the independent dogfood scorer check for check
[`docs/superpowers/specs/2026-10-25-pro-design-switch-design.md`, Outcome]. Live, on the real Divi 5.15.1: **62 PASS, 0 FAIL**
(the saved switched page equals the directly built page, the preview token is single-use, a page changed after the preview
is skipped, undo restores the page exactly, and both pages render the same at 1280 and 400 px) [`make verify-pro-switch-design`].
What this does not prove: pages built by hand switch only partially in the default mode (matching blocks are switched, the
rest are listed); colours and fonts the tool does not recognise are left; and the tool needs the free plugin release that
ships the design adapters. We found no competitor that states a role-based, previewed, undoable restyle of an existing page
[`docs/launch/competitor-refresh-2026-10-25.md`]; that is what we found, not a proof that none exists.

## 5. Blind assistants: can a fresh AI follow the guide?

We give a fresh assistant ONLY the guide the plugin serves and a brief, then score its page statically against what the real
adapters would write (39 named checks) and render it on the real Divi [`docs/dogfood.md`].

| Round | Pages | Score | Render | Source |
|---|---|---|---|---|
| First live run (double-frame x corporate-trust) | 1 | 89/93 (95.7%) | clean, 0 overflow | CLAUDE.md "Dogfood harness" |
| Round 2 | 3 | 95.3%, 100%, 99.5% | 2 clean, **1 HTTP 500** (led to the sweep in section 3) | CLAUDE.md "Dogfood round 2" |
| Round 2 re-run on the fixed guide | 2 | 100%, 100% | both clean | CLAUDE.md "Dogfood round 2" |
| Round 3 | 2 | 251/251, 209/209 | both clean | CLAUDE.md "Wording gaps" |

Read these honestly: an assistant run is a sample, not a proof [`docs/dogfood.md`, "When"], and a 100% score does not cover
everything the adapters decide (label colours, shadows, hover states are not scored; the screenshots are the check for those)
[`docs/dogfood.md`, "Blind spots"].

## 6. The HTML converter

`html_to_divi` turns HTML into a Divi 5 layout with a report. Checks prove no copy or link was dropped. 8,000 random hostile
pages over 40 seeds pass; the style reader was compared with a real Chromium on 3,248 cases (56,491 values, all equal); the
live gate over 41 sample pages: 615 PASS, 0 FAIL on Divi 5.15.1 [CLAUDE.md, "`html_to_divi`"].

## 7. The test suites and live gates

- Free plugin suite: 6,365 tests; Pro suite: 2,367 (2,169 before the design switch) [CLAUDE.md, "Wording gaps", "Design switch (Pro)"]. `make test` exits 0 only when everything passes.
- Live gates on the real Divi 5.15.1 (each creates throwaway content and deletes it): history, dry run, global styles, page
  title, OAuth, Test connection, components, custom fields, Divi Projects, style values, guide shapes, HTML conversion, kits,
  shapes, recipes, and the Pro gates for settings, Theme Builder, site tools, REST, build_site, custom fields, WooCommerce
  products and (unreleased) the design switch [CLAUDE.md, "Entry points"; "Current state" for the 4.13.0 run].

## What this does not prove

- **Structure, not looks.** The checker proves a page is built in a way Divi accepts. It does not judge design.
- **The sweep covered desktop content values.** Tablet/phone/hover values, inner sub-keys, decoration attributes, five modules
  without a real example page, and crashes that kill PHP itself were not tested [`docs/render-fatal-sweep.md`, "Limits"].
- **"Ok" in the sweep means "no crash"**, not "renders correctly" [`docs/render-fatal-sweep.md`, "Limits"].
- **Evidence is per Divi version.** Each new Divi release is diffed and re-gated (`make divi-currency`) before we claim it.
- **Third-party Divi modules are unknown to the checker** and are refused.
- **A check of how the saved page renders is not shipped yet.** A spike shows it can see empty modules and render errors
  [`docs/post-save-render-check-evidence-5.14.md`]; it is in development.
