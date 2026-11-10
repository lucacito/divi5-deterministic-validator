# Studio: build a page from a brief (design)

Date: 2026-11-09. Status: draft for owner approval. Free plugin (WordPress.org), no licence gating.

## Intent and why

Today an AI assistant that builds a page with this plugin writes Divi 5 block markup, hundreds of lines of it, and the validator then accepts or refuses it. That is expensive in tokens, slow, often generic, and every retry costs more. A person without an AI connection gets nothing from the plugin's design engine at all.

The idea: nobody writes markup. A page is described by a short **brief**: an ordered list of sections, each a role and a layout choice, each with its words and pictures, plus one design language and one style kit for the whole page. A deterministic **compiler** turns the brief into valid Divi 5 by filling the plugin's existing, already-validated recipes. The same brief can be produced by a person (the **Studio** screen in the WordPress admin) or by their connected AI assistant (a new MCP/REST route). Because the output is made from proven recipes, the layout cannot be broken, and because the look is data, it can be changed later and the page rebuilt.

Why this is different: every competitor in this space lets an AI write the output. Here the AI (or the person) writes only the meaning, and the plugin guarantees the rest.

## Goals

1. A new admin tab **Lab** (internal name Studio) in the free plugin. A five-step screen with a live preview beside the questions. A person with no technical knowledge, and no AI connection, ends with a good-looking Divi 5 draft page in a few minutes.
2. A brief format and a compiler (pure PHP, deterministic, no network, no AI) that both the screen and the AI route use.
3. An AI route (MCP and REST) where an assistant sends or completes a brief and gets a preview or a saved draft, instead of writing Divi markup. A button in Studio hands the saved brief to the person's assistant to write the words.
4. A saved brief per Studio-made page, so the look or the words can be changed and the page rebuilt.
5. Everything stays inside the existing gate: `LayoutGate` before any save, history snapshot, undo, drafts only.

## Non-goals (version 1)

- Multi-page sites from one brief (later, Pro).
- Rebuilding existing pages that were not made by Studio (the Pro `switch_design` already restyles any page).
- Adding or removing items inside a section (a fourth card). Item counts are fixed by the chosen layout variant.
- Any AI call from inside the plugin. WordPress.org bans remote calls; the AI is always the person's own assistant.
- Editing pages made by hand in the Divi builder.
- Telemetry or analytics of any kind.

## Anti-goals (this fails even if it works)

- A free tab that is deliberately weaker so it can nag for Pro. The only Pro mention allowed in the plugin UI is the one dismissible Dashboard card (`AdminPage::proCard()`); `WpOrgComplianceTest` must stay green and is never weakened.
- Invented facts. An empty text slot stays a clearly marked placeholder; the compiler and every guide never write claims, prices, names or numbers the person did not supply. The Review step lists every slot still holding a placeholder.
- Pages that look like generic AI output. The output must keep the plugin's design quality: one design language, one kit, adjacent sections not repeating the same layout (`DesignVariety`).

## Constraints that already exist

- The validator is deterministic and untouched. No Divi schema from memory: the compiler only moves text, links and picture tokens inside recipe markup that is already grounded in real exports.
- Recipe markup, language and kit resolution already exist as a pipeline (`SectionRecipes::recipe`, `resolveMarkup`: `ImageTokens`, `LanguageResolver`, `KitResolver`, `RecipeTokens`). The compiler reuses it; it does not re-implement any adapter.
- Slots are not declared in the recipes today. Measured on the 75 recipes: 342 `divi/text` blocks and 185 `divi/heading` blocks carry copy, plus 35 buttons and a few accordion, counter, blurb, testimonial and form fields. Copy lives in `innerContent` values (for example `<h4>Featured Projects</h4>`); pictures are `{{aied:image:...}}` tokens. Recipe item counts are fixed (three cards, three pricing tiers).
- Text from a person or an assistant must be safe: plain text or an allow-listed inline subset (see `HtmlProse`), never script, handlers or unsafe URLs; `CodePolicy` and `LayoutGate` run on the compiled result before any save, preview or create.
- The WordPress.org compliance test bans meta-write functions in `wp-plugin/`, so the saved brief cannot use post meta. Precedent for own storage: the history table (`HistoryTable`, `HistoryStore`). Uninstall must remove whatever is stored.
- Tool budget: the ChatGPT importer allows 30 operations and the free (16) plus Pro (14) tools use all 30. A new tool is therefore MCP and REST only (precedent: `html_to_divi`, `get_site_structure`), with a lockstep test that pins its absence from OpenAPI.
- The `get_style_guide` `topic` parameter description is near its schema limit (279 of 300 characters); a new guide topic name must fit or the format must be taught another way.
- The free plugin ships no licence checks and no remote fetches.

## The five steps (decided with the owner)

The screen is a stepper on the left and a live preview on the right; every answer changes the preview. Tab name: **Lab** (owner decision 2026-11-10; "Studio" stays the internal code name).

1. **Goal and business.** What the page is for (sell, book a call, collect sign-ups, explain, show work), who it is for, the one action wanted, business name and one line (pre-filled from the site title and tagline). Chooses the persuasion flow and the suggested section stack (`LandingGuide` stages, recipe `role`).
2. **Look and feel.** One of the 10 design languages, shown on the person's own page skeleton; a button style from the kits; an airy-to-compact density control (recipe `density`). Switching re-skins the preview instantly.
3. **Sections.** The suggested stack for the goal. Each section can be switched on or off, reordered, and given a layout variant chosen from thumbnails (recipes filtered by role and layout, with the item count shown).
4. **Words and pictures.** Per section: the recipe's slots (headline, supporting line, button label and link, item texts) and a picture (Media Library first, then the built-in pack; see `ImageGuide`). Two ways to fill words, both in version 1: the person types them, or a button hands the saved brief to their connected assistant, which writes the words into the same slots over MCP/REST and the preview updates.
5. **Review and create.** The page at desktop, tablet and phone widths, the validator's verdict, the list of unfilled placeholders, and **Create as draft** (always a draft). The brief is saved with the page.

## The brief and the compiler

The brief is a small JSON document: page title, goal, one design language id, one style kit id, and an ordered list of sections. Each section names a recipe (or a role plus layout the compiler resolves to one) and a map of slot id to value, plus a picture choice. Exact field names and the schema are left to the builder, with these requirements:

- It is human-readable, small (target: a typical page far smaller than its compiled markup), validated strictly (unknown keys, wrong types and over-long values refused with exact reasons, like the existing tools).
- Slot ids and roles (eyebrow, headline, body, button label, button link, item title, item text, picture) are derived mechanically from the recipe markup by a build-time extraction script, with a test that fails when any text-bearing block in any recipe is neither a slot nor a named exemption (the same pattern as `TypeMigrationTest` and the other audits).
- Compilation is a pure function: brief in, Divi markup and a report out. Same brief, same output, byte for byte. The report lists unfilled slots, images chosen, and anything refused.
- Compiled output always passes through `LayoutGate` and the existing advisories.
- Preview and create follow the `html_to_divi` model: preview by default, an explicit flag saves one draft through `DraftPage`. Rebuilding a Studio page from its saved brief goes through `HistoryService`, so it is snapshotted and undoable.

## The AI route

A new MCP and REST tool takes a brief (or a brief with some slots empty, and returns what is missing) and returns a preview or a draft, as above. The assistant needs to learn the format cheaply; how (tool description with schema, a guide topic, or both) is left to the builder within the constraints above. Success is measured, not assumed: the token cost of building a page by brief versus by markup is to be measured with the existing token tooling (`scripts/measure-tokens.php`, `docs/token-usage.md`) before any public claim.

## Rules settled after review (the author's calls, open to the owner's change)

1. **Rebuilding never destroys content.** A rebuild from a saved brief overwrites the page only if the page's stored content is still exactly what Studio last compiled for it (compare a content hash kept with the brief) and the page is still a draft; the old content is snapshotted first so it is undoable. If the page was edited in the Divi builder, or is published, the rebuild creates a new draft instead and says so. Nothing is ever overwritten silently.
2. **Placeholders do not block a draft.** Any slot the person or assistant did not fill keeps the recipe's sample copy and is counted as a placeholder (the recipes' sample copy is placeholder copy by definition). Create as draft and the AI route's create flag both work with placeholders present, because the result is a draft; Review and the tool's response list every unfilled slot by section and role.
3. **Item counts.** Supplying more items than the chosen layout holds is refused with a reason that names layouts that hold that many; the Studio screen prevents it by showing the count on each thumbnail. Supplying fewer leaves the remaining items as placeholders and reports them. The same rule applies in the compiler, so the screen and the AI route cannot differ.
4. **Handing the brief to the assistant.** The brief is saved as soon as the person clicks the button (no page needs to exist yet) and gets an id. The button copies a ready-made instruction to the clipboard (it names the brief id and the tool, and repeats the "no invented facts" rule). The assistant reads the brief through the new tool, which reports empty slots, and writes words back into those slots; partial writes are allowed and merge into the stored brief. The Studio screen refreshes its preview on a button press or a poll of the site's own REST route (no outside requests).
5. **Preview and created page.** The preview is the compiled sections rendered inside the active theme's real header and footer where a faithful render works (see the spike). Before sections are chosen, it shows the suggested stack with placeholder copy plus the business name. A created page contains only the compiled sections and the title; the theme supplies the rest.
6. **Library changes.** A brief stores the plugin version and the recipe ids it used. A rebuild re-resolves by id; if a recipe has been removed or its slots changed, the rebuild fails with a clear message and offers to pick a replacement layout for that section, keeping the other sections' words. A rename gets an alias map entry so old briefs keep working.
7. **Links.** Allowed: the site's own pages (picked from a list), an anchor on the same page, `https://`, `mailto:` and `tel:`. Everything else is refused with a reason. A link to an own page that no longer exists, or a page that is not published, is reported as a warning, not refused.

## What is left to the builder

- Brief schema, slot-id naming, extraction script design, where the saved brief lives (own table or similar, within the constraint above).
- How the live preview is rendered. A real Divi front-end render is preferred over an approximation. Candidate approaches: a signed, admin-only preview URL that swaps the compiled content into a render; or a temporary private draft. Divi caches generated CSS per post, so this needs a short spike on the dev site before committing. Fallback if a faithful render is not possible: an approximation clearly labelled as one.
- Which recipes are offered as variants for each goal and stage, and the default section stacks per goal.
- The UI build (the admin already has tabs and an `admin.js`; new tabs are added in `AdminPage`).
- A recipe whose slots cannot be extracted cleanly may be left out of Studio in version 1 and listed as an exemption.

## Later (not in version 1)

- Pro: rebuild all Studio pages in a new look; build a multi-page site from one brief; both use the same compiler and the existing batch and undo machinery. Any Pro hook uses the existing `jhmg_aied_*` extension points; nothing Pro-related is added to the free Studio screen.
- Cloning repeated items so a section can have more or fewer cards.
- A "match my current site" language derived from supplied HTML (the CSS reader behind `html_to_divi` styles already exists).

## Success

- A new user with no AI connection can get from the Studio tab to a good-looking, valid draft page in about five minutes (to be timed with real people, not claimed before).
- An assistant can build a page from a brief with no invalid layouts possible and with materially fewer tokens than writing markup (to be measured).
- The free plugin's listing can honestly say it designs pages, not only edits them. Downloads, reviews and Pro interest are the owner's measure; this design adds no tracking.
