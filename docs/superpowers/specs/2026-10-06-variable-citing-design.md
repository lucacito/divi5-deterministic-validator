# Free plugin 4.7.0: teach the AI to cite global variables, and warn about unknown ones (phase B)

Status: implemented on branch feat/variable-citing (free plugin 4.7.0, not uploaded). Design approved in chat by the owner 2026-10-06 (scope (a): teach only the four proven places; version 4.7.0). Date: 2026-10-06.
Source: phase B of `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md`; evidence `docs/global-variables-citing-evidence-5.14.md` (live renders on Divi 5.14 plus the owner's real builder page).

## Goal

The AI assistant can use the site's own numbers and strings variables in the layouts it writes (spacing and sizes that follow the design system, shared text), in exactly the form Divi's builder stores, and is warned when it cites a variable the site does not have (Divi drops such a value silently while our gate says the layout is valid). Variables are created in Divi's Variables Manager or by the Pro add-on; the free plugin only reads and teaches.

## Evidence this rests on (all on Divi 5.14)

- The builder stores a numbers variable cited from a heading's font size as `"size":"$variable({"type":"content","value":{"name":"gvid-f7rcuxrcdb","settings":{}}})$"` and Divi renders `font-size:var(--gvid-f7rcuxrcdb)` with `:root{--gvid-f7rcuxrcdb: 12px;}` (the owner's page 17).
- The same form, written programmatically, renders for a padding value, a font size, a heading title and a text module's content, for the builder's own variable and for a variable in the builder's stored shape. An ARCHIVED variable renders exactly like an active one. A cited id that does not exist is DROPPED silently (no declaration, no text, no leaked token) while `LayoutGate` reports the layout valid.
- Real exports already use the same `content` type for dynamic values (`post_title` in a heading title, `site_logo`), so the form and the heading-title place are builder-traced; the strings case with a `gvid-` id is additionally proven by the live render and by Divi's own `DynamicContentGlobalVariableOptions` source.
- Not proven, so NOT taught: any other attribute, other modules, `before`/`after` settings on strings, fonts, images, links, gradients.

## Scope

In (free plugin `wp-plugin/`, all read-only): the style guide text; a new `unknown_variables` field in the existing non-blocking `advisories.style_references`; the known-ids lookup that feeds it; the ChatGPT OpenAPI schema for that advisory; one extra note in `list_global_styles`; tests; one real-builder fixture; the real-site check `make verify-global-styles`; version 4.7.0 (header, constant, readme changelog, rebuilt tracked zip).

Out: the validator (`src/`, `wp-plugin/validator/`) stays site-agnostic and unchanged; the Pro add-on; the recipes and landing/site guides (they keep hard-coded values); any write path; any mention of the Pro add-on (the only Pro mention allowed in the free UI stays the one dashboard card).

## Design

### 1. The guide (`StyleGuide`, section "Match the site (colors and presets)")

Replace "Variables other than colors are listed for information only: do not reference them" with guidance that says, in the guide's existing voice:
- `list_global_styles` also lists the site's **numbers** and **strings** variables (ids `gvid-…`, with their values). Reuse one instead of hard-coding the value.
- A **numbers** variable can be cited as the value of a **padding** side (a spacing `padding` value) or of a font **`size`**. A **strings** variable can be cited as a **heading's title text** (`title.innerContent`) or a **text module's content** (`content.innerContent`). Those four places are verified; do not cite a variable anywhere else, and do not reference fonts, images, links or gradients variables.
- The exact markup (inside a block comment the quotes are written as `"`): `"size":"$variable({"type":"content","value":{"name":"gvid-XXXX","settings":{}}})$"` with a real id from the tool in place of `gvid-XXXX`.
- Use only ids the tool lists; never invent one: Divi silently drops a value (a padding, a font size, a line of text) that cites an id the site does not have, and our checks cannot see that. The save, create and preview tools add `advisories.style_references.unknown_variables` when a layout cites such an id.
- The tool is read-only: variables are created in Divi's Variables Manager.
The existing test pinning the old sentence (`tests/StyleGuideTest.php` line ~101) is replaced by tests for the new text. The guide stays within its current size discipline (`docs/token-usage.md` numbers are re-measured with `scripts/measure-tokens.php` and updated where they quote the style guide).

### 2. The advisory (`StyleReferences`, `GlobalStyles::knownIds`, `OpenApiSpec`)

- `GlobalStyles::knownIds()` and `knownIdsWith()` also return `variables`: every variable id in every group Divi reports (archived ones included, the built-in customizer fonts included), or `null` when Divi's reader is missing or throws (then nothing is flagged). `knownIdsWith( ?callable $presets, ?callable $colors, ?callable $variables = null )` keeps the old two-argument calls working.
- `StyleReferences::advise()` scans every string attribute for tokens matching `gvid-[A-Za-z0-9_-]+` (this covers the `content` form and a stray raw `var(--gvid-…)`), counts those absent from `known['variables']`, and returns them as `unknown_variables: list<{id,count}>` (at most 20, ids cut to 100 characters, as presets and colors). The advisory exists when ANY of the three kinds is unknown; when nothing is unknown the payload is byte-identical to today's.
- `StyleReferences::MESSAGE` is extended to say that a missing variable makes Divi drop the value that cites it. It stays an advisory: never changes `valid`, never blocks, never alters content; a refused save still carries violations only.
- It is attached exactly where `style_references` is today (`validate_layout`, `update_page_layout`, `edit_page_content`, `create_page`, every `dry_run` preview; MCP and REST), and `OpenApiSpec`'s `StyleAdvisory` schema gets the `unknown_variables` property (MCP, REST and OpenAPI stay in lockstep). No new tool, no new operation: the ChatGPT budget is untouched.

### 3. Discovery (`GlobalStyles::catalogWith`)

When the variables list holds numbers or strings variables, `notes` gets one line: numbers and strings variables can be cited as described in `get_style_guide`; other kinds are listed for information. The data returned does not change.

### 4. Grounding (fixture and tests)

- New fixture `fixtures/valid/variables-numbers-heading-page-17.json` in the repo's existing wrapper format: the builder-made heading block (815 characters) taken verbatim from page 17, wrapped in a minimal section/row/column. It passes the gate (checked 2026-10-06); the rest of page 17 does not (it holds code modules and image blocks missing attributes) and is not used.
- A test pins that the guide's example markup has the same shape as the citation found in that fixture (name prefix, type, `settings`), and that the fixture validates.
- Optional strengthening, not blocking: a real builder export of a STRINGS variable cited from a heading title would make the strings case builder-traced as well.

## Testing

Unit (`make test`): `StyleReferencesTest` (unknown variable flagged with count and cut id; known and archived ones not; a raw `var(--gvid-…)` is caught; unreadable variables reader means no variable advice; presets and colors behaviour unchanged; payload byte-identical when nothing is unknown); `GlobalStylesTest` (knownIds with variables, null when unreadable, the new note appears only when numbers or strings variables exist); `StyleAdvisoryHandlersTest` (MCP and REST both carry `unknown_variables`); `OpenApiSpec` test (schema property present); `StyleGuideTest` (the four allowed places, the markup, the "never invent an id" and "fonts, images, links, gradients" warnings, no mention of any paid add-on); fixture validity; `WpOrgComplianceTest` unchanged and green.
Real site: `make verify-global-styles` gains checks on real Divi: a layout citing a real variable id gets no `unknown_variables`; one citing a missing id does, over both the REST and MCP handlers; nothing is written. Plugin Check clean on the dev copy and on the extracted zip before any upload.

## Release

Free plugin 4.7.0: header and `AI_EDITOR_DIVI5_VERSION`, readme `Stable tag` and a changelog entry written for users (the AI can now use your Divi number and text variables, and is warned when it cites one your site does not have), rebuilt `jhmg-ai-editor-for-divi-5.zip`. Nothing is uploaded or pushed; the owner does the WordPress.org upload.

## Risks and decisions to confirm

- The teaching is deliberately narrow (four places). If the AI avoids variables in other obviously valid places, that is the cost of staying grounded; widen later from evidence.
- A variable written by an older tool or plugin WITHOUT the builder's stored shape (`id`, `variableType`, `lastUpdated`) is ignored by Divi at render time even though `list_global_styles` shows it. The advisory does not detect that; the Pro tool now writes the full shape (0.8.0).
- The advisory scans every string attribute for `gvid-` tokens, so a text that merely mentions an id in prose would be flagged. Acceptable: such text is rare and the message only advises.
- Divi may change how it stores or renders variables in a later version; the live check (`make verify-global-styles`) and the evidence doc are the early warning.
