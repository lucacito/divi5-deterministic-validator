# Citing global variables in a layout: live evidence (Divi 5.14, Docker dev site, 2026-10-06)

Spike for phase B of `manage_global_variables` (spec `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md`). Script: `scripts/spike-global-variables-citing.php` (dev sites only, self-cleaning: it creates one numbers and one strings variable through the real Pro tool, builds throwaway PUBLISHED pages, fetches each page's HTML and same-site stylesheets, deletes everything and proves the stored variables are identical to the start). No product code changed.

## Question

Can a layout cite a numbers variable (a padding and a font size) and a strings variable (a heading's title and a text module's content) so that real Divi renders the value, and what happens when the cited variable is archived or does not exist?

## Source facts (Divi 5.14 theme source, read only)

- `Utils::resolve_dynamic_variable()` turns `$variable({"type":"content","value":{"name":"gvid-abc123"}})$` into `var(--gvid-abc123)` (any non-color type).
- `DynamicContentGlobalVariableOptions` renders a STRINGS variable's value as dynamic content (`wp_kses_post`), numbers and the rest with `esc_html`.
- `GlobalVariableReferenceUtils::sanitize_css_reference()` accepts only `var(--gvid-...)` (id `[a-z0-9-]+`).
- Real exports in `fixtures/valid/` use the `content` type for dynamic content (`site_logo`, `post_title`, settings `{}` or `{"before":"","after":""}`); NONE cites a `gvid-` id. Colors use `type: color` and `gcid-`.

## Results (what each form produced on the rendered page)

| Variant (all with an ACTIVE variable unless stated) | Our gate (`LayoutGate`) | Divi's output |
|---|---|---|
| D control: literals (`40px`, `30px`, plain text) | valid | `padding-top:40px`, text shown (the probe renders what it is given) |
| A: `$variable({"type":"content","value":{"name":"gvid-...","settings":{}}})$` in padding-top, font size, heading title, text content | valid | **Nothing.** The `padding-top` declaration is dropped from the CSS entirely, the heading and text show no string (not even the raw token), no `gvid-` appears anywhere in the page or its CSS |
| E: raw `var(--gvid-...)` in padding-top and font size, literal text | valid | `.et_pb_section_0.et_pb_section{padding-top:var(--gvid-...)}` and the font size use `var(--gvid-...)`, **but no `--gvid-...:40px` definition is emitted anywhere** (page HTML or its stylesheets), so the declaration has no value to resolve to |
| F: `$variable({"type":"content","value":{"name":"gvid-...","settings":{"before":"","after":""}}})$` in heading title and text content | valid | **Nothing:** the string is not shown |
| E archived (both variables archived) | valid | identical to E |
| F archived | valid | identical to F |
| E missing (a cited `gvid-` id that does not exist) | valid | `padding-top:var(--gvid-<missing>)`, identical in shape to E with a real id |

## What this proves, and what it does not

1. **The validator and gate cannot tell a working citation from a dead one.** Every variant is `valid`. Citing a variable is silent loss when it is wrong (the same character as an unknown preset id), so nothing may be taught until a form is proven to render.
2. **Neither documented form works end to end for a programmatically written page on 5.14.** The `$variable(...)$` content form is dropped by Divi for a padding and for text; the raw `var(--gvid-...)` form is emitted but its definition is not.
3. **Not shown:** whether Divi defines `--gvid-...` once the builder itself has saved a page that uses the variable (for example by recording the used variable ids when the builder saves, or by a stylesheet generated on a builder save), or which attributes accept a variable at all. A page written through the REST/MCP path never went through that save. The citing forms the builder stores are therefore unknown, and the plan's rule applies: markup handed to the AI must trace to a real export.
4. Archived and missing variables render exactly like active ones in the forms that emit anything, so archiving is NOT a safe way to retire a variable that pages still cite.

## Recommendation

- Do not teach citing in the guides yet.
- Next step needs ONE real builder export: in the Divi 5 builder create a numbers variable (for example `12px`) and a strings variable in the Variables Manager, apply the number to a section's top padding and the string to a text or heading module, save the page, and capture the page content (`make export-layouts`, or the page's stored content). From it we take the exact stored form, repeat this spike with that form on a programmatic page, and only then write the guides.
- Independent of that: an advisory like `StyleReferences` (non-blocking, free plugin) could warn when a layout cites a `gvid-` id that the site does not have; it needs the same real export to know what a citation looks like.
