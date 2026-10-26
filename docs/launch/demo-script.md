Audience: the owner (recording) and, through the video, Divi agencies and freelancers
Status: DRAFT plan for a 5-minute screen recording; nothing recorded yet
Date: 2026-10-25

# Demo script: "Your AI on a client's Divi 5 site, with a gate and an undo"

Length: about 5 minutes. Everything in the main path exists today (free 4.14.0 + Pro 0.12.1, or main after 4.15.0 ships).
One segment (the design switch) is BUILT BUT UNRELEASED: it ships with the next Pro release, so record it only from main with
the new Pro build. One PLACEHOLDER segment (render check with rollback) is parked; cut it or show it only as "coming", never
as shipped.

## Before you record

- A disposable site (staging or the Docker dev site), Divi 5.15.1, free plugin + Pro active and licensed, a few existing pages
  (Home, About, Contact) that mention a phone number, and WooCommerce off unless you show it.
- Claude Desktop or Claude Code connected (Settings tab, Test connection shows "Connected"). ChatGPT works for most tools but
  not `html_to_divi` (MCP and REST only), so use Claude for the whole take.
- A client's landing page as an HTML file (your own, or one written by Claude earlier) in the clipboard.
- Approval mode OFF at the start (you switch it on during the demo).
- Browser tabs: wp-admin Pages, the plugin Dashboard, the front end.
- Do a full dry run first; assistants vary between takes. If a result differs from what is described, show what happened;
  do not edit the video to fake a result.

## 0:00-0:20 Hook (voice over the plugin Dashboard)

"This is Claude editing a real Divi 5 site. Everything it writes goes through a checker that is not AI. If the layout would
break Divi, it is not saved. Every edit can be undone. Let me show you."

## 0:20-1:30 Build a landing page under a chosen design language and kit

**Prompt:**
> Build a landing page draft for "Harbor Dental", a family dentist. Use the design language corporate-trust and the style kit
> steady-solid. Hero, three services, two patient reviews with placeholder names, opening hours, and a booking button.
> Use the section recipes and the style guide.

**The viewer sees:** Claude calling `get_landing_guide`, `get_section_recipes` (with `design_language` and `style_kit`),
`get_style_guide`, then `create_page`. If the checker refuses a first attempt, Claude gets the violation codes and retries:
leave that on screen, it is the point of the product.

**Expected result:** a new DRAFT page in wp-admin Pages. Open the preview: buttons, cards and dividers share one look.
Say: "It is a draft; nothing is published until I publish it."

## 1:30-2:15 Preview a change before saving (dry run)

**Prompt:**
> On the Harbor Dental draft, change the hero headline to "Gentle care for the whole family" and make the booking button say
> "Book a visit". Show me a dry run first; do not save yet.

**The viewer sees:** `edit_page_content` or `update_page_layout` with `dry_run: true`, returning `valid: true` and a short list
of the blocks and settings that would change.

**Then:** "Looks good, save it." The real save follows.

## 2:15-2:45 Approval mode

In Settings, switch on "Require a preview before every save". Then:

**Prompt:**
> Change the opening hours on Saturday to 9:00-13:00 and save it straight away.

**Expected result:** the save is refused with `PREVIEW_REQUIRED` and Claude previews first, then saves. Say: "With Approval
mode, nothing is saved that was not previewed."

## 2:45-3:15 Undo

**Prompt:**
> Undo the last change you made to the Harbor Dental page.

**The viewer sees:** `list_page_history` then `restore_page_version`. Or click Restore under "Recent AI edits" on the plugin
Dashboard. Refresh the page: Saturday's hours are back.

## 3:15-4:00 Import a client's HTML (`html_to_divi`)

**Prompt:**
> Convert this HTML into a Divi 5 page and show me the report first. [paste HTML]

**The viewer sees:** the preview report: sections and modules created, `text_check` and `links` showing no copy or link was
dropped, the colours and fonts that were carried, and what was left out (scripts, forms, external stylesheets). Then:
> Save it as a draft.

**Expected result:** a draft that keeps all the copy and links; third-party images become placeholders. Say plainly that
the converter does not fetch websites or linked stylesheets.

## 4:00-4:40 Pro: find and replace across the site, then audit

**Prompt:**
> Our phone number changed from 555-0100 to 555-0142. Find every Divi page that has the old one and show me what would
> change. Do not save yet.

**The viewer sees:** `find_replace_site` in preview: every page and the exact change, with a preview token. Then "apply it".
Say: "One batch. If a page was edited since the preview, it is skipped, not overwritten. One undo takes the whole batch back."
Optionally show `list_batches` / `undo_batch`.

**Prompt:**
> Run a site audit and tell me the three most important problems.

**The viewer sees:** `audit_site` (read-only): layout problems, colours or presets a page cites that the site does not have,
internal links to missing or unpublished pages.

## 4:40-5:00 Close

"The AI is creative; the checker decides what is saved. Free on WordPress.org; Pro adds site-wide tools, Theme Builder,
global styles and WooCommerce. Links below." Show the WordPress.org page and divi5lab.com.

## Built, unreleased segment (ships with the next Pro release; show only with that build)

- **[BUILT, UNRELEASED: design switch]** Re-skin a finished page, built with this plugin, from one design language/kit to
  another in one call: `switch_design` (Pro, MCP + REST only). Show the dry run first (the report of what would change and
  what is left), then the call with the token, then the page, then `undo_batch`. It does not need the render check. It needs
  the free plugin release that ships the type, rhythm and carriers adapters (on main, not in the published 4.14.0): on 4.14.0
  the tool answers "needs a newer version of the free plugin". Say what is proven: a recipe page switched equals the same
  page built directly under the new design in 6,549 of 7,100 sampled switches between the ten default designs, the rest
  differ in named, pinned ways, and the live gate saw the switched and the directly built page render the same on the real
  Divi (62 PASS, 0 FAIL) [`docs/superpowers/specs/2026-10-25-pro-design-switch-design.md`, Outcome]. Do not say it restyles
  hand-built pages perfectly: in the default `leave` mode only blocks that match a role are switched and the rest are
  listed. Do not say "no other tool does this"; say "we found no other tool that states it" (see
  `competitor-refresh-2026-10-25.md`). Do not show it before the Pro release is out.

## PLACEHOLDER segments (in development, NOT shipped; do not present as available)

- **[PLACEHOLDER: render check with rollback]** After a save, render the page on the server and roll back automatically if
  Divi throws. Today: the checker refuses the 80 content keys proven to crash Divi before saving, and undo is manual; a
  post-save render check exists only as a spike (`docs/post-save-render-check-evidence-5.14.md`). If shown, caption:
  "In development".

## Things not to say on camera

- "It can never break a site." Say: "It refuses what we have proven breaks Divi, and you can undo."
- "No other tool validates." Several competitors state validation (see `competitor-refresh-2026-10-25.md`).
- Anything about a customer, a review or a number that is not in `evidence-page.md`.
