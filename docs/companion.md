# Using JHMG AI Editor for Divi 5 alongside Divi's MCP

Divi's own MCP builds and edits pages. This plugin is the layer around it: design guidance before the build, a fixed-rule check
after it, and undo for every change, whichever tool made it. The two are independent. Neither wraps, blocks or changes the
other's tools.

Requirements: the abilities need WordPress 6.9 or later (the Abilities API). On older WordPress the plugin works exactly as
before over its own MCP endpoint and REST API; only the abilities and the "undo for other tools" capture described below
depend on it (capture works on any version, abilities do not).

## What you get

| Need | Ability (free) |
|---|---|
| Pick a look before building | `jhmg-ai-editor/get-design-languages` (10 languages: colors, fonts, shape, spacing, what to avoid, contrast-checked pairings) |
| Pick sections to build with | `jhmg-ai-editor/get-section-recipes` (filter by `role`, `layout`, `mood`, `density`, `stage`; pass `name` for the markup) |
| Learn the valid attribute shapes | `jhmg-ai-editor/get-style-guide`, `get-landing-guide`, `get-site-guide`, `get-image-guide` |
| Check a layout against fixed rules | `jhmg-ai-editor/validate-layout` (exact violation codes) |
| Review the design of any page | `jhmg-ai-editor/check-design` (advice only) and `get-variety-advice` |
| Undo | `jhmg-ai-editor/list-page-history`, `get-page-history-entry`, `restore-page-version` |
| Read and write pages (still validated) | `list-divi-pages`, `get-page-layout`, `update-page-layout`, `edit-page-content`, `create-page` |

Pro adds, in the `jhmg-ai-editor-pro` category: `audit-site`, `design-review-site`, `find-replace-site`, `list-batches`,
`start-batch`, `end-batch`, `undo-batch`.

All abilities are private to signed-in users: they need the same permission as the plugin's tools (edit pages, plus edit
permission on the page for anything that names one). Over REST they are at `/wp-json/wp-abilities/v1/abilities`; read-only ones
run with GET (input in the `input` query parameter), the others with POST. The official WordPress MCP adapter lists them too
(the plugin sets `meta.mcp.public`).

## Example instructions for your agent

Paste this (it is also in Settings, under "Instructions for your AI"):

```
When you work on a Divi 5 page, use the JHMG AI Editor abilities alongside the Divi tools:
1. Before building, call jhmg-ai-editor/get-design-languages and jhmg-ai-editor/get-section-recipes, pick one design language and the section recipes that fit, and read jhmg-ai-editor/get-style-guide.
2. Build or edit the page with any tool you like.
3. After building, call jhmg-ai-editor/validate-layout and jhmg-ai-editor/check-design on the page, and fix what they report.
4. If a change was wrong, undo it with jhmg-ai-editor/list-page-history and jhmg-ai-editor/restore-page-version.
```

With Pro, add: "Before a long job call jhmg-ai-editor-pro/start-batch, and when you are done call end-batch. When you have built the
site, call design-review-site and fix its list from the top." A person can then undo the whole job with `undo-batch`.

## What `check-design` does and does not do

It never blocks, changes or saves anything, and it lists what it could not check instead of passing it.

* **Contrast.** Reports only text that definitely cannot be read: both colors are plain values written in the layout, the
  background is a solid color of the module or its nearest ancestor with nothing painted over it, and the ratio is below 3:1 (or
  below 4.5:1 for text whose size is written in px and is under 18px). Colors that come from the site's settings (global colors,
  variables), text with no color of its own, images, gradients, video, positioned or overlapping modules, semi-transparent colors
  and hover states are counted as "not checked" and never reported.
* **Divi's stock look.** Divi's grey placeholder image (certain: the bytes are Divi's own), and a button or tab set with no
  styling and no preset (possible: a theme option can restyle them). The stock look was measured on Divi 5.15.1 (see
  `fixtures/shapes/divi-defaults-5.15.1.json`).
* **Ids this site does not have** (presets, colors, variables, custom fields) and **repetitive sections**.

## Undo for changes made by other tools

Before a Divi page is changed by something other than this plugin, the previous version is kept (the same last 10 per page, 512
KB each). Each version records where the change came from:

| Source | Kept by default | How it is recognised |
|---|---|---|
| An ability (for example Divi's MCP) | yes | the running ability's name |
| An application password | yes | the name you gave the password (never the password) |
| Other REST requests | yes | a REST route that is not Divi's |
| Divi's visual builder | no (WordPress revisions cover it) | Divi's REST route `/divi/v1/` or its old save action |
| Anything else (other plugins, WP-CLI, classic editor) | yes | nothing else matched: labelled "unknown" |

Change this under Settings, "Undo for changes made by other tools". The Dashboard's "Recent AI edits" shows the source. Restoring
a version first saves the version it replaces, so a restore can itself be undone.

## Pro: batches, audits and strict validation

* **Batches for any source.** There is no new table. A batch is worked out from the page history: an explicit one (between
  `start-batch` and `end-batch`) takes every change by that user in that time; otherwise changes by the same user from the same
  source with no gap longer than 10 minutes are one batch. `undo-batch` restores each page to how it was before the batch and
  skips, with a reason, a page that was changed after the batch (a newer snapshot exists, or it was modified later). It never
  overwrites a newer change and never deletes or trashes a page. History times have one-second resolution, so two batches
  inside the same second cannot be told apart.
* **`audit-site` and `design-review-site`** read 25 items per call (pass `next_cursor` to continue). The review returns one list,
  high priority first (unreadable text, links to missing pages, layouts the checker refuses), then medium (Divi's stock look, ids
  the site does not have), then low (repetition, uneven spacing, a color written as a plain value that is a global color).
* **Strict validation for AI writes** (Pro tab, off by default). After a Divi page is saved by an ability, an application password
  or the REST API, the checker runs and a failing page is listed in the Pro tab and in an admin notice. It only flags: nothing
  is blocked, changed or rolled back, and no page content is stored. A later valid save clears the flag.
