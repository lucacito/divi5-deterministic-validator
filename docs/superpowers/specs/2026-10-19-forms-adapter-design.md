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
  or `inverse` and `muted` otherwise (Ruling 1: a field's frame is its affordance, so it must be at least 3:1 on the field fill
  and the container in every language; the card's decorative `border` hairline is NOT enough, `muted` is, as the recipes
  already use). An underline kit draws the bottom edge only IF the probe proves it on a field; otherwise it draws the full
  frame at 1px (Ruling 2, decided by the probe; the fallback is stated in the guide).
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
against field fill and against the container, the submit label on its fill and the submit border against the container, the
captcha ink on the band. Floors: text 4.5:1, placeholder 4.5:1, frame and button border 3:1 (an outline or ghost submit:
border and label 4.5:1 / 3:1 as the button proof defines). A failing cell changes the role table, never a baseline.

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
