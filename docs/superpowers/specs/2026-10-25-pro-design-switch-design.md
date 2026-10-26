# Pro design switch: `switch_design` restyles a page to another design language and style kit (design)

Date: 2026-10-25. Status: built and merged to local main (Tasks 0-9), NOT released; see the Outcome. Plan: `docs/superpowers/plans/2026-10-25-pro-design-switch.md`.
Owner decision (2026-10-25): revenue first. The Pro add-on needs features agencies and freelancers pay for and
competitors do not have. This one turns the design system the free plugin already ships (10 design languages, 40 style
kits, the kit, type and rhythm adapters) into a one-call, deterministic restyle of an EXISTING page.

## 1. Goal and success

An assistant (or the owner through it) can say "make this page luxe with the gilt-hairline kit" and get, in one
previewed, validated, undoable call, the page as it would have been built under that design: palette colours swapped by
role, fonts swapped by role, every kit-driven block (buttons, cards and panels, rules and dividers, chips, accordion and
toggle items, contact forms, image radius, testimonial / blurb / counter carriers) re-styled by the target kit, headline
type (weight, letter spacing, capitals) and section rhythm re-applied by the target language. No AI runs: the same page
and the same arguments always give the same result.

Success, measured (section 9):
- A page built from the recipe library under (L1, K1) and switched to (L2, K2) is BYTE-IDENTICAL to the same recipes built
  directly under (L2, K2), or differs only in the named difference classes D1-D5 (section 6), whose counts are pinned.
- Switching a page to its own design is a no-op; switching twice to the same target is a no-op; A -> B -> A returns the
  original wherever both directions are clean.
- Every saved result passes `LayoutGate::check($new, $stored)` (validator + `CodePolicy`); nothing is saved without a
  preview token; one `undo_batch` puts every page back byte for byte.
- The independent dogfood scorer (`tests/Support/Dogfood/Scorer.php`) gives the switched page the same report under the
  target design as the direct build gets.

## 2. Market claim and honest limits

Claim (Pro docs and divi5lab.com only; the free plugin and its guides never mention it):

> "Switch the whole look of a Divi 5 page or Project built with JHMG AI Editor (colours, fonts, headline type, section
> spacing and every kit-styled button, card, rule, chip, accordion, form and image) to any of 10 design languages and
> 40 style kits in one previewed, validated, undoable call. Deterministic: no AI tokens, no custom CSS, the same answer
> every time."

Competitors (docs/competitor-benchmark-2026-10.md): Oxygen 6.2's MCP edition styles through existing selectors and
variables; Divi AI, AI Engine, WPVibe and the generic agents restyle by REGENERATING content with a model, which is
non-deterministic and can break a layout; DiviOps manages presets and variables. None offers a role-based, validated,
undoable restyle of an existing page. Divi's own global colours restyle only the values that reference them.

Honest limits (they go into the Pro README and the tool description, not hidden):
- Full fidelity is for pages built with the plugin's recipes and kit markers (the target market). A hand-written page
  switches the blocks that are exactly kit-conformant; the others are listed (`drifted`, `unclassified`) and, by default,
  left as they are. Measured on the dogfood corpus (section 9.5), a freehand page keeps a visibly mixed look in `leave`
  mode; `conform` mode (section 5.6, opt-in) re-styles the near-conformant blocks too.
- A colour that is in no role of the source palette (a literal hex, a Divi global colour `gcid-...`, a preset) is left and
  counted. Divi global colours, module presets and variables are never changed (the Pro `manage_global_*` tools do that).
- Text, images, links, structure and layout are never changed. A target language's prose advice (its preferred layouts,
  hero composition, motion, shadow prose) is not applied: only what an adapter decides is switched.
- Theme Builder headers, footers and templates are out of scope (pages and Divi Projects only).
- ChatGPT cannot call it: MCP and REST only (the 30-operation importer budget is used up, section 7).
- It needs the free plugin version that ships the type, rhythm and carriers adapters (unreleased today, section 11).

## 3. What exists and what the brief assumed (verified in code, 2026-10-25, main 6d8ae9a)

- Served recipes are resolved by `SectionRecipes::resolveMarkup($markup, $language, $kit)`: `LanguageResolver` (type and
  rhythm markers) -> `KitResolver` (kit markers and whole-value kit tokens) -> `RecipeTokens` (colour and font tokens).
  Markers are removed and tokens are replaced, so a SAVED page carries only hard values: the role of a value is lost.
- Recipe bodies (`wp-plugin/data/section-recipes.json`, 71 recipes) hold 860 colour tokens (all whole values, at keys
  `color` (781) and `value` (79), no alpha form) and 628 font tokens (all at key `family`); alpha colour tokens exist only
  inside kit patches; 11 `{{aied:kit:radius-media}}` image tokens; 405 markers (kit, type, rhythm).
- Value collisions inside ONE language (the reverse lookup colour -> role is NOT exact, contrary to the brief):

  | Language | Colour roles with the same value | Font roles with the same family | Type triples equal |
  |---|---|---|---|
  | editorial | surface=accent-text | body=eyebrow | - |
  | glass-dark | bg=accent-text | display=body=eyebrow | - |
  | organic-warm | - | body=eyebrow | - |
  | tech-mono | - | body=eyebrow | - |
  | playful-pastel | surface=inverse, text=accent-text | display=eyebrow | - |
  | corporate-trust | bg=accent-text | display=body=eyebrow | display=title |
  | soft-saas | surface=accent-text | body=eyebrow | - |
  | bold-vibrant | surface=accent-text=inverse, text=border | body=eyebrow | - |
  | brutalist-flat | surface=inverse, text=accent-text=border | - | - |
  | luxe | - | body=eyebrow | - |

- Kit patches are recoverable: a kit-driven block holds the resolved patch of exactly one (block, role, on, ground) for
  the source kit and language (the merge-trap tests prove marked recipe blocks keep no literal at those keys).
- The dogfood scorer is a lenient JUDGE (role-set membership for colours, union of bands when a band is unknown, numeric
  tolerance, best role by passing-check count). It does not invert a page. It is kept as an independent oracle for the
  switch, not promoted (section 4).
- Pro write template: `FindReplaceSite` (one batch of `post_content` items through `HistoryService::write`, a
  `PreviewToken` of id => content hash, `LayoutGate::check($new, $stored)`, `LayoutDiff` summary, skip reasons
  `invalid_result` / `not_in_preview` / `changed_since_preview` / `save_failed`). The item kind is `post_content`
  (`PostContentKind`), not `page_content` as the brief says.

## 4. Where the code lives (decision)

All switch code is in the Pro add-on, namespace `AiEditorDivi5\Pro\Design`, directory `pro-addon/src/Design/`. The free
plugin gets NO change: `pro-addon/README.md`'s Contract says "It must not need any change to the free plugin beyond the
documented hooks", and a pure library in the free zip whose only caller is the paid add-on is exactly the kind of code a
WordPress.org reviewer reads as a locked feature (Guideline 5). The engine calls these public free classes and pins
every one in `pro-addon/tests/FreeApiCompatTest.php`: `SectionRecipes::{resolveMarkup, rawMarkup, names}`,
`DesignLanguages::{get, ids}`, `StyleKits::{get, ids, problems, valueToken}`, `RecipeTokens::encodeJson`,
`BlockAttrs::{BLOCK, decode}`, `LanguageAdapters::{APPLIED, TYPE_HEADING_LEVELS}`, `KitResolver::leftovers`,
`LanguageResolver::leftovers`, plus the existing `EditTarget`, `LayoutGate`, `DryRun`, `LayoutDiff`, `StyleReferences`,
`HistoryService`. Five PRIVATE `KitResolver` constants (`ROLE_BLOCKS`, `ROLE_SURFACES`, `ROLE_GROUNDS`,
`GROUND_SURFACES`, `GROUND_BLOCKS`) are copied into `Design\MarkerSpace` and pinned equal by a test that reads the free
values with `ReflectionClassConstant` (a free role added later is then simply not recovered: left and reported, the safe
failure, until the copy is refreshed).

The scorer stays dev tooling in `tests/Support/Dogfood/`. Lockstep is a PROOF, not shared code: the switch's output is
scored by the scorer for the target design and must match the direct build's report (section 9.4), and the band rule
the engine ports from `Page::bandOf` is pinned equal to the scorer's on every block of the library and the corpus. Two
independent implementations agreeing is a stronger proof than one shared one.

## 5. The engine (pure, deterministic)

`DesignSwitch::run(string $page, Design $from, Design $to, string $mode): SwitchResult`, where `Design` is a validated
(language array, kit array, ids) pair. It UNRESOLVES the page under the source design into recipe-like markup (markers
and tokens back in place), then resolves it with the free pipeline under the target design:
`SectionRecipes::resolveMarkup(unresolve($page, $from), $to->language, $to->kit)`. Re-application order, merge rules and
every patch are therefore the free plugin's own, by construction (language step, then kit step, then tokens; the patch
wins over a literal at the same key; lists are replaced).

### 5.1 Scan and bands (`PageScan`)

One pass over every block comment (opening with or without attributes, self-closing, closing) gives the tree in document
order with byte spans. The band each block sits on is the scorer's rule D3 (`Page::bandOf`): the nearest painting
ancestor's background, reverse-looked-up in the SOURCE palette with the fixed priority bg, surface, surface-glass,
accent, text, overlay, inverse (`light`, `surface`, `surface`, `accent`, `panel`, `dark`, `dark`); a background image or
an overlay gradient stop is `dark`; any other paint `unknown`; no painting ancestor `light`.

### 5.2 Marker recovery (`MarkerRecovery`)

For each block whose name has kit roles (`MarkerSpace`), every candidate marker (role, on, ground) is resolved once as a
one-block markup under the source design (`Expectations`, memoised; the same construction as the scorer's `Expected`)
and compared LEAF BY LEAF with the block's attributes (a list is one leaf). A candidate matches when every leaf of its
resolved patch is present with the same JSON value. The match with the most leaves wins; a tie between candidates whose
TARGET patches differ is broken by the block's band (`on` equal to the band; a button on `surface` reads `light`, the
adapter's own substitution), then reported as `assumed` when the band is `unknown`. The matched leaves are removed (and
every object their removal empties is pruned), the marker is put back as a top-level `aied`. Language markers the same
way for `divi/heading`, `divi/text` and `divi/section` (`LanguageAdapters::APPLIED`), with the heading level breaking a
display/title tie (`TYPE_HEADING_LEVELS`; corporate-trust has equal display and title triples). A block never gets both
(the kit and language carriers are disjoint). An image whose radius object equals the source kit's
`valueToken('radius-media')` gets the whole-value token back.

A block of a kit carrier type that matches no candidate fully, but has a candidate matching at least half its leaves, is
`drifted` (listed with the leaves that differ); one with decoration at kit paths but no such candidate is
`unclassified`. Both are left byte-identical in `leave` mode.

### 5.3 Colour and font roles (`RoleReverse`, `ContextTable`)

Every string leaf at key `color`, `value` or `family` whose value is in the source palette (colours compared as
normalised RGBA keys, so `#FFF` = `#ffffff`) or font set becomes a token again:
- one role has that value: exact;
- several roles have it (the collision table above): the CONTEXT decides. `ContextTable` is derived at run time from the
  installed free recipes (`SectionRecipes::rawMarkup`), resolved under a synthetic palette in which every role has its own
  colour, so the band of every token occurrence is exact: context = (block, attribute path, band) for colours, (block,
  attribute path, recovered type role) for fonts. If exactly one of the colliding roles is used by the library in that
  context: that role. If several are used and the library majority is one of the colliding roles: the majority role,
  reported per block under `assumed`. Otherwise the value is left and reported under `ambiguous`. A collision whose roles
  all map to the same TARGET value is not ambiguous.
- not in the palette: left, counted under `unknown_colours` (with the value) or `unknown_fonts`.
- an `rgba()` equal to `RecipeTokens`' alpha form of exactly one role (a hand-written page) becomes `{{aied:color:<role>:<NN>}}`; otherwise left.

The context table costs 0.06 s and is memoised per request (measured, section 9.6); it is keyed by the sha256 of the
recipes file, so it always describes the free plugin that is installed. Leaves are rewritten in place (`JsonLeaves`):
an untouched block is byte-identical, a block the engine re-encodes is written with `RecipeTokens::encodeJson` (the
resolvers re-encode such blocks anyway, so the result equals the direct build).

### 5.4 Detection (`Detector`)

When `from_language` / `from_kit` are not given: the language is the one with the most palette, font and rhythm matches
(measured: 284 of 284 single-recipe pages correct); the kit is the one whose candidates fully match the most kit
carrier leaves (measured: 140 exact, 56 tied with a kit that unresolves to the same markup, 80 pages with no kit block,
8 wrong of 284: ties on equal scores and coincidental matches on pages with no true kit block). The result carries
`confidence` (share of decorated carriers matched, margin to the runner-up). A tie between kits that unresolve the page
differently, or a margin below the floor fixed in Task 5, is refused with both candidates named and a request to pass
`from_kit`. Detection never changes a page by itself.

### 5.5 Report (`SwitchResult`)

Per page: `from` (ids, `given|detected`, confidence), `to`, `switched` (markers by kind and role, colours and fonts by
role, images), `left` (`unknown_colours`, `unknown_fonts`, `ambiguous`, `drifted`, `unclassified`, `global_refs`),
`assumed`, each list at most 25 entries plus a count (the free `ViolationReport` style), and `content` (the new markup;
never returned to the client, only used by the tool).

### 5.6 `conform` mode (opt-in, Task 7)

For a `drifted` block, `conform` removes EVERY leaf at a path its nearest candidate's source patch writes (whatever the
value) and puts the marker back, so the target kit styles it; each overwritten leaf is reported under `overridden`
(path, old value). Proof: on the dogfood corpus switched to the ten default designs, every block the engine conforms
scores 100% in its kit category under the scorer, and the scorer's (role, band) for it equals the engine's (shrink-only
disagreement list, starting at the measured count). Default stays `leave`.

## 6. Named difference classes (measured; each is pinned by count, shrink-only)

Prototype measurement (section 9): a switched recipe differs from the direct build only in these ways.

| Id | What | Where (library) | Why it cannot be exact | Policy |
|---|---|---|---|---|
| D1 | a font family on a text with NO type marker (big numbers, quotes, a few labels in the display or eyebrow font), plus h4 heading fonts inside texts and the slider | 19 recipes, 54 untyped texts (41 display, 13 eyebrow font) | the source language uses one family for two roles and the context is not unanimous (body 236, display 41, eyebrow 13 at `divi/text` body font) | library majority (`body`), reported under `assumed` |
| D2 | a square (0px) image under a source kit whose media radius is 0px | team-grid, gallery-masonry-style (10 flush images) | a flush image and a kit image are the same bytes | read as a kit image (rounded by the target kit), reported under `assumed` |
| D3 | a group fill on a `panel` or `unknown` band whose value two roles share (surface=inverse) | proof-logo-wall, pricing-comparison-table | the library uses both roles there | left, reported under `ambiguous` |
| D4 | an UNMARKED block whose literals equal what the source kit would write (and a panel whose own padding equals the source kit's card padding) | proof-logo-wall, stats-big-captions, pricing-comparison-table under a few source kits; contact-location-form under gilt-hairline | indistinguishable from a kit-driven block | switched as kit-driven ("indistinguishable is switched"), reported |
| D5 | key order only (same JSON, different bytes) | follows D4 | a stripped literal is re-added at the end by the merge | accepted: Divi reads JSON, not key order; the proof compares decoded JSON for D5 |

A free follow-up would remove D1 for pages built after it (a type role for display-font texts, so their values carry the
role); it is NOT part of this work and is listed for the owner.

## 7. The tool

`switch_design` (MCP + REST; joins `ProOpenApi::MCP_REST_ONLY`: free 16 + Pro 14 OpenAPI operations = 30, the importer
budget, measured). Registry capability `edit_pages`; per page `EditTarget::resolve` (a page or Divi Project the user can
`edit_post`). Approval mode does not apply (every Pro tool is exempt; the token is the approval).

Arguments (strict, `Args`): `page_id` (int) OR `page_ids` (list of 1-25 positive ints, exactly one of the two; a
refinement of the brief so an agency can restyle a site section in one batch), `to_language` (id) and/or `to_kit` (id
or `"default"` = the target language's own kit); an omitted one keeps the source's; `from_language`, `from_kit`
(optional; detection otherwise), `mode` (`leave` default | `conform`), `dry_run` (strict bool), `token`.
Unknown ids are refused with the valid list; a target equal to the source is a normal `changed:false`.

Preview (`dry_run: true`): per page the section 5.5 report, `valid` + `violations` (`LayoutGate::check($new, $stored)`),
`diff` (`LayoutDiff::compare` summary), `StyleReferences::attach` advisories, `would_change`; ONE `token`
(`PreviewToken`: argument hash, page id => "stored hash:planned hash", user, 30 minutes, single use). Pages refused in
the preview: `invalid_result` (the gate fails; never saved), `too_large_for_undo` (stored or new content over
`PageHistory::MAX_BYTES` = 512 KB: a whole-page restyle without an undo point is not offered), `forbidden`/`not_found`,
`nothing_to_switch` (no role recovered), `detection_ambiguous`.

Apply: requires the token; recomputes; a page whose stored hash OR planned output hash changed since the preview is
skipped `changed_since_preview` (the second catches a free-plugin update between preview and run); each page is written
through `HistoryService::write($id, $new, 'switch_design')` (so the free history snapshots it and, if the render check
of `2026-10-25-pro-render-check-design.md` is built, its post-write hook runs too) and recorded as a
`PostContentKind::itemFor` item; ONE `BatchLog::record('switch_design', ...)` batch; `BatchLog::attach` result. Like
`FindReplaceSite` for pages, no cache is cleared by the tool itself.

Undo: the existing `undo_batch` (PostContentKind restores the history version; changed-since rule; undoable itself).

## 8. Rulings (the owner can overrule; each says what it costs)

1. Engine in Pro, zero free change (section 4). Cost if wrong: none now; a later free release could move pure parts.
2. Colour/font collisions resolved by the library context, majority `assumed` for fonts and colours, `ambiguous` left
   otherwise (section 5.3). The alternative "leave every collision the context does not decide unanimously" makes a
   glass-dark page switched to luxe keep Inter in every untyped text (measured: 72.0% byte-identical instead of 92.5%).
3. "Indistinguishable is switched" (D2, D4). The alternative (refuse such blocks) needs provenance the saved page does
   not carry.
4. Only full matches are switched by default (`leave`); `conform` is opt-in.
5. A page over 512 KB is refused (no undo point otherwise).
6. `to_kit: "default"` picks the target language's own kit; an omitted target keeps the source's.
7. Up to 25 pages per call, one batch.
8. The Pro version bump, `Compat::MIN_FREE`, the store release and the divi5lab.com copy are the owner's at release.

## 9. Proofs (measured by the plan writer's throwaway prototype; the plan's tests re-measure and pin)

Prototype: about 300 lines in the scratchpad (not committed), the algorithm of section 5 without `conform`.
1. Headline matrix, every recipe, the ten (language, default kit) designs to each other: 71 x 10 x 10 = 7,100 switches,
   **6,571 byte-identical (92.5%)**; all others only D1-D5 (D1 435, D2 48, D3 36, D4 9, D5 1 switches); 47 of 71 recipes
   identical in all 100 pairs. With the rule "leave every collision the context does not decide unanimously": 5,115 (72.0%).
2. Seeded cross designs (seed 7, 12 random (language, kit) pairs): 71 x 12 x 12 = 10,224 switches, **9,533 (93.2%)**,
   48 of 71 recipes clean; identity 846 of 852 (the 6 are D4/D5).
3. Kit layer only (editorial, every kit to every kit, every recipe): 113,600 switches, **112,282 (98.84%)**, 65 of 71
   recipes clean; failures only D2 (team-grid, gallery-masonry-style), D4/D5 (proof-logo-wall, stats-big-captions,
   pricing-comparison-table, contact-location-form). Too slow for the suite (about 5 minutes): the plan samples it.
4. Properties on matrix 1: identity 709 of 710 (the one is D4/D5); A -> B -> A **5,879 of 6,390 (92.0%)**; B -> B after
   A -> B **6,381 of 6,390**; LayoutGate valid on 912 of 912 sampled outputs.
5. Dogfood corpus (8 pages) switched to the ten default designs in `leave` mode and scored for the target: the
   recipe-built edit base stays 149/149 (100%) under every target; the freehand pages fall (for example hairline-dashed
   166/188 under its own design, 134/183 under editorial), because their near-conformant blocks keep the source look.
   This is the reason for `conform` (5.6).
6. Cost: context table 0.06 s; a 71-section page of 517 KB switches in 0.13 s, 23 MB peak.

The plan's suite pins: matrix 1 in full (7,100), matrix 2 in full (10,224), the kit layer as 3 recipes x 40 x 40 (4,800)
plus 14 coverage recipes (every one of the 25 (block, role, ground) kit markers and the media token) x 40 source kits x
8 seeded target kits (4,480), the four properties, the gate on every 7th output, the scorer lockstep on 710 pairs, and
the corpus under both modes. Each identical count is pinned EXACTLY and each Dn count as a shrink-only cap.

## 10. Live gate `make verify-pro-switch-design` (controller-run, dev site only, self-cleaning)

`scripts/verify-pro-switch-design.cjs` + `scripts/verify-pro-switch-design.php` (dev-guard): build a throwaway draft
from 6 recipes under (editorial, quiet-solid) and a second throwaway draft with the same recipes built directly under
(luxe, gilt-hairline); call `POST /pro/switch_design` with `dry_run` (assert the report counts and `valid:true`), then
with the token; read back: the stored content equals the direct build byte for byte (the six recipes are chosen from
the clean set) ; render both drafts at 1280 and 400 px in Chromium and compare every module's computed colours, fonts,
borders, radii and paddings (equal) and pixel dimensions; MCP parity (`tools/call`); a second apply with the same token
refused; `undo_batch` -> stored content byte-identical to the original; an editor without `edit_post` refused; a page
changed between preview and run skipped; then delete everything (`aied switch ` titles, `--clean-orphans`).

## 11. Spec issues found (brief versus code; which side this design follows)

1. The brief says the colour -> role reverse lookup is exact once the source is known. It is not: 7 of 10 languages
   share a colour between roles and 9 of 10 share a font family between roles (section 3). Followed: the code; context rule + named D1/D3.
2. The brief names `page_content` item kinds; the code's kind is `post_content` (`PostContentKind`). Followed: the code.
3. The brief calls the scorer "the core of the switch" and asks to promote it. The scorer is a lenient judge, not an
   inverter, and the Pro Contract forbids free changes. Followed: a new exact engine in Pro, the scorer kept as the
   oracle, its band rule ported and pinned (section 4).
4. The type, rhythm and carriers adapters the switch re-applies are on local main, NOT in the published free 4.14.0.
   `Compat::MIN_FREE` is 4.9.0. Followed: the tool also checks at run time that `LanguageResolver` and the carrier roles
   exist and refuses with a plain message otherwise; the owner sets `MIN_FREE` to the free release that ships them.
5. Another 2026-10-25 Pro plan (`2026-10-25-pro-render-check.md`) adds `check_page_render` as a Pro tool. The
   twenty-one-tool pins (`Tools::all()`, `ProParityTest`, `ToolsTest`, README) move once per added tool; this plan takes
   whatever count is current when its Task 8 starts and says so in its commit.
6. The brief's single `page_id`; refined to `page_id` or `page_ids` (1-25) for agency use (Ruling 7).
7. The brief asks for byte-identical results "or differ only in named, justified ways": measured, D1-D5.
8. The brief's 71 x 10 x 40 matrix: the full kit matrix is 113,600 switches and about 5 minutes; sampled (section 9).

## Outcome

Built as Pro tool `switch_design` (22nd Pro tool; MCP and REST only, `ProOpenApi::MCP_REST_ONLY`; the ChatGPT budget stays
16 free + 14 Pro = 30 operations). Engine in `pro-addon/src/Design/*` and `pro-addon/src/Tools/SwitchDesign.php`; ZERO change to
`wp-plugin/` or `src/`. Merged to local main (merge ed8fa11); NO release, NO version bump (Pro file still 0.12.1); the
tracked free zip is untouched. Measured facts only:

**What the tool does.** Switches the design language and/or style kit of one page or Divi Project, or up to 25 (`page_ids`),
deterministically (no AI). Preview first (a `dry_run` flow with a single-use 30-minute `PreviewToken` per user and a
per-page content hash: a page changed since the preview is skipped). Every result goes through `LayoutGate`, is saved
through the free `HistoryService` (one snapshot per page) and is one undoable batch (item kind `post_content`). Skip reasons:
`not_found`, `forbidden`, `too_large_for_undo`, `detection_ambiguous`, `same_design`, `unresolved`, `nothing_to_switch`,
`invalid_result`. Modes: `leave` (default: only blocks that fully match a kit or language role are switched; hand-written
blocks are left and reported) and `conform` (opt-in: also restyles drifted hand-written blocks; conformed and overridden
lists in the report). The engine re-resolves the page through the free pipeline `SectionRecipes::resolveMarkup`, so no
adapter logic is re-implemented. Detection of the source design by palette match when `from_*` is not given: languages
284 of 284 on the library pages; kits 129 exact, 39 harmless, 76 none, 40 refused, 0 wrong (a refusal asks for `from_kit`).

**Headline proof (Task 6, `pro-addon/tests`).** A library recipe built under (L1, K1) and switched to (L2, K2) equals the
direct build byte for byte in:
- kit layer: 4,800 of 4,800 and 4,480 of 4,480;
- matrix of the ten default designs to each other: 6,549 of 7,100; the rest are the named difference classes D1 458, D2 48,
  D3 36, D4 9, D5 0, none unclassified (the plan's 6,571 less 23 `divi/slider` switches whose font context is a tie left
  ambiguous by the strict-majority rule);
- seeded cross: 4,199 of 4,544 (8 pairs; 12 pairs measured 9,512 of 10,224);
- round trip A -> B -> A: 5,856 of 6,390 (the rest are D classes); switching twice: 6,381 of 6,390;
- every one of 913 sampled outputs passes the layout gate;
- scorer lockstep: all 710 pairs equal, check for check, against the independent dogfood scorer;
- conform on the hand-written dogfood corpus: scorer totals 9,785 of 12,894 (leave) -> 10,832 of 13,081 (conform), no cell
  loses passed checks, 68 pinned engine-versus-scorer role disagreements (50 underlined-label groups, all passing, and 18
  on one unmarked accent badge); the corpus failures under leave are pinned (66 checks on 8 blocks, 5 of them fail under
  their own design too).

**Live gate `make verify-pro-switch-design`** (Docker site, Divi 5.15.1, 2026-10-26): 62 PASS, 0 FAIL. The switched stored
page equals the directly built page byte for byte (compared after WordPress's attribute escaping of stored block JSON, so
`<p>` stored as `\u003cp\u003e` is equal); the one-time token; REST and MCP parity; permissions (an author gets 403, an editor
without `edit_post` gets a bare `forbidden` row); a page changed after the preview is skipped; undo restores byte for byte;
the real Divi renders the switched and the direct page the same at 1280 and 400 px (computed styles of every module equal and
boxes within 0.5 px, the contact form's random captcha boxes excluded); no orphan page.

**Suites at the merge.** Pro 2,367 tests (228 s wall, budget 240 s; the count re-checked with `--list-tests` at the docs
commit); free 6,365 unchanged.

**Guard.** On a free plugin that lacks the classes or roles the engine needs, ONLY `switch_design` is disabled, with a plain
400 "needs a newer version of the free plugin". The published free 4.14.0 lacks `LanguageAdapters` and the other adapters
this tool re-applies, so the tool will not run against it. `Compat::MIN_FREE` is set at release (section 11, item 4).

**Honest limits.** Pages built by hand (not through this plugin's kits) switch only partially in `leave` mode; unrecognised
colours and fonts are left and reported; tablet and hover states of hand-written blocks are not remapped beyond what the
roles express; the difference classes D1-D4 above are real, named, pinned differences, not hidden. The market claim (a
one-call, previewed, validated, undoable, AI-free restyle across 10 languages x 40 kits) rests on a search that found no
competitor stating it (`docs/launch/competitor-refresh-2026-10-25.md`); that is an absence in our reading, not a proof.

**Not done.** No release, version bump or `MIN_FREE` change; no ChatGPT operation; the render-check rollback is a separate,
PARKED plan (`2026-10-25-pro-render-check.md`) and is not part of this build. Code-review findings of the whole-branch
review are recorded by the reviewing agent, not here.
