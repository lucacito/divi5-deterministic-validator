=== JHMG AI Editor for Divi 5 Pro ===
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.3.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for JHMG AI Editor for Divi 5. Lets your AI assistant set the front page and menu, publish pages, add CSS and edit the live Divi header and footer, and lets you undo each change in one step.

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
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

== External services ==

This plugin contacts one external service, **divi5lab.com**, which is operated by the plugin's author. It is used only to check your licence and to look for plugin updates.

* **When:** when you activate or deactivate a licence, about once a day while a licence key is saved, and when WordPress checks for plugin updates. Never on page views of your site's visitors.
* **What is sent:** your licence key, your site URL, the product id (`ai-editor-divi5-pro`), the plugin version and your WordPress version. Nothing else, and no content from your pages.
* **Why:** to confirm the licence is valid for this site and to deliver updates.
* Terms and privacy policy: https://divi5lab.com

If the service cannot be reached, the Pro tools you already unlocked keep working. Only a licence that divi5lab.com explicitly reports as revoked or invalid locks them again; an expired or lapsed licence stops updates and support, not features.

== Uninstall ==

Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS or page statuses: undo those first if you want them back.

== Changelog ==

= 0.3.0 =
* New: edit the live Divi header and footer (`list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`): checked like every page write, previewed with `dry_run`, undoable.
* New: `manage_theme_template` enables, disables or re-targets one existing Theme Builder template (preview token required, undoable). It cannot create or delete templates.

= 0.2.0 =
* Licence checks: a server error page can no longer lock your Pro tools; refreshes back off during an outage.
* New: batches and one-step undo (`list_batches`, `undo_batch`).
* New: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`.

= 0.1.0 =
* Skeleton: licensing, compatibility guard and the Pro admin tab.
