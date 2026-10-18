# Design richness: more varied, more designed pages from the AI (free plugin)

Date: 2026-10-17. Status: draft for the owner's approval. Nothing is built.
Origin: the owner finds the generated pages "too plain": always the same three-column card grid with icons, too few design
elements. A second wish, reproducing a design from a screenshot, a Figma export or a sketch, is Phase 2 and gets its own
design once Phase 1 exists (outline in the last section).

## Intent and why

The plugin does not generate layouts in code. It steers an AI with guides and proven section recipes, and the validator is
the gate. The AI can only compose from what those guides show it, and what they show is narrow. Measured on 2026-10-17:

1. **The validator does not limit styling.** It checks structure, required content, one h1 and the no-custom-code policy.
   It never checks which decoration attributes are used. So richness is not blocked by the gate; it is blocked by what we
   teach. Corollary: nothing in the gate stops an invented attribute either, and an invented one is silently dead (see the
   evidence below). The vocabulary must therefore be verified by rendering, not by validation.
2. **The recipe library is 17 sections that use about 19 of the ~90 block types the validator knows.** Five are card, blurb or
   icon grids (`card-grid-3`, `card-grid-4`, `blurb-grid`, `icon-features`, `icon-values`); there is one hero (`hero-cta`).
   No recipe uses pricing tables, accordion, tabs, timeline, team member, circle counter, countdown, video, map,
   before/after, gallery or icon list, although all are accepted.
3. **The style guide describes more than the recipes use.** The guide teaches animation, filters, sticky, blend, absolute
   positioning. Counted in the recipes (distinct block attribute paths, 215 in total): animation, filters, sticky, blend,
   parallax and video backgrounds appear 0 times; absolute positioning once; gradients 13 places; transforms 12. The AI
   copies recipes, so the page comes out flat.
4. **Divi has far more than the guide teaches.** Divi 5.15.1 ships 91 module definitions (`module.json`). Across them, the
   universal option families include `transition`, `interactions`, `order`, `disabledOn`, `conditions` and, on sections,
   `dividers`, `innerShadow` and `gutter`; none of these appears in the style guide. Which background features exist
   (patterns, masks, video, parallax) and what attribute shapes they use was NOT read in this brainstorm; Phase 1 mines it.
5. **Design direction is one paragraph** of the style guide ("commit to ONE clear aesthetic direction ... dominant + accent
   color"). There is no palette system, type pairing, rhythm rule, or "do not repeat a layout".
6. **Dead shapes are a proven failure mode.** `docs/guide-shapes-evidence-5.14.md`: an earlier draft taught three attribute
   shapes for image fit and gallery aspect ratio; they passed the validator and emitted no CSS at all. They were found
   only by rendering. Any "richer vocabulary" built by guessing shapes would reproduce this at scale.

Intent: make the AI's pages look designed and varied by (a) growing the verified vocabulary of Divi settings, (b) growing the
library of proven section patterns far past the card grid, and (c) teaching and checking composition, all without weakening
the gate or the grounding rule.

## Goals, non-goals, anti-goals

**Goals (Phase 1)**
- G1. A machine-readable **shape registry** of every attribute shape the guides and recipes may use, each proven by a render.
- G2. The style guide teaches the registry's shapes, in on-demand topics, so a bigger vocabulary does not cost more tokens
  per call.
- G3. **60 or more section recipes** (from 17), several variants per role, tagged so the AI can pick for variety, each
  validated, rendered clean at desktop, tablet and phone, and using only registered shapes.
- G4. **Design languages** (about 10): complete, original art-direction systems (palette roles, type pairing, radius and
  spacing scale, shadow and border treatment, motion, section rhythm, which recipes fit). Served to the AI.
- G5. A non-blocking **`advisories.design_variety`** on validate, save, create and dry run: deterministic, explainable
  findings such as "4 sections use the same card-grid layout", each with a fix pointing at named recipes.
- G6. Every existing guarantee stays: the gate, the WordPress.org compliance rules, the 3-transport lockstep, the Pro
  add-on's compatibility.

**Non-goals**
- No new MCP tool or REST/OpenAPI operation (the ChatGPT importer's 30-operation budget is used up; this work fits inside
  existing tools through optional parameters).
- No change to validator rules unless a new real export proves a new placement (the existing rule applies).
- No custom CSS, JS or PHP, no `divi/code`, no backdrop blur: still refused by policy; the guides say what Divi cannot do.
- No server-side page generation, no AI inside the plugin, no remote calls, no licence gating in `wp-plugin/`.
- No change to the Pro add-on.
- Phase 2 (image-to-layout) is not built here.

**Anti-goals (would make this a failure even if tests pass)**
- Variety that is cosmetic: recipes that differ only in colors but share the same skeleton.
- A guide so large the AI skims or ignores it. The default `get_style_guide` answer must not grow; measure with
  `scripts/measure-tokens.php`.
- Shapes that validate but render nothing (the dead-shape failure above), or that look right on desktop and break on a phone.
- Low contrast text, or a hero that only works with the exact stock image.
- Recipes that quietly depend on a demo site's preset or color ids (existing recipes already carry a warning for this; new
  ones must not need it).

## Constraints (from CLAUDE.md, unchanged)

- Validator stays pure and deterministic; `src/` canonical, `wp-plugin/validator/` mirrored.
- No Divi schema from memory. Every shape must trace to a real builder export, or to Divi's own `module.json`/PHP style
  classes, in either case proven by a live render on the real Divi (evidence stored).
- Every recipe is re-validated in the suite. `src/VerifiedModules.php` is generated, never hand-edited.
- No remote fetch, no custom-code saving, no licence-gating in `wp-plugin/`; `WpOrgComplianceTest` is not weakened. The
  readme's claims (including counts) must stay true and tested.
- MCP, REST and OpenAPI stay in lockstep for any parameter added.
- Max 3 parallel Claude workers when generation work is parallelised (owner feedback, 2026-10-17).

## Design

### 1. Shape registry and the effect probe (foundation, built first)

`wp-plugin/data/verified-shapes.json`: one entry per shape.

```
{ "id": "section.dividers.top.style", "element": "module", "path": "module.advanced.dividers.{bp}.value.top.style",
  "example": "wave", "values": ["wave", "..."] , "modules": ["divi/section"],
  "how": "builder-export|module-json+render", "css_effect": "<what changes in the emitted stylesheet>",
  "divi": "5.15.1", "evidence": "docs/shape-evidence-5.15.md#...", "phones": "ok|n/a" }
```
(The entry above is illustrative of the fields; the real paths and values come from mining, not from this document.)

- **Candidate sources, in order of trust:** (1) a real builder round-trip driven headless with Playwright (the method that
  produced the Components exports), (2) Divi's `module.json` plus its PHP style classes, then render-proven.
- **Effect probe** (`scripts/verify-shapes.cjs`, dev-only, extends the pattern of `verify-guide-shapes.cjs`): for each
  candidate, save two throwaway draft pages that differ only by that attribute, render both with the real Divi, and compare
  the emitted stylesheet and the computed style in a real browser at 1280, 900 and 400 px. A shape that changes nothing is
  DEAD and is rejected, never taught. Same safety rules as the other gates: dev site only, self-cleaning.
- **Registry lint (unit test, no Docker):** every attribute path used by any recipe or by a worked example in any guide must
  match a registry entry, or the suite fails. This turns "never invent shapes" from a promise into a test. (Content paths
  such as `innerContent` and structure keys are exempt by an explicit allowlist.)
- Evidence docs per Divi version in `docs/shape-evidence-<version>.md`, as the other gates do. `make divi-currency` is
  extended to re-run the probe so a new Divi release that renames or drops a shape is reported.

### 2. Vocabulary mining (A), by family, in batches

Mine the families the guide does not yet teach, in this order (highest visual payoff first): background features beyond
color and gradient (as found), section dividers and inner shadow, hover and transition, interactions, animation variants,
sticky and scroll variants, overlap and positioning patterns, typography extras (heading font per level, text shadow,
columns), image treatments (fit, filters, masks as found), button variants and icon placement, per-device ordering
(`order`) and visibility (`disabledOn`, which the 5.14 evidence explicitly marks untested), spacing scales. For each family,
the output is registry entries plus guide topic text. A family whose shapes cannot be proven stays out and is listed as
"not taught" in the evidence doc.

### 3. Recipe library (B): 17 to 60+, tagged

Target roles and variants (counts are targets, not promises; a role that cannot be built from proven shapes ships smaller and
is reported):

| Role | Variants (examples of how they differ structurally) |
|---|---|
| Hero | centered, split image right/left, full-bleed image with overlay, gradient with floating cards (overlap), type-led band with motion, minimal type-led, stat-led |
| Proof strip | logo row, logo carousel, press quotes, rating strip |
| Features | bento grid (unequal tiles), zigzag alternating, numbered steps, icon list with dividers, tabbed, accordion beside image, checklist, comparison |
| Stats | counter band, circle counters, big-number with captions |
| Testimonials | single large quote, slider, grid with avatars, quote over image |
| Pricing | 3-tier, 2-tier with highlight, comparison table style |
| Team / about / story | team grid, founder note, timeline, values |
| FAQ | accordion, two-column Q&A, toggle list |
| Process | horizontal steps, vertical timeline |
| CTA | band, split with form, card over image, countdown |
| Gallery / portfolio | masonry-style, filterable portfolio, before/after |
| Contact / location | form + map, form + details, simple |
| Page headers for inner pages | title band, breadcrumb band, image band |
| Section transitions | divider shapes, overlapping edge, color band |

Each recipe carries tags: `role`, `layout` (e.g. grid, split, stacked, bento, overlap), `mood` (the design languages it suits),
`density`, `background` (solid, gradient, image, none), `modules`, `needs_images`. The catalog stays one short line per recipe
and gains optional filters (`role`, `mood`, `layout`) on the existing tool.

**How a recipe is produced and accepted**, in this order of preference: build it in the real Visual Builder with Playwright and
export (fully grounded), or compose it from registered shapes and the existing proven structures. Acceptance gate, all
automatic: validates; every attribute path is in the registry (lint); renders without PHP errors or notices on the real Divi;
screenshots at 1280, 900, 400 px show no horizontal overflow and nothing unexpected collapsed (measured, not eyeballed);
text contrast against its own background meets WCAG AA for the palette in the recipe; no remote image host, no unresolved
token (the existing `SectionRecipesTest` checks, kept).

**Colors and fonts in recipes (my proposal):** recipes should not hard-code one palette. Add serve-time tokens in the style of
the existing image tokens (`{{aied:color:accent}}`, `{{aied:font:display}}`), resolved by a pure class from a palette and
type pairing chosen by the AI (a design language supplies defaults; `list_global_styles` ids can be substituted instead).
`get_section_recipes` gains an optional `design_language` (strict string). Result: one recipe yields many looks, and the test
suite validates each recipe under every language's tokens. Without the parameter the recipe is served with its own default
palette, as today.

### 4. Design languages and the guide restructure (C, part 1)

- About 10 original design languages (candidates: soft-saas, editorial, bold-vibrant, glass-dark, brutalist-flat, luxe,
  organic-warm, tech-mono, playful-pastel, corporate-trust). Each is a data file plus short prose: palette roles with
  contrast-checked pairs, display and body font pairing (fonts the plugin already teaches as usable), radius and spacing
  scale, shadow and border treatment, motion level, section rhythm (which backgrounds alternate), preferred and forbidden
  recipes, hero and CTA character. Each language's example palette is contrast-tested in the suite.
- `get_style_guide` gets an optional strict-string `topic` (for example `design-languages`, `backgrounds`, `motion`,
  `typography`, `layout`, `responsive`, `images`, `components`, `dynamic-content`). No topic returns the current core guide
  (no larger than today) plus an index of topics. The existing long sections move into topics; nothing is lost. A language is
  fetched by `topic=design-language:<id>` or through the recipes tool. Both transports and the OpenAPI spec get the same
  parameter; the lockstep tests are updated.
- The landing guide and site guide stop saying "pick one aesthetic" and instead say: pick a language first, state it, then
  choose recipes by role with the rhythm rules.

### 5. The variety advisory (C, part 2)

`DesignVariety` (pure, in `wp-plugin/src/`, beside `StyleReferences`/`ContentLoss`; the validator is untouched), attached the same
way those advisories are (every save, create, validate, dry-run path). Per section it computes a structural fingerprint
(column structure, the module-type sequence, whether the background is solid, gradient or image) and reports, never blocking:

- `REPEATED_LAYOUT`: the same fingerprint in adjacent sections, or three or more times on a page;
- `CARD_GRID_OVERUSE`: a card-grid-like section (3 or more equal columns each holding the same module sequence) in more than
  a third of the page's sections;
- `FLAT_BACKGROUNDS`: every section on one solid background;
- `NO_FOCAL_ELEMENT`: no hero-level contrast (no image, gradient or large type) in the first section;
- `NO_MOTION_OR_DEPTH`: no shadow, overlap, transform or animation anywhere on a page of 5 or more sections (a soft hint).

Each finding names the section, why it was flagged, and 2 or 3 recipe names from a different role or layout that would fit.
Thresholds are named constants with tests. It never changes `valid`, never blocks, never rewrites content, same as
`style_references`. It is silent on short edits (a page of fewer than 3 sections, or an `edit_page_content` that touches one
section).

### 6. Release shape

Free 4.13.0 (version bumped in the plugin header, constant and readme). No new tool or route; optional parameters on
`get_style_guide` and `get_section_recipes`; new data files; new advisory. Because the content is large, ship it in
increments if the owner prefers (4.13.0 foundation + first recipe batches, then more), each increment fully gated. Plugin
Check and the WordPress.org compliance test run on every increment. The readme's recipe/vocabulary claims are updated to the
tested numbers.

## Verification (how we know it worked)

1. Unit/CI (no Docker): registry lint, every recipe validates, every recipe under every language's tokens validates, contrast
   tests, `DesignVariety` rules with fixtures (including the owner's real plain pages as the "should flag" cases), token
   budget unchanged for the default guide, lockstep tests.
2. Live (Docker, real Divi): `make verify-shapes` (effect probe, 3 widths), `make verify-recipes` (render all recipes, 3 widths,
   no errors, no overflow), plus the existing gates re-run (`make divi-currency ... GATES=1` pattern).
3. Human: a contact sheet of every recipe at desktop and phone for the owner's eye, and the same brief given to the AI before
   and after, compared side by side, with the advisory's findings on each. "Less boring" is the owner's call; the tests prove
   only that what exists is valid, varied in structure and renders.

## Decisions from the builder check (2026-10-17)

Marked **[my call]** where the owner has not decided; the owner can overturn any of them.

- **Imagery [my call].** No bundled photographs (licensing, size and the WordPress.org review make them a poor trade). The
  built-in pack may GROW with more original, script-generated art (`scripts/generate-image-pack.php`, byte-deterministic,
  manifest-listed): abstract gradients, textures and patterns, device and interface illustrations, shapes. Every recipe
  must look right with that art AND with the site's own Media Library photos (Media Library first, as the image guide
  already says). No recipe depends on one particular picture.
- **Count [my call].** 60 is a target, not a gate. There is no minimum count to release; a release ships whatever passes
  every automatic gate, and the readme states the real, tested number. A role that cannot be built from proven shapes ships
  with fewer variants and the evidence doc says why.
- **Composed recipes [my call, following CLAUDE.md's rule].** A recipe composed from registry shapes and already-proven
  structures is acceptable if it passes the acceptance gate. A NEW structural placement (a module inside a parent no
  export or verified-parent evidence shows) still needs a real builder export or a render proof of the placement, exactly as
  today. Heroes and any recipe that introduces a compound module in a new arrangement are the first candidates for builder
  exports.
- **Divi versions [my call].** Target is the current tested Divi (5.15.1; `DiviCompat::TESTED` is 5.15). The registry records
  the version a shape was proven on; shapes are not claimed for older versions. The probe runs on the dev site's Divi only;
  `make divi-currency` re-runs it on each new release.
- **Video and other user media [my call].** No recipe requires a video file or URL (no stock video is allowed). A recipe may
  offer an optional background-video setting only if the registry proves the shape, with a normal non-video fallback that
  looks finished; the first version ships none.
- **Tokens [my call].** Color and font tokens are resolved when a recipe is served, so the AI always receives hard values and a
  saved page never contains a token. A stray unresolved `{{aied:...}}` token in a save gets a non-blocking advisory
  (`unresolved_tokens`), not a refusal, so the gate itself is unchanged. The design language is not stored on the page; the
  variety advisory and contrast checks work on the saved page's real values.
- **Variety advisory fixtures [my call].** Use structure-only fixtures (copy stripped) derived from pages already built on
  the connected dev sites, plus pages generated for the test. No client content is copied into the repo. The owner can point
  to specific pages they consider plain; those become the first "should flag" cases.
- **Increments [my call].** Build in increments on one branch (foundation, then recipe batches by role, then languages and the
  advisory). A release is a separate decision made by the owner at the end of any increment; every increment passes every
  gate before it can be released.
- **Catalog size [my call].** The default `get_section_recipes` catalog becomes compact (names grouped by role, a few words
  each); full descriptions come from the optional `role`, `layout` and `mood` filters. Target: the default catalog stays within
  roughly twice today's size (measured with `scripts/measure-tokens.php`).
- **Design language text [my call].** Written fresh for this plugin. They are not copied from the layoutlab store's pipeline.
- **Sign-off on "less boring" [owner's decision, 2026-10-17].** No contact-sheet review gate for now; the owner will revisit
  later. Automated gates alone decide whether an increment can be released. The contact sheet is still produced as an
  artifact of `make verify-recipes` so the owner can look whenever they choose.

## Hard ceiling, stated honestly

Whatever needs custom CSS or JS cannot be produced (backdrop blur, advanced scroll choreography, custom shapes beyond what
Divi's settings offer). "As close as possible" in Phase 2 means as close as Divi's own settings allow.

## Phase 2 outline: reproduce a design from an image (not designed here)

Inputs: a screenshot, a Figma export (an image, or Figma's structured data when the client can read it), a sketch. The
AI client does the seeing; the plugin never receives or stores the image. Pipeline: the AI writes a structured design spec
(sections, their roles, layout type, color and type tokens, copy) from the input; the spec is mapped onto the Phase 1
languages and recipes with tokens set to the extracted values; the AI saves a draft; the client renders it and compares with the
input and fixes. Open questions for that design: the spec schema and where it is validated (a pure checker can confirm a spec
is complete and that the saved page matches it structurally); how the render-and-compare loop is driven when the client has no
browser tool; how a sketch differs from a screenshot (intent, not pixels); the relationship to `url-to-divi` (live capture)
and `html_to_divi` (supplied markup, whose style conversion is its own unbuilt phase 2); whether any part belongs in a Pro tool.
Phase 2 is brainstormed after Phase 1 ships, because it depends on all of it.

## Left to the builder

File layout and class names; the exact registry schema and probe implementation; the order of families inside the mining
batches; the exact thresholds of `DesignVariety`; how recipes are batched across at most 3 workers; whether the color/font
tokens are the right mechanism once the first batch is built (the idea may change if it proves awkward, the goal of "one
recipe, many looks, all validated" does not).
