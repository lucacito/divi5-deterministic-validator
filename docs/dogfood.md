# Dogfood: measure what an assistant builds from the served guide

Dev-only routine for the JHMG AI Editor for Divi 5 repo. Spec: `docs/superpowers/specs/2026-10-22-dogfood-harness-design.md`
(with an Outcome section); plan: `docs/superpowers/plans/2026-10-22-dogfood-harness.md`. Nothing here is shipped in `wp-plugin/`.

## What it is

A fresh assistant is given ONLY the guide the plugin serves (the default style guide plus five topics) and a brief, and builds or edits a
Divi 5 page. Three scripts make that repeatable and its result measurable, a pinned corpus documents what real assistants got right and
wrong:

| Script | What it does | Needs |
|---|---|---|
| `scripts/dogfood-prepare.php` (`tests/Support/Dogfood/Prepare.php`) | writes the six guide files and `TASK.md` (and, for an edit, a base `page.html`) | PHP only |
| `scripts/dogfood-score.php` (`Scorer.php`, `Page.php`, `Expected.php`) | statically scores a page against what the real kit adapters write for the kit and the language adapters write for the language | PHP only |
| `scripts/dogfood-render.cjs` (+ `scripts/lib/dogfood-render.cjs`) | validates the page through the real `LayoutGate`, renders it at 1280 and 400 px on the dev site, deletes it | Docker dev site, Playwright |

The corpus is `fixtures/dogfood/`: the final pages of the guide rounds before the planned 4.14.0 release (six freehand pages, the edit base and the edited page)
and `corpus.json` with the score of each, pinned by `DogfoodCorpusTest`.

No script calls a model, a network service or a key. The assistant step is run by a person, or by Claude Code's `Agent` tool, outside the
scripts. The harness reads the plugin only through its public classes (`StyleGuide`, `StyleKits`, `DesignLanguages`, `SectionRecipes`,
`KitAdapters`, `KitResolver`, `RecipeTokens`, `LanguageAdapters`).

## When

Before releasing a change to a guide wording, a kit, an adapter or a recipe: it finds the gaps that thousands of pure tests do not (the
guide rounds before the planned 4.14.0 release found every real guide gap of that release this way). It is not part of `make test` beyond its own pure tests, and it is not
a release gate: an assistant run is a sample, not a proof.

## The routine

Order: prepare, assistant, score, render. One kit under one language per run.

1. **Prepare.** `make dogfood-prepare KIT=<kit> LANGUAGE=<language> [TASK=edit] [OUT=<dir>]`
   (e.g. `make dogfood-prepare KIT=double-frame LANGUAGE=corporate-trust`). Valid ids are listed in the error when one is unknown
   (`StyleKits::ids()`, `DesignLanguages::ids()`). It writes to `dist/dogfood/<kit>-<language>` (the edit task to
   `dist/dogfood/<kit>-<language>-edit`): `01-style-guide.md` (the default guide), `02-kit-rules.md`, `03-style-kit.md`,
   `04-design-language.md`, `05-controls.md`, `06-forms.md`, `TASK.md`, `prepare.json`, and for `TASK=edit` also `page.html` (six recipes
   under the kit: hero-cta, card-grid-3, pricing-three-tier, faq-accordion, team-grid, contact-form). The output is byte-identical on
   repeat. Re-running it into the same out directory is the normal way to refresh after a guide change: the files of the
   earlier run (recognised by the `prepare.json` it wrote: the guide files, `TASK.md`, and `page.html` only if that run was an edit
   prepare) are overwritten, while your page and any foreign file are never touched. Anything else that already exists with different
   content (a same-name file of yours, a directory with no valid `prepare.json`, or an edit prepare into an earlier freehand directory
   whose `page.html` is the assistant's page) is refused with exit 2 and nothing is written; move it away or pass another `OUT`.
   A directory whose `prepare.json` names ANOTHER kit or language is refused too (exit 2, nothing written): a refresh would leave a page
   of one design under the guide of another. Use another `OUT`. The task (freehand or edit) may differ.
   The assistant's own `page.html` (freehand) or `page-edited.html` (edit) is not a target and is never touched.
2. **Assistant.** Start a FRESH assistant that has none of this conversation's context. In Claude Code: the `Agent` tool (a
   general-purpose agent) with exactly this prompt, `<out>` being the absolute path of the directory step 1 wrote:

   ```
   Read <out>/TASK.md and do exactly what it says. Use only the files in that directory; do not read any other file, search the web or look at a repository.
   ```

   Or paste `TASK.md` into any assistant together with the six guide files. `TASK.md` itself says: use only these files, do not look at the
   repository, do not use recipes, save the page as `<out>/page.html` (edit: `<out>/page-edited.html`), and reply with the path and one
   paragraph listing what was unclear, missing or contradictory. A subagent started in this repository could in principle read other
   files; the prompt and the brief are the only guard, so keep the prompt exactly as above and do not add hints.
3. **Score.** `make dogfood-score PAGE=<out>/page.html KIT=<kit> LANGUAGE=<language>` (add `ARGS="--json"` for the JSON, `ARGS="--min 90"`
   to exit 1 below a percentage; a page with nothing checked has an overall of `n/a`, which counts as 0 for `--min`, so `--min 1` fails it and `--min 0` passes). `LANGUAGE` defaults to the language whose own kit is `<kit>`, else `editorial`; the output names the
   language used. Always pass it for a run. For an edit, score `page-edited.html`.

   Absolute paths with spaces work: the Make targets quote `PAGE`, `OUT`, `KIT`, `LANGUAGE` and `TASK` as one argument each (a single quote inside
   a value is escaped); `ARGS` is passed as written, so it may carry several flags.
4. **Render.** `make dogfood-render PAGE=<out>/page.html` (dev site, Docker up, ONE run at a time). It runs the script's pure tests first,
   then saves the page as one draft titled `aied dogfood <runToken> <name>`, renders it at 1280 and 400 px, writes
   `dist/dogfood/<name>/shot-1280.png`, `shot-400.png` and `render.json` (`page`, `title`, `valid`, `widths`, `cleanupOk`, plus `verdict`, the validator's violation codes, when the layout was not valid, and `error` when the script itself failed), and deletes the draft (also on Ctrl-C). Exit 0 only when the
   layout is valid, both widths were measured, horizontal overflow is 0 at both, there is no console error and no PHP error text, and the
   cleanup finished. Look at the two screenshots. There is no `--kit` option: the renderer does not need the kit. Options: `--name <label>`
   names the run (the draft's title and, unless `--out` is given, the output directory `dist/dogfood/<label>`; by default the name comes from the
   page's folder, plus the file stem when it is not `page.html`, e.g. `edit-glass-edge-page-edited`) and `--out <dir>` chooses the output directory. Before the run
   `pgrep -fl "node scripts/(verify|dogfood)-"` should print nothing except itself (anything else is another gate or an earlier run); leftovers after a crash are removed with
   `make dogfood-render ARGS=--clean-orphans`, only when no run is going. The run can take a minute or more (the script scrolls the page and waits for it to settle at each width): wait
   for it to finish instead of starting anything else on the dev site, and never poll it with a second job. The page is saved EXACTLY as given (no wrapping, no token resolving), which is what an assistant would
   do. The transport is one `wp eval` call, so a page much over about 95 KB may not fit it; the corpus pages are 32 to 61 KB.
5. **Read the assistant's paragraph** ("unclear / missing / contradictory") next to the score: the score says WHERE the page departed from
   the kit, the paragraph says WHY the assistant thinks it did.

## Reading the score

The shape of the output (the numbers here are made up for illustration):

```
dogfood score <page> kit=<kit> language=<language>
button 15/15 100.0%
rule 4/6 66.7%
... (chip, framed, item, form, image, type, rhythm)
overall 70/80 87.5%
FAIL rule.width divi/divider#3 (rule on light): expected 1px, observed 2px
FAIL type.weight divi/heading#2 (title on light): expected 600, observed absent (Divi 500)
UNCLASSIFIED divi/group#9: carries a fill and a heading: no rule, tag, card or panel fits
UNCLASSIFIED divi/section#0: a section with desktop padding 8%, not a px value: no rhythm role
```

- **Nine categories**: `button`, `rule`, `chip`, `framed` (cards and panels, with a frame or a radius), `item` (accordion and toggle items),
  `form`, `image` (the kit decides these), and `type` (headline weight, letter spacing and capitals) and `rhythm` (section top and bottom
  padding at desktop, tablet and phone), which the design LANGUAGE decides; 39 named checks in all (`Scorer::CHECKS`, each with a planted
  pass and fail in `DogfoodScorerTest`). A category with nothing to check is `n/a`, which is not a failure.
- **Type.** A `divi/heading` is placed by the `TypeAudit` rule: h1 or h2 in the display font is `display`, h3 in the display font is
  `title`, h4 in the eyebrow font is `eyebrow`; a `divi/text` whose desktop body font is `uppercase` in the eyebrow font is an `eyebrow`
  label. The font is a `{{aied:font:<role>}}` token or a literal family whose first name is the first name of the language's font for
  that role (a family the language uses for several roles counts for each). Weight, letter spacing and capitalisation are compared with
  `LanguageAdapters::patch(..., 'type', <role>, <language>)`. A value the page does not write is Divi's default (weight 500, spacing 0px,
  no capitals, measured in `fixtures/shapes/language-type-vocabulary.md`) and passes only where the role's value is that default; a role
  whose capitalisation is `none` passes with no key or `none`, never with a transform. A heading with neither a level nor a family is
  only counted (`ignored`); any other heading or uppercase text the rule cannot place (an h4 in the display font, an h2 in the body font,
  an h5) is `UNCLASSIFIED`.
- **Rhythm.** A `divi/section` whose desktop padding is top = bottom, both `Npx` with N > 0, is matched to the nearest of the language's
  `section`, `open` and `tight` desktop values (`DesignLanguages::sectionSpace()`, nearest in ratio, a tie to the first); then desktop,
  tablet and phone are compared with that role's patch. A breakpoint (or one side of it) the page does not write takes the value of the
  one above, as Divi cascades it. No desktop padding, an asymmetric, percent, `vw` or 0px one, or one whose ratio to the language's
  `section` value is outside `RhythmAudit::BAND` (0.45 to 1.6) is `UNCLASSIFIED`.
- **Expected values are the adapters' own output.** The scorer builds a one-block marker, resolves it with `KitResolver` and
  `RecipeTokens` as a recipe is served, and reads the attributes it wrote. No value is typed by hand.
- **Best role.** A block that more than one role could produce (button `control` or `control-secondary`, rule `rule` or `rule-strong`,
  `card` or `panel` or a ground, `item` or `item-rule`) is judged against the role with the most passing checks; a tie goes to the first
  role in `KitAdapters::APPLIED` order. A failure line names the role it was judged as.
- **Bands.** A freehand page has no markers, so the surface a block sits on is inferred: the nearest painting ancestor's background is
  looked up in the language's palette in the fixed priority `bg, surface, surface-glass, accent, text, overlay, inverse` and mapped to
  `light`, `surface`, `accent`, `panel`, `dark`; a background image or an overlay gradient is `dark`. Palettes repeat a hex across roles
  (`#ffffff` is both `surface` and `inverse` in playful-pastel), and the priority decides. Any other colour or gradient is band `unknown`,
  and then colours are judged against the union of what the role writes on any band it takes, which is lenient. The same union is used when
  the block's band is known but the role does not take it (a tag on an inverted `panel` band, a rule row on an `accent` band, a card on a
  `panel` band). In both cases the report keeps the band the block really sits on (`unknown` or the real name) and a failure carries a
  `note` saying the colours were judged against the union (printed in brackets in the text report). A button on a `surface` band is not
  such a case: the adapter itself uses its `light` treatment for it, so it is judged on `light` without a note.
- **Colours are role-set membership**, not one exact hex: the observed colour (normalised, alpha rounded to two decimals, so `#FFF`,
  `#ffffff` and `rgb(255,255,255)` are the same) must be one of the colours the role writes on that band. Lengths are compared in px with
  tolerance 0.
- **`UNCLASSIFIED` is neither good nor bad.** The scorer could not place the block (a shaped image, a fill-only box with a heading, an icon
  with a frame but no fill) and counted it as neither passed nor failed. Read the list: a long one means the page is built from shapes no
  kit decides, or the scorer is too cautious there. `ignored` counts undecorated wrappers; it appears in the `--json` report only, not in the
  text report.
- **What it does NOT see**: pixels (a rendered look is judged from the screenshots), copy, layout quality, and for an edit whether everything else was kept byte for byte (the whole edited page is scored, not the four changes: diff
  `page.html` and `page-edited.html` yourself).

### Blind spots (known, deliberate)

- **A 100% page is not "fully kit-conformant".** The 39 checks cover frames, radii, fills, rule/tag/icon colours, headline weight,
  tracking and capitals and section padding, but the adapters write
  more than that and the scorer never looks at it: a button's label colour, shadows, padding and hover states (button, card, form), the
  accordion and toggle title, body and icon colours, a form's field ink, placeholder and captcha colours, and card padding. A page can
  score 100% with unreadable or off-kit text and still be wrong there; the screenshots are the check for those.
- A **frameless bordered box of plain text** (a group with a border, no fill and no heading, button or image inside) is classified as a
  tag and scored as one, even if the assistant meant a quote box or a callout.
- A **button filled with a gradient** reads as filled with a colour that cannot be judged: `button.fill` passes where the kit fills the
  button, `button.fill-colour` fails (observed `gradient`), and where the kit draws a transparent button `button.fill` fails. The same
  holds for a form's submit.
- A button or submit with **no border written** is read as Divi's own default (`.et_pb_button{border:2px solid}`: 2px, solid), so it fails
  `button.edge` under a kit with no button border (the adapters write `width: 0px` explicitly) and passes under a 2px kit. Its radius is
  read strictly: no radius written never counts as square.

- An **absent corner radius counts as square (0px)** on everything but a button, which has a non-zero Divi default of its own. A page that
  leaves a 0px-radius kit's radius out passes; one that leaves a rounded kit's out fails.
- **Bare cards**: a block with neither a frame nor a radius cannot be told from a plain filled wrapper, so it is unclassified, not scored.
  A freehand card that drops both its frame and its radius is not caught.
- **Global colour references** (a colour written as a global colour or variable reference instead of a hex) are not looked up, so they
  cannot match a role and a background written that way gives band `unknown`.
- **Images** are checked loosely: the kit's media radius OR 0px passes (the shipped recipes keep flush images square under every kit),
  and shaped images (circles, arches, 999px) are unclassified. A page that leaves every image square passes.
- A rule on top of a framed block is judged on width and style only (no adapter defines its colour band).
- **A hand-written heading's role is guessed** from its level and font only. An h2 meant as a small subtitle in the display font is
  judged as a `display` headline; an h4 eyebrow set in the display font, or a headline in the body font, is unclassified, not failed. When
  the language uses one family for several roles (glass-dark's Inter) the font cannot tell them apart and the level decides.
- An **eyebrow label is found by its capitals**: a `divi/text` that is not `uppercase` is body copy. Under a language whose eyebrow has
  no capitals (playful-pastel) a hand-written label is therefore not found and not checked; one written in capitals there is found and
  fails `type.capitalization`. Size, line height, colour and family are never judged.
- **Sections with literal padding that matches no role** (asymmetric, a percentage, 0px, no padding, or far from every role) are listed
  as unclassified, so a page that leaves every section at Divi's default scores `n/a` for rhythm, not 0. A section near a role but off
  by a few pixels is judged against that role and fails.
- The edit base's images point at `https://example.com/...` (the test bootstrap's `plugins_url` shim): on the dev site they do not load.
  That is not a console error of the page. It is kept so the base stays byte-identical to the corpus.

## What to do with a gap

A failure is one of three things, in this order of likelihood:

1. **The guide was unclear or silent.** The assistant's paragraph usually says so. Fix the wording in the generator that owns it
   (`StyleGuide`, the topic builders, the kit and language data), not in the output, then re-run the routine with the same kit.
2. **The shape question has no proof.** If the guide teaches a path nobody rendered, run a probe (`make verify-shapes`,
   `docs/shape-evidence-5.15.1.md`) before teaching it.
3. **The scorer judged wrongly** (a role it cannot place, a band it guessed). Fix the scorer and pin the case with a test (a planted pass
   and fail in `DogfoodScorerTest`).

Never edit `corpus.json` numbers by hand: a scorer change re-measures the corpus and re-pins the numbers in the same commit, with the
reason in the commit message. A fresh run is never added to the corpus (it would pin a live assistant's output).

## Costs

One run is one assistant session; the scripts cost nothing. Sizes come from `prepare.json` (it records each file's bytes without
`TASK.md`, plus `guide_bytes`, the sum of the six guide files). For `double-frame` under `corporate-trust`, freehand: `guide_bytes` is
63,564 (about 16,000 tokens read, taking bytes / 4 as an estimate). An edit run reads the same guide plus the base `page.html`
(54,566 bytes here, about 14,000 tokens) and writes the whole page back. A written page is 32 to 61 KB in the corpus (roughly 8,000 to
15,000 output tokens). These are estimates, not measurements of a tokenizer; the rendering step uses no model.

## Corpus

`fixtures/dogfood/` (nothing else lives there, total under 400 KB), described by `corpus.json` in this order:

- `hairline-dashed` (tech-mono), `heavy-block` (bold-vibrant), `dotted-pill` (playful-pastel), `underlined-label` (glass-dark),
  `soft-rounded` (organic-warm), `gilt-hairline` (luxe): freehand pages from the guide rounds before the planned 4.14.0 release, final version only.
- `edit-glass-edge/page.html`: the edit base, exactly `Prepare::editBase('glass-edge', 'glass-dark')` (kit- and language-built, so it
  scores 100% in every category it has; its three sections without a px rhythm are the recipes' named exemptions and are unclassified).
- `edit-glass-edge/page-edited.html`: the page the edit assistant produced.

Each entry records the kit, language, task, the page and, per category and overall, `[checked, passed]` plus the `unclassified` count.
`DogfoodCorpusTest` re-scores every page and fails when a number moves, so a scorer change is visible; every page is also checked valid
by `LayoutGate`, and `tests/DogfoodCorpusTest.php` pins that nothing of the harness is shipped under `wp-plugin/`.

## Known limits of the harness

The ten "Spec issues found" of the plan, one line each:

1. Palette hex collisions make the band lookup depend on a fixed priority, and bold-vibrant and brutalist-flat cannot tell a quiet `border`
   rule from a strong `text` rule by colour (the width decides).
2. Framed groups are judged against the card radius and tags against the control radius (no adapter writes a control radius on a group).
3. A card with neither a frame nor a radius is unclassified.
4. Images pass the media radius or 0px; shaped images are unclassified.
5. A rule on a framed block: only width and style are judged.
6. An edit run is scored on the whole page; a base-aware mode that scores only the changed blocks is not built.
7. `dogfood-render` takes no `--kit` option (controller ruling 7).
8. The edit base's images stay `example.com` placeholders.
9. Exit codes: 0 after measuring, 1 only with `--min`, 2 for a usage error or a page that does not parse (the spec's "always 0" is amended).
10. The edit task's default output directory gets a `-edit` suffix so it never collides with a freehand run.
