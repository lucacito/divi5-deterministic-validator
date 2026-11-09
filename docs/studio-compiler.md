# Studio compiler (dev note, unreleased)

Plan 1 of 3 for Studio (spec `docs/superpowers/specs/2026-11-09-studio-design.md`, plan `docs/superpowers/plans/2026-11-09-studio-compiler.md`). Pure PHP in `wp-plugin/src/`, no screen, tool, route or storage yet (plans 2 and 3).

## What it does

A **brief** (small JSON) goes in; valid Divi 5 markup and a report come out. Each section of a brief is one of the 75 recipes with its slots filled, resolved through the normal serve pipeline for the chosen design language and kit, joined, and checked by `LayoutGate`.

```json
{
  "title": "Acme plumbing", "goal": "book", "language": "editorial", "kit": "quiet-solid",
  "sections": [
    { "recipe": "hero-cta",
      "slots": { "heading.1": "Fix leaks fast", "button.0.text": "Book now", "button.0.link": "/contact/" },
      "pictures": { "section.0.background": { "pack": "hero-blue-1" } } }
  ]
}
```

`version` (1) and `kit` are optional. `goal` is one of sell, book, signup, explain, showcase.

## Classes

| Class | Job |
|---|---|
| `StudioSlots` | `extract()` the slots of a raw recipe, `fill()` them, `recipesWithSlot()`; `EXEMPT_PATHS`, `isDynamic()` |
| `StudioBrief` | `check()` a brief strictly: stable code + path + message for every problem, a normalised copy when ok |
| `StudioLinks` | the allowed link forms: https, mailto, tel, `#anchor`, `/site-path` |
| `StudioCompiler` | `compile()`: brief to markup, `unfilled`, `refused`, `warnings`, `advice` (design variety, loops) |
| `SectionRecipes::resolveRaw()` | the serve pipeline for markup that is not a stored recipe |

## Slot ids

`<block>.<index>[.<field>[.<k>]]`, `index` = order among all blocks of that name in the recipe. Examples: `heading.1`, `text.0.body.2` (third paragraph or heading of a text module), `button.0.text`, `button.0.link`, `image.0` and `image.0.alt`, `section.0.background`, `testimonial.0.portrait`, `contact-form.0.button`. Roles: headline, subhead, eyebrow, body, item_title, figure, field_label, attribution, button_label, link, picture, alt. The table is pinned by `tests/golden/studio-slots.json` (regenerate with `AIED_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter StudioSlotsTest` and read the diff).

## Rules worth knowing

- Supplied text is data. It is trimmed, HTML-escaped, and `{` and `$` are written as entities, so no colour token, kit marker or Divi dynamic-content token can be built from it (the serve pipeline runs after the fill).
- An empty or whitespace-only value is not supplied: the recipe's sample copy stays and the slot is listed in `unfilled`. Placeholders never block a draft.
- A loop's own values (`$variable(` tokens: titles, links, pictures, alts) are never slots.
- Every `divi/button` has a `.link` slot; most recipe buttons carry no `linkUrl`, so filling adds the key beside `text` (the shape real exports write).
- A filled block is re-encoded with `RecipeTokens::encodeJson` (same value, different escape style from some stored recipes); blocks with nothing filled keep their exact bytes.
- Whole-field slot: an HTML field with no paragraph or heading element (loose text) is one slot, `element = StudioSlots::WHOLE`.
- A `<div>` that holds only copy (no paragraph, heading or other div inside) is a slot like a paragraph; a wrapper div is not. `testNoCopyIsLeftInsideAnHtmlField` fills every element slot and fails if visible text remains in a field.
- Counter figures (`number-counter`, `circle-counter` `number`) must be plain numbers (`1250`, `1,250`, `98.6`; a circle counter 0 to 100): Divi reads them with `parseFloat`, so "$2M" would show NaN. Refused with `FIGURE_NOT_NUMBER`; the %, + or $ sign comes from the counter's own settings.
- Text may not hold control characters (NUL and the like, `TEXT_INVALID`); tabs and line breaks are fine.
- Link addresses refuse `" < > \` { } | ^` and the backslash; picture addresses allow only `A-Za-z0-9 - . _ ~ : / ? # @ ! & * + , = %` (percent-encode anything else), because a background address is written into a CSS `url()`.
- `StudioLinks::sitePath` strips the install's own path for an https address on a site in a subdirectory; a `/path` link is read from the host root, as a browser reads it.
- Picture slots: `{"pack": token}` or `{"url": https address or /site-path}`; a loop's picture is not a slot.

## Proofs

`StudioLibraryProofTest`: every recipe under every design language with a unique marker in every text, alt and link slot compiles valid, each marker appears exactly once, nothing but pictures is unfilled; and 200 seeded hostile briefs (`AIED_SEED=n`) never break a page, form a token, marker, block comment or disallowed link. The whole-library test found a real defect while this was built (filling a loop image's alt overwrote the loop's dynamic token); `StudioSlotsTest::testNoSlotCarriesALoopsDynamicContent` pins the fix.

## Open (for the Studio screen's live gate)

- Plain fields are HTML-escaped on fill; whether Divi shows `&amp;` or `&#36;` literally in a heading, button label or counter number is not verified on a real render.
- Filling a rich slot drops its inline markup (links, bold) by design in version 1.
