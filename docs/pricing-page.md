<!--
DRAFT. Not on the live site. Every price, site limit, cap and reminder day is a {{token}} read from layoutlab/config/pricing.json ({{reminders}} = the renewalReminderDays joined with "and"; {{tier:lifetime}} = the label of the Lifetime tier).
Competitor facts are third-party and dated "as of October 2026"; each has its source URL in a comment.
TODO(owner) markers are decisions or facts I could not verify. {{refund:days}} = refundWindowDays.
-->

# JHMG AI Editor for Divi 5: pricing

## An AI editor that cannot save a broken Divi layout

Your AI assistant can be creative. Nothing it writes reaches your database until a deterministic validator has checked every block against the real Divi 5 schema. Same layout in, same verdict out, no AI in the check. If a layout is invalid, the assistant gets the exact violation codes and fixes its own mistake.

- **Undo for your AI's edits.** Before the plugin saves an edit it keeps a snapshot of the page, so you can restore the previous version. (A page too large to snapshot says so in the response; creating a new page has nothing to undo.)
- **Theme Builder editing.** Change the live Divi header and footer, with a preview first.
- **Whole-site build, one-step undo.** Build a site page by page as drafts, launch it in one previewed step, and take it all away again with one undo.
- **Works with Claude, ChatGPT, Cursor and VS Code Copilot.** Over MCP and REST; ChatGPT through a custom connector that signs in with your WordPress login (we have not yet confirmed that with a real ChatGPT account). You bring your own assistant.
- **No metered AI credits.** We never sit between you and your AI provider, so there is no usage meter to run out.

The free plugin on WordPress.org has 16 tools, including creating pages as drafts and undo. Pro adds 14 more: find and replace across the whole site, a site audit, Theme Builder tools, front page and menu control, publishing, and whole-site build.

The code is GPL. The licence pays for updates and support.

## Plans

Pick by the number of sites you manage. Every plan has the same Pro tools.

| | Personal | Freelancer | Agency |
|---|---|---|---|
| Sites | {{sites:personal}} | {{sites:freelancer}} | {{sites:agency}} |
| Price per year | {{price:personal}} | {{price:freelancer}} | {{price:agency}} |

**Founding offer.** The first {{cap:founding}} buyers get {{percent:founding}}% off any plan. The discounted renewal price is locked for as long as your licence stays active.

**Lifetime.** {{tier:lifetime}} ({{sites:agency}}), one payment of {{price:lifetime}}, no renewal. Limited to {{cap:lifetime}} sales, then it is removed. <!-- TODO(owner): confirm what Lifetime includes: updates and support for life, or a fixed period. -->

**Free trial.** {{trial:days}} days of Personal, no card needed.

**Upgrades.** Move to a bigger plan any time. You pay the difference for the rest of your current term, not a full new year.

## If your licence expires

Nothing breaks. All 14 Pro tools and the free plugin keep working on every site where the licence was activated. Only updates and support stop. You will get an email {{reminders}} days before your renewal date, and one dismissible notice in wp-admin if it lapses.

## How it compares

Facts are taken from each product's own pricing page, as of October 2026. "Not stated" means the page does not say; it does not mean the product lacks it.

| | JHMG AI Editor Pro | AI Engine Pro | WPVibe | Divi Pro |
|---|---|---|---|---|
| What it is | Divi 5 layout editing for your own AI assistant | WordPress AI toolkit (Meow Apps) | WordPress admin through MCP | Divi theme bundle from Elegant Themes with Divi AI |
| Entry price | {{price:personal}}/year | $79/year <!-- source: https://meowapps.com/products/ai-engine-pro/ --> | Free; Pro $99/year <!-- source: https://mcp.wpvibe.ai/pricing --> | Divi Pro $277/year (Divi alone is $89/year) <!-- source: https://www.elegantthemes.com/join/ --> |
| Sites on the entry plan | {{sites:personal}} | 1 <!-- source: https://meowapps.com/products/ai-engine-pro/ --> | Not limited by site; limited by daily tool calls <!-- source: https://mcp.wpvibe.ai/pricing --> | Unlimited <!-- source: https://www.elegantthemes.com/join/ --> |
| Usage model | Bring your own assistant; no meter | Not stated | Daily tool calls: 100 free, 500 on Pro, 2,000 on Power <!-- source: https://mcp.wpvibe.ai/pricing --> | Divi AI included; agent usage has a yearly complimentary budget <!-- source: https://www.elegantthemes.com/join/ --> |
| Lifetime option | {{price:lifetime}}, {{tier:lifetime}} only, {{cap:lifetime}} sales | Yes, from $499 <!-- source: https://meowapps.com/products/ai-engine-pro/ --> | Not stated | Divi Lifetime + Pro: $297 once, then $212/year for the Pro services <!-- source: https://www.elegantthemes.com/join/ --> |
| MCP | Yes | Yes, a WordPress MCP server <!-- source: https://meowapps.com/products/ai-engine-pro/ --> | Yes <!-- source: https://mcp.wpvibe.ai/pricing --> | Not stated <!-- source: https://www.elegantthemes.com/join/ --> |
| Divi mentioned | Divi 5 only, validated against the Divi 5 schema | Not stated | Divi listed among page builders it works with <!-- source: https://mcp.wpvibe.ai/pricing --> | It is Divi |
| Approval or preview before changes | Preview (dry run) and one-step undo | Not stated | Asks approval with a preview for destructive actions <!-- source: https://mcp.wpvibe.ai/pricing --> | Not stated |
| Deterministic layout validator | Yes | Not stated | Not stated | Not stated |

<!-- TODO(owner): the competitor columns only use what each pricing page states. If you want claims such as "no layout validator" or "no undo" for a competitor, each needs a hands-on check or a documentation source first. -->

Different tools do different jobs. If you want an AI to run a wide range of WordPress admin tasks, WPVibe and AI Engine are built for that. If you want Divi's own built-in AI inside the builder, that is Divi Pro. JHMG AI Editor is for one job: letting the assistant you already use edit Divi 5 pages safely.

## Questions

**What happens when my licence expires?**
All Pro tools keep working. Updates and support stop. Renewing brings them back.

**Can I get a refund?**
You have a {{trial:days}}-day free trial first, so a purchase is final once you have bought a plan. We do refund in full if you write to support within {{refund:days}} days of the charge and you were charged twice or in error, the add-on cannot be activated or does not work on a supported setup and we cannot fix it, or a yearly renewal was charged and you want to stop (we refund that renewal and cancel the plan). You can cancel at any time and keep the plan until the end of the period you paid for. The full text is on the licence page (kept in layoutlab `lib/legal/refund.ts`, `aiEditorRefundPolicy()`).

**The code is GPL. What am I paying for?**
Updates (including compatibility with new Divi and WordPress versions), support, and the licence service that handles activation and renewal. You may read and modify the code under the GPL.

**How are sites counted?**
Each distinct site address is one site. Writing it with or without www, with http or https, or with a trailing slash counts once. A different domain, subdomain or folder counts separately. To free a slot, deactivate the licence on the old site, or free it from your account page.

**How do upgrades work?**
You move to the higher plan and pay the difference for the time left on your current term. Your renewal date stays the same.

**Does the free trial need a card?**
No. After {{trial:days}} days the trial ends without charging you. Updates and support stop; the tools on sites where you activated it keep working, as with any lapsed licence. <!-- TODO(owner): this means a no-card trial gives Personal-tier tools permanently on the activated site. Keep, or require a card, or shorten? -->

**Does it work with my AI assistant?**
Claude Desktop and Claude Code, Cursor, VS Code Copilot and ChatGPT, over MCP or REST (ChatGPT through a custom connector, not yet confirmed by us with a real account). You need your own assistant account.
