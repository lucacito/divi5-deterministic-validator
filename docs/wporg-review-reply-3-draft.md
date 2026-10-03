# Reply to the WordPress.org review — DRAFT (the owner sends it; nothing here was sent)

Review ID: `R RMT jhmg-ai-editor-for-divi-5/lucaslopvet/1Oct26/T3 3Oct26/4.3 (P0TDX379088HGN)`

Before sending: upload the rebuilt `jhmg-ai-editor-for-divi-5.zip` at "Add your plugin", run Plugin Check on the zip, and test on a clean install with WP_DEBUG on.

---

Hello,

Thank you for the review. We believe both points are misreadings, and we explain why below. We also made one small change related to the first.

**1. Enqueueing**

The plugin's only CSS and JavaScript files (`assets/admin.css`, `assets/admin.js`) are loaded with `wp_enqueue_style()` and `wp_enqueue_script()`, on the `admin_enqueue_scripts` hook and only on the plugin's own screen (`AdminPage::enqueueAssets()`). The plugin prints no `<script>` or `<style>` tag, loads nothing on the front end, and uses no inline event handlers.

The example you gave (`src/StyleGuide.php:220`) is not code. It is a sentence in a text guide that the plugin sends to the user's AI assistant, telling it never to write `<script>`, `<style>`, `<iframe>`, `<object>` or `<embed>` markup. That is the guide that came out of the previous review round. It is never output to a page.

To remove any doubt, we replaced the last two inline `style="…"` attributes in the admin screen with classes in `admin.css`.

**2. Remote administration**

The plugin does not give an external service any administrative capability, and the author operates no server, panel or service that connects to the site. The only caller is the AI assistant that the site owner chooses and connects with a key that only they can see.

- `ApiKey::authenticateRequest()` accepts a request only with a valid Bearer key from the site's own `wp_options`. The key is created by an administrator on the plugin's settings screen, shown only to users with `manage_options`, and can be regenerated there at any time, which revokes the old one at once.
- After the key is accepted, every request still needs the key owner to hold `edit_pages`, and every page that is read or written is checked again with `edit_post`. A demoted or deleted key owner gets a 403.
- The routes (`/mcp` and the REST routes) only run the plugin's own handlers. All the tools do one of: read or edit the content of existing pages (with an undo history), create a page as a draft, list the media library and the site's global styles read-only, or return text guides. The free plugin has no tool or route to install, activate, update or delete plugins or themes, create or manage users, change site settings, menus or the front page, or save CSS, JavaScript or PHP. A test in our suite (`WpOrgComplianceTest`) fails if one is added.
- The admin screen tells the owner that the key lets their AI assistant read and edit the site, and to keep it private.

So there is no remote administration, and there is no external infrastructure of ours that would need an infrastructure audit. If you think a specific route does allow an administrative action, please tell us which one and we will fix it.

Thank you,
Lucas
