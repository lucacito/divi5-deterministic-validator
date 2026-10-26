Audience: the owner (internal; feeds the Pro sales page and the launch plan)
Status: DRAFT, not published anywhere
Date: 2026-10-25

# Competitor refresh, 2026-10-25

Every fact below was read on 2026-10-25 from the competitor's OWN public page (URL given each time), or from its own
WordPress.org listing. Nothing was installed or tested. "Not stated" means the page read does not say it. It does NOT mean the
product lacks it. Marketing pages leave out features, so a "not stated" must never become "they do not have it" in public copy
without a hands-on check or a documentation source.

The earlier passes were `docs/competitor-benchmark-2026-10.md` (2026-10-06, Oxygen/Breakdance) and
`docs/competitor-respira-and-extra-2026-10.md` (2026-10-14). The section "What changed since 2026-10-06" lists the differences.

## Sources (all retrieved 2026-10-25)

| Id | URL |
|---|---|
| ET-JOIN | https://www.elegantthemes.com/join/ |
| ET-AI | https://www.elegantthemes.com/ai/ |
| ET-AGENT | https://www.elegantthemes.com/blog/theme-releases/ai-agent ("Introducing Divi AI Agents", page says "Last Updated on September 4, 2026") |
| OXY-AI | https://oxygenbuilder.com/build-with-ai/ |
| OXY-62 | https://oxygenbuilder.com/oxygen-6-2-is-now-available/ |
| BD-AI | https://breakdance.com/features/build-with-ai/ |
| VIBE-PRICE | https://mcp.wpvibe.ai/pricing |
| VIBE-DIVI | https://wpvibe.ai/works-with/divi/ |
| VIBE-DIVI5 | https://wpvibe.ai/wpvibe-divi-support (post dated July 21, 2026) |
| AIE-PRO | https://meowapps.com/products/ai-engine-pro/ |
| AIE-MCP | https://ai.thehiddendocs.com/mcp/ (AI Engine's documentation site) |
| RES-DIVI | https://www.respira.press/divi |
| RES-PRICE | https://www.respira.press/pricing |
| CAKE-SHOP | https://divicake.com/shop/mcp-for-divi/ |
| CAKE-CHECK | https://divicake.com/docs/mcp-for-divi/how-a-page-is-checked/ |
| CAKE-UNDO | https://divicake.com/docs/mcp-for-divi/undo-a-change/ |
| CAKE-TOOLS | https://divicake.com/docs/mcp-for-divi/what-the-assistant-can-do/ |
| WIP-ORG | https://wordpress.org/plugins/wiplink-mcp-for-divi-5/ |
| DL-HOME | https://divilove.com/ (Divi Connect, the paid edition of Wiplink) |
| DOPS-ORG | https://wordpress.org/plugins/diviops-agent/ |
| DOPS-GH | https://github.com/oaris-dev/diviops |
| DOPS-PRICE | https://diviops.com/pricing/ |
| D5MCP | https://github.com/moeadas/D5MCP |
| OURS-ORG | https://wordpress.org/plugins/jhmg-ai-editor-for-divi-5/ |
| OURS-STORE | https://divi5lab.com/plugins/divi-5-ai-editor |

Not re-checked this time: Novamira (the WordPress.org URL tried did not resolve to its listing; the 2026-10-14 facts in
`docs/competitor-respira-and-extra-2026-10.md` came from third-party reviews, so treat them as unverified). To verify: open
https://github.com/novamira or its own site and read the readme.

## The table

| Product | What it is | Price and licence model (as stated) | Builder scope | Safety, preview, undo, validation (as stated) | Design-consistency features (as stated) | Divi 5 support |
|---|---|---|---|---|---|---|
| **Divi AI / Divi Pro** (Elegant Themes) | AI inside the Divi Visual Builder; since September 2026 also the "Divi AI Agent": "a new way to build websites with natural language" that can "Create and publish pages, manage menus, Theme Builder templates, and Theme Options from the same chat" [ET-AI]. Builds from a brief, screenshot, mockup, URL or moodboard; "outside the builder" skills "currently limited" [ET-AGENT] | Divi $89/yr; Divi Lifetime $249 once; Divi Pro $277/yr (Divi AI, Cloud, Dash, Teams, VIP); Lifetime + Pro $297 then $212/yr [ET-JOIN]. Divi AI alone $193/yr or $21.36/mo [ET-AI]. Agent: "$60 complimentary agent spend per year"; unlimited generation "does not apply to AI Agent usage" [ET-JOIN]. Models: "choose from hundreds of available models" [ET-AGENT] | Divi only | Undo, preview, approval, validation: not stated on ET-AI, ET-JOIN or ET-AGENT. MCP: not stated in the article; a reply in the article's comments says "the official MCP is on the way" (a comment, not a product statement) [ET-AGENT] | "Provide your brand's colors and fonts, and Divi AI will create on-brand layouts every time"; "consistent global styles and dynamic templates" [ET-AI]; agent can consolidate colours into global variables [ET-AGENT] | The agent is announced "for Divi 5" [ET-AGENT] |
| **Oxygen 6.2** (agent connector) | Oxygen ships "Agent Connector, an MCP server and full Abilities API support" [OXY-AI] | "$199.50 once — unlimited sites, no renewal"; AI not included, "We don't resell AI credits" [OXY-AI]. 6.2 is "RC 1" (release candidate, 30 Sept 2026) [OXY-62] | Oxygen only | "edit-post now validates dynamic data bindings and rejects malformed bindings"; edit-post rejects unknown payload keys; `preview-post` / `preview-element` let agents "render and verify their work"; parallel calls no longer overwrite each other [OXY-62]. Revisions/undo for agent edits: not stated | "native Oxygen elements with real classes, variables and components" [OXY-AI] | n/a |
| **Breakdance 3.0** (agent connector) | "exposes its abilities over MCP, giving your agent total control of your site" [BD-AI] | "One license, unlimited websites", 60-day money-back; dollar price not stated on BD-AI. 3.0 is "RC 1" [BD-AI] | Breakdance only | "The old scheme is saved as a revision in the variables panel" (colour changes). Validation, preview, undo: not stated [BD-AI] | "Colors live in variables" [BD-AI] | n/a |
| **WPVibe** | Free WordPress plugin plus hosted MCP service [VIBE-PRICE] | Free $0 (100 tool calls/day); Pro $99/yr (500); Power $299/yr (2,000); Agency $599/yr (5,000); Scale $899/yr (10,000); calls shared across all sites; no lifetime listed [VIBE-PRICE] | Elementor, Gutenberg, theme editing, design skills; FAQ names Elementor, Divi, SeedProd, Beaver Builder [VIBE-PRICE] | "Actions that are destructive or hard to undo require your approval, with a preview" [VIBE-PRICE]; landing pages "saved as a draft you approve" [VIBE-DIVI]; "Drafts come first"; a whole-layout rebuild "stops and asks first" [VIBE-DIVI5]. Undo/backup: not stated | Not stated beyond reusing existing header/footer templates [VIBE-DIVI] | "ships a Divi skill for Divi 4 and Divi 5", incl. Theme Builder headers and footers [VIBE-DIVI]. New pages: "Divi upgrades it to the new format automatically" when first opened in the Visual Builder [VIBE-DIVI5] |
| **AI Engine Pro** (Meow Apps) | WordPress AI toolkit with "MCP for Claude and ChatGPT" [AIE-PRO] | Yearly: Starter $69 (1 site), Standard $99 (5), Professional $179 (20). Lifetime: $499 (5) to $1,699 (2,500) [AIE-PRO] | General WordPress; builders not mentioned [AIE-PRO, AIE-MCP] | MCP off until enabled; admin-only by default; Bearer token. Approval/undo: not described; "smart annotations for safety" [AIE-MCP]. Pro MCP includes plugin and theme install and file editing [AIE-MCP] | Not stated | Not stated |
| **Respira** | Plugin that lets external assistants edit WordPress over MCP across many builders [RES-DIVI, RES-PRICE] | EUR: Maker €71/yr (1 site, 7-day no-card trial), Builder €150/yr (5 or 10), Studio €451/yr (25-250), Enterprise from €10,000/yr, Founder lifetime €5,001 [RES-PRICE] | "All 19 page builders" [RES-PRICE] | "13-point validation" (context, nesting, module rules, column counts, variable references, plus 16 mistake patterns) before the database; every Divi edit goes to a duplicate first, "Your live page is never touched until you approve"; every write snapshotted, "One-click restore"; diff review and audit trail [RES-DIVI] | Uses Divi's styling engine, manages global presets, saves sections as live-linked Library items [RES-DIVI] | "117 module types in Divi 5.15.1", plugin 9.2.6 [RES-DIVI] |
| **MCP for Divi 5** (Divi Cake) — NEW | Plugin: "AI assistants such as Claude, ChatGPT, and Gemini build and edit native Divi 5 pages"; 29 tools [CAKE-SHOP, CAKE-TOOLS] | Launch: $49/yr (regularly $99) or $99 lifetime (regularly $299) until 8 November 2026; 7-day trial; "Unlimited sites on every licence" [CAKE-SHOP]. v1.0.8, updated 25 Oct 2026 [CAKE-SHOP] | Divi 5 only (5.1+; Divi 4 not supported) [CAKE-SHOP] | Four steps: validate before saving (structural errors stop the save; some mistakes auto-fixed), save as draft by default, read back and compare, render check (modules with no markup, missing text, unknown blocks; "does not judge appearance") [CAKE-CHECK]. Page versions via WordPress revisions (none if revisions are off); 7-day snapshots of presets/variables/Theme Builder, restore undoable; dry runs [CAKE-UNDO]. Cannot delete pages, install plugins, manage users or change WordPress settings [CAKE-TOOLS] | Uses global colours and existing presets; creates/changes/deletes presets, variables, global colours and Theme Builder templates; adds images from public URLs [CAKE-SHOP, CAKE-TOOLS] | Yes |
| **Wiplink (free) / Divi Connect (paid)** (Divi Love) — NEW | Connector that lets an assistant "design and publish real Divi pages just by chatting" [WIP-ORG] | Free: $0, unlimited sites. Pro: $39/yr (1 site), $59/yr (5), $89/yr (1,000); founding lifetime sold out; 7-day trial with card [DL-HOME]. WordPress.org: v2.3.6, 50+ installs, no reviews [WIP-ORG] | Divi 5 only (5.7+; "Divi 4 is not supported") [DL-HOME] | AI acts as a limited user, "never as an administrator"; cannot add custom CSS or scripts [WIP-ORG]. "Each page keeps its last 20 versions"; snapshot before every change; "Undo checks afterwards that the site actually reverted"; drafts-only mode; confirmation before overwrites; change log [DL-HOME]. Validation: built-in "Divi 5 Skill and module schema help pages come out valid" [WIP-ORG]; a separate validation gate is not stated | Reads and writes the design system (Pro writes from chat); "readability checker"; five looks, "Each is checked for readability"; Pro: "490 ready-made styles" [DL-HOME, WIP-ORG] | Yes |
| **DiviOps** (Agent plugin + npm MCP server) | "AI harness for WordPress site authoring", public beta, MIT [DOPS-GH] | Free; Pro Starter €49/yr (1 site), Pro €79/yr (5), Studio €149/yr (unlimited), Studio Lifetime €299; founder prices €29/€49/€99/€199 for the first 50 seats; "Tools keep working if you cancel" [DOPS-PRICE]. WordPress.org: v1.5.48, 1,000+ installs, no reviews [DOPS-ORG] | Divi 5 today, "WordPress as the wider foundation" [DOPS-ORG] | "Every write tool accepts `dry_run`"; `diviops_validate_blocks` checks before a write and against the saved page; `diviops_render_preview` [DOPS-GH]; rollback snapshots for Divi content writes; checksums refuse drifted pages [DOPS-ORG]. Free also writes site-wide Custom CSS blocks [DOPS-ORG] | Presets, variables, global colours and fonts tools; read-only Design System dashboard [DOPS-ORG, DOPS-GH] | Yes |
| **D5MCP** (GitHub) | "A working MVP" MCP server plus WordPress bridge [D5MCP] | Licence not stated; 1 star, 5 commits [D5MCP] | Divi 5 (Divi 4 shortcodes optional) [D5MCP] | Drafts by default, publish only through an explicit tool; "validated page-plan JSON"; rollback listed as a future improvement [D5MCP] | 12 approved section types [D5MCP] | Yes |
| **Us: JHMG AI Editor** | Free plugin 4.14.0 on WordPress.org, 100+ installs, 5 stars from 4 reviews [OURS-ORG]; Pro add-on 0.12.1 (repo `pro-addon/`) | Free; Pro tiers by site count (tokens in `docs/pricing-page.md`); the live store lists the tiers and a 45-day no-card trial [OURS-STORE] | Divi 5 only | Deterministic validator on every AI write, dry-run diff, optional approval mode, per-write undo (last 10), drafts-only page creation, custom-code refusal (repo: CLAUDE.md) | 10 design languages x 40 style kits with contrast proofs; 71 validated recipes (repo: CLAUDE.md, `wp-plugin/readme.txt`) | Yes, verified on 5.15.1 |

## What changed since 2026-10-06 (and since the 2026-10-14 notes)

1. **Elegant Themes now has an agent.** The Divi AI Agent (article last updated 2026-09-04) creates and publishes pages and
   manages menus, Theme Builder templates and Theme Options from chat, with a choice of "hundreds" of models [ET-AI, ET-AGENT].
   The 2026-10-14 notes said Divi AI "does not expose an MCP or REST API" without a source for that; today's pages simply do
   not state MCP, and a comment reply says "the official MCP is on the way" [ET-AGENT]. Treat an official Divi MCP as a
   likely near-term risk, unverified. Agent spend is now stated as "$60 complimentary agent spend per year" [ET-JOIN];
   Divi Lifetime is $249 [ET-JOIN]; Divi AI alone is $193/yr [ET-AI] (the 2026-10-14 notes had a $115.20 sale price).
2. **Three Divi-5-only MCP competitors appeared or grew**, none of which the 2026-10-06 benchmark compared in full:
   Divi Cake's MCP for Divi 5 (render check, read-back, dry runs, preset/variable/Theme Builder writes, unlimited sites at
   a $49/yr or $99 lifetime launch price) [CAKE-*]; Wiplink/Divi Connect (free unlimited, Pro from $39/yr, 20 versions,
   drafts-only mode, readability-checked looks) [WIP-ORG, DL-HOME]; DiviOps (was v1.5.30 with 500+ installs, now v1.5.48 with
   1,000+; `dry_run` on every write, validate before and after, render preview; € pricing with founder rates) [DOPS-*].
3. **Respira**: from 18 to "All 19 page builders" [RES-PRICE]; Divi coverage from 112 modules (Divi 5.11) to "117 module
   types in Divi 5.15.1", plugin 9.2.6 [RES-DIVI]; "One-click restore" is now stated [RES-DIVI]; a €5,001 Founder lifetime
   exists [RES-PRICE]. The adoption numbers quoted on 2026-10-14 (1,755 sites, 2,210 sandboxes) are NOT on the page today.
4. **WPVibe** added Agency ($599/yr, 5,000 calls/day) and Scale ($899/yr, 10,000) [VIBE-PRICE] and states a Divi 4 + Divi 5
   skill that covers Theme Builder headers and footers [VIBE-DIVI].
5. **AI Engine Pro**'s entry plan is now stated as Starter $69/yr for 1 site; `docs/pricing-page.md` still says $79 [AIE-PRO].
   Fix that row before publishing.
6. **Oxygen 6.2 and Breakdance 3.0 are release candidates** ("RC 1"), not final releases, per their own pages [OXY-62, BD-AI].
   Oxygen now states that edit-post rejects unknown payload keys and malformed bindings [OXY-62].
7. **Our own store page is out of date** [OURS-STORE]: it says "61 Divi 5 block types modeled" (the validator knows 89,
   CLAUDE.md "Current state") and "100% of saves validated first", which is not exact: `restore_page_version` bypasses the
   validator by design (CLAUDE.md, "Undo"). Fix before the launch push (see `readme-and-store-listing-suggestions.md`).

## Where we are ahead / behind / unknown (plain language)

Only what the sources show. "Ahead" means we state and prove it in the repo and the competitor's page does not state it, or
states less. It never means "they do not have it".

| | Claim | Basis |
|---|---|---|
| **Ahead** | The check is deterministic and every rule traces to a real Divi export, Divi's own module definitions or a live render (render-fatal sweep: 92 page-crashing values found and guarded) | Repo: `docs/render-fatal-sweep.md`, `docs/SCHEMA.md`. No competitor page read states a deterministic check or render evidence for its rules (Respira states "13-point validation", Divi Cake and DiviOps state validation, without saying how it is grounded) |
| Ahead | Design consistency by construction with published proofs: 10 languages x 40 kits, contrast checked on every kit/language/surface cell, live render gate | Repo: CLAUDE.md, `docs/launch/evidence-page.md`. Divi Connect states readability-checked looks and a readability checker [DL-HOME]: comparable claim, so lead with the proof and the scale, not "only we check contrast" |
| Ahead | A complete free plugin on WordPress.org with undo, dry run and approval mode, no remote calls | [OURS-ORG]; Wiplink and DiviOps also have free WordPress.org editions, so "free" alone is not a difference |
| Ahead | Pro WooCommerce writes (create, update, bulk regular-price change with preview and undo) | Repo: `pro-addon/README.md`. Not stated by Divi Cake, Wiplink or DiviOps (DiviOps Pro states FluentCart tools; Divi Connect Pro states "WooCommerce pages") |
| Ahead | Optional approval mode that refuses a save without a matching preview | Repo: CLAUDE.md "Approval mode". Divi Connect states "require confirmation before overwrites" [DL-HOME], WPVibe approval for destructive actions [VIBE-PRICE]: similar intent, mechanism not stated |
| **Behind** | Price for many sites: Divi Cake states unlimited sites on every licence at a $49/yr or $99 lifetime launch price (regularly $99/$299); Divi Connect $89/yr for 1,000 sites; DiviOps Studio €149/yr unlimited (€99 founder). Our unlimited tier is `{{price:agency}}` for `{{sites:agency}}` (`layoutlab/config/pricing.json`, the live store [OURS-STORE]): compare these yourself before choosing the message; on entry price for one site we are in the same band as DiviOps and AI Engine, above Divi Connect | [CAKE-SHOP, DL-HOME, DOPS-PRICE, AIE-PRO] |
| Behind | A post-save render check: Divi Cake renders the page after saving; DiviOps has a render preview; Oxygen has preview-post | [CAKE-CHECK, DOPS-GH, OXY-62]. Ours is in development (a spike exists: `docs/post-save-render-check-evidence-5.14.md`), not shipped |
| Behind | Writing presets/variables/global colours in the free tier: our free plugin is read-only by design (WordPress.org rules); Divi Cake (paid) and DiviOps state preset and variable tools | [CAKE-TOOLS, DOPS-ORG]; ours are Pro tools |
| Behind | Images from public URLs (Divi Cake) | [CAKE-TOOLS]; we refuse third-party image hosts by design (CLAUDE.md compliance rule) |
| Behind | Breadth: Respira 19 builders, WPVibe several builders and Divi 4 | [RES-PRICE, VIBE-DIVI] |
| Behind | Social proof: DiviOps 1,000+ installs vs our 100+ | [DOPS-ORG, OURS-ORG] |
| Behind | In-builder agent from the theme vendor itself, bundled with Divi Pro | [ET-AI, ET-JOIN] |
| **Unknown** | Whether any competitor's check is deterministic, what schema it uses, and how it was verified | Not stated. Verify by reading their docs in full or by testing a known render-fatal value (e.g. a plain-string testimonial `company`, `docs/render-fatal-sweep.md`) on a trial copy |
| Unknown | Divi AI Agent's undo/preview/approval behaviour | Not stated on ET pages. Verify in a Divi Pro trial |
| Unknown | Whether Elegant Themes ships an official MCP, and when | Only a comment reply [ET-AGENT]. Watch the ET blog and changelog |
| Unknown | Divi Connect Pro and Wiplink validation details; whether "one-click undo" covers every write | [WIP-ORG] only a screenshot caption. Read divilove.com/docs |
| Unknown | Divi Cake's price after 8 November 2026 beyond "regularly $99 / $299" | [CAKE-SHOP] |

## Suggested positioning response (for the owner)

- The "safe Divi 5 MCP" space is no longer empty: at least three Divi-5-only tools state validation, drafts and undo. Our
  message must move from "we have a gate" to "our gate is proven": deterministic, grounded in real exports and live renders,
  with the numbers (`evidence-page.md`).
- Design consistency with proofs (kits x languages) and the WooCommerce Pro tools are the clearest Pro-only differences.
- On price, compare per site honestly; the owner may want to review the agency tier against unlimited-site competitors
  (an owner decision, not made here).
