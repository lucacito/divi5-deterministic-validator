# WordPress.org Compliance (4.0.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the plugin into **JHMG AI Editor for Divi 5 4.0.0**: free, fully functional, no licence code, no remote files, built-in image pack + Media Library access, a single dismissible Pro-add-on card, extension hooks for a separate Pro add-on, new slug — and pass the WordPress.org review on resubmission.

**Architecture:** Mechanical rename first (slug, text domain, main file). Then remove everything licence-gated / custom-code / remote-admin (moving the source into a non-shipped `pro-addon/` staging folder), add generic `jhmg_aied_*` hooks, replace remote images with a generated local image pack addressed by `{{aied:image:*}}` tokens, add a read-only `list_media_images` tool (MCP + REST + OpenAPI), add the Pro card, then enforce all of it with compliance tests, rewrite the readme, rebuild the zip and verify under the new slug with Plugin Check.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (WP shims in `tests/bootstrap.php`), WordPress APIs, SVG, Docker WP + WP-CLI, Playwright (dev-only, at `/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright`) for screenshots.

**Spec:** `docs/superpowers/specs/2026-10-01-wporg-compliance-design.md` (approved 2026-10-01 incl. Media Library requirement)

## Global Constraints

- Display name `JHMG AI Editor for Divi 5`; slug / folder / zip / **Text Domain** `jhmg-ai-editor-for-divi-5`; main file `jhmg-ai-editor-for-divi-5.php`; version **4.0.0**; readme `Contributors: lucaslopvet`.
- Internal identifiers do NOT change: namespace `AiEditorDivi5\WP`, constants `AI_EDITOR_DIVI5_*`, `ai_editor_divi5_*` option/nonce/action names, REST namespace `ai-editor-divi5/v1`, admin menu slug `ai-editor-divi5`, MCP server name `ai-editor-divi5`.
- Validator core (`src/`, `wp-plugin/validator/`) is NOT modified. `make test` exit 0 after every task.
- NOTHING in `wp-plugin/` may: check a licence, gate a feature, call a remote host (`wp_remote_*`, curl, `file_get_contents('http…')`), mention a third-party image host, contain an updater, or save arbitrary custom CSS/PHP/JS. Enforced by `tests/WpOrgComplianceTest.php` (Task 8) and checked incrementally.
- Free plugin MCP tool list (15): `list_divi_pages, get_page_layout, get_style_guide, get_site_guide, get_landing_guide, get_image_guide, get_section_recipes, validate_layout, update_page_layout, edit_page_content, create_page, list_page_history, get_page_history_entry, restore_page_version, list_media_images`. `create_page` stays draft-only and is FREE.
- Pro mention: exactly ONE card, Dashboard tab only, dismissible per user and never re-shown, factual wording that never implies the free plugin is limited (text fixed in Task 7). No other upgrade/promo string anywhere in the plugin.
- Hook names (stable API): `jhmg_aied_mcp_tools`, `jhmg_aied_mcp_call`, `jhmg_aied_register_rest_routes`, `jhmg_aied_openapi_paths`, `jhmg_aied_openapi_schemas`, `jhmg_aied_admin_tabs`, `jhmg_aied_render_admin_tab`, `jhmg_aied_image_guide`, `jhmg_aied_image_token`.
- `Tested up to` only in `readme.txt`; plugin passes Plugin Check with no errors under the NEW slug.
- Every OpenAPI operation description ≤ 300 chars; no bare `type: object` response schema.
- PHP `>=8.1`; PHPUnit `failOnWarning`/`failOnRisky` (attribute DataProviders; every test asserts). No `mb_*` functions (WordPress has no polyfills). Content to the DB via `wp_slash` discipline as before.
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push, upload, or email anything; never `wp plugin delete` in the shared dev site (runs uninstall.php and wipes dev data).

## Review Focus

1. After this release nothing licence-gated, Pro-only, custom-code-saving, remote-fetching or third-party-image-hosting remains anywhere under `wp-plugin/` — including generated output (guides, recipes, OpenAPI, tool descriptions) (Task 8 compliance tests + greps).
2. The free plugin still lets an AI build a complete image-rich page: every `{{aied:image:*}}` token used anywhere resolves to a file that exists; an unknown token resolves to the fallback instead of leaking `{{aied:`; recipes still validate (Tasks 4-5 tests).
3. `list_media_images` returns only image attachments the caller can read, enforces `upload_files` on both transports, clamps `per_page`, never uploads/modifies media (Task 6 tests + live).
4. The Pro card renders only on the Dashboard tab, never after dismissal, and no other upsell string exists (Task 7 + Task 8 guard).
5. The slug rename is complete and consistent: text domain = slug in every i18n call, folder/main file/zip/Docker/build script updated, Plugin Check clean under the new slug (Tasks 1 and 8).
6. Extension hooks are inert without a listener (tool list, dispatch, REST, OpenAPI, admin tabs unchanged) (Task 3 tests).

---

### Task 1: Rename — slug, text domain, main file, identity

**Files:**
- Rename: `wp-plugin/ai-editor-divi5.php` → `wp-plugin/jhmg-ai-editor-for-divi-5.php` (`git mv`)
- Modify: every `wp-plugin/**/*.php` i18n call (text domain), `wp-plugin/readme.txt`, `scripts/build-plugin-zip.sh`, `scripts/bootstrap.sh`, `docker-compose.yml`, `tests/**` references to the old main file / slug, `CLAUDE.md`
- Modify: `tests/WpOrgNamingTest.php` (create)

**Interfaces:**
- Produces: slug constants for later tasks: folder/zip `jhmg-ai-editor-for-divi-5`, main file `jhmg-ai-editor-for-divi-5.php`, text domain `jhmg-ai-editor-for-divi-5`; header `Plugin Name: JHMG AI Editor for Divi 5`, `Version: 4.0.0`, `AI_EDITOR_DIVI5_VERSION` = `4.0.0`.

- [ ] **Step 1: Write the failing test** — `tests/WpOrgNamingTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class WpOrgNamingTest extends TestCase
{
    private const SLUG = 'jhmg-ai-editor-for-divi-5';
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__) . '/wp-plugin';
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function testMainFileIsNamedAfterTheSlugAndOldOneIsGone(): void
    {
        $this->assertFileExists($this->root . '/' . self::SLUG . '.php');
        $this->assertFileDoesNotExist($this->root . '/ai-editor-divi5.php');
    }

    public function testHeaderIdentity(): void
    {
        $h = (string) file_get_contents($this->root . '/' . self::SLUG . '.php');
        $this->assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s+JHMG AI Editor for Divi 5\s*$/m', $h);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s+' . preg_quote(self::SLUG, '/') . '\s*$/m', $h);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s+4\.0\.0\s*$/m', $h);
        $this->assertStringContainsString("define('AI_EDITOR_DIVI5_VERSION', '4.0.0');", $h);
        $this->assertDoesNotMatchRegularExpression('/Tested up to/i', $h, 'Tested up to belongs only in readme.txt');
    }

    public function testEveryTranslationCallUsesTheSlugAsTextDomain(): void
    {
        $bad = [];
        foreach ($this->phpFiles() as $file) {
            if (str_contains($file, '/validator/')) {
                continue;
            }
            $src = (string) file_get_contents($file);
            // The text domain is the LAST string argument of an i18n call: ..., 'domain' )
            if (preg_match_all("/,\s*'([a-z0-9-]*editor[a-z0-9-]*)'\s*\)/", $src, $m)) {
                foreach ($m[1] as $domain) {
                    if ($domain !== self::SLUG) {
                        $bad[] = basename($file) . ": '{$domain}'";
                    }
                }
            }
        }
        $this->assertSame([], $bad, 'text domain must equal the slug everywhere');
    }

    public function testReadmeIdentity(): void
    {
        $r = (string) file_get_contents($this->root . '/readme.txt');
        $this->assertStringStartsWith('=== JHMG AI Editor for Divi 5 ===', $r);
        $this->assertMatchesRegularExpression('/^Contributors:\s+lucaslopvet\s*$/m', $r);
        $this->assertMatchesRegularExpression('/^Stable tag:\s+4\.0\.0\s*$/m', $r);
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/WpOrgNamingTest.php` → 4 failures (old main file, old name…).

- [ ] **Step 3: Implement**
  1. `git mv wp-plugin/ai-editor-divi5.php wp-plugin/jhmg-ai-editor-for-divi-5.php`; in it set `Plugin Name: JHMG AI Editor for Divi 5`, `Text Domain: jhmg-ai-editor-for-divi-5`, `Version: 4.0.0`, `define('AI_EDITOR_DIVI5_VERSION', '4.0.0');`. Keep `Plugin URI`, `Author` lines; update the `Description` line to drop any "Pro/premium" wording; the header must NOT contain `Tested up to`.
  2. Replace the text domain in every i18n call under `wp-plugin/` (NOT `validator/`): safe regex over PHP files: `s/, 'ai-editor-for-divi-5'(\s*\))/, 'jhmg-ai-editor-for-divi-5'\1/` (the 3.x slug is `ai-editor-for-divi-5`; there are ~170 occurrences; also catch the double-quoted/`$domain` forms if any — grep `ai-editor-for-divi-5` afterwards must return nothing in `wp-plugin/`). Do NOT touch `'ai-editor-divi5'` used as the admin page slug, MCP server name, REST namespace or Licensing page slug.
  3. `readme.txt` top: `=== JHMG AI Editor for Divi 5 ===`, `Contributors: lucaslopvet`, `Stable tag: 4.0.0` (full rewrite happens in Task 8; do only these three lines now).
  4. `scripts/build-plugin-zip.sh`: `SLUG="jhmg-ai-editor-for-divi-5"` and `MAIN="jhmg-ai-editor-for-divi-5"`; update comments.
  5. `docker-compose.yml`: bind-mount `./wp-plugin:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5:ro`; `scripts/bootstrap.sh`: every `ai-editor-divi5` plugin-slug reference (wp plugin status/activate) → the new slug.
  6. Fix tests/other files that reference the old main file or folder: `grep -rn "ai-editor-divi5.php\|ai-editor-for-divi-5\|plugins/ai-editor-divi5" tests scripts docker-compose.yml Makefile CLAUDE.md` and update (e.g. `tests/ValidatorSyncTest.php` paths, anything requiring `wp-plugin/ai-editor-divi5.php`).
  7. `CLAUDE.md`: update the "plugin build" paragraph and current-state line to the new name/slug/zip; one line: "WP.org review (2026-10-01) → 4.0.0 compliance rework in progress".
  8. Dev site: `docker compose up -d wordpress` (recreate with the new mount) then `docker compose exec -T wpcli wp plugin activate jhmg-ai-editor-for-divi-5`. (The old `ai-editor-divi5` entry disappears; do NOT `wp plugin delete`.) If activation prints a fatal, the cause is a stale path in the main file's `require`s — fix and re-run.

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/WpOrgNamingTest.php` → `OK`; `make test` exit 0; `docker compose exec -T wpcli wp plugin list --fields=name,status,version | grep jhmg` → `jhmg-ai-editor-for-divi-5 active 4.0.0`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: rename to JHMG AI Editor for Divi 5 (slug jhmg-ai-editor-for-divi-5), v4.0.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Remove licensing, Pro-only tools and upsell UI (stage them in `pro-addon/`)

**Files:**
- Move (`git mv`) to `pro-addon/src/`: `wp-plugin/src/Licensing.php`, `wp-plugin/src/Licensing/LicenseClient.php`, `wp-plugin/src/MenuBuilder.php`, `wp-plugin/src/CustomCss.php`, `wp-plugin/src/PhpProposals.php`
- Move to `pro-addon/tests/`: `tests/LicensingTest.php`, `tests/PhpProposalsTest.php`; delete `tests/ConverterPromoTest.php`
- Create: `pro-addon/README.md` (staging note: not shipped; contract = the hooks in Task 3; its own spec/plan follows)
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `wp-plugin/src/AdminPage.php`, `wp-plugin/src/autoload.php`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `wp-plugin/uninstall.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/src/LandingGuide.php`, `wp-plugin/src/StyleGuide.php`, `tests/SiteGuideTest.php`, `tests/HistoryLockstepTest.php` (count/premium-test updates), `phpunit.xml` stays scanning only `tests/`

**Interfaces:**
- Consumes: nothing new.
- Produces: a plugin where `create_page` is free; MCP tool list = 14 tools (the 15 in Global Constraints minus `list_media_images`, added in Task 6); no symbol `Licensing`, `isPremium`, `MenuBuilder`, `CustomCss`, `PhpProposals`, `set_front_page`, `set_primary_menu`, `set_custom_css`, `propose_php_snippet` anywhere under `wp-plugin/`.

- [ ] **Step 1: Write the failing guard (temporary, folded into Task 8's test later)** — `tests/WpOrgNoProTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class WpOrgNoProTest extends TestCase
{
    private const FORBIDDEN = [
        'Licensing', 'LicenseClient', 'isPremium', 'MenuBuilder', 'CustomCss', 'PhpProposals',
        'set_custom_css', 'propose_php_snippet', 'set_front_page', 'set_primary_menu',
        'setFrontPage', 'setPrimaryMenu', 'pre_set_site_transient', 'update_plugins',
    ];

    public function testNoLicenceOrProCodeRemainsInThePlugin(): void
    {
        $root = dirname(__DIR__) . '/wp-plugin';
        $hits = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !in_array($f->getExtension(), ['php', 'js', 'json', 'txt', 'css'], true)) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                if (stripos($src, $needle) !== false) {
                    $hits[] = substr($f->getPathname(), strlen($root) + 1) . " mentions {$needle}";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testCreatePageIsFree(): void
    {
        $mcp  = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/McpHandler.php');
        $rest = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/RestController.php');
        $this->assertStringNotContainsString('upgrade_url', $mcp);
        $this->assertStringNotContainsString('upgrade_url', $rest);
        $this->assertStringNotContainsString('PREMIUM', (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/OpenApiSpec.php'));
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/WpOrgNoProTest.php` → many "mentions …".

- [ ] **Step 3: Implement the removal**
  1. `mkdir -p pro-addon/src/Licensing pro-addon/tests`; `git mv` the five source files and two tests as listed; `git rm tests/ConverterPromoTest.php`. Write `pro-addon/README.md`: purpose (staging copy of the code removed from the WordPress.org plugin: licence client, set_front_page, set_primary_menu, set_custom_css, propose_php_snippet and their UI), "not shipped, not built, not in PHPUnit", and the contract ("will use only the `jhmg_aied_*` hooks from Task 3 of the 4.0.0 plan").
  2. `wp-plugin/src/autoload.php`: delete the `require_once` lines for `Licensing/LicenseClient.php`, `Licensing.php`, `MenuBuilder.php`, `PhpProposals.php`, `CustomCss.php`.
  3. `wp-plugin/jhmg-ai-editor-for-divi-5.php`: delete the whole "Licensing: periodic validation + admin notices" block EXCEPT the AdminPage registration; the `if (is_admin())` block becomes exactly:

```php
if (is_admin()) {
    (new AiEditorDivi5\WP\AdminPage())->register();
}
```

  4. `wp-plugin/uninstall.php`: remove the `Licensing::clear()` and `PhpProposals::clear()` lines (keep `UsageTracker::dropTable()`, `ApiKey::delete()`, `HistoryStore::deleteAll()`, the db-version option delete).
  5. `McpHandler.php`: delete the four tool definitions (`set_custom_css`, `propose_php_snippet`, `set_front_page`, `set_primary_menu`), their four `match` arms and their four `tool*` methods; in `toolCreatePage` delete the `if (!Licensing::isPremium()) { … }` block entirely (and any `upgrade_url`/`premium` wording in the tool description: rewrite the `create_page` description as a plain free tool — it creates a DRAFT for the owner to review; keep the guidance about calling get_landing_guide/get_style_guide/get_section_recipes/get_image_guide first).
  6. `RestController.php`: delete the `/front-page` and `/primary-menu` routes + `set_front_page`/`set_primary_menu` methods; delete the `isPremium` block in `create_page`; remove premium/upgrade wording in comments and responses.
  7. `OpenApiSpec.php`: delete the `setFrontPage` and `setPrimaryMenu` operations; change `createPage` summary to `Create a new page (draft)` and its description to a plain, non-"PREMIUM" description (≤ 300 chars) with no 402 response.
  8. `AdminPage.php`: remove `handleActivateLicense`, `handleDeactivateLicense`, `handleDeleteProposal`, the `admin_post_*` registrations for them, `isPremium()`, `licensePanel()`, `viewUpgrade()`, the `upgrade` tab (tab list, `in_array` allow-list, switch case, nav label), `converterPromoSection()` and every call to it, the lock/"Recommended for you" cards that mention Premium/Upgrade/custom CSS/whole-site (replace the "Recommended" block with nothing — keep the Dashboard's connection card, setup progress (drop the `Premium unlocked` step: 3 steps), results stats, history panel), the code-proposals list in the Settings tab, the `license_*` and `proposal_deleted` notice keys, and `PhpProposals::count()` usage. The `features` tab lists tools: delete the Premium section and keep a single "Included" list that matches the 14 tools (create_page now listed under included). Grep `upgrade|premium|license|proposal|converter|Pro ` (case-insensitive) in `AdminPage.php`/`assets/admin.js`/`assets/admin.css` must return nothing except the Pro card added in Task 7.
  9. Guides: `SiteGuide`, `LandingGuide`, `StyleGuide` — remove any instruction to call `set_front_page`, `set_primary_menu`, `set_custom_css`, `propose_php_snippet` or to tell the owner to upgrade; where a site-building flow ended with "set the front page / build the menu", replace with "tell the owner which page to set as the front page and which pages to add to the menu (Settings → Reading, Appearance → Menus)". Update `tests/SiteGuideTest.php` and `tests/StyleGuideTest.php`/`LandingGuideTest.php` expectations accordingly (they must still assert real content, not be loosened to nothing).
  10. `tests/HistoryLockstepTest.php`: the "MCP exposes" test is by name list — unchanged; if it counts tools, set the expected count to 14 for now (Task 6 raises it to 15).

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/WpOrgNoProTest.php` → OK; `make test` exit 0 (note the removed/moved tests no longer run). `php -l` every edited PHP file. Live: `tools/list` over MCP shows exactly 14 tools (use the bearer-key curl pattern from the 3.5.0 task; read the key with `wp option get ai_editor_divi5_api_key`, never print or commit it); a draft page via `create_page` succeeds; the Dashboard renders (Playwright screenshot looked at) with no Premium/Upgrade/licence text.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat!: remove licensing and Pro-only tools from the WordPress.org plugin; create_page is free

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Extension hooks for a separate Pro add-on

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `wp-plugin/src/AdminPage.php`
- Modify: `tests/bootstrap.php` (filter shims)
- Create: `tests/ExtensionHooksTest.php`
- Create: `docs/EXTENDING.md` (developer-facing hook reference, ≤ 80 lines)

**Interfaces:**
- Produces (all generic; the free plugin never checks a licence):
  - `apply_filters( 'jhmg_aied_mcp_tools', array $tools ): array`
  - `apply_filters( 'jhmg_aied_mcp_call', null, string $name, array $arguments, mixed $rpcId )` → a `WP_REST_Response` claims the call; anything else falls through to "Unknown tool".
  - `do_action( 'jhmg_aied_register_rest_routes', string $namespace )` at the end of `RestController::register_routes()`.
  - `apply_filters( 'jhmg_aied_openapi_paths', array $paths, string $base )`, `apply_filters( 'jhmg_aied_openapi_schemas', array $schemas )` inside `OpenApiSpec::spec()`.
  - `apply_filters( 'jhmg_aied_admin_tabs', array $tabs ): array` (slug ⇒ label) and `do_action( 'jhmg_aied_render_admin_tab', string $tab )` for an add-on's own screen.

- [ ] **Step 1: Shims** — append to `tests/bootstrap.php` (guarded):

```php
// ---- Hook shims (filters/actions) ------------------------------------------------------
$GLOBALS['__wp_filters'] = [];
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $fn, $priority = 10, $args = 1 ) { $GLOBALS['__wp_filters'][ $tag ][] = $fn; return true; }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $fn, $priority = 10, $args = 1 ) { return add_filter( $tag, $fn, $priority, $args ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        foreach ( $GLOBALS['__wp_filters'][ $tag ] ?? [] as $fn ) { $value = $fn( $value, ...$args ); }
        return $value;
    }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) { foreach ( $GLOBALS['__wp_filters'][ $tag ] ?? [] as $fn ) { $fn( ...$args ); } }
}
if ( ! function_exists( 'remove_all_filters' ) ) {
    function remove_all_filters( $tag ) { unset( $GLOBALS['__wp_filters'][ $tag ] ); return true; }
}
```

(If `add_action`/`apply_filters` already exist in `bootstrap.php` from an earlier task, extend the existing ones instead of redefining; keep `$GLOBALS['__wp_filters']` reset in the test `setUp`.)

- [ ] **Step 2: Failing test** — `tests/ExtensionHooksTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

class ExtensionHooksTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_filters'] = [];
    }

    private function spec(): array
    {
        return OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
    }

    public function testWithoutListenersTheSpecIsUnchanged(): void
    {
        $a = $this->spec();
        $b = $this->spec();
        $this->assertSame($a, $b);
        $this->assertArrayNotHasKey('/addon/thing', $a['paths']);
    }

    public function testAnAddonCanAddAPathAndASchema(): void
    {
        add_filter('jhmg_aied_openapi_paths', function (array $paths, string $base): array {
            $paths['/addon/thing'] = ['get' => ['operationId' => 'addonThing', 'summary' => 's', 'description' => 'd',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]]]]]]];
            return $paths;
        }, 10, 2);
        add_filter('jhmg_aied_openapi_schemas', function (array $schemas): array {
            $schemas['AddonThing'] = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            return $schemas;
        });
        $s = $this->spec();
        $this->assertArrayHasKey('/addon/thing', $s['paths']);
        $this->assertArrayHasKey('AddonThing', $s['components']['schemas']);
    }

    public function testMcpAndRestAndAdminCallTheDocumentedHooks(): void
    {
        $mcp   = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/McpHandler.php');
        $rest  = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/RestController.php');
        $admin = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/AdminPage.php');
        $this->assertStringContainsString("apply_filters('jhmg_aied_mcp_tools'", $this->compact($mcp));
        $this->assertStringContainsString("apply_filters('jhmg_aied_mcp_call'", $this->compact($mcp));
        $this->assertStringContainsString("do_action('jhmg_aied_register_rest_routes'", $this->compact($rest));
        $this->assertStringContainsString("apply_filters('jhmg_aied_admin_tabs'", $this->compact($admin));
        $this->assertStringContainsString("do_action('jhmg_aied_render_admin_tab'", $this->compact($admin));
    }

    /** Normalise spacing so the source-guard is not whitespace-brittle. */
    private function compact(string $src): string
    {
        return (string) preg_replace('/\(\s+/', '(', (string) preg_replace('/\s+/', ' ', $src));
    }
}
```

- [ ] **Step 3: Implement**
  - `McpHandler::onToolsList`: build `$tools = [ …the existing definitions… ];` then `$tools = apply_filters( 'jhmg_aied_mcp_tools', $tools );` and return `$this->rpcResult($id, ['tools' => $tools])`.
  - `McpHandler::onToolsCall`: the `match`'s `default` arm becomes `default => $this->extensionCall($id, $name, $arguments),` and add:

```php
    /**
     * Add-ons claim tool calls via the jhmg_aied_mcp_call filter. An add-on handler is
     * responsible for its own capability checks and returns a WP_REST_Response
     * (use the same JSON-RPC shapes as the built-in tools); anything else = unknown tool.
     */
    private function extensionCall(mixed $id, string $name, array $arguments): WP_REST_Response
    {
        $response = apply_filters( 'jhmg_aied_mcp_call', null, $name, $arguments, $id );
        if ( $response instanceof WP_REST_Response ) {
            return $response;
        }

        return $this->rpcError($id, -32602, "Unknown tool: {$name}");
    }
```

  - `RestController::register_routes()`: as the last statement add `do_action( 'jhmg_aied_register_rest_routes', self::NS );`.
  - `OpenApiSpec::spec()`: assign the big array to `$spec`, then before returning:

```php
        $spec['paths']                       = apply_filters( 'jhmg_aied_openapi_paths', $spec['paths'], $base );
        $spec['components']['schemas']       = apply_filters( 'jhmg_aied_openapi_schemas', $spec['components']['schemas'] );

        return $spec;
```

  - `AdminPage::render()`: `$extra = apply_filters( 'jhmg_aied_admin_tabs', [] );` (slug ⇒ label), merge into the nav after the built-in tabs, allow-list the extra slugs in the `in_array` tab check, and in the view `switch` add `default: do_action( 'jhmg_aied_render_admin_tab', $tab ); break;`.
  - `docs/EXTENDING.md`: the table of hooks above with signatures, the rule that add-on handlers must check capabilities themselves, and that OpenAPI additions must obey the ≤300-char / no-bare-object rules.

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/ExtensionHooksTest.php tests/OpenApiSpecTest.php` OK; `make test` exit 0.
  Live inertness + extension proof in Docker (temporary mu-plugin, removed afterwards):

```bash
docker compose exec -T -u root wordpress bash -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/aied-hook-probe.php <<"PHP"
<?php
add_filter("jhmg_aied_mcp_tools", fn($t)=>array_merge($t,[["name"=>"probe_tool","description"=>"probe","inputSchema"=>["type"=>"object","properties"=>new stdClass()]]]));
add_filter("jhmg_aied_mcp_call", function($r,$name,$args,$id){ return $name==="probe_tool" ? new WP_REST_Response(["jsonrpc"=>"2.0","id"=>$id,"result"=>["content"=>[["type"=>"text","text"=>"probe-ok"]]]],200) : $r; },10,4);
PHP'
```

  then over MCP: `tools/list` contains `probe_tool` (15 tools), `tools/call probe_tool` returns `probe-ok`, an unknown name still returns -32602; remove the mu-plugin (`rm -f` as root) and confirm `tools/list` is back to 14. Never leave the probe in place.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: generic jhmg_aied_* extension hooks for a separate Pro add-on

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Generated image pack, manifest and token resolver

**Files:**
- Create: `scripts/generate-image-pack.php` (deterministic generator; dev-only, not shipped)
- Create: `wp-plugin/assets/images/*.svg` (generated, committed) and `wp-plugin/assets/images/manifest.json` (generated, committed)
- Create: `wp-plugin/src/ImagePack.php`, `wp-plugin/src/ImageTokens.php`
- Modify: `wp-plugin/src/autoload.php` (require both)
- Create: `tests/ImagePackTest.php`, `tests/ImageTokensTest.php`

**Interfaces:**
- Produces `ImagePack` (`final`, static): `manifest(?string $path = null): array` → `['fallback'=>string, 'images'=>list<array{token:string,file:string,ratio:string,width:int,height:int,palette:string,role:string,alt:string}>]`; `catalogMarkdown(): string` (a Markdown table of tokens: token, role, ratio, palette, alt).
- Produces `ImageTokens` (`final`, static): `const PATTERN = '/\{\{aied:image:([a-z0-9-]+)\}\}/'`; `resolve(string $text, string $baseUrl, ?array $manifest = null, ?callable $override = null): string` — each token is replaced with `rtrim($baseUrl,'/') . '/' . $file`; `$override` is `fn(?string $url, string $token): ?string` (the `jhmg_aied_image_token` hook plugs in here: if it returns a non-empty string that is used instead); an UNKNOWN token resolves to the manifest's `fallback` file (never leaks `{{aied:`).
- Manifest roles (exact strings): `hero`, `section-bg`, `card`, `square`, `avatar`, `logo`. Token naming: `<role>-<palette>-<n>` e.g. `hero-blue-1`, `avatar-3`, `logo-1`, `square-teal-2`; fallback token `square-slate-1`.

- [ ] **Step 1: Failing tests**

`tests/ImageTokensTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImageTokens;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';

class ImageTokensTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'fallback' => 'square-slate-1',
            'images'   => [
                ['token' => 'hero-blue-1', 'file' => 'hero-blue-1.svg', 'ratio' => '16:9', 'width' => 1600, 'height' => 900, 'palette' => 'blue', 'role' => 'hero', 'alt' => 'a'],
                ['token' => 'square-slate-1', 'file' => 'square-slate-1.svg', 'ratio' => '1:1', 'width' => 800, 'height' => 800, 'palette' => 'slate', 'role' => 'square', 'alt' => 'b'],
            ],
        ];
    }

    public function testTokensResolveToFilesUnderTheBaseUrl(): void
    {
        $out = ImageTokens::resolve('<img src="{{aied:image:hero-blue-1}}">', 'https://s.example/wp-content/plugins/p/assets/images/', $this->manifest());
        $this->assertSame('<img src="https://s.example/wp-content/plugins/p/assets/images/hero-blue-1.svg">', $out);
    }

    public function testUnknownTokenFallsBackAndNeverLeaksBraces(): void
    {
        $out = ImageTokens::resolve('{{aied:image:does-not-exist}}', 'https://s.example/i', $this->manifest());
        $this->assertSame('https://s.example/i/square-slate-1.svg', $out);
        $this->assertStringNotContainsString('{{aied:', $out);
    }

    public function testOverrideWinsWhenItReturnsAUrl(): void
    {
        $out = ImageTokens::resolve('{{aied:image:hero-blue-1}} {{aied:image:square-slate-1}}', 'https://s.example/i', $this->manifest(),
            fn (?string $url, string $token): ?string => $token === 'hero-blue-1' ? 'https://cdn.addon.example/x.jpg' : $url);
        $this->assertSame('https://cdn.addon.example/x.jpg https://s.example/i/square-slate-1.svg', $out);
    }

    public function testTextWithoutTokensIsUntouched(): void
    {
        $this->assertSame('plain {text} {{other}}', ImageTokens::resolve('plain {text} {{other}}', 'https://s.example/i', $this->manifest()));
    }
}
```

`tests/ImagePackTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImagePack;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';

class ImagePackTest extends TestCase
{
    private const DIR = __DIR__ . '/../wp-plugin/assets/images';

    public function testManifestListsExactlyTheFilesOnDisk(): void
    {
        $m     = ImagePack::manifest();
        $files = array_map('basename', glob(self::DIR . '/*.{svg,png,jpg,webp}', GLOB_BRACE) ?: []);
        $listed = array_column($m['images'], 'file');
        sort($files);
        sort($listed);
        $this->assertSame($files, $listed, 'every image file is in the manifest and vice versa');
        $this->assertGreaterThanOrEqual(40, count($listed));
    }

    public function testEveryEntryIsWellFormedAndTokensAreUnique(): void
    {
        $m = ImagePack::manifest();
        $roles = ['hero', 'section-bg', 'card', 'square', 'avatar', 'logo'];
        $tokens = [];
        foreach ($m['images'] as $i) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $i['token']);
            $this->assertContains($i['role'], $roles);
            $this->assertMatchesRegularExpression('/^\d+:\d+$/', $i['ratio']);
            $this->assertGreaterThan(0, $i['width']);
            $this->assertNotSame('', $i['alt']);
            $tokens[] = $i['token'];
        }
        $this->assertSame($tokens, array_values(array_unique($tokens)));
        $this->assertContains($m['fallback'], $tokens);
        foreach ($roles as $r) {
            $this->assertNotEmpty(array_filter($m['images'], fn ($i) => $i['role'] === $r), "role {$r} has images");
        }
    }

    public function testSizeBudgetsAndNoExternalReferencesInsideImages(): void
    {
        $total = 0;
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            if (basename($f) === 'manifest.json') {
                continue;
            }
            $size = filesize($f);
            $total += $size;
            $this->assertLessThanOrEqual(61440, $size, basename($f) . ' exceeds 60 KB');
            if (str_ends_with($f, '.svg')) {
                $svg = (string) file_get_contents($f);
                $this->assertStringNotContainsString('<script', $svg);
                $this->assertDoesNotMatchRegularExpression('#(href|src)="https?://#i', $svg, basename($f) . ' must not reference remote files');
                $this->assertDoesNotMatchRegularExpression('/on[a-z]+\s*=/i', $svg, basename($f) . ' must not contain event handlers');
            }
        }
        $this->assertLessThanOrEqual(1572864, $total, 'image pack must stay under 1.5 MB');
    }

    public function testCatalogMarkdownListsEveryToken(): void
    {
        $md = ImagePack::catalogMarkdown();
        foreach (ImagePack::manifest()['images'] as $i) {
            $this->assertStringContainsString('{{aied:image:' . $i['token'] . '}}', $md);
        }
    }

    public function testGeneratorIsDeterministic(): void
    {
        $tmp = sys_get_temp_dir() . '/aied-pack-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/generate-image-pack.php') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            $this->assertFileEquals($f, $tmp . '/' . basename($f), basename($f) . ' differs from a fresh generator run');
        }
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
    }
}
```

Run both → FAIL (classes/files missing).

- [ ] **Step 2: Implement `ImagePack` and `ImageTokens`**

```php
<?php // wp-plugin/src/ImagePack.php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The plugin's built-in, locally bundled image pack (see assets/images/manifest.json). */
final class ImagePack
{
    private const DEFAULT_PATH = __DIR__ . '/../assets/images/manifest.json';

    /** @return array{fallback:string, images:list<array<string,mixed>>} */
    public static function manifest( ?string $path = null ): array
    {
        $raw  = is_readable( $path ?? self::DEFAULT_PATH ) ? (string) file_get_contents( $path ?? self::DEFAULT_PATH ) : '';
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['images'] ) || ! is_array( $data['images'] ) ) {
            return [ 'fallback' => '', 'images' => [] ];
        }

        return [ 'fallback' => (string) ( $data['fallback'] ?? '' ), 'images' => array_values( $data['images'] ) ];
    }

    /** Markdown table of every token the AI may use. */
    public static function catalogMarkdown(): string
    {
        $lines = [ '| Token | Role | Ratio | Palette | What it shows |', '|---|---|---|---|---|' ];
        foreach ( self::manifest()['images'] as $i ) {
            $lines[] = sprintf( '| `{{aied:image:%s}}` | %s | %s | %s | %s |', $i['token'], $i['role'], $i['ratio'], $i['palette'], $i['alt'] );
        }

        return implode( "\n", $lines );
    }
}
```

```php
<?php // wp-plugin/src/ImageTokens.php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Resolves {{aied:image:<token>}} placeholders to site-local URLs of the bundled image pack. */
final class ImageTokens
{
    public const PATTERN = '/\{\{aied:image:([a-z0-9-]+)\}\}/';

    /**
     * @param array{fallback:string, images:list<array<string,mixed>>}|null $manifest
     * @param (callable(?string, string): ?string)|null $override add-on hook: return a URL to replace the bundled one
     */
    public static function resolve( string $text, string $baseUrl, ?array $manifest = null, ?callable $override = null ): string
    {
        $manifest ??= ImagePack::manifest();
        $files    = [];
        foreach ( $manifest['images'] as $i ) {
            $files[ (string) $i['token'] ] = (string) $i['file'];
        }
        $base = rtrim( $baseUrl, '/' );

        return (string) preg_replace_callback(
            self::PATTERN,
            static function ( array $m ) use ( $files, $manifest, $base, $override ): string {
                $token = $m[1];
                $file  = $files[ $token ] ?? ( $files[ $manifest['fallback'] ] ?? '' );
                $url   = $file === '' ? null : $base . '/' . $file;
                if ( null !== $override ) {
                    $custom = $override( $url, $token );
                    if ( is_string( $custom ) && '' !== $custom ) {
                        return $custom;
                    }
                }

                return (string) $url;
            },
            $text
        );
    }
}
```

Add both `require_once` lines to `wp-plugin/src/autoload.php` (before `ImageGuide.php`/`SectionRecipes.php`).

- [ ] **Step 3: Write `scripts/generate-image-pack.php`** (dev-only). Contract (the implementer designs the art; the tests above are the acceptance gate):
  - Usage `php scripts/generate-image-pack.php [outDir]` (default `wp-plugin/assets/images`); writes every SVG and `manifest.json` into `outDir`; **fully deterministic** (no `rand()`/time; derive variation from a fixed integer seed per image via `mt_srand($seed)` or a small hash function — identical output on every run and on every machine/PHP version: avoid float formatting differences by rounding coordinates with `round($v, 1)` and formatting with `number_format`).
  - Content (≥ 40 images, original art we own): `hero` (8: palettes blue, purple, teal, sunset, forest, slate, rose, gold; 1600×900; layered mesh gradients + soft shapes/waves), `section-bg` (8: subtle patterns — dots, grid, diagonal lines, waves, blobs, topo-like lines, geometric tiles, noise-free gradient; 1600×900, low contrast so text stays readable), `card` (8: abstract illustrated scenes — shapes forming simple "chart", "devices", "shield", "rocket", "leaf", "bulb", "handshake-like abstract", "map pin"; 1200×900), `square` (8: 800×800 abstract/object-style graphics incl. the fallback `square-slate-1`), `avatar` (8: 400×400 illustrated faces/initial-monograms in varied skin/hair/background palettes — friendly, clearly illustrations, no real-person likeness), `logo` (4: 600×200 neutral placeholder wordmark/emblem shapes with NO text that could be mistaken for a real brand). Every `alt` is a short truthful description ("Abstract blue gradient with soft waves"). Palettes use accessible colour pairs. Each SVG: valid XML, `viewBox` matching width/height, no external references, no scripts, ≤ 20 KB typical (hard cap 60 KB), `<title>` element = the alt.
  - Manifest: `{"fallback":"square-slate-1","images":[{token,file,ratio,width,height,palette,role,alt}, …]}` pretty-printed with a trailing newline.
  - Quality gate (do this, it is part of the task): render a contact sheet (HTML page with all SVGs in a grid, labels = token) and screenshot it with Playwright into the scratchpad directory, LOOK at it (Read the PNG), and iterate on the generator until the pack looks polished and varied, not like placeholders; describe what you saw in the report. Do not commit the contact sheet.
  - The legal line: header comment in the script and `wp-plugin/assets/images/README.txt` state "Original artwork generated by scripts/generate-image-pack.php, © JHMG, released under GPL-2.0-or-later".

- [ ] **Step 4: Run, expect PASS** — `php scripts/generate-image-pack.php` (writes into `wp-plugin/assets/images`), then `vendor/bin/phpunit tests/ImagePackTest.php tests/ImageTokensTest.php` → OK (includes the determinism test); `make test` exit 0.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(images): generated original image pack + manifest + token resolver

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Replace every remote image URL with tokens; rewrite ImageGuide

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (replace each `https://picsum.photos/…` with a token)
- Modify: `wp-plugin/src/SectionRecipes.php`, `wp-plugin/src/ImageGuide.php`, `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/LandingGuide.php`/`SiteGuide.php` (only where they mention image sources)
- Modify: `tests/ImageGuideTest.php`, `tests/SectionRecipesTest.php`, `tests/StyleGuideTest.php`
- Modify: `tests/bootstrap.php` (`plugins_url` shim if missing)

**Interfaces:**
- Consumes: `ImagePack`, `ImageTokens` (Task 4); filter `jhmg_aied_image_token` / `jhmg_aied_image_guide` (this task defines their use).
- Produces: `SectionRecipes::recipe()` returns markup with tokens already resolved to site-local URLs; every guide returns text with no `{{aied:`; `ImageGuide::markdown()` = media-library-first guidance + the pack catalogue.

- [ ] **Step 1: Failing tests** — extend/replace:
  - `tests/SectionRecipesTest.php`: add
```php
    public function testNoRecipeContainsARemoteImageHostOrAnUnresolvedToken(): void
    {
        foreach (\AiEditorDivi5\WP\SectionRecipes::names() as $name) {
            $md = (string) \AiEditorDivi5\WP\SectionRecipes::recipe($name);
            $this->assertStringNotContainsString('{{aied:', $md, "$name leaks an unresolved token");
            $this->assertDoesNotMatchRegularExpression('#https?://(?!example\.com|[a-z0-9.-]*\.example(/|"))[^"\s]*\.(jpe?g|png|webp|gif|svg)#i', $md, "$name hotlinks a remote image");
            foreach (['picsum.photos', 'pravatar', 'randomuser', 'placehold.co', 'loremflickr', 'unsplash'] as $host) {
                $this->assertStringNotContainsString($host, $md, "$name mentions $host");
            }
        }
    }

    public function testRecipeImagesPointAtTheBundledPack(): void
    {
        $md = (string) \AiEditorDivi5\WP\SectionRecipes::recipe(\AiEditorDivi5\WP\SectionRecipes::names()[0]);
        $this->assertStringContainsString('/assets/images/', $md);
    }
```
  Keep the existing "every recipe validates" test untouched (the validator ignores `src`). If the bootstrap lacks `plugins_url`, add the guarded shim: `function plugins_url( $path = '', $plugin = '' ) { return 'https://example.com/wp-content/plugins/jhmg-ai-editor-for-divi-5/' . ltrim( $path, '/' ); }`.
  - `tests/ImageGuideTest.php`: rewrite the assertions for the new guide: contains the pack catalogue (every manifest token), mentions `list_media_images` and says to use it FIRST, tells the AI to fall back to the built-in pack only when the library has no suitable image, and contains none of the forbidden hosts (picsum, pravatar, randomuser, placehold.co, loremflickr, unsplash, pexels, pixabay) and no `{{aied:` unresolved.
  - `tests/StyleGuideTest.php`: assert `picsum` no longer appears and that image guidance points to `get_image_guide`.

- [ ] **Step 2: Implement**
  1. `section-recipes.json`: for each recipe's markup, replace every `https://picsum.photos/seed/…` URL with a token chosen by the image's ROLE in that recipe (hero/background → `{{aied:image:hero-<palette>-<n>}}`; card/blurb/gallery → `card-…` or `square-…`; avatar/testimonial → `avatar-<n>`; logo strip → `logo-<n>`), using distinct tokens within one recipe where it shows several images (e.g. gallery recipes get several different `square-*`/`card-*` tokens). Edit with a small Python/PHP one-off that rewrites the JSON and keeps it pretty-printed with the same key order; do not commit the one-off script. The text "swapped for picsum placeholders" in `SectionRecipes.php`'s docblock becomes "local image tokens".
  2. `SectionRecipes::recipe()`: after finding the markup, `return ImageTokens::resolve( $r['markup'], self::imageBaseUrl(), null, static fn ( ?string $u, string $t ): ?string => apply_filters( 'jhmg_aied_image_token', $u, $t ) );` with

```php
    private static function imageBaseUrl(): string
    {
        return defined( 'AI_EDITOR_DIVI5_FILE' ) ? plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ) : '';
    }
```
  and update `catalog()`'s sentence "replace the example text and image URLs with the user's content" to: "replace the example text with the user's content and swap the example images for Media Library images (list_media_images) when suitable ones exist; otherwise keep the built-in images".
  3. `ImageGuide::markdown()`: rewrite fully. Required structure: (a) why role-based images matter (keep the good existing prose about roles/ratios, trimmed); (b) **Step 1 — use the Media Library**: call `list_media_images` (search by subject/keywords, `orientation` per role: hero → landscape, avatar/team → square, card → landscape), pick by alt/title, use the returned `url` in the Divi image module, set the module's alt text from the attachment alt; (c) **Step 2 — built-in pack**: only when the library has no suitable image. The AI never writes `{{aied:…}}` tokens itself — it needs real URLs. So the guide shows a catalogue table with the columns `Token`, `URL` (the resolved site-local URL), `Role`, `Ratio`, `Palette`, `What it shows`; the AI copies the URL. To support this, change `ImagePack::catalogMarkdown()` to `catalogMarkdown( string $baseUrl = '' )`: with a base URL it adds the `URL` column (`rtrim($baseUrl,'/') . '/' . $file`); with an empty base it keeps the token-only table used in tests. `ImageGuide::markdown()` passes `plugins_url('assets/images/', AI_EDITOR_DIVI5_FILE)` (guard with `defined()`); (d) a rule list: never hotlink third-party images, never invent URLs, always give every image an alt, keep one ratio per row of cards, one palette per page; (e) at the very end `return (string) apply_filters( 'jhmg_aied_image_guide', $markdown );` (add-ons append their own sourcing instructions). Remove ALL third-party hosts and the `source.unsplash.com` retired note.
  4. `StyleGuide`: replace its single `picsum` mention with a sentence pointing to `get_image_guide` and `list_media_images`; `LandingGuide`/`SiteGuide`: same if they mention image sources.
  5. Update `ImagePackTest::testCatalogMarkdownListsEveryToken` if the signature change requires (the empty-base call still lists every token).

- [ ] **Step 3: Run, expect PASS** — `vendor/bin/phpunit tests/SectionRecipesTest.php tests/ImageGuideTest.php tests/StyleGuideTest.php tests/ImagePackTest.php` OK; `make test` exit 0; `grep -rniE "picsum|pravatar|randomuser|placehold|loremflickr|unsplash" wp-plugin/` returns nothing. Live: `get_section_recipes {"name":"<a recipe with images>"}` over MCP returns markup whose image URLs are `http://localhost:8181/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/…svg`; fetch two of those URLs with curl → HTTP 200 and `image/svg+xml`; create a draft page from that recipe via `create_page`, open it with Playwright, screenshot the front end, LOOK at it (images render), delete the page.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(images): recipes and guides use the bundled local image pack; media-library-first ImageGuide

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `list_media_images` — read-only Media Library access (MCP + REST + OpenAPI)

**Files:**
- Create: `wp-plugin/src/MediaLibrary.php` (pure formatting/clamping), `wp-plugin/src/MediaService.php` (WordPress query wrapper)
- Modify: `wp-plugin/src/autoload.php`, `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`
- Create: `tests/MediaLibraryTest.php`; modify `tests/HistoryLockstepTest.php` (add the media tool to the lockstep assertions)

**Interfaces:**
- Produces `MediaLibrary` (`final`, static, pure): `DEFAULT_PER_PAGE = 20`, `MAX_PER_PAGE = 50`, `SCAN_LIMIT = 200`; `perPage(mixed $v): int` (clamp 1..50, default 20); `page(mixed $v): int` (≥1); `normalizeOrientation(mixed $v): ?string` (`landscape|portrait|square` else null); `orientation(int $w, int $h): string` (`square` when |w−h|/max ≤ 0.05, else landscape/portrait; 0 dims → `square`); `matchesOrientation(string $actual, ?string $wanted): bool`; `formatItem(array $a): array` from raw keys `id,title,alt,caption,url,thumbnail_url,width,height,mime,filename` → the public item `['id'=>int,'title'=>string,'alt'=>string,'caption'=>string,'url'=>string,'thumbnail_url'=>string,'width'=>int,'height'=>int,'orientation'=>string,'mime'=>string,'filename'=>string]` (strings trimmed, `tags stripped`); `isImageMime(string $m): bool` (`image/` prefix).
- Produces `MediaService::list(array $args): array` → `['items'=>list,'total'=>int,'pages'=>int,'page'=>int,'per_page'=>int]` (WordPress; capability check is the CALLER's job).
- MCP tool `list_media_images{search?,per_page?,page?,orientation?}`; REST `GET /media` (same args); OpenAPI `listMediaImages`; `UsageTracker::log('list_media', null, 'valid')`.

- [ ] **Step 1: Failing test** — `tests/MediaLibraryTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\MediaLibrary;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/MediaLibrary.php';

class MediaLibraryTest extends TestCase
{
    public function testPerPageIsClampedAndDefaulted(): void
    {
        $this->assertSame(20, MediaLibrary::perPage(null));
        $this->assertSame(20, MediaLibrary::perPage('abc'));
        $this->assertSame(1, MediaLibrary::perPage(0));
        $this->assertSame(1, MediaLibrary::perPage(-5));
        $this->assertSame(50, MediaLibrary::perPage(500));
        $this->assertSame(7, MediaLibrary::perPage('7'));
    }

    public function testPageIsAtLeastOne(): void
    {
        $this->assertSame(1, MediaLibrary::page(null));
        $this->assertSame(1, MediaLibrary::page(0));
        $this->assertSame(3, MediaLibrary::page('3'));
    }

    public function testOrientationRules(): void
    {
        $this->assertSame('landscape', MediaLibrary::orientation(1600, 900));
        $this->assertSame('portrait', MediaLibrary::orientation(900, 1600));
        $this->assertSame('square', MediaLibrary::orientation(800, 800));
        $this->assertSame('square', MediaLibrary::orientation(800, 820));   // within 5%
        $this->assertSame('square', MediaLibrary::orientation(0, 0));
        $this->assertSame('landscape', MediaLibrary::orientation(1000, 900));
    }

    public function testNormalizeAndMatchOrientation(): void
    {
        $this->assertSame('landscape', MediaLibrary::normalizeOrientation('LANDSCAPE'));
        $this->assertNull(MediaLibrary::normalizeOrientation('wide'));
        $this->assertNull(MediaLibrary::normalizeOrientation(null));
        $this->assertTrue(MediaLibrary::matchesOrientation('portrait', null));
        $this->assertTrue(MediaLibrary::matchesOrientation('portrait', 'portrait'));
        $this->assertFalse(MediaLibrary::matchesOrientation('portrait', 'landscape'));
    }

    public function testOnlyImageMimesAreAccepted(): void
    {
        $this->assertTrue(MediaLibrary::isImageMime('image/jpeg'));
        $this->assertTrue(MediaLibrary::isImageMime('image/svg+xml'));
        $this->assertFalse(MediaLibrary::isImageMime('application/pdf'));
        $this->assertFalse(MediaLibrary::isImageMime('video/mp4'));
        $this->assertFalse(MediaLibrary::isImageMime(''));
    }

    public function testFormatItemShapeAndSanitising(): void
    {
        $i = MediaLibrary::formatItem([
            'id' => '12', 'title' => ' Team <b>photo</b> ', 'alt' => "Our team\nat work", 'caption' => '<p>Hi</p>',
            'url' => 'https://s.example/wp-content/uploads/a.jpg', 'thumbnail_url' => 'https://s.example/wp-content/uploads/a-300x200.jpg',
            'width' => '1600', 'height' => '900', 'mime' => 'image/jpeg', 'filename' => 'a.jpg',
        ]);
        $this->assertSame(
            ['id', 'title', 'alt', 'caption', 'url', 'thumbnail_url', 'width', 'height', 'orientation', 'mime', 'filename'],
            array_keys($i)
        );
        $this->assertSame(12, $i['id']);
        $this->assertSame('Team photo', $i['title']);
        $this->assertSame('Our team at work', $i['alt']);
        $this->assertSame('Hi', $i['caption']);
        $this->assertSame('landscape', $i['orientation']);
        $this->assertSame(1600, $i['width']);
    }

    public function testFormatItemToleratesMissingKeys(): void
    {
        $i = MediaLibrary::formatItem(['id' => 3]);
        $this->assertSame(3, $i['id']);
        $this->assertSame('', $i['alt']);
        $this->assertSame(0, $i['width']);
        $this->assertSame('square', $i['orientation']);
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/MediaLibraryTest.php` → class not found.

- [ ] **Step 3: Implement**

`wp-plugin/src/MediaLibrary.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Pure helpers for the read-only Media Library tool. No WordPress calls. */
final class MediaLibrary
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;
    public const SCAN_LIMIT       = 200;

    public static function perPage( mixed $v ): int
    {
        if ( ! is_numeric( $v ) ) {
            return self::DEFAULT_PER_PAGE;
        }

        return max( 1, min( self::MAX_PER_PAGE, (int) $v ) );
    }

    public static function page( mixed $v ): int
    {
        return is_numeric( $v ) ? max( 1, (int) $v ) : 1;
    }

    public static function normalizeOrientation( mixed $v ): ?string
    {
        $o = is_string( $v ) ? strtolower( trim( $v ) ) : '';

        return in_array( $o, [ 'landscape', 'portrait', 'square' ], true ) ? $o : null;
    }

    public static function orientation( int $w, int $h ): string
    {
        $max = max( $w, $h );
        if ( $max <= 0 || abs( $w - $h ) / $max <= 0.05 ) {
            return 'square';
        }

        return $w > $h ? 'landscape' : 'portrait';
    }

    public static function matchesOrientation( string $actual, ?string $wanted ): bool
    {
        return null === $wanted || $actual === $wanted;
    }

    public static function isImageMime( string $mime ): bool
    {
        return str_starts_with( $mime, 'image/' );
    }

    /**
     * @param array<string,mixed> $a raw attachment data
     * @return array<string,mixed>
     */
    public static function formatItem( array $a ): array
    {
        $clean = static fn ( mixed $v ): string => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) );
        $w     = (int) ( $a['width'] ?? 0 );
        $h     = (int) ( $a['height'] ?? 0 );

        return [
            'id'            => (int) ( $a['id'] ?? 0 ),
            'title'         => $clean( $a['title'] ?? '' ),
            'alt'           => $clean( $a['alt'] ?? '' ),
            'caption'       => $clean( $a['caption'] ?? '' ),
            'url'           => (string) ( $a['url'] ?? '' ),
            'thumbnail_url' => (string) ( $a['thumbnail_url'] ?? '' ),
            'width'         => $w,
            'height'        => $h,
            'orientation'   => self::orientation( $w, $h ),
            'mime'          => (string) ( $a['mime'] ?? '' ),
            'filename'      => (string) ( $a['filename'] ?? '' ),
        ];
    }
}
```

`wp-plugin/src/MediaService.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only query of the site's Media Library images. Never uploads, sideloads or
 * modifies media. The CALLER must have checked current_user_can('upload_files').
 */
final class MediaService
{
    /** @param array<string,mixed> $args search, per_page, page, orientation */
    public static function list( array $args ): array
    {
        $perPage     = MediaLibrary::perPage( $args['per_page'] ?? null );
        $page        = MediaLibrary::page( $args['page'] ?? null );
        $orientation = MediaLibrary::normalizeOrientation( $args['orientation'] ?? null );
        $search      = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';

        $ids = self::ids( $search );
        $out = [];
        foreach ( $ids as $id ) {
            if ( ! current_user_can( 'read_post', $id ) ) {
                continue;
            }
            $item = self::item( (int) $id );
            if ( null === $item || ! MediaLibrary::isImageMime( $item['mime'] ) || ! MediaLibrary::matchesOrientation( $item['orientation'], $orientation ) ) {
                continue;
            }
            $out[] = $item;
        }

        $total = count( $out );

        return [
            'items'    => array_slice( $out, ( $page - 1 ) * $perPage, $perPage ),
            'total'    => $total,
            'pages'    => (int) max( 1, (int) ceil( $total / $perPage ) ),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** Newest-first attachment ids (images only), bounded by MediaLibrary::SCAN_LIMIT; search matches title/caption/description/filename and alt text. @return list<int> */
    private static function ids( string $search ): array
    {
        $base = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => MediaLibrary::SCAN_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if ( '' === $search ) {
            return array_map( 'intval', get_posts( $base ) );
        }

        $byText = get_posts( $base + [ 's' => $search ] );
        $byAlt  = get_posts( $base + [
            'meta_query' => [ [ 'key' => '_wp_attachment_image_alt', 'value' => $search, 'compare' => 'LIKE' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] );
        $merged = array_values( array_unique( array_merge( array_map( 'intval', $byText ), array_map( 'intval', $byAlt ) ) ) );
        // Keep newest-first across both result sets.
        rsort( $merged );

        return array_slice( $merged, 0, MediaLibrary::SCAN_LIMIT );
    }

    /** @return array<string,mixed>|null */
    private static function item( int $id ): ?array
    {
        $post = get_post( $id );
        $url  = wp_get_attachment_url( $id );
        if ( ! $post || ! $url ) {
            return null;
        }
        $meta = wp_get_attachment_metadata( $id );
        $file = get_attached_file( $id );

        return MediaLibrary::formatItem( [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'       => (string) $post->post_excerpt,
            'url'           => (string) $url,
            'thumbnail_url' => (string) ( wp_get_attachment_image_url( $id, 'medium' ) ?: $url ),
            'width'         => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
            'height'        => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
            'mime'          => (string) get_post_mime_type( $id ),
            'filename'      => $file ? basename( (string) $file ) : '',
        ] );
    }
}
```

Add both files to `autoload.php` (before McpHandler/RestController).

MCP: add the tool definition (before `create_page`, spacing `'name'        => 'list_media_images',` exactly like the others):

```php
            [
                'name'        => 'list_media_images',
                'description' => 'List images already in this site\'s Media Library (read-only). Call this BEFORE building a page and use a suitable image\'s "url" in Divi image modules (set the module alt text from the image alt). Filter with "search" (title/alt/filename) and "orientation" (landscape for heroes/cards, square for people). Use the built-in image pack (get_image_guide) only when the library has nothing suitable.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'      => ['type' => 'string',  'description' => 'Optional keywords matched against title, alt text and filename'],
                        'orientation' => ['type' => 'string',  'enum' => ['landscape', 'portrait', 'square'], 'description' => 'Optional orientation filter'],
                        'per_page'    => ['type' => 'integer', 'description' => 'Results per page (default 20, max 50)'],
                        'page'        => ['type' => 'integer', 'description' => 'Page number (default 1)'],
                    ],
                ],
            ],
```

dispatch arm `'list_media_images' => $this->toolListMedia($id, $arguments),` and:

```php
    private function toolListMedia(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('upload_files')) {
            UsageTracker::log('list_media', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the Media Library.');
        }
        $result = MediaService::list($args);
        UsageTracker::log('list_media', null, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($result)]],
        ]);
    }
```

REST: route + handler:

```php
        // GET /media — read-only list of Media Library images
        register_rest_route(self::NS, '/media', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_media'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
```

```php
    public function list_media(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can('upload_files')) {
            return new WP_Error('forbidden', 'You do not have permission to read the Media Library.', ['status' => 403]);
        }
        $result = MediaService::list([
            'search'      => $request->get_param('search'),
            'per_page'    => $request->get_param('per_page'),
            'page'        => $request->get_param('page'),
            'orientation' => $request->get_param('orientation'),
        ]);
        UsageTracker::log('list_media', null, 'valid');

        return new WP_REST_Response($result, 200);
    }
```

OpenAPI path `/media` (GET, operationId `listMediaImages`, description ≤ 300 chars: "Lists images already in the Media Library (read-only) so pages can reuse the owner's own images. Filter by search text and orientation. Returns id, title, alt, url, thumbnail, size and orientation for each image."), `parameters` for `search` (string), `orientation` (string enum), `per_page`, `page` (integers) as query params, and a 200 response object schema with `properties` (`items` array of an inline/`$ref` `MediaItem` component schema with the item fields, `total`, `pages`, `page`, `per_page`).

Lockstep test: extend `tests/HistoryLockstepTest.php` (or a new `MediaLockstepTest.php`) to assert `list_media_images` exists in the MCP tools list and dispatch, `'/media'` route in `RestController`, and operationId `listMediaImages` in the spec; raise any tool-count expectation to 15.

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/MediaLibraryTest.php tests/HistoryLockstepTest.php tests/OpenApiSpecTest.php` OK; `make test` exit 0; `php -l` edited files.
  Live (scratch media, removed afterwards): upload three images with WP-CLI (`wp media import` of two PNGs/JPGs generated on the fly plus the plugin's own SVG may be disallowed by WP — use PNG/JPG: create them with PHP GD in the container or copy two files from the image pack converted via Playwright; set alt text on one via `wp post meta update <id> _wp_attachment_image_alt "Team at work"`), then: MCP `tools/list` shows 15 tools incl. `list_media_images`; `tools/call list_media_images {}` returns the 3 images with correct `orientation`; `{"search":"team"}` finds the alt-matched one; `{"orientation":"square"}` filters; `{"per_page":500}` returns `per_page:50`; REST `GET /media?search=team` matches; as a temporary SUBSCRIBER (no `upload_files`) REST returns 403 and nothing else changes; a non-image attachment (upload a tiny `.txt` is rejected by WP — use a PDF if allowed, else skip) never appears. Delete the scratch attachments and the subscriber afterwards (counts 0).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(media): read-only list_media_images tool (MCP, REST, OpenAPI) so pages reuse Media Library images

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: The single dismissible Pro-add-on card

**Files:**
- Modify: `wp-plugin/src/AdminPage.php`, `wp-plugin/uninstall.php`
- Create: `tests/ProCardTest.php` (source/behaviour guard)

**Interfaces:**
- Produces: admin-post action `ai_editor_divi5_dismiss_pro_card` (nonce action `ai_editor_divi5_dismiss_pro_card`), user meta `aied_pro_card_dismissed`, private constant `AdminPage::PRO_URL = 'https://divi5lab.com/plugins/divi-5-ai-editor'`, private method `proCard()` rendered ONLY by `viewDashboard()`.
- Card copy (fixed, translatable, exact English text):
  - Heading: `Want live stock photos?`
  - Body: `JHMG AI Editor for Divi 5 includes a built-in image pack and reads your Media Library. The separate Pro add-on adds live photo sourcing for each section, plus site tools like front page and menu setup.`
  - Link text: `Learn about the Pro add-on` (to `PRO_URL`, `target="_blank" rel="noopener"`), button `Dismiss`.

- [ ] **Step 1: Failing guard** — `tests/ProCardTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class ProCardTest extends TestCase
{
    private function admin(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/AdminPage.php');
    }

    public function testCardIsRenderedOnlyFromTheDashboardView(): void
    {
        $src = $this->admin();
        $this->assertSame(1, substr_count($src, '$this->proCard()'), 'proCard() must be called exactly once');
        $start = strpos($src, 'private function viewDashboard()');
        $this->assertNotFalse($start);
        $end   = strpos($src, 'private function ', (int) $start + 10);
        $body  = substr($src, (int) $start, ($end === false ? strlen($src) : $end) - (int) $start);
        $this->assertStringContainsString('$this->proCard()', $body, 'the card belongs to the Dashboard tab only');
    }

    public function testDismissIsPerUserNonceProtectedAndPermanent(): void
    {
        $src = $this->admin();
        $this->assertStringContainsString("admin_post_ai_editor_divi5_dismiss_pro_card", $src);
        $this->assertStringContainsString("guard('ai_editor_divi5_dismiss_pro_card')", $src);
        $this->assertStringContainsString("update_user_meta", $src);
        $this->assertStringContainsString("aied_pro_card_dismissed", $src);
        $this->assertStringContainsString("wp_nonce_field( 'ai_editor_divi5_dismiss_pro_card' )", $src);
    }

    public function testCopyDoesNotImplyTheFreePluginIsLimited(): void
    {
        $src = $this->admin();
        $this->assertStringContainsString('Want live stock photos?', $src);
        $this->assertStringContainsString('includes a built-in image pack and reads your Media Library', $src);
        foreach (['unlock', 'locked', 'trial', 'limited', 'upgrade now', 'only in pro', 'included with pro', 'free version'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $src, "AdminPage must not contain “{$bad}”");
        }
    }

    public function testNoOtherScreenOrToolMentionsThePro(): void
    {
        $root = dirname(__DIR__) . '/wp-plugin';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (!$f->isFile() || str_contains($p, '/validator/') || str_ends_with($p, 'readme.txt') || str_ends_with($p, 'AdminPage.php')) {
                continue;
            }
            if (preg_match('/divi5lab\.com|\bPro add-on\b|\bPro version\b/i', (string) file_get_contents($p), $m)) {
                // the plugin header may carry the Plugin URI
                if (!str_ends_with($p, 'jhmg-ai-editor-for-divi-5.php')) {
                    $hits[] = substr($p, strlen($root) + 1) . " ({$m[0]})";
                }
            }
        }
        $this->assertSame([], $hits, 'the Pro add-on may be mentioned only in AdminPage.php (the one card), the readme and the header');
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — card/handler absent.

- [ ] **Step 3: Implement** in `AdminPage.php`:
  - `private const PRO_URL = 'https://divi5lab.com/plugins/divi-5-ai-editor';`
  - `register()`: `add_action('admin_post_ai_editor_divi5_dismiss_pro_card', [$this, 'handleDismissProCard']);`
  - handler:

```php
    public function handleDismissProCard(): void
    {
        $this->guard('ai_editor_divi5_dismiss_pro_card'); // guard() verifies the nonce via check_admin_referer().
        update_user_meta( get_current_user_id(), 'aied_pro_card_dismissed', 1 );
        $this->redirect('dashboard');
    }
```

  - `proCard()` (private), rendered by `viewDashboard()` between the connection/progress grid and "Your results":

```php
    private function proCard(): void
    {
        if ( get_user_meta( get_current_user_id(), 'aied_pro_card_dismissed', true ) ) {
            return;
        }
        ?>
        <div class="aied-card aied-procard">
            <div class="aied-card__head">
                <h3><?php esc_html_e( 'Want live stock photos?', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            </div>
            <p class="aied-muted"><?php esc_html_e( 'JHMG AI Editor for Divi 5 includes a built-in image pack and reads your Media Library. The separate Pro add-on adds live photo sourcing for each section, plus site tools like front page and menu setup.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <p>
                <a class="button" href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn about the Pro add-on', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="ai_editor_divi5_dismiss_pro_card">
                    <?php wp_nonce_field( 'ai_editor_divi5_dismiss_pro_card' ); ?>
                    <button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                </form>
            </p>
        </div>
        <?php
    }
```

  (Use valid HTML: put the form next to, not inside, the `<p>`; adjust markup so the `<form>` is not nested in a `<p>`.)
  - `uninstall.php`: add `delete_metadata( 'user', 0, 'aied_pro_card_dismissed', '', true );`
  - Remove the `style=` inline attribute if Plugin Check objects; use an existing `aied-*` class or add one to `assets/admin.css`.

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/ProCardTest.php` OK; `make test` exit 0. Live (Playwright, admin at http://localhost:8181; read the admin password as earlier tasks did, never write it into files): Dashboard shows the card once; open Settings/Features tabs → no card and no Pro text; click Dismiss → card gone; reload → still gone; a SECOND user (create a temp admin) still sees the card (per-user); a POST without nonce → 403 and the card is not dismissed. Screenshot the Dashboard (card visible) and LOOK at it: it must read calm and fit the existing card style. Delete temp users afterwards.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(admin): single dismissible Pro add-on card on the Dashboard

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Compliance guards, readme, graphics, docs, release 4.0.0

**Files:**
- Create: `tests/WpOrgComplianceTest.php`; remove `tests/WpOrgNoProTest.php` (folded in)
- Modify: `wp-plugin/readme.txt` (full rewrite), `wporg-assets/*` (regenerate), `scripts/` (art script, dev-only), `CLAUDE.md`, memory notes
- Create: `docs/wporg-review-reply-draft.md`
- Rebuild: `jhmg-ai-editor-for-divi-5.zip` (delete the old `ai-editor-for-divi-5.zip` from the repo root with `git rm`)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Compliance guard** — `tests/WpOrgComplianceTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/** Locks in the WordPress.org review findings of 2026-10-01 so they cannot regress. */
class WpOrgComplianceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    private const FORBIDDEN_CODE = [
        'Licensing', 'LicenseClient', 'isPremium', 'MenuBuilder', 'CustomCss', 'PhpProposals',
        'set_custom_css', 'propose_php_snippet', 'set_front_page', 'set_primary_menu',
        'pre_set_site_transient', 'update_plugins', 'upgrade_url', 'PREMIUM',
    ];

    private const REMOTE_FETCH = '/\b(wp_remote_(?:get|post|request|head)|curl_init|curl_exec)\s*\(|file_get_contents\(\s*[\'"]https?:|fopen\(\s*[\'"]https?:/i';

    private const IMAGE_HOSTS = ['picsum.photos', 'pravatar', 'randomuser', 'placehold.co', 'placehold.it', 'loremflickr', 'unsplash', 'pexels', 'pixabay', 'lorempixel', 'dummyimage'];

    /**
     * Hosts that may appear as plain text/links in plugin files (never fetched by the plugin).
     * Each entry is justified; add nothing without a reason.
     */
    private const ALLOWED_HOSTS = [
        'example.com'             => 'documentation/example URLs',
        'www.gnu.org'             => 'GPL licence URI in the plugin header',
        'divi5lab.com'            => 'link to the separate Pro add-on page / author URI (a link, not a service call)',
        'wordpress.org'           => 'documentation links',
        'developer.wordpress.org' => 'documentation links',
        'json-schema.org'         => 'schema reference strings',
        'localhost'               => 'example MCP/REST URLs in connection instructions',
        'modelcontextprotocol.io' => 'documentation link in connection instructions',
    ];

    /** @return \Generator<string,string> relative path => contents */
    private function files(): \Generator
    {
        $root = realpath(self::ROOT);
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php', 'js', 'json', 'txt', 'css', 'svg'], true)) {
                yield substr($f->getPathname(), strlen($root) + 1) => (string) file_get_contents($f->getPathname());
            }
        }
    }

    public function testNoLicenceProOrCustomCodeFeatureRemains(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_ends_with($rel, 'readme.txt')) {
                continue;
            }
            foreach (self::FORBIDDEN_CODE as $needle) {
                if (stripos($src, $needle) !== false) {
                    $hits[] = "$rel mentions $needle";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testThePluginNeverFetchesRemoteFiles(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_starts_with($rel, 'validator/') || str_ends_with($rel, 'readme.txt')) {
                continue;
            }
            if (preg_match(self::REMOTE_FETCH, $src, $m)) {
                $hits[] = "$rel: {$m[0]}";
            }
        }
        $this->assertSame([], $hits);
    }

    public function testNoThirdPartyImageHostAnywhere(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            foreach (self::IMAGE_HOSTS as $h) {
                if (stripos($src, $h) !== false) {
                    $hits[] = "$rel mentions $h";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testEveryUrlHostIsAllowlistedAndJustified(): void
    {
        $unknown = [];
        foreach ($this->files() as $rel => $src) {
            if (preg_match_all('#https?://([a-z0-9.-]+)#i', $src, $m)) {
                foreach (array_unique($m[1]) as $host) {
                    $host = strtolower($host);
                    $ok   = false;
                    foreach (array_keys(self::ALLOWED_HOSTS) as $allowed) {
                        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                            $ok = true;
                        }
                    }
                    if (!$ok) {
                        $unknown[] = "$rel → $host";
                    }
                }
            }
        }
        $this->assertSame([], $unknown, 'every host in the plugin must be allowlisted with a reason');
    }

    public function testGeneratedGuidesRecipesAndSpecAreCleanToo(): void
    {
        require_once self::ROOT . '/src/OpenApiSpec.php';
        $texts = [
            \AiEditorDivi5\WP\OpenApiSpec::spec('https://s.example/wp-json/ai-editor-divi5/v1', '4.0.0'),
        ];
        $blob = json_encode($texts) . \AiEditorDivi5\WP\StyleGuide::markdown() . \AiEditorDivi5\WP\SiteGuide::markdown()
              . \AiEditorDivi5\WP\LandingGuide::markdown() . \AiEditorDivi5\WP\ImageGuide::markdown();
        foreach (\AiEditorDivi5\WP\SectionRecipes::names() as $n) {
            $blob .= \AiEditorDivi5\WP\SectionRecipes::recipe($n);
        }
        $this->assertStringNotContainsString('{{aied:', $blob, 'no unresolved image token may reach the AI');
        foreach (['set_front_page', 'set_primary_menu', 'set_custom_css', 'propose_php_snippet', 'upgrade', 'premium'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $blob, "generated output mentions {$bad}");
        }
        foreach (self::IMAGE_HOSTS as $h) {
            $this->assertStringNotContainsString($h, $blob);
        }
    }

    public function testReadmeExplainsHowItWorksInPlainWords(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        $this->assertStringContainsString('= How it works (in plain words) =', $r);
        $this->assertStringContainsString('= What makes it smart =', $r);
        $this->assertStringContainsString('= Examples to try =', $r);
        $this->assertMatchesRegularExpression('/^1\.\s.+\n2\.\s.+\n3\.\s.+\n4\.\s/m', $r, 'four numbered steps');
        $this->assertMatchesRegularExpression('/same page in, same (verdict|result) out|same input,? same (verdict|result)/i', $r, 'states the deterministic-checker idea');
        $this->assertMatchesRegularExpression('/more than \d+ (Divi 5 )?(module|block)/i', $r, 'quotes a real, computed number of known module types');
        foreach (['#1', 'best plugin', 'world\'s first', 'guaranteed', '5 stars', '★'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not use hype/unverifiable claim: {$bad}");
        }
    }

    public function testReadmeStatesNoExternalServicesAndClaimsNoLockedFeatures(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        $this->assertStringContainsString('does not connect to any external service', $r);
        foreach (['license key', 'licence key', 'activate your license', '$30', 'per year', 'Pro features already activated'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not describe licensing ({$bad})");
        }
        $this->assertStringContainsString('not affiliated with Elegant Themes', $r);
    }
}
```

Run it → FAIL until the readme is rewritten (and surfaces anything still left); fix every hit properly (do not widen the allowlist to silence it unless the host is truly a non-fetched documentation link; record the reason).

- [ ] **Step 2: Readme rewrite** (`wp-plugin/readme.txt`) — keep valid WordPress.org syntax; content requirements:
  - Header block: `=== JHMG AI Editor for Divi 5 ===`, `Contributors: lucaslopvet`, Tags (`divi, divi 5, ai, mcp, editor`), `Requires at least: 6.0`, `Tested up to: 7.1`, `Stable tag: 4.0.0`, `Requires PHP: 8.1`, GPL lines, short description.
  - **Description — written for a non-technical site owner, simple words, confident and truthful (this is the plugin's shop window; the owner asked for it explicitly).** Required structure inside `== Description ==`:
    1. A one-sentence promise (what it does for you).
    2. `= How it works (in plain words) =` — four short numbered steps: (1) connect your own AI assistant (Claude, ChatGPT, Cursor, Copilot) to your site with one key — copy/paste, about two minutes; (2) tell it what you want in plain English ("change the phone number on the Contact page", "build me a landing page for my dental clinic"); (3) the plugin's built-in guides and proven section patterns steer the AI so it builds real, good-looking Divi 5 pages instead of guessing, using your own Media Library images when it can; (4) before anything is saved, a built-in checker verifies the page against fixed rules — if something is wrong it is refused with an exact reason and the AI fixes it; if it is fine it is saved, and the previous version is kept so you can undo.
    3. `= What makes it smart =` — short bullets, each true and verifiable against the code: the checker is **not AI** (fixed rules: same page in, same verdict out — "AI can be creative, but it can never save a broken page"); it knows every Divi 5 module type and nesting rule (quote the REAL number: compute it from `SchemaRules` — known block types — and write "more than N", never invent a number); rules come from real Divi 5 exports, not guesses; tiny edits are surgical (change one phone number without rebuilding the page); proven section recipes + conversion-focused landing-page guidance; image intelligence (your Media Library first, built-in image pack otherwise); one-click undo; works with the AI assistant you already use; nothing leaves your site (no third-party services).
    4. `= Examples to try =` — 4-5 plain-English prompts.
    No fabricated statistics, ratings, testimonials or comparisons to named competitors; no "best/#1" claims; no promise the AI never makes mistakes (the promise is that a broken page cannot be saved).
  - The rest of the Description: the full free tool list (15 tools incl. create_page draft, undo history, `list_media_images`), the built-in image pack + Media Library, a one-sentence factual mention "A separate Pro add-on (sold separately at https://divi5lab.com/plugins/divi-5-ai-editor) adds live stock-photo sourcing and site-level tools." — no price, no "locked/unlock/licence" language. A line "Works with Divi 5. Not affiliated with, endorsed by, or sponsored by Elegant Themes."
  - `== External services ==`: "This plugin does not connect to any external service. Your AI assistant connects to your own site using the API key you generate; no data passes through the plugin author's servers." (Exactly contains the phrase `does not connect to any external service`.)
  - FAQ must include: "Does the AI get administrator access to my site?" → No: the key authenticates as the WordPress user who owns it and every action re-checks that user's capabilities; the tools only list/read/validate/edit/create-draft/undo page content and read Media Library images (read-only); they cannot install plugins, change users, settings, menus or the front page, or run code. "Where do images come from?" → Media Library first, then the built-in pack; nothing is downloaded from third parties. "Is my data sent anywhere?" → No.
  - Privacy section: API key + optional usage log + page history in the site database; nothing sent elsewhere; removed on uninstall.
  - Installation, Requirements, Screenshots (3, matching the regenerated art), Changelog `= 4.0.0 =` (rename; free: create_page, undo, Media Library tool, built-in image pack; removed Pro tools/licensing from this plugin; no remote assets), keep earlier entries condensed, Upgrade Notice 4.0.0.

- [ ] **Step 3: Graphics** — regenerate `wporg-assets/` with the new name via a dev-only script `scripts/build-wporg-art.cjs` (Playwright from the layoutlab path; same visual language as the 3.x art: indigo gradient, "A✓" mark): icon 128/256, banner 772×250/1544×500 with the text `JHMG AI Editor for Divi 5` and subline `Edit Divi 5 pages in plain English. Every change is validated before it saves.`; no Divi logo; screenshots 1-3 re-taken from the running dev site (Dashboard with the Pro card dismissed — i.e. the normal state after first use —, Settings → Connect with the API key replaced by `YOUR_API_KEY` and the URL by `https://your-site.com`, Features). LOOK at every image. Raw captures with real keys must never be written to the repo (mask in the DOM before screenshotting, as in 3.3.0).

- [ ] **Step 4: Docs and reply draft**
  - `CLAUDE.md`: rewrite "What this project is" / "Architecture" / "Current state": the product is now a free WordPress.org plugin + a separate Pro add-on (staged in `pro-addon/`, own spec/plan pending); the freemium-in-one-build model is rejected by WordPress.org Guideline 5; hooks reference (`docs/EXTENDING.md`); the image pack + tokens + Media Library tool; rename facts; the rule "nothing licence-gated, remote-fetching or custom-code-saving may enter `wp-plugin/`" with a pointer to `tests/WpOrgComplianceTest.php`.
  - `docs/wporg-review-reply-draft.md`: the short reply the owner will send in the review thread (do NOT send anything): (1) explicit slug request `jhmg-ai-editor-for-divi-5` with the display name; (2) one short line per resolved class — no locked/licensed features or licence code in the plugin (paid features are a separate add-on hosted by us), custom CSS/PHP tools removed, remote-admin tools (front page/menu) removed, no remote files (built-in image pack + Media Library), no external services, contributors set to `lucaslopvet`; (3) one clarification: the plugin only exposes page-content operations to the owner's own AI client through the owner's key, each action re-checking capabilities; (4) a closing line saying the build was tested with Plugin Check. Keep it under 150 words.
  - Update project memory notes (`wporg-submission.md`): 4.0.0 compliance release built; what the owner must do (reply with slug request, upload the zip).

- [ ] **Step 5: Release verification (report every output)**
  - `make test` exit 0.
  - `git rm ai-editor-for-divi-5.zip`; `bash scripts/build-plugin-zip.sh` → `jhmg-ai-editor-for-divi-5.zip` containing only `wp-plugin/` content under a top-level `jhmg-ai-editor-for-divi-5/` folder; no `tools/ scripts/ build/ docs/ fixtures/ pro-addon/`; includes `assets/images/` (size ≤ ~1.6 MB total zip growth), `src/MediaService.php`, `src/ImagePack.php`; main file Version 4.0.0 and no `Tested up to`; `unzip -p` the readme shows the new text; zip identical to `wp-plugin/` (`diff -rq`).
  - Plugin Check under the NEW slug: the dev site already mounts `wp-plugin` as `jhmg-ai-editor-for-divi-5`, so run `docker compose exec -T wpcli wp plugin check jhmg-ai-editor-for-divi-5 --format=table` directly AND on the extracted zip copy (swap procedure into a temp folder name is no longer needed — extract to a scratch dir and `docker cp` it as `jhmg-ai-editor-for-divi-5-zipcheck`, activate only for the check, remove with `rm -rf` as root; never `wp plugin delete`) → `Success: Checks complete. No errors found.`
  - Live end-to-end smoke (scratch objects deleted afterwards): over MCP list tools (15), `get_image_guide` (catalogue with working URLs), `list_media_images` with a scratch image, `create_page` draft using a recipe, edit via `edit_page_content`, history list + restore, dashboard screenshot (Pro card once, dismiss works), front-end screenshot of the created draft showing images.
  - `git status` clean; `git diff <base> --stat -- src wp-plugin/validator` empty (validator untouched).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "release: JHMG AI Editor for Divi 5 4.0.0 — WordPress.org compliance

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
