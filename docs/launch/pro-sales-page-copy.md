Audience: visitors of divi5lab.com (Divi agencies, freelancers, site owners); the owner edits and publishes
Status: DRAFT, not published. Every price, site limit, cap and day count is a {{token}} from layoutlab/config/pricing.json, exactly as in docs/pricing-page.md
Date: 2026-10-25

<!--
Owner notes (remove before publishing):
- Tokens used: {{sites:personal}} {{sites:freelancer}} {{sites:agency}} {{price:personal}} {{price:freelancer}} {{price:agency}}
  {{cap:founding}} {{percent:founding}} {{tier:lifetime}} {{price:lifetime}} {{cap:lifetime}} {{trial:days}} {{reminders}} {{refund:days}}.
- Tool counts: free 21 (wp-plugin/readme.txt), Pro 22 (pro-addon/README.md, src/Tools/; `switch_design`, the 22nd, shipped in Pro 0.13.0 with free 4.15.0). Re-count before publishing.
- POSITIONING (2026-11-05): the page now leads with the result ("Divi 5 sites that look designed, built in minutes, with undo for everything") and treats MCP and the assistant as the way in, not the product. Three claims in the headline must be backed before you publish:
  1. "Built in minutes": NOBODY HAS TIMED A BUILD. Nothing in this repo measures how long `build_site` takes with a real assistant. Record the demo (docs/launch/demo-script.md), time it, and put the real figure in the "Built in minutes" paragraph; if it is more than a few minutes, change the headline to what you measured ("in an afternoon", "in an hour").
  2. "Undo for everything": true for every change a Pro tool makes (each is one batch with an undo point), not literally for everything: undo refuses an item that was edited since, a page too large to snapshot has no earlier version, Pro keeps the last 20 steps, page history keeps 10 versions per page, and a free-plugin title change cannot be undone. The page says so in the paragraph and in Honest limits; keep both if you shorten the headline.
  3. "Look designed": backed by the ten design languages and forty style kits (readable text proven in every language) and `switch_design`; "looks designed" is still a judgement. Show before and after pictures made by the plugin (docs/launch/demo-script.md) rather than adjectives.
- The mega menu (Pro admin tab, 14 layouts) is built but UNRELEASED: it is mentioned once below, marked, and must be removed from this page if you publish before the next Pro release.
- Competitor rows come ONLY from docs/launch/competitor-refresh-2026-10-25.md (retrieved 2026-10-25). Re-check prices on the day you publish:
  Divi Cake's launch price ends 8 November 2026.
- TODO(owner) markers carried over from docs/pricing-page.md are still open (Lifetime scope, no-card trial keeps tools).
-->

# JHMG AI Editor for Divi 5 Pro

## Divi 5 sites that look designed, built in minutes, with undo for everything.

Tell the assistant you already use what site you want. Pro builds it page by page as drafts, in one consistent design, and
nothing goes live until you launch it in one previewed step. Want another look? Restyle every page in one call. Changed your
mind about anything Pro did? Undo it in one step.

**1. It looks designed, not just assembled.**
Ten design languages and forty style kits decide the details that make a page look like one person designed it: colours and
fonts by role, buttons, cards, dividers, tags, accordions, forms, headline type and the space between sections. Every kit
was checked for readable text in every language, so a different look never costs you legibility. Pick the look once and
every page that follows matches it.
`switch_design` restyles a page, or up to 25 at once, to another language and kit in one call. It uses no AI, so the same
request gives the same page; you see a preview first and one undo puts every page back. It works best on pages built with
the plugin; blocks you built by hand that do not match a design role are left alone and listed.
*Built, not released yet:* a Mega menu tab in the Pro screen, with 14 ready-made mega menu layouts for your header, one
click to apply, and the panels still editable in Divi.

**2. It is built in minutes.** <!-- OWNER: unmeasured, see the notes at the top. Put the timed figure here before publishing. -->
`build_site` creates a whole site page by page, each page checked and saved as a draft. When you are happy, launch it in
one previewed step: it publishes the pages, sets the front page and builds the main menu. The slow, fiddly work after that
is one call as well: `find_replace_site` changes a phone number, a price or a sentence on every page at once, and
`manage_global_colors` changes a brand colour everywhere it is used. You see every change before it is made.

**3. Undo for everything Pro changes.**
Every change a Pro tool makes is saved as one step with an undo point: a whole site build, a restyle, a find and replace
across the site, the header and footer, the menu, the front page, your colours, variables and presets, WooCommerce prices
and products. `undo_batch` puts it back in one step. Before any write you can ask for a preview that shows exactly what
would change, and Approval mode makes a page edit impossible without one. Undo is honest about its limits: it checks that
nothing changed since, restores what it safely can, and tells you what it could not (see Honest limits).

**4. Underneath: a check that is not AI.**
Before anything your assistant writes reaches your database, a fixed set of rules checks every block against how Divi 5
really stores a page. Same layout in, same verdict out. A layout that breaks a rule is not saved and the assistant gets the
exact reason, so it fixes its own mistake. The rules come from real Divi 5 page exports, Divi's own module definitions and
live renders on the real Divi. In October 2026 we fed Divi 5.15.1 wrong-typed values for every content setting of every
module and found 92 that make Divi crash the whole page for every visitor; the checker now refuses all of them before they
are saved. [See how we know](evidence-page.md) New pages are always drafts, and the AI never saves custom code through a
page layout.

**Works with the assistant you already use.** Claude (web, Desktop, Code), ChatGPT, Cursor, Windsurf, VS Code Copilot and
Gemini CLI connect over MCP or REST; you bring your own assistant account and there are no AI credits to run out.

## What Pro adds: 22 tools

The free plugin on WordPress.org already edits, previews, checks and undoes Divi 5 pages (21 tools). Pro adds 22 more for
the work around the page.

**Build and launch a whole site**
- `build_site`: build a site page by page as drafts, each one checked; launch it in one previewed step (publish, set the
  front page, build the main menu); take the whole build away again with one undo.
- `publish_page`, `set_front_page`, `set_primary_menu`: the last steps, done by your assistant, each undoable.

**Theme Builder**
- `list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`: read and change the live Divi header and footer, checked
  like any page, with a preview.
- `manage_theme_template`: assign, enable or disable an existing Theme Builder template, after a preview.

**Across the whole site**
- `find_replace_site`: replace a phone number, a price or a sentence on every Divi 5 page (and, if you allow it, the header
  and footer) in one batch. You see every change first; a page edited since the preview is skipped, not overwritten.
- `audit_site`: a read-only report of layout problems, colours or presets a page uses that your site does not have, and
  internal links that point to nothing or to unpublished pages.

**Your design system**
- `manage_global_colors`, `manage_global_variables`, `manage_global_presets`: create and change Divi global colours,
  variables and module presets, all or nothing, with a preview and undo.
- `switch_design`: restyle a page or Divi Project, or up to 25 at once,
  to another of the ten design languages and forty style kits in one call: colours and fonts by role, buttons, cards, rules,
  tags, accordions, forms, headline type and section spacing. No AI is used, so the same request gives the same page. You see
  a preview first, every result goes through the checker, and one undo puts every page back. Best on pages built with the
  plugin; hand-built blocks that do not match a design role are left as they are and listed (an opt-in mode restyles the
  near matches too). Needs the free plugin version that ships the design adapters.

**Content and WooCommerce**
- `set_custom_fields`: set custom field values on one post (keys your site already uses), all or nothing, undoable.
- `create_product`: up to 25 simple WooCommerce products per call, saved as drafts, read back and undoable.
- `update_products`: change prices, sale dates, stock, texts, categories, images and variations of existing products,
  behind a before/after preview; rolled back on any failure; one undo.
- `bulk_adjust_prices`: raise or lower regular prices by a percent or an amount over a filter (category, tag, stock, sale...),
  with your shop's rounding, every price shown before and after, up to 100 products per call, one undo.

**Settings and code, carefully**
- `set_custom_css`: one managed Additional CSS block, undoable (this is a Pro tool on purpose: the free plugin never saves code).
- `propose_php_snippet`: your assistant can only PROPOSE a PHP snippet; it appears in a panel for you to read. It is never
  run or saved by the AI.
- `list_batches`, `undo_batch`: see recent Pro changes and undo a batch. Undo checks that nothing changed since, item by item.

## What the free plugin already gives you (and Pro keeps)

- Read a page, an outline of a big page, or one block; change one sentence without rebuilding the page.
- `create_page` (always a draft), `update_page_layout` with `dry_run`, page history and restore.
- `html_to_divi`: turn HTML you already have into a Divi 5 layout, with a report proving no copy or link was dropped and
  listing what was left out.
- 71 section recipes, each checked by the validator; the style, landing, site and image guides; your Media Library first.
- Read-only look-ups: your colours and presets, post types, ACF field groups, custom fields, WooCommerce products.
- Sign in from Claude on the web or ChatGPT (opt-in; ChatGPT's custom connector is set up to OpenAI's published requirements but not yet confirmed by us with a real account), or connect over MCP or REST. The older ChatGPT Action route still works until OpenAI retires custom GPTs.

## Plans

Pick by the number of sites you manage. Every plan has all 22 Pro tools.

| | Personal | Freelancer | Agency |
|---|---|---|---|
| Sites | {{sites:personal}} | {{sites:freelancer}} | {{sites:agency}} |
| Price per year | {{price:personal}} | {{price:freelancer}} | {{price:agency}} |

**Founding offer.** The first {{cap:founding}} buyers get {{percent:founding}}% off any plan. The discounted renewal price is
locked for as long as your licence stays active.

**Lifetime.** {{tier:lifetime}} ({{sites:agency}}), one payment of {{price:lifetime}}, no renewal. Limited to {{cap:lifetime}}
sales. <!-- TODO(owner): confirm what Lifetime includes (carried over from docs/pricing-page.md). -->

**Free trial.** {{trial:days}} days of Personal, no card needed.

**Upgrades.** Move to a bigger plan any time and pay the difference for the rest of your term.

**If your licence expires, nothing breaks.** All Pro tools keep working on the sites where you activated them. Only updates
and support stop. You get an email {{reminders}} days before renewal.

**No AI credits.** You bring your own assistant. We never sit between you and your AI provider, so there is no meter.

## Honest limits

- **`switch_design` works best on pages built with this plugin.** On a hand-built page it switches the blocks that match a
  design role and lists the rest; colours and fonts it does not recognise are left alone; tablet and hover settings of
  hand-built blocks are not remapped beyond what the roles express.

- **Divi 5 only.** Divi 4 shortcode pages are not supported.
- **Divi's own modules only.** A page with a module from another add-on (for example Divi Pixel or Divi Plus) is refused,
  even for a text change.
- **The checker proves a page is built correctly, not that it looks good.** Look at every draft before you publish it.
  A check of how the saved page renders is in development and not part of this release.
- **The render-crash guards cover what we tested**: the main (desktop) content value of each module. Tablet, phone and hover
  values, inner settings and five modules with no real example page were not swept (see the evidence page).
- **Restore is the exception to the gate, on purpose.** Undo brings back your own earlier version exactly, even if it would
  not pass today's checker; your assistant is told whether it passes.
- **A page title change is not covered by undo**; your assistant tells you the old title.
- **Approval mode covers page edits in the free plugin.** Pro tools use their own preview step instead (site builds, find and
  replace, templates, products and prices all preview first).
- **The older ChatGPT Action route does not get every tool.** Eight Pro tools (colours, variables, presets, custom fields,
  the three WooCommerce tools and the design switch) and several free tools work over MCP and REST only. A ChatGPT custom
  connector uses MCP, so it is offered all of them (not yet confirmed with a real ChatGPT account).
- **"Undo for everything" means everything Pro changes, within these bounds:** Pro keeps your last 20 steps; undo checks each
  item and leaves one alone that was edited since (and says so); a page may be too large to snapshot, and the response says
  so; page history keeps up to 10 versions per page; renaming a page title through the free plugin cannot be undone.
  Divi's own builder edits are not recorded.
- **`html_to_divi` does not fetch websites or linked stylesheets** and turns third-party images into placeholders.
- **WooCommerce**: `create_product` makes simple products only; `bulk_adjust_prices` changes regular prices only.
- **Claude Desktop needs Node.js; ChatGPT and Claude on the web need a public https site.**

## How it compares

Facts from each product's own pages, retrieved 2026-10-25 (sources in `competitor-refresh-2026-10-25.md`). "Not stated"
means their page does not say; it does not mean the product lacks it.

| | JHMG AI Editor Pro | MCP for Divi 5 (Divi Cake) | Divi Connect (Divi Love) | DiviOps | Respira | Divi Pro (Elegant Themes) |
|---|---|---|---|---|---|---|
| Builders | Divi 5 | Divi 5 | Divi 5 | Divi 5 today | 19 builders | Divi |
| Entry price | {{price:personal}}/yr, {{sites:personal}} | $49/yr launch (regularly $99), unlimited sites | Free; Pro from $39/yr (1 site) | Free; Pro Starter €49/yr (1 site) | €71/yr (1 site) | $277/yr |
| Your own AI assistant over MCP | Yes | Yes | Yes | Yes | Yes | Not stated (built-in agent, models chosen in Divi) |
| Check before save | Deterministic, rules from real exports and live renders | Validates before saving, reads back, render check | Skill and module schema "help pages come out valid" | Validate before write and against the saved page | "13-point validation" | Not stated |
| Preview / dry run | Yes, with a diff; optional approval mode | Dry runs | Confirmation before overwrites | `dry_run` on every write | Diff review on a duplicate | Not stated |
| Undo | Last 10 per page; batch undo | WordPress revisions; 7-day design snapshots | Last 20 versions per page | Rollback snapshots | One-click restore | Not stated |
| Drafts for new pages | Always | By default | Drafts-only mode | Not stated | Duplicate first | Not stated |
| Design consistency | 10 languages x 40 kits, contrast-proven | Uses global colours and presets | Readability-checked looks; 490 styles (Pro) | Presets and variables tools | Global presets | On-brand colours and fonts |
| WooCommerce writes | Create, update, bulk prices | Not stated | "WooCommerce pages" (Pro) | FluentCart tools (Pro) | Add-on €95/yr | Not stated |

Different tools fit different jobs. If you need many page builders, Respira or WPVibe cover more. If you want Divi's own AI
inside the builder, that is Divi Pro. JHMG AI Editor is for agencies who want the assistant they already use to work on Divi 5
sites with a gate they can trust and a history they can undo.

## Questions

**Is the checker AI?**
No. It is plain PHP with fixed rules. Same page in, same verdict out. It runs on your server and costs no AI tokens.

**Where do the rules come from?**
From real Divi 5 exports, Divi's own module definitions, and live renders on the real Divi version. We never write a Divi rule
from memory. Details and numbers are on the [evidence page](evidence-page.md).

**What happens when my licence expires?**
All Pro tools keep working. Updates and support stop. Renewing brings them back.

**Can I get a refund?**
You have a {{trial:days}}-day free trial first. We refund in full within {{refund:days}} days of a charge if you were charged
twice or in error, if the add-on cannot be activated or does not work on a supported setup and we cannot fix it, or if a yearly
renewal was charged and you want to stop. The full text is on the licence page.

**The code is GPL. What am I paying for?**
Updates (including compatibility with new Divi and WordPress versions), support, and the licence service.

**How are sites counted?**
Each distinct site address is one site; www, http/https and a trailing slash count once. Free a slot from your account page.

**Does it need the free plugin?**
Yes. Install JHMG AI Editor for Divi 5 from WordPress.org first; Pro adds its tools to the same connection.

**Which assistants work?**
Claude (web, Desktop, Code), ChatGPT (custom connector, not yet confirmed by us with a real account; the older custom GPT Action gets the first 14 Pro tools), Cursor, Windsurf, VS Code Copilot, Gemini
CLI and other MCP clients. You need your own assistant account.

**Does it work with Divi 5.15 components?**
Yes: pages with components and Slots are read and edited, and your assistant is told when a change would not show because
the component owns that part.

**Can my client's site go down because of an AI edit?**
An AI edit cannot save a layout the checker refuses, and the checker refuses the value types we saw crash Divi. That is
narrower than "can never go down": a crash from something we have not tested (see Honest limits) is still possible. Undo is
there for that.
