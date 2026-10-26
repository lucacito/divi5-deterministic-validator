Audience: the owner (decides what to apply; nothing here has been applied)
Status: DRAFT suggestions only. wp-plugin/readme.txt was NOT edited
Date: 2026-10-25

# Suggested edits: WordPress.org readme and divi5lab.com copy

## Ground rules for the readme (from CLAUDE.md, "WordPress.org compliance rule")

- No upsell in the free plugin beyond the one dismissible Dashboard card. In the readme that means: keep the existing
  neutral "= Add-on =" paragraph as it is; do NOT add Pro feature lists, prices, "upgrade" wording or comparison tables.
- Every claim must stay true for the free plugin alone. `tests/WpOrgComplianceTest.php` checks the readme's claims, including
  the "more than N Divi 5 block types" number against `SchemaRules`; run `make test` after any readme change.
- Changes go in with the 4.15.0 release (version bump, changelog, zip rebuild, Plugin Check on dev copy and zip). Items marked
  "after 4.15.0" describe work that is on main but not yet released.

## A. `wp-plugin/readme.txt`

### A1. Short description (line 11, max 150 characters)

```
- MCP server for Divi 5: let Claude, ChatGPT or Cursor edit and build your pages, even from a screenshot or URL. Every change is checked and undoable.
+ MCP server for Divi 5: Claude, ChatGPT or Cursor edit and build your pages. A checker that is not AI tests every save, and every change can be undone.
```
Why: leads with the difference (a non-AI checker), which competitors with similar features do not state the same way.
Count the characters before applying (the alternative is about 148). Optional: keep the current line if you think
"screenshot or URL" converts better; this is a judgement call, unverified either way (A/B is not possible on WordPress.org).

### A2. Tags (line 3)

```
  Tags: divi 5, divi, mcp, claude, chatgpt
```
No change suggested. WordPress.org uses at most five tags. Possible swap to test later: `chatgpt` -> `ai`. Unverified whether
it helps; check the directory search for "ai divi" before and after.

### A3. Description: "What makes it smart" (lines 39-54) — after 4.15.0

```
  * **The checker is not AI.** ...
+ * **It refuses the values that crash Divi.** We tested every content setting of every Divi 5.15.1 module with values of the
+   wrong type and found 92 that make Divi stop the whole page with a server error. The checker refuses all of them before
+   they are saved.
```
Source: `docs/render-fatal-sweep.md`, `src/VerifiedContentTypes.php`. Keep it out of the readme until 4.15.0 ships the guards
(they are on main, not in 4.14.0).

```
- * **Consistent looks.** Ten design languages (...) and 40 style kits set the exact look of buttons, cards, accordions, toggles, contact forms and image corners, so a whole page matches.
+ * **Consistent looks.** Ten design languages (...) decide headline type and section spacing, and 40 style kits set the exact look of buttons, cards, accordions, toggles, contact forms, testimonials, icons, dividers, tags and image corners, so a whole page matches. Every kit was checked for readable text in every language.
```
Source: CLAUDE.md "Type adapter", "Rhythm adapter", "Carriers adapter", "Rules and chips adapter". After 4.15.0 only.

### A4. FAQ: new entries (free-only, factual, no upsell)

```
+ = What does the checker not catch? =
+
+ It checks that a page is built the way Divi 5 accepts: known blocks in allowed places, values of the right type, no custom
+ code. It does not judge how the page looks, so review every draft before you publish it. Modules from other Divi add-on
+ plugins are unknown to it and refused. Restoring an earlier version is not checked, on purpose: it brings back your own
+ page exactly as it was, and your assistant is told whether that version passes.
```
Why: honest limits reduce one-star reviews from wrong expectations; it also answers "how is this different" credibly.

```
+ = What happens when Divi releases a new version? =
+
+ Each Divi release is compared with the previous one and the plugin's checks are re-run on it before the readme says it
+ is verified. Until then, the plugin tells you on its Dashboard when your Divi is newer than the newest version it was
+ verified on.
```
Source: CLAUDE.md "make divi-currency"; changelog 3.4.0 ("A notice when your Divi is newer..."). Verify that the notice
still exists in 4.15.0 before publishing (`DiviCompat::TESTED`).

```
+ = Can I use it on client sites? =
+
+ Yes. Each site gets its own key, and the key works as the WordPress user who created it, with that user's permissions.
+ Give clients' sites a dedicated editor account if you want the AI limited to page editing.
```
Verify the "dedicated editor account" advice against `PageAccess` (needs `edit_pages`) before applying.

### A5. FAQ: tighten one existing answer

```
  = Can the AI break my pages? =
- The AI can make mistakes, but a broken page is never saved by an AI edit.
+ The AI can make mistakes, but the checker refuses every layout that breaks its rules, and those rules include the values
+ we have seen crash Divi. A layout that passes can still look wrong, so review drafts, and undo any change you do not like.
```
Why: "a broken page is never saved" is stronger than the evidence (the sweep's own limits list untested cases,
`docs/render-fatal-sweep.md`). Same change for the phrase in "What makes it smart" ("so a broken page is never saved by an
AI edit"). This lowers a review-guideline risk (claims the plugin cannot back).

### A6. Screenshots (lines 267-271 plus `wporg-assets/`)

```
  1. The Dashboard ...
  2. Settings ...
  3. Features ...
+ 4. A preview (dry run) in Claude: the blocks and settings that would change, and the checker's verdict, before anything is saved.
+ 5. A refused save: the exact reason the checker gives your assistant, and the corrected save that follows.
+ 6. "Recent AI edits" on the Dashboard with the Restore button.
+ 7. The same page in two design languages and style kits.
```
4, 5 and 7 are assistant screens, not wp-admin: generate them with real runs on the dev site and mask keys and URLs as
`scripts/build-wporg-art.cjs` does. Only screenshots of what the free plugin does.

### A7. Changelog / upgrade notice for 4.15.0 (draft lines)

```
+ = 4.15.0 =
+ * Safer: the checker now refuses 80 content settings that, given a value of the wrong type, made Divi 5.15.1 stop the whole page with a server error (found by testing every module). No real page or section pattern was refused by the new rules.
+ * New: design languages also decide headline type (weight, letter spacing, capitals) and section spacing, so headings and bands match the chosen look.
+ * New: style kits also style testimonials, blurb icons, number counters and accent dividers.
+ * Improved: the style guide explains how to write tags, icon chips, plan columns, the featured plan and rules by hand, and `get_style_guide` takes an optional `design_language`.
+ * Improved: a framed button keeps its frame on hover in the kits where it vanished.
```
Sources: git log 0c1773d..6d8ae9a; CLAUDE.md "Dogfood round 2", "Type adapter", "Rhythm adapter", "Carriers adapter",
"Wording gaps". Check each line against the final release diff; the owner writes the final notes.

## B. divi5lab.com copy blocks

### B1. Fix stale claims on https://divi5lab.com/plugins/divi-5-ai-editor (seen 2026-10-25)

| Live text | Problem | Suggested |
|---|---|---|
| "61 Divi 5 block types modeled" | The checker knows 89 (CLAUDE.md "Current state") | "89 Divi 5 block types known, each from a real export or a live render" (re-count from `SchemaRules` before publishing) |
| "15 violation classes checked" | Not re-verified; the code list has grown (e.g. `OBJECT_WHERE_SCALAR`, `STYLE_VALUE_BREAKS_PAGE`) | Count the codes in `src/` and update, or drop the number |
| "100% of saves validated first" | Restore bypasses the checker by design (CLAUDE.md "Undo") | "Every AI edit is checked before it is saved" |

### B2. Hero block

> **AI drafts. The checker decides.**
> Let Claude, ChatGPT or Cursor build and edit your Divi 5 sites. A checker that is not AI tests every edit against rules
> taken from real Divi 5 pages and live renders, so a layout that would break Divi is refused with the exact reason.
> Preview every change, undo any of it.
> [Get the free plugin] [Start the {{trial:days}}-day Pro trial]

### B3. Proof strip (numbers trace to `docs/launch/evidence-page.md`)

> 92 page-crashing values found and refused · 264 of 264 live style checks passed · 71 section patterns, all rendered on
> the real Divi · 10 design languages x 40 style kits, contrast-checked

Publish the first item only after 4.15.0 is live.

### B4. Agency block

> **Built for client sites.** New pages are always drafts. Every edit keeps the previous version. Site-wide changes (a new
> phone number on 40 pages, a price change across a shop) are previewed first and undone in one step. Your AI never gets
> administrator tools: it cannot install plugins, change users or save custom code through a layout.

Check: "never gets administrator tools" is true for the free plugin; Pro adds `set_front_page`, `set_primary_menu` and
`set_custom_css`, which need the matching WordPress capabilities. Word it as "only the tools you install, with your
WordPress permissions" if the Pro tab is in scope.

### B5. "Why not just Divi AI?" block

> Divi AI is Elegant Themes' own AI inside the builder, with its own agent. JHMG AI Editor lets the assistant you already
> use work on your Divi 5 sites from outside the builder, over MCP, with a deterministic check on every save and an undo
> for every edit. Many agencies will use both.

Source: `competitor-refresh-2026-10-25.md` [ET-AI, ET-AGENT]. Do not claim Divi AI lacks undo or preview: not stated is not absent.

### B6. Evidence page

Publish `docs/launch/evidence-page.md` as https://divi5lab.com/... (owner picks the path) and link it from the hero and the FAQ.
