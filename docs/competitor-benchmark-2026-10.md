# Native-AI benchmark: Oxygen 6.2 and Breakdance 3.0 (backlog #7)

Retrieved 2026-10-06 from public pages only (nothing was installed or tested). Every claim names its source; a claim a page did not state is marked "not stated", never assumed. Oxygen and Breakdance share one Agent Connector (the Breakdance 3.0 page uses the same Settings path and wording), so they are treated as one product line below.

## What they ship

| | Oxygen 6.2 / Breakdance 3.0 | Our free plugin | Our Pro add-on |
|---|---|---|---|
| Setup | Settings > Agents & MCP > "Install Agent Connector" (one click), then a wizard: pick the agent (Codex CLI, Codex Desktop, Claude Code CLI, Claude Desktop, Other), it writes tailored instructions and an application password shown once ([Oxygen docs](https://oxygenbuilder.com/documentation/getting-started/connect-your-agent/)) | Settings tab per assistant (Claude Desktop, Claude Code, Gemini CLI, Cursor, ChatGPT...), key + endpoint URL | same |
| Auth | WordPress application password | Plugin API key tied to a user with `edit_pages` | same |
| Opt-in switch | "Allow agents to access this site over MCP" setting; multisite needs a super admin (same docs page) | The key itself is the opt-in | |
| Local sites | Docs say a hosted agent cannot reach a local site ([docs](https://oxygenbuilder.com/documentation/getting-started/connect-your-agent/)) | Same limit, documented in 4.6.0 setup notes | |
| Price | Oxygen: $199.50 once, AI included, unlimited sites ([Oxygen](https://oxygenbuilder.com/build-with-ai/)); Breakdance: page does not state ([Breakdance 3.0](https://breakdance.com/meet-breakdance-3-0/)) | Free | Annual, by site count |
| Builder scope | Their own builder | Divi 5 | Divi 5 |
| Protocol | MCP server plus WordPress Abilities API ([Oxygen](https://oxygenbuilder.com/build-with-ai/)) | MCP over HTTP, REST, ChatGPT OpenAPI | MCP + REST |

## Tool coverage they list (Oxygen 6.2 post, 2026-07-20: [source](https://oxygenbuilder.com/oxygen-6-2-is-now-available/))

`edit-post`, `html-to-page`, `create-template`, `set-template-conditions`, `get/set-element-conditions`, `create-reusable-component`, component property tools (3), `set-element-variable-overrides`, `insert-css-variables`, `insert-stylesheet`, `get-css-selectors`, `get/set-global-settings`, `get/set-settings`, `get/set-maintenance-mode`, `get-post-css-files`, `get-media-sizes`, `get-dropdown-options`, `search-condition-values`, `run-site-tool`, `get-skill`, `get-ecommerce-instructions`, and **`preview-post` / `preview-element`** ("render and verify their work"). Capabilities named in prose ([ahoi.dev, 2026-07-23](https://ahoi.dev/oxygen-6-2-mcp-edition/)): pages, templates, headers, footers, components; styling through existing selectors, variables and breakpoints; **dynamic content bound to WordPress, ACF or Meta Box data, with repeaters and post loops**; interactions and animations; full form building. The Oxygen build-with-ai page adds that the agent can create post types and custom fields and install and configure plugins.

Safety, as stated: MCP output validation and rejection of malformed dynamic-data bindings (Oxygen 6.2 post); Breakdance says a color change keeps the old scheme as a revision ([Breakdance AI page](https://breakdance.com/features/build-with-ai/)). Drafts, dry run, approval, per-write undo and a deterministic structural check are **not stated** on any page read.

## Where we are ahead

- **A deterministic gate on every write** with exact violation codes; they state only output validation without describing it.
- **Preview before save** (`dry_run` diff) and **optional approval mode**; not stated by them.
- **Per-write undo** (10 snapshots a page, restore is itself undoable) and **drafts-only page creation**; not stated by them.
- **Custom-code refusal** (CodePolicy): they let the agent insert stylesheets and install plugins. We cannot copy that, and it is a selling point for cautious owners (and a WordPress.org requirement).
- A free plugin, against a $199.50 builder licence.

## Gaps, ranked

| # | Gap | Evidence | Where it fits | Verdict |
|---|---|---|---|---|
| 1 | **Dynamic content bound to ACF / custom fields**, repeaters, post loops | Oxygen 6.2 prose and the Breakdance 3.0 page both name ACF and Meta Box | Free listing exists (`get_site_structure`); binding is backlog 2b | Highest value; blocked on a real Divi export of an ACF dynamic-content field |
| 2 | **Post-save render check** (`preview-post` / `preview-element`) | Named in the Oxygen 6.2 tool list | Backlog 2e (Pro, low). A render needs a remote or loopback request, which the free plugin may not make | Worth a spike for Pro: fetch the page's own URL and return a structural summary, not a screenshot |
| 3 | **Guided connect wizard** that writes agent-specific instructions and a one-time credential | Oxygen docs | Free, admin UI | We already have per-assistant tabs; the gap is polish (copy-paste complete command with the key filled in, "test connection" button). Small, safe |
| 4 | **HTML to native layout** (`html-to-page`) | Oxygen 6.2 tool list | Free guide or Pro tool | Needs real Divi shapes for every element; same constraint as the recipes. Park until the style vocabulary covers it |
| 5 | **Forms, interactions, animations** | Oxygen 6.2 prose | Pro candidate | Needs real exports of Divi's contact-form, interactions and animation attributes; no schema from memory |
| 6 | **Opt-in switch separate from the key** | Oxygen docs | Free | Minor; a deleted or demoted key owner is already refused |
| 7 | **WordPress Abilities API exposure** | Oxygen build-with-ai page ("full Abilities API support") | Free, architectural | Lets any Abilities-aware MCP adapter see the tools without our endpoint. Compliance-neutral, but a new surface to keep in lockstep; needs its own spec |
| 8 | Template conditions, components, maintenance mode, CSS variables, stylesheets | Oxygen tool list | Pro / forbidden | Template conditions: Pro Theme Builder already covers assignment. Stylesheets, CSS and plugin installs: never (custom-code rule) |

## Other Divi 5 tools seen (not Oxygen or Breakdance)

Respira (duplicate-first editing across many builders), Novamira, wp-ops-mcp and DiviOps Agent (WordPress.org, free core with Pro components, v1.5.30 on 2026-08-20, 500+ installs: dry-run planning, checksum drift checks, snapshots before writes, Divi preset and variable management) ([search results](https://ja.wordpress.org/plugins/?p=332312), [DiviOps page](https://wordpress.org/plugins/diviops-agent/)). DiviOps is the closest direct competitor in the free tier: it also states dry run, snapshots and drift checks, so the "preview and undo" advantage over it is narrower than over Oxygen. Our remaining distinctions against it are the deterministic validator with exact codes, approval mode, the custom-code policy, and the guide set (style, landing, site, image, recipes). A proper comparison needs its docs read in full; this pass only read its directory page.

## Recommendation

1. Keep 2b (ACF binding) first; it is the one gap two of three competitors lead with. Ask the owner for the real export.
2. Add a small "test connection" and fully filled copy-paste setup step to the Settings screen (gap 3): bounded, free, compliant, no schema risk.
3. Spike 2e for Pro as a structural post-save check (gap 2), not a screenshot.
4. Open a spec for Abilities API exposure only if the owner sees it as a distribution channel (gap 7); it is architectural.
5. Do not chase gaps 4, 5 and 8's code-writing parts without real exports or against the custom-code rule.
6. Before the Facebook reply to the commenter who asked about Oxygen and Breakdance: lead with the gate, preview, approval mode and undo, and the free price; do not claim they lack those features, only that their pages do not state them.

## Limits of this pass

Public pages only, one retrieval on 2026-10-06; marketing pages can omit safety features that exist. The Oxygen docs page did not name its MCP tools; the tool list comes from the release post. Breakdance's price and tool list were not stated on the pages read. Nothing here was run against their products.
