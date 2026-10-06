=== JHMG AI Editor for Divi 5 Pro ===
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for JHMG AI Editor for Divi 5. Lets your AI assistant set the front page and menu, publish pages, add CSS, edit the live Divi header and footer, find and replace text across your pages, audit them for layout problems and broken links, build a whole site page by page and launch it in one undoable step, and lets you undo each change in one step.

== Description ==

This is a separate plugin. It needs the free plugin "JHMG AI Editor for Divi 5" (version 4.4.0 or newer) and a licence from divi5lab.com. Without both, the Pro tools stay off.

Every change a Pro tool makes is recorded as a batch with an undo point. `undo_batch` restores everything it safely can and tells you what it could not (because the page or setting was changed afterwards, or the page history no longer holds the earlier version). It only says "fully undone" when every item was restored.

= Tools =

* `set_front_page` - set a published page as the site's front page.
* `set_primary_menu` - build the primary menu from pages and links, and assign it to the theme's menu location. Undo restores titles, links, nesting, CSS classes, link target, title attribute, XFN and description, but not custom meta that other plugins add to menu items.
* `publish_page` - publish or schedule a draft; preview first with `dry_run`.
* `set_custom_css` - add CSS inside one clearly marked block of WordPress Additional CSS. Your own CSS is never touched.
* `propose_php_snippet` - store PHP for you to review. Nothing is ever run, saved as a file or loaded by the plugin.
* `list_theme_layouts` - list the live Divi Theme Builder templates and their header, footer and body layouts (read-only); with a header or footer id it also returns that layout's content.
* `update_theme_layout` - replace the content of a live Divi header or footer with a new, checked Divi 5 layout. It shows on every page, so preview with `dry_run` first; undo restores the previous content.
* `edit_theme_layout` - find and replace literal text inside a live Divi header or footer, with the same checks, `dry_run` preview and undo as `update_theme_layout`.
* `manage_theme_template` - enable or disable one live Divi Theme Builder template, or change where it applies. It affects the whole site, so every real run needs the token from a preview (`dry_run`). It cannot create or delete templates.
* `find_replace_site` - replace literal text across your Divi 5 pages (and, with Theme Builder permission, the header and footer) as one undoable batch. You must preview first (`dry_run`) and repeat with the token; pages whose result would fail the layout checks are skipped; at most 50 items per run.
* `audit_site` - a read-only check of your Divi 5 pages and header/footer: layout check violations (including custom code the plugin would not save), unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call. It never makes a remote request and never changes anything.
* `build_site` - build a whole Divi 5 site as one undoable build: `add_page` creates one checked draft page per call (a page that fails the checks, or reuses a slug, is refused and the rest are kept), then the build is launched in one previewed step. Nothing goes live while pages are added, and `undo_batch` takes the whole build away again.
* `manage_global_variables` - create, edit or archive your site's Divi global variables of type numbers and strings (never deletes one). It affects every page that uses a variable, so every real run needs the token from a preview (`dry_run`); undo puts it all back.
* `manage_global_presets` - create or update your site's Divi module presets (never deletes one; updating a module's default preset needs `allow_default_presets`). A preset restyles every module that cites it, so every real run needs the token from a preview (`dry_run`); `undo_batch` puts the changes back as long as nothing changed them afterwards.
* `manage_global_colors` - create, recolor, rename or deactivate your site's Divi global colors (never deletes one; the five theme colors only with `allow_theme_colors`). It affects every page that uses a color, so every real run needs the token from a preview (`dry_run`); undo puts it all back.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and, except `manage_global_colors`, `manage_global_variables` and `manage_global_presets` (MCP and REST only), in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

== External services ==

This plugin contacts one external service, **divi5lab.com**, which is operated by the plugin's author. It is used only to check your licence and to look for plugin updates.

* **When:** when you activate or deactivate a licence, about once a day while a licence key is saved, and when WordPress checks for plugin updates. Never on page views of your site's visitors.
* **What is sent:** your licence key, your site URL, the product id (`ai-editor-divi5-pro`), the plugin version and your WordPress version. Nothing else, and no content from your pages.
* **Why:** to confirm the licence is valid for this site and to deliver updates.
* Terms and privacy policy: https://divi5lab.com

If the service cannot be reached, the Pro tools you already unlocked keep working. Only a licence that divi5lab.com explicitly reports as revoked or invalid locks them again; an expired or lapsed licence stops updates and support, not features.

== Uninstall ==

Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS, page statuses, header or footer content or Theme Builder templates: undo those first if you want them back.

== Changelog ==

= 0.8.0 =
* New: `manage_global_variables` creates, edits and archives your site's Divi global variables of type numbers (for example `12px` or `clamp(1rem, 2vw + 1rem, 3rem)`) and strings (short plain text), so an assistant can set spacing, radius and size tokens before it builds pages. It never deletes a variable. Every real run needs the single-use preview token of a dry run of the same arguments; variables that changed since the preview are skipped and reported, never overwritten. Values are checked strictly (a number with an optional unit or calc, clamp, min, max of those; plain text without braces, semicolons, quotes, comment markers, unbalanced brackets or code). It needs Divi's Variables Manager permission as well as the theme options permission. One `undo_batch` puts it all back; undoing a created variable removes it, unless it changed since.
* Variables are written in the same stored form as Divi's own Variables Manager (with their id, type and update time), so a layout that cites one renders it.
* Images, links, fonts and gradients variables are carried through untouched (they are not written yet). A site whose stored variables Divi's own save would damage (for example a stored colors group) is refused with the reason instead of being changed.
* Over MCP and REST only: this is the 16th Pro tool, and the ChatGPT importer's 30 operations are used up by the first 14 (16 free + 14 Pro).
* New: `manage_global_presets` creates and updates your site's Divi module presets (for example a heading style) so an assistant can set the look of a whole kind of module before it builds pages. Creating never changes an existing preset; changing a preset or a module's default preset restyles every module that uses it, so each run needs the single-use preview token of a dry run, custom CSS and code in a preset are refused, and `undo_batch` puts the changes back as long as nothing changed them afterwards (a default preset Divi adds for a module that had none goes too). It never deletes a preset. Updating a module's default preset also needs `allow_default_presets`.
* Presets stored in a form this tool does not manage (old-style entries, and option-group presets) are carried through untouched and refused as a target.
* Over MCP and REST only: this is the 17th Pro tool and the third that is MCP and REST only (after `manage_global_colors` and `manage_global_variables`), and the ChatGPT importer's 30 operations are used up by the first 14 (16 free + 14 Pro).
* `audit_site` now also reports global variable ids (`gvid-...`) that a page cites but your site does not have (code `UNKNOWN_VARIABLE_ID`); Divi silently drops such a value.
* Fix (also in 0.7.0's code path): an `undo_batch` takes the site-wide lock before it first reads the global colors.

= 0.7.0 =
* New: `manage_global_colors` creates, recolors, renames and deactivates your site's Divi global colors, so an assistant can set the palette before it builds pages. It never deletes a color. Every real run needs the single-use preview token of a dry run of the same arguments; colors that changed since the preview are skipped and reported, never overwritten. The five theme colors (primary, secondary, heading, body, link) change only when you pass `allow_theme_colors`, and only their color. Color values are checked strictly (hex or rgb/rgba/hsl/hsla numbers only). One `undo_batch` puts it all back; undoing a created color removes it, unless it changed since.
* Over MCP and REST only: this is the 15th Pro tool, and the ChatGPT importer's 30 operations are used up by the first 14 (16 free + 14 Pro).

= 0.6.0 =
* New: licence tiers (Personal, Freelancer, Agency) with per-tier site limits shown on the Pro tab.
* Expiry notice: a dismissible admin notice shows when your licence has ended (expired, canceled, or past due), but all 14 Pro tools keep working.
* Licence server: site-limit errors are caught and shown on the Pro tab so you can upgrade or free up a slot.
* No prices in the plugin; all tier and site information comes from the licence server.

= 0.5.0 =
* New: `build_site` builds a whole Divi 5 site as one undoable build. `add_page` creates one checked draft page per call: a page that fails the layout checks, or whose slug another page already uses, is refused with the exact reason and the pages already added are kept. `status` lists the build. `launch` publishes the pages, sets the front page and rebuilds the primary menu in one step, after a preview (`dry_run`) and with its single-use token; pages that changed or lost their slug since the preview are skipped and the rest goes live. Nothing is visible on the live site before the launch.
* One `undo_batch` takes the whole build away again (the menu and the front page come back, the pages go to the trash, unless your menu or front page still uses them); a page you edited in the Divi builder afterwards is skipped and reported, never trashed behind your back, even with the force option. An unfinished build can be abandoned the same way, and the undo closes it even when nothing could be restored.
* `build_site` uses the last of the 30 operations the ChatGPT importer accepts (16 free + 14 Pro).

= 0.4.1 =
* Safer previews: the token a preview returns now only works for the person who made it, only once, and only within 30 minutes. A preview of a Theme Builder template change has the same rules (it used to have no time limit).
* `audit_site` continues by page id, so pages added or deleted between two calls can no longer make it skip or repeat a page; it looks up each distinct link once per call, and it no longer says whether a draft or private post you cannot read is a draft or private.
* REST: a request body that was not sent as JSON is refused with 400 instead of running the tool with its defaults.
* ChatGPT spec: operation summaries are cut at a word and never inside brackets, and the 401 and 500 answers are documented.
* A header or footer preview that changes nothing now also reports whether the stored layout passes the checks, and a Theme Builder template write that Divi accepts without changing anything is reported as no change.

= 0.4.0 =
* New: `find_replace_site` (literal find and replace across your Divi 5 pages as one previewed, undoable batch) and `audit_site` (a read-only check for layout problems, unknown preset or color ids and links to missing or unpublished pages).
* New: every Pro tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and in the free plugin's ChatGPT OpenAPI spec, with the same permission checks as the MCP tools.

= 0.3.0 =
* New: edit the live Divi header and footer (`list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`): checked like every page write, previewed with `dry_run`, undoable.
* New: `manage_theme_template` enables, disables or re-targets one existing Theme Builder template (preview token required, undoable). It cannot create or delete templates.

= 0.2.0 =
* Licence checks: a server error page can no longer lock your Pro tools; refreshes back off during an outage.
* New: batches and one-step undo (`list_batches`, `undo_batch`).
* New: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`.

= 0.1.0 =
* Skeleton: licensing, compatibility guard and the Pro admin tab.
