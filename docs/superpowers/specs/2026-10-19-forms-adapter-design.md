# Forms adapter: a style kit also styles contact forms

Status: APPROVED BY DELEGATION (owner 2026-10-19: "do the next adapter ... do as best for the guide space"; the owner's order
after cards and accordions is forms, then blurb, testimonial; the image-radius token pass stays a separate small branch). The
owner was not asked to read this spec first; every call below that the owner might have made differently is marked
"Ruling" and is listed again in the Outcome. Built on branch `feat/forms-adapter` from main 89b6f4f. Follows
`2026-10-19-accordion-adapter-design.md`, `2026-10-19-card-adapter-design.md` and `2026-10-18-style-kits-design.md`.

## Intent and why

A kit now changes buttons, cards, accordions and toggles. The four contact forms of the library still carry the recipe's
baked field corners, field frames and their own submit button (a hand-written `button.decoration.*` block, 12px, 999px, 4px
or 25px radius, not the kit's), so a form next to kit buttons is visibly off-kit: the submit button is the most visible
mismatch left in the library. The adapter makes a form's fields, submit button and captcha follow the kit with the same proof
level as cards. The second payoff is outside the recipes: the generated `kit-rules` topic and the `forms` topic will say how
a form follows a kit, so a form the AI builds without a recipe matches too.

## What the audit found

Script audit of `wp-plugin/data/section-recipes.json` (a block parser): **4 `divi/contact-form` blocks in 4 recipes**,
13 `divi/contact-field` children; `divi/signup` (Email Optin) appears in no recipe. Nothing else in the library is a form.

| Recipe | Form sits | Field fill | Field radius | Button (own `button.decoration.*`) | Module decoration |
|---|---|---|---|---|---|
| contact-form | on its own band, form itself framed | `surface` | none | accent fill, 25px radius, hover swap, padding 10px sides | 1px `border` frame, radius 20px, maxWidth 950px |
| cta-split-form | inside a `panel` on `surface` | `surface` | 12px | accent, 2px frame, 12px, 15px/600, padding 14px sides | none |
| contact-location-form | inside a `panel` on `light` | `bg` | 16px | accent, 2px frame, 999px, padding 14px sides | none |
| contact-details-form | inside a `panel` on `light` | `bg` | 4px | accent, 2px frame, 4px, padding 14px sides | none |

All fields carry a 1px `muted` solid frame, `text` ink, `muted` placeholder and the `body` font token. The captcha is set in
`contact-form` only (`inverse` ink). Two of the four forms put a `surface` field inside a `surface` panel, so the field has
no fill difference from its container, only its frame.

## What the repo already proves on `divi/contact-form`

From the forms topic (`StyleGuide::formsTopic()`, rendered on Divi 5.15.1): `field.decoration.background.{bp}.value.color`,
`field.decoration.font.font.{bp}.value.{color,family}`, `field.decoration.placeholderFont.font.{bp}.value.color`,
`field.decoration.border.{bp}.value.radius.*` and `styles.all.{width,color,style}`, and the captcha font colour, family,
border and background. Divi draws no field frame by default (a set frame drew; the corner radius rounded the inputs). The
field font colour does NOT reach the placeholder. NOT proven on this module (the registry is path-only): the submit button's
`button.decoration.*` paths (they are the same group names as on `divi/button`, where `button.decoration.spacing`,
`boxShadow` and `transform` are DEAD and the padding, shadow and transform live on `module.decoration.*`), a bottom-only
field frame, field padding, a field shadow, and the hover or focus state of a field. The probe (Task 1) decides each; the
adapter writes nothing the probe did not prove ON `divi/contact-form`.

## Goals, non-goals, anti-goals

Goals: a marker `"aied":{"kit":"form","on":"light|surface|accent|dark"}` on `divi/contact-form` resolves to the kit's field
look, submit button and captcha ink; all 4 recipe forms migrate (`BESPOKE_FORMS` ends empty, cap 0); proof level of the card
(contrast over every kit x language x band x state, zero exceptions, no baseline file; golden; near-duplicate list;
dead-on-module guard; render gate with computed styles); guide wording generated from `KitAdapters::APPLIED`.

Non-goals: `divi/signup` and its custom fields (no recipe, nothing to migrate; a later pass), labels, required marks, field
types, success and error messages, the focus ring (not proven), field-to-field spacing, any module that is not a contact form.
Anti-goals: a new guide topic, a new MCP tool or REST route, any custom CSS, any hex in a recipe, a weakened test, a frozen
contrast failure.

## Design

### 1. Marker, role, bands

One role `form` on `divi/contact-form`, bands `light|surface|accent|dark` (`on` = the band the form's own container is, so the
inks and the field fill are judged against it; a form inside a `panel` on `surface` is `on:"surface"`). One marker styles the
whole form: the fields (the form's `field.*` group styles every child field), the submit button and the captcha. The marker
keys `kit`/`on` go through the existing strict `KitResolver::marker()`; `ROLE_BLOCKS` gains `divi/contact-form` from
`KitAdapters::APPLIED`.

### 2. Kit fields to form attributes (no kit file changes)

- Field fill: `surface`, `bg` on a `surface` band (the card rule: the field never matches its container).
- Field ink `text`; placeholder `muted` (the field colour does not reach it); family stays recipe-owned (`{{aied:font:body}}`,
  the kit has no font decision for fields).
- Field frame: kit border width with a 1px floor, kit style, colour `text` when the kit's border colour is `text`, `accent`
  or `inverse` and `muted` otherwise, and always `text` on an `accent` band (Ruling 1, amended by Ruling 6: a field's frame is
  its affordance, so it uses the strong roles and is at least 3:1 on the field fill; the field's boundary against the container
  is the BETTER of the frame and the fill at 3:1, the WCAG 1.4.11 reading; the card's decorative `border` hairline is NOT
  enough, `muted` is, as the recipes already use). An underline kit draws the bottom edge only IF the probe proves it on a
  field; otherwise it draws the full frame at 1px (Ruling 2, decided by the probe; the fallback is stated in the guide).
- Field radius: the kit's `radius-control` token (a field is a control; matches the submit button). Field padding and shadow:
  only if the probe proves them; a shadow on a field is not written (Ruling 3: noise, and the soft shadow is faint on dark
  languages).
- Submit button: the kit's primary control on the band (same fill, border, radius, label colour, capitalization, letter
  spacing, weight and hover as `divi/button` on that band, derived from the same treatment code, never a second table);
  its padding, shadow and lift only where the probe proves them live on the form's button; a kit whose padding cannot be
  written keeps the recipe's padding and the guide says so (Ruling 4).
- Captcha: ink is the band's ink (`text` light and surface, `accent-text` accent, `inverse` dark); the captcha is the one
  element printed straight on the container.
- The form module's own frame, radius, maxWidth and alignment stay the recipe's (the kit writes no `module.decoration`; a
  framed form is a card decision, so `contact-form`'s own frame stays a literal recipe decision).

### 3. Contrast proof (pure PHP, zero exceptions, no baseline file)

Over 40 kits x 10 languages x 4 bands x {rest, hover}: field text on field fill, placeholder on field fill, field frame
against field fill (strict), the field's boundary against the container (the better of the frame and the fill against the
container, Ruling 6), the submit label on its fill and the submit border against the container, the captcha ink on the band.
Floors: text 4.5:1, placeholder 4.5:1, frame and button border 3:1 (an outline or ghost submit: border and label 4.5:1 / 3:1
as the button proof defines). A failing cell changes the role table, never a baseline. (This section first demanded the frame
against BOTH the fill and the container; the first run of the proof showed that cannot be met, see Ruling 6 in the Outcome.)

### 4. Probe list (`fixtures/shapes/candidates/kit-form.json`, ids `kf-<decision>`)

All on `divi/contact-form` only, against the known-good control: submit button fill, border width/colour/radius, font
colour, capitalization, letter spacing, weight, hover fill/label/border, padding at `button.decoration.spacing` and at
`module.decoration.spacing`, shadow at `button.decoration.boxShadow` and at `module.decoration.boxShadow`, transform at
`button.decoration.transform` and `module.decoration.transform`, field border bottom-only, field padding, field radius token
form, captcha ink, placeholder colour; each rendered at 1280/900/400 and measured (`scripts/verify-shapes.cjs`). The probe
results become `fixtures/shapes/kit-form-vocabulary.md`; DEAD paths go to `dead-on-module.txt` for `divi/contact-form`.

### 5. Render gate and recipes

`verify-kits` renders one form per band pair per cell (a light form and a surface form), measures the fields (computed
fill, border width/style/colour, radius, ink, placeholder colour via `::placeholder`, clipping, overflow) and the submit
button (the existing button measures, including a real hover) with the codes `form`, `form-field-fill|border|radius|ink|
placeholder`, `form-button`, `form-captcha`, `form-contrast`. Four recipe forms migrate (marker plus dropping the
form's own `field.*`/`button.*` decoration that the kit now writes; the merge trap: a marked block keeps none of it);
`ItemMigrationTest`'s pattern gives `FormMigrationTest` (own ink, band walk, merge trap); `BESPOKE_FORMS` empty.

### 6. Size and the guide (the hard part; read with `docs/token-usage.md`)

Current caps: the `topic` parameter description is 298 of 300 characters, the default guide 28,975 of 29,108 B, the largest
kit topic 9,599 of 10,000 B, `kit-rules` 3,525 B, `forms` about 5,600 B. Rulings for space (Ruling 5, the owner delegated
the choice):
1. **No new topic name.** Form rules go in the existing generated `kit-rules` topic (one new "Forms" paragraph, generated
   from `KitAdapters::APPLIED` and a form patch; budget +700 B) and the `forms` topic gains one generated sentence like
   `togglesKitSentence()` (budget +350 B).
2. **The default guide grows 0 B** (pinned: 28,975).
3. **Kit topics get NO form example** (a resolved form is about 1.8 KB, the largest topic has 400 B of headroom):
   `appliedSentence()` names "forms" (budget at most +40 B per kit topic, largest stays at or below 9,700 B).
4. **Reclaim headroom for the next adapters** in the same change: Task 2 rewords the fixed text of
   `topicParameterDescription()` so it has at least 40 characters of headroom (it is generated from the topic names; the
   current fixed text is 90 characters), and a test pins `<= 260` characters. The blurb and testimonial adapters then fit
   without a new topic.
5. `section-recipes.json` must not grow (the markers replace more decoration than they add; bound 716,800 B).

### 7. Done means

All suites green; `make verify-shapes` ok; the full `make verify-kits` passes every cell with the form codes; the narrowed
recipe render (the four form recipes, every marker recipe, 10 guard recipes) passes; the Outcome records real numbers; the
Pro suite is green and untouched; validator, `src/`, `wp-plugin/validator/` and the root zip unchanged.

## Phased plan, order and cost

Task 1 probe (live, serial) -> Task 2 adapter + resolver + generated guide + parameter-description headroom -> Tasks 3
(proofs), 4 (recipe migration), 5 (render gate), which can run in parallel worktrees (max 3 workers) -> Task 6 docs ->
Task 7 live gates (serial, controller). Estimated 5 to 6 hours elapsed, about 3 hours of live dev-site time.

## Left to the builder

Names of constants and test helpers; the exact probe candidate list beyond section 4 where the first runs show a path is
dead; the wording of the generated sentences within the byte budgets; which of the shared helpers (`frameStyles()`,
`restShadow()`, `treatment()`) are reused as they are.

## Outcome (2026-10-19)

Built on branch `feat/forms-adapter` (spec 6ac6295 and plan 2cb3057 from main 89b6f4f) in six tasks plus a one-candidate-group
probe follow-up (Task 1b) and a fix round on Task 2; Tasks 3, 4 and 5 ran in parallel worktrees and were merged back. NO
release, version bump or zip rebuild: the tracked root zip is still the published 4.12.0 build; the validator (`src/`,
`wp-plugin/validator/`) is untouched. The spec was approved by delegation; the Rulings below are the calls the owner might
have made differently.

**What was built, per task.**
- **T1 + T1b Probe.** 50 candidates (`fixtures/shapes/candidates/kit-form.json`, ids `kf-<decision>`; the first run had 47,
  Task 1b added the submit underline, its hover and the 0px frame) rendered ON `divi/contact-form` on the real Divi 5.15.1
  in a real browser at 1280/900/400: **44 PASS, 6 css-only, 0 DEAD**, no phone overflow, no control noise. The six
  css-only are one finding, not six: `module.decoration.{spacing,boxShadow,transform}` on a contact form land on the WHOLE
  form container (`div.et_pb_contact_form_container`), never on the submit button, and the button's own
  `button.decoration.{spacing,boxShadow,transform}` are the live paths: the OPPOSITE of `divi/button`, where the module paths
  are live and the button's own are dead. Because nothing was DEAD on the module, `fixtures/shapes/dead-on-module.txt` gained
  no line (recipes may legitimately style the form container); the "kit writes no `module.decoration`" rule and the
  per-decision tests carry the risk instead. 17 new registry paths in `verified-shapes.json`; evidence in
  `docs/shape-evidence-5.15.1.md#kf-*` and `fixtures/shapes/kit-form-vocabulary.md`. Task 1 also extended the probe tooling
  (`hoverSelector`: hover a selector inside the measured element, since the form's centre is not the submit button;
  `changedElements` in each width's detail).
- **T2 Adapter, resolver, generated guide.** `KitAdapters::formPatch()` (reached through `patch('divi/contact-form','form',
  $on,$kit)`; `APPLIED['divi/contact-form'] = ['form']`; bands light|surface|accent|dark; no ground; output keys `field`,
  `button`, `captcha` only, never `module`). The submit is `button('control', ...)` relocated to `button.decoration.*`, so no
  second treatment table exists. `KitAdapters::FORM_PROVEN` holds six decisions, **all true**: `button-padding`,
  `button-shadow`, `button-lift`, `field-underline`, `field-padding`, `button-underline`; a test parses the "Adapter
  decisions" table of `kit-form-vocabulary.md` and requires equality, so a false decision flips the written output and the
  guide wording. `KitResolver` gained the role `form` (`ROLE_BLOCKS`, `ROLE_SURFACES`). The guide is generated: `kit-rules`
  gains a "Contact forms" paragraph (worded from `FORM_PROVEN`), the `forms` topic gains `formsKitSentence()`,
  `appliedSentence()` says forms. `testEveryLeafIsAProbedPathOnTheForm` builds the set of probed paths from the candidate file
  and results and fails for any leaf of any of 40 kits x 4 bands outside it (css-only paths excluded; a planted fault proves
  it bites).
- **T2 fix round 1.** The sixth decision `button-underline` (Task 1b proved the bottom-only submit edge): an underline kit's
  submit takes button()'s underline form (`styles.all` 0px + `styles.bottom`) and NO hover border colour, because the hover
  `styles.all.color` recolours only the 0px sides and the visible bottom edge never changes (kf-button-underline-hover). The
  guide parenthesis is keyed to the decision. Two new tests pin every emitted submit width and border path to the probed set.
- **T3 Proofs.** The contrast proof: 40 kits x 10 languages x 4 bands x {rest, hover} = **3,200 cells, zero exceptions, no
  baseline file** (see Ruling 6 for the one reading that matters). `tests/KitFormGoldenTest.php` +
  `tests/golden/kit-form-patches.json`: **40 patches** (10 default kits x 4 bands). The visible-difference rule for forms:
  **2 of 780** kit pairs give the same form (`fixtures/shapes/form-near-duplicates.txt`: quiet-solid|steady-solid,
  steady-solid|soft-solid; shrink-only, cap 2). The dead-on-module guard test (a planted `divi/contact-form|
  button.decoration.boxShadow` line makes the resolved form fail, scoped to the form only). Planted failures for every check.
- **T4 Recipes.** Four markers, all four forms migrated, `BESPOKE_FORMS` EMPTY (cap 0): contact-form `on:dark`, cta-split-form
  `on:light`, contact-location-form `on:surface`, contact-details-form `on:surface`. Dropped from each: the field fill, frame,
  radius, spacing and ink, the placeholder colour, the captcha ink, and the submit's fill, border, radius, padding (including
  the per-device steps), shadow, lift, label colour and weight. Kept: the form's uniqueId, the fonts, the label and button
  settings, the phone sizes, and (contact-form) the form's own 1px frame, 20px radius, maxWidth and centring.
  `tests/FormMigrationTest.php` (own ink, band walk, merge trap over all 40 kits x 10 languages, pinned markers, planted
  failures; 5 of 9 checks fail against the old file). `section-recipes.json` **675,810 -> 671,687 B** (-4,123 B).
  `RecipeLibraryTest`'s marker-recipe count went 32 -> 33 and two `SectionRecipesTest` assertions now read the SERVED recipe.
- **T5 Render gate.** `scripts/verify-kits.cjs`, `scripts/lib/kit-checks.cjs`, new `scripts/lib/kit-form-run.cjs`: every
  kit/language cell renders two forms (`form-bg` marker `{"kit":"form"}`, `form-surface` marker `{"kit":"form","on":"surface"}`,
  two fields each, Divi's basic captcha, a unique submit label) and measures them with the codes `form`,
  `form-field-fill|border|radius|ink|placeholder|padding`, `form-button`, `form-captcha`, `form-container`, `form-contrast`,
  `form-clip` and `form-expectation` (only if the fail-closed gate is bypassed). Computed AND painted fills (admin bar and
  fixed elements hidden), `::placeholder` colour, a real hover on the submit, the form container checked for stray shadow, lift
  and frame. **85 pure tests** (`node scripts/test-verify-kits.cjs`, 70 -> 85) and **34 synthetic Chromium departures**
  (`scripts/test-kit-card-run.cjs`), each caught with the intended code. The accent and dark bands of a form are judged by the
  pure contrast proof, not by the render sheet (the sheet renders the bg and surface forms).
- **T6 Docs.** This Outcome, CLAUDE.md, `docs/token-usage.md`, the vocabulary final pass.

**Measured sizes and counts.** (`wc -c` and `php scripts/measure-tokens.php` at the end of Task 6.)
- `section-recipes.json` **675,810 -> 671,687 B** (bound 675,810 held; the 700 KB rule and the 716,800 B spec bound hold).
  `style-kits.json` 25,479 B (unchanged, no kit-file change).
- Guide, before -> after (`docs/token-usage.md`): default guide **28,975 -> 28,975 B** (0 B, pinned; 133 B under its 29,108 B
  bound); topic `kit-rules` **3,525 -> 4,218 B** (+693; budget +700); topic `forms` **6,315 -> 6,474 B** (+159; budget +350);
  topic `style-kits` (the catalog) **8,423 -> 8,453 B** (+30); the largest kit topic (neo-brutal) **9,599 B, unchanged**
  (401 B under the 10,000 bound; the 40 kit topics total 339,482 B before and after, because the forms sentence lives in
  `kit-rules` and `appliedSentence()` only gained a noun that replaced another); tool definitions **21,641 -> 21,601 B**; the
  `topic` parameter description **298 -> 258 characters** (fixed text 90 -> 44 characters; pinned `<= 260`, so 42 characters
  of headroom for the next topic names; Task 2 reclaimed it on purpose).
- Suites: free **2658** tests (`make test` at the merged head, 3,922,310 assertions, OK; 2574 at the end of kit limits and
  2614 after the accordion adapter), Pro **2169** (untouched, last run by Task 3). `node scripts/test-verify-kits.cjs`: 85 tests OK.
- `fixtures/shapes/form-near-duplicates.txt`: 2 pairs. Form golden: 40 patches. The card, item and button goldens are
  byte-identical.

**Rulings made** (in plain words).
1. *The field frame uses the strong roles.* A field's frame is its affordance, so it is `text` when the kit's border colour
   is text, accent or inverse, and `muted` otherwise, never the card's decorative `border` hairline. Amended by Ruling 6: on
   an `accent` band the frame is always `text`.
2. *An underline kit.* A field draws the bottom edge only (probe: kf-field-underline); an underline SUBMIT takes the bottom
   edge too and has no hover border colour (sixth decision `button-underline`: the hover recolours only the 0px sides). Without
   the decision both fall back to a full frame, and the guide says so.
3. *No field shadow, no field hover, no focus ring.* Noise, and not proven; none is written.
4. *Padding.* The submit padding is written at `button.decoration.spacing` (not `module.decoration.spacing`, which pads the
   whole form). The field padding is the kit's control padding, `{{aied:kit:space-control}}` (the implementer's call at Task
   2, accepted: the same four-sides shape the probe measured).
5. *Space for the guide.* No new topic name: the form rules are a generated "Contact forms" paragraph in `kit-rules` and one
   generated sentence in `forms`; the default guide grows 0 B; the kit topics get no form example; the `topic` parameter
   description was reworded to buy headroom for later adapters; `section-recipes.json` does not grow.
6. *The field boundary is judged as the better of frame and fill (controller, ratified at the Task 3 review).* The strict
   reading of section 3 (the frame at least 3:1 on the field fill AND on the container) fails **636** kit x language x band
   cells (356 on the accent band, 280 on the dark band) and **600 even after any change to the role table**: on a dark photo
   band a light language's field is a near-white `surface` box (9.5 to 15:1 against the container) and a frame that is 3:1
   on both a near-white fill and a near-black container needs a mid grey (relative luminance about 0.16 to 0.30) that no colour
   role of a light language has. The relaxed reading, WCAG 1.4.11-consistent (the component's boundary must reach 3:1, not
   every edge of it), takes the BETTER of frame-vs-container and fill-vs-container at 3:1, keeps frame-vs-fill strict at
   3:1, and exempts nothing. Under it 18 cells (all playful-pastel on the accent band, a `muted` frame at 2.55:1 and a white
   field at 2.45:1 on `#ff7aa5`) failed before and **0 fail after** the one role change, the accent-band frame being `text`
   (5.75:1 there). Section 2 and section 3 above were amended to say this; the strict wording no longer stands. The impossibility
   is arithmetic, not a test.
- The plan expected `on:"light"` for contact-form; the walk found it sits in a column of a row with a photo background and an
  `overlay` gradient, so the nearest ground is dark and it is `on:"dark"` (the old form already used `surface` fields and
  `inverse` captcha ink, exactly the kit's dark-band output).

**Deviations from the spec.**
- 50 probe candidates (section 4 listed groups of decisions, not a count); the sixth decision `button-underline` (probe follow-up 1b) was
  not in the spec.
- Task 2's first pass wrote an underline submit as a full frame (only `styles.all` was probed on the button); the fix round
  added the probe and the decision. The guide wording changed with it.
- `KitAdapters::formPatch()` is public and takes the proven map as a parameter, so the false branches are testable.
- The render sheet has two forms (bg and surface bands) per cell as the spec said; the accent and dark bands of a form are
  proven by the contrast test only.
- The contrast proof's boundary rule is Ruling 6, not the spec's first wording.
- `form-container`, `form-clip` and `form-field-padding` are extra render-gate codes beyond section 5.

**Known limits.**
- `divi/signup` (Email Optin) and its custom fields are not adapted (no recipe uses them; a later pass).
- No focus ring and no field hover or focus state: none was probed, none is written.
- The per-device padding steps the old recipe submits had (desktop 40px, tablet and phone steps) are gone: a kit gives one
  padding on all devices, as with cards.
- An underline kit's submit hover edge is unchanged (hover recolours only the 0px sides); fill and label hover follow the kit.
- contact-form's own frame (1px `border` colour, a recipe decision) on a dark photo band is the recipe's and is not covered by
  the contrast proof (decorative).
- The placeholder is judged for contrast but its opacity (Chrome applies one) is not modelled; the ink walk's style limits
  (fail-open on module backgrounds) are unchanged.
- The render gate's field and submit selectors come from Divi's class names; the first smoke is the check of them.
- The form golden has no extra underline-kit entry (40 patches as briefed); the underline form is pinned by
  `KitFormAdapterTest`.
- A form's submit label may wrap at 400 px on a wide kit padding; the gate's `form-clip` and `overflow` codes watch it.
- Checks left in the review as minors: the placeholder assertion in `FormMigrationTest` is looser than `KitFormAdapterTest`'s;
  `FORM_KEEPS` is wider than needed; no test asserts the strict reading is unsatisfiable; `KitAdapters`' docblock line about
  the accent-band frame is long and still says "neither edge would reach 3:1" (wording only, comments only).

**Live gates:** pending (the controller fills this in).

**Owner decisions left.** Merge and release (push is the owner's); the blurb and testimonial adapters next; the image-radius
token pass (a separate small branch); image-to-layout later. Guide headroom after this branch, so the next adapter can plan:
default guide 133 B (28,975 of 29,108), the largest kit topic 401 B (9,599 of 10,000), `kit-rules` 5,782 B of its 10,000
bound but each kit topic still points to it, the `topic` parameter description 42 characters (258 of 300), the catalog 8,453 B (about 9,937 B
at 50 kits, 63 B under the bound).
