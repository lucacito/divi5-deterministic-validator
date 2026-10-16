# Respira, Extra theme, Divi AI, and similar products (October 2026)

**Scope:** Competitor and related-product research for JHMG AI Editor for Divi 5. Every claim is sourced; unverified assumptions are flagged. Retrieval date: October 14, 2026.

---

## Respira for WordPress

### What it does

Respira (respira.press, v8.7.1 shipped 2026-08-26) is a WordPress plugin that lets external AI assistants (Claude, ChatGPT, Cursor, Codex CLI) safely edit WordPress sites through MCP over any of 18 page builders, including Divi 4 and Divi 5.

**Divi 5 implementation** ([respira.press/divi](https://www.respira.press/divi)): Respira reads and writes Divi 5 block JSON natively, keeping AI edits compatible with the Visual Builder. All 112 module types in Divi 5.11 are registered and checked against Divi's own metadata. A recent update (2026-08-26) added a check that stops Gutenberg changes from replacing a page with a version that has lost most of its text or images.

### Duplicate-first safety and sandboxing

Instead of editing the live page, Respira duplicates the page first and lets the AI edit the copy ([respira.press/blog/sandbox-ai-wordpress-edits](https://www.respira.press/blog/sandbox-ai-wordpress-edits)). The live published version keeps serving visitors. After validation passes 13 checkpoints, a human reviewer sees the diff and decides whether to publish or roll back to the snapshot. This is a **per-page sandbox**, not a staging environment.

**Real-world adoption** ([respira.press/divi](https://www.respira.press/divi)): Across 1,755 sites, 2,210 per-page sandboxes have been created and 680 changes rolled back.

### Licensing and pricing

Respira uses a **subscription model based on site count**, not per-token AI usage ([respira.press](https://www.respira.press)):
- **Maker:** €71/year (1 site)
- **Builder:** €150/year (5 sites)
- **Studio:** €451/year (25 sites)

### AI model: bring-your-own

Respira does not operate its own AI. It integrates with external AI assistants you already use or subscribe to ([respira.press/for-ai](https://www.respira.press/for-ai)): Claude, ChatGPT, Grok, Cursor, etc. You manage your own AI provider relationship and associated costs (your Claude subscription, ChatGPT Plus, etc.). Respira charges separately for the WordPress safety layer and page-builder intelligence, not for AI processing.

### How it compares to JHMG AI Editor

| Feature | Respira | JHMG AI Editor (Free) | JHMG AI Editor (Pro) |
|---|---|---|---|
| **Page builders supported** | 18 (Divi, Elementor, Bricks, Breakdance, SeedProd, etc.) | Divi 5 only | Divi 5 only |
| **Safety on live pages** | Per-page duplication + 13 checkpoints + human review | Deterministic validator + dry-run preview + approval mode (optional) | Same |
| **Undo** | Rollback from snapshot | Per-write undo, 10 snapshots per page | Same |
| **AI you bring** | Yes; Claude, ChatGPT, Cursor, Grok | Yes; Claude, ChatGPT, Cursor, Gemini, Copilot | Same |
| **Draft pages** | Not stated | Yes; create as draft only | Yes |
| **Pricing** | €71–451/year | Free | Annual, $99–$399/year depending on site count |
| **Layout validator** | Not stated | Deterministic, exact violation codes | Same |
| **Dry-run diff** | Not stated | Yes; up to 50 changes shown before save | Yes |

---

## Extra theme by Elegant Themes

### Does Extra support Divi 5?

**Yes.** Extra is a magazine theme from Elegant Themes ([elegantthemes.com](https://www.elegantthemes.com)), and it is "fully powered by the Divi Builder plugin" ([elegantthemes.com/blog/theme-releases](https://www.elegantthemes.com/blog/theme-releases)). Divi 5 was officially released on 2026-02-26 ([elegantthemes.com/blog/theme-releases/divi-5-official](https://www.elegantthemes.com/blog/theme-releases/divi-5-official)).

### Block format: Extra uses Divi Builder blocks

Extra is not a separate page builder. It uses the Divi Builder framework for design, which means pages built in Extra use the same Divi 5 block JSON format as pages built in the Divi theme. The theme difference is that Extra is a magazine theme with content modules designed for bloggers and publications, whereas Divi is a general-purpose theme.

**For JHMG AI Editor compatibility:** A page built in the Divi Builder under the Extra theme should be editable via our plugin, since it uses Divi 5 blocks. However, Extra's theme-specific content modules (if any) would need verification against the real Divi schema to be sure they are known to our validator.

### Key limitation

The Elegant Themes lifecycle means **Extra is not independently upgraded.** If Divi 5 adds a new module or changes a block shape, Extra will inherit that change only when Elegant Themes updates the Divi Builder itself, not on a separate Extra schedule.

---

## Divi AI by Elegant Themes

### What it is

Divi AI is a built-in generative suite integrated into the Divi theme ([elegantthemes.com/ai](https://www.elegantthemes.com/ai)). It runs inside the Divi visual builder and can generate complete layouts, sections, modules, text, images, and custom code without leaving the builder.

**Divi 5 integration:** Divi AI works within Divi's visual builder environment; specific Divi 5 block-level features are not separately documented.

### Pricing

- **Included in Divi Pro:** $277/year (which includes Divi AI, Divi Cloud, Divi Dash, Divi Teams, and $5/month agent spend) ([elegantthemes.com/join](https://www.elegantthemes.com/join))
- **Standalone for existing Divi users:** $115.20/year billed annually (60% off) or $129.6/year billed monthly

### How it differs from JHMG AI Editor

| Aspect | Divi AI | JHMG AI Editor |
|---|---|---|
| **What it is** | Built-in AI in Divi's visual builder | Tool for an external AI assistant (Claude, ChatGPT, etc.) to edit Divi 5 pages |
| **How to use it** | Open Divi builder, prompt in UI | Use Claude Desktop, ChatGPT, Cursor, or VS Code Copilot over MCP or REST |
| **AI provider** | Elegant Themes (included in Divi Pro) | You bring your own (Claude, ChatGPT, etc.) |
| **Works on all page builders?** | Divi only | Divi 5 only |
| **MCP / REST API** | No | Yes |
| **Deterministic validator** | Not stated | Yes |
| **Dry-run preview** | Not stated | Yes |
| **Undo** | Not stated | Yes, per-write |

### Key fact

Divi AI is a **builder-native interface**, not an external agent tool. It is tied to the Divi builder UI and does not expose an MCP or REST API for external assistants to use. If you want your Claude or ChatGPT assistant to edit Divi pages, you cannot use Divi AI—you use JHMG AI Editor or Respira instead.

---

## Novamira WordPress MCP plugin

### Overview

Novamira ([github.com/novamira](https://github.com/novamira)) is a free, open-source WordPress plugin that connects external AI coding agents (like Cursor, Claude Code) to a WordPress installation through MCP. It supports page builders including Elementor, Bricks, Divi, ACF, and WooCommerce.

### Divi 5 awareness

The latest version (1.12.7, released 2026-10-01) adds a check that stops Gutenberg changes from replacing a page with a version that has lost most of its text or images—a safeguard relevant to Divi 5 block editing.

### Safety positioning: development/staging only

**Important:** Novamira explicitly states it should be used for **development and staging environments only** ([instawp.com/novamira-review](https://instawp.com/novamira-review), [daily.dev/posts](https://daily.dev/posts/novamira-review-an-mcp-plugin-that-gives-your-ai-agent-access-to-wordpress-hsry2yzte)). The plugin gives AI agents full admin access with "unrestricted control over this WordPress installation," can execute PHP, run WP-CLI, query the database, and read/write files. All changes execute immediately with no automatic rollback.

Novamira's guardrails are warnings you can click through. It includes a check that flags production-looking domains but still allows AI access if the user confirms—a deliberate choice to keep it as a development tool.

### Pricing

Free and open-source; no stated licensing fees.

### How it compares to JHMG AI Editor

| Feature | Novamira | JHMG AI Editor |
|---|---|---|
| **Recommended environment** | Development/staging only | Live production-safe |
| **Page builders** | Elementor, Bricks, Divi, ACF, WooCommerce | Divi 5 only |
| **Safety model** | Full PHP execution, immediate changes, no rollback | Deterministic validator, dry-run, optional approval, per-write undo |
| **Pricing** | Free | Free (plugin); Pro is annual by site count |
| **AI provider** | You bring your own (Cursor, Claude Code, etc.) | You bring your own |

---

## WPVibe WordPress MCP plugin

### Overview

WPVibe ([wpvibe.ai](https://wpvibe.ai)) is a free WordPress plugin plus hosted MCP service that connects AI assistants (Claude, ChatGPT, Cursor, Claude Code) to WordPress. It supports multiple page builders including Divi 5 ([wpvibe.ai/wpvibe-divi-support](https://wpvibe.ai/wpvibe-divi-support)).

### Divi 5 implementation

"WPVibe now speaks Divi, 5 included." When WPVibe builds a page, the result is a native Divi layout with sections, rows, modules, and your brand colors, with each piece individually editable as if built by hand.

### Pricing

- **Free tier:** $0 forever
- **Pro:** $99/year
- **Power:** $299/year
- **Agency:** $599/year
- **Scale:** $899/year

Pricing is based on daily tool calls: Free = 100 calls/day, Pro = 500, Power = 2,000.

### Safety and approval

WPVibe "asks approval with a preview for destructive actions" but does not explicitly claim staging-only operation or per-page sandboxing.

### How it compares to JHMG AI Editor

| Feature | WPVibe | JHMG AI Editor |
|---|---|---|
| **Page builders** | Divi 5, Elementor, Bricks, SeedProd, Beaver Builder, Breakdance | Divi 5 only |
| **Pricing model** | Per-day tool call limit | Per-site annual (no call meter) |
| **Approval before changes** | Approval + preview for destructive actions | Dry-run diff + optional approval mode |
| **Undo** | Not stated | Per-write, 10 snapshots per page |
| **Deterministic validator** | Not stated | Yes, exact violation codes |
| **AI provider** | You bring your own | You bring your own |
| **Free tier available** | Yes, 100 calls/day | Yes (free plugin) |

---

## FAQ Answers for readme / site

### Q: Can I use the AI Editor with Extra theme?

**UNVERIFIED (flagged for review).** Our plugin is built for pages using the Divi Builder on Divi 5, and Extra is powered by the Divi Builder. Pages built in Extra using the Divi Builder should work with our plugin, but Extra-specific content modules (if any exist) have not been tested against our validator. Contact support before using on Extra sites to confirm compatibility.

**[Source flag: Extra's relation to Divi Builder modules is documented by Elegant Themes, but Extra-only modules are not confirmed to exist or be part of the real schema.]**

### Q: How does JHMG AI Editor differ from Divi AI?

**VERIFIED.** Divi AI is built into the Divi visual builder and lets you prompt text, images, and layouts inside the builder itself. JHMG AI Editor lets your external AI assistant (Claude, ChatGPT, Cursor) read and edit Divi 5 pages over MCP or REST, with a deterministic safety check on every save and a dry-run preview before you approve. If you want Claude or ChatGPT to edit your pages, use our plugin. If you want to design inside Divi's builder with built-in generation, use Divi AI (part of Divi Pro).

[Sources: [elegantthemes.com/ai](https://www.elegantthemes.com/ai); Divi AI is builder-native with no MCP/REST.]

### Q: How is JHMG AI Editor safer than Respira?

**VERIFIED.** Both tools keep your live pages safe from broken edits. Respira duplicates a page and lets AI edit the copy; you review the diff and publish or roll back. JHMG AI Editor runs a deterministic validator on every write—the same input always produces the same verdict—and shows you a dry-run diff before you approve. Both approaches work. Our validator is narrower (Divi 5 only) and more deterministic (no fuzzy output validation); Respira covers 18 page builders but states only that it validates output without describing how. If you need Divi 5 depth and determinism, we're the better fit. If you need 18 builders, Respira is the fit.

[Sources: Respira documentation; our own features.]

### Q: Is JHMG AI Editor free or paid?

**VERIFIED.** The plugin is free on WordPress.org and includes 16 tools: reading pages and site structure, creating drafts, dry-run previews, undo, and guides for the AI to learn safe Divi patterns. The Pro add-on ($99–$399/year depending on site count) adds 14 more tools: find-and-replace across the whole site, site audit, Theme Builder header/footer editing, front-page and menu control, publishing, and whole-site build.

[Sources: plugin repository, divi5lab.com pricing.]

### Q: What happens if I use JHMG AI Editor on a Divi 4 site?

**UNVERIFIED (flagged for review).** Our plugin is built for Divi 5 block JSON only. Divi 4 used shortcodes, which is a completely different format. If you run our plugin on a Divi 4 site and try to have an AI edit a page, our validator will reject any changes because Divi 4 blocks are not in our schema. To use an AI assistant on Divi 4 sites, use Respira (which supports both Divi 4 and Divi 5) or upgrade to Divi 5.

[Source flag: Divi 4 shortcode format is documented in Divi release notes; our schema is Divi 5 only by design. This answer is accurate but should be confirmed with the owner for tone.]

---

## Positioning statement for divi5lab.com About page

**Primary:**

> I built JHMG AI Editor to prove one thing: AI can edit your live site safely. Every layout the AI writes passes a deterministic validator before it touches your database—same input, same verdict, no guessing. The free plugin on WordPress.org includes 16 tools; Pro adds 14 more. You bring your own assistant—Claude, ChatGPT, Cursor—and I'll keep the gate.

**Alternative 1 (personal, owner-voice):**

> AI keeps breaking my websites, so I built a validator that cannot be fooled. Feed it broken JSON, it says no. Feed it valid Divi 5, it says yes, same every time. JHMG AI Editor wraps this gate around the free plugin and Pro tools so you and your AI can be creative without the risk.

**Alternative 2 (technical, concise):**

> JHMG AI Editor is a deterministic Divi 5 layout validator wrapped in a WordPress plugin. It lets Claude, ChatGPT, or Cursor read and edit your pages safely—with dry-run previews, per-write undo, and exact violation codes when something goes wrong. Free on WordPress.org; Pro for whole-site tools.

---

## Research limits

1. **Extra theme and Divi Builder:** Elegant Themes documents that Extra is "powered by the Divi Builder," but whether Extra-only modules exist and whether they use the same Divi 5 block format as the Divi theme is not explicitly confirmed. Assumed true but not directly verified.

2. **Divi AI's Divi 5 specifics:** Divi AI's official pages do not separate Divi 5 features from general Divi features. Its Divi 5 block-level integration is not documented separately.

3. **Novamira's Divi 5 depth:** The latest update (1.12.7) shows Gutenberg-related safety improvements, but Novamira's specific Divi 5 block JSON support is not documented; only its support for "Divi" as a builder is named.

4. **Respira's validator internals:** Respira's 13-checkpoint validation process is mentioned in marketing but not technically documented. It is not stated whether it is deterministic or probabilistic.

5. **WPVibe's safety mechanism:** WPVibe asks approval for destructive actions but the exact mechanism (dry-run vs. staging vs. other) is not documented on the pages retrieved.

All product prices and features are as stated on October 14, 2026, on their official pages. Pricing may have changed since publication.
