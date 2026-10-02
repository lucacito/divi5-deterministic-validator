# Free-Plugin Re-cut (Phase 0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the never-uploaded 4.3.0 header/footer (Theme Builder layout) editing from the WordPress.org plugin `wp-plugin/`, park its source in `pro-addon/` for the Pro add-on, and ship the result as free 4.4.0 with 16 tools.

**Architecture:** `EditTarget` stays as the single "which post may the plugin touch" rule but becomes page-only again (its 9 call sites are untouched). `ThemeLayouts` and everything that only existed for layouts is deleted from `wp-plugin/`. A new guard test fails if any header/footer/Theme-Builder-editing code returns to the free plugin. The deleted source and tests are first copied into `pro-addon/staged-4.3.0/` (not run, not shipped) so the add-on plan can start from them.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (`./vendor/bin/phpunit`), `tests/` shims, Python 3 for the scripted multi-line edits, Docker dev site (Divi 5.14) for the real-site checks, Plugin Check 2.x.

**Spec:** `docs/superpowers/specs/2026-10-02-pro-addon-design.md` ("Phase 0 — free-plugin re-cut"). The spec for the thing being removed is `docs/superpowers/specs/2026-10-01-theme-layouts-design.md`.

## Global Constraints

- Local commits only. NEVER push, upload or send anything. Work on a new branch `feat/free-recut` cut from `main` (`git switch -c feat/free-recut`). Ask the owner before merging to main.
- Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- The validator (`src/` and `wp-plugin/validator/`) is NOT touched. No Divi schema from memory.
- `tests/WpOrgComplianceTest.php` is never weakened. The only edit allowed is the pinned tool list and count going from 17 to 16 (a tool was removed), with the justification in the test comment (Task 4).
- Nothing licence-gated, remote-fetching or custom-code-saving enters `wp-plugin/`; the free plugin gets no quota or usage limit.
- The plugin version stays **4.4.0** (header, `AI_EDITOR_DIVI5_VERSION`, readme Stable tag): 4.3.0 was never published, so its number is skipped and its changelog / upgrade-notice entries are deleted.
- Never run `wp plugin delete` (it runs uninstall.php against the shared dev DB); remove a test copy with `rm -rf`. Container wp-cli: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp ...`.
- Tool count after this plan: **16** MCP tools and **16** OpenAPI operations.
- The additive `post_type` field on the page envelope (added in 4.3.0) STAYS; only its OpenAPI description changes to say it is always `page`.

## Review Focus

Failure modes the spec implies but a straight "delete the code" plan would not exercise; each has a test in the owning task:

1. A client that still sends `tools/call list_theme_layouts` must get the normal unknown-tool error (`-32602 Unknown tool: list_theme_layouts`), and `tools/list` must not advertise it. (Task 4, `RemovedThemeLayoutsToolTest`)
2. A header/footer/body layout id (or any non-page post id) passed to the page tools must be "not found", never "forbidden", and must leak nothing about the post. (Task 3 `EditTargetTest`, Task 4 `RemovedThemeLayoutsToolTest`)
3. The Dashboard Restore button must refuse non-page ids, so a history row left by a dev build for a layout cannot be restored from the UI. (Task 3, `AdminRestoreGateTest`)
4. `HistoryService::recent()` rows no longer carry `kind`, and the Dashboard must still render a row with an empty title as "(no title)". (Task 3, `HistoryServiceTest` keeps asserting row shape)
5. No source, readme, data or JSON file under `wp-plugin/` (excluding the bundled validator, assets and languages) may mention the removed feature, case-insensitively, so a later edit cannot quietly bring it back. (Task 2, `NoThemeLayoutsInFreeTest`)

---

## File structure

| File | Change |
|---|---|
| `pro-addon/staged-4.3.0/**` | Create: parked copies of the removed source, tests, verify script and the 4.3.0 diff |
| `pro-addon/README.md` | Modify: one table row pointing at `staged-4.3.0/` |
| `tests/NoThemeLayoutsInFreeTest.php` | Create: the guard (red first) |
| `wp-plugin/src/ThemeLayouts.php` | Delete |
| `wp-plugin/src/EditTarget.php` | Rewrite: page-only |
| `wp-plugin/src/autoload.php`, `DiviCompat.php`, `HistoryService.php`, `AdminPage.php` | Remove layout-only code |
| `wp-plugin/src/McpHandler.php`, `RestController.php`, `OpenApiSpec.php` | Remove `list_theme_layouts` / `/theme-layouts` / schemas; restore descriptions |
| `wp-plugin/src/StyleGuide.php`, `SiteGuide.php`, `wp-plugin/readme.txt` | Remove header/footer text |
| `tests/EditTargetTest.php`, `AdminRestoreGateTest.php` | Rewrite: page-only |
| `tests/RemovedThemeLayoutsToolTest.php` | Create |
| `tests/ThemeLayoutsTest.php`, `ThemeLayoutHandlersTest.php`, `ThemeLayoutsLockstepTest.php`, `scripts/verify-theme-layouts.php` | Delete (parked in Task 1) |
| `tests/HistoryServiceTest.php`, `DryRunHandlersTest.php`, `HistoryMigrationTest.php`, `GlobalStylesLockstepTest.php`, `StyleAdvisoryHandlersTest.php`, `StyleGuideTest.php`, `SiteGuideTest.php`, `DryRunLockstepTest.php`, `WpOrgComplianceTest.php` | Trim / recount |
| `Makefile`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-01-theme-layouts-design.md` | Docs and target |
| `jhmg-ai-editor-for-divi-5.zip`, `wporg-assets/screenshot-3.png` | Rebuild / regenerate |

---

### Task 1: Park the 4.3.0 header/footer source in `pro-addon/staged-4.3.0/`

**Files:**
- Create: `pro-addon/staged-4.3.0/src/ThemeLayouts.php`, `.../src/EditTarget.php`, `.../tests/{ThemeLayoutsTest,EditTargetTest,ThemeLayoutHandlersTest,ThemeLayoutsLockstepTest,AdminRestoreGateTest,DiviThemeBuilderShims}.php`, `.../scripts/verify-theme-layouts.php`, `.../4.3.0-free-plugin.diff`
- Modify: `pro-addon/README.md`

**Interfaces:**
- Consumes: the current tree (4.4.0, clean on `main`).
- Produces: a verbatim copy of every file Task 3 and Task 4 delete or rewrite, so the Pro add-on plan can start from them. Nothing under `wp-plugin/` or `tests/` changes in this task.

- [ ] **Step 1: Create the branch and confirm a clean, green start**

```bash
git switch -c feat/free-recut
git status --short
./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: no status output; `OK (567 tests, 3868 assertions)`.

- [ ] **Step 2: Copy the files**

```bash
D=pro-addon/staged-4.3.0
mkdir -p $D/src $D/tests $D/scripts
cp wp-plugin/src/ThemeLayouts.php wp-plugin/src/EditTarget.php $D/src/
for t in ThemeLayoutsTest EditTargetTest ThemeLayoutHandlersTest ThemeLayoutsLockstepTest AdminRestoreGateTest DiviThemeBuilderShims; do cp tests/$t.php $D/tests/; done
cp scripts/verify-theme-layouts.php $D/scripts/
git diff 837b712^1 837b712 -- wp-plugin > $D/4.3.0-free-plugin.diff
ls -R $D | head -30
wc -l $D/4.3.0-free-plugin.diff
```

Expected: the listing shows the 2 src files, 6 test files, 1 script and the diff (several hundred lines).

- [ ] **Step 3: Document it in `pro-addon/README.md`**

Add this row to the "What is in here" table (after the `PhpProposals` row) and this paragraph after the table:

```markdown
| `staged-4.3.0/` | the 4.3.0 header/footer editing (`ThemeLayouts`, the layout half of `EditTarget`, their tests, `verify-theme-layouts.php`, and `4.3.0-free-plugin.diff`, the exact hunks that were in the free plugin's handlers, OpenAPI and guides). Removed from the free plugin in the free re-cut because it moves to the Pro add-on. Copies of the 4.4.0 tree; not run, not shipped. |
```

```markdown
`staged-4.3.0/tests/` need their own bootstrap (they use `tests/WpRestShims.php` and the other shims of the main suite) before they can run; `phpunit.xml` only scans `tests/`, so they are not run today.
```

- [ ] **Step 4: Run the suite (nothing may have changed) and commit**

```bash
./vendor/bin/phpunit 2>&1 | tail -3
git add pro-addon
git commit -m "chore: park the 4.3.0 header/footer source in pro-addon/staged-4.3.0

Copies only: nothing under wp-plugin/ or tests/ changes. The free plugin drops
header/footer editing next; the Pro add-on will start from these files.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Expected: `OK (567 tests, 3868 assertions)`.

---

### Task 2: The guard test (red first)

**Files:**
- Create: `tests/NoThemeLayoutsInFreeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `NoThemeLayoutsInFreeTest::testFileHasNoThemeLayoutCode(string $rel)`, one case per file under `wp-plugin/`. It turns green file by file as Tasks 3 to 5 land; the whole test is green only at the end.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Header/footer (Theme Builder) editing lives in the separate Pro add-on, never in the WordPress.org
 * plugin. This keeps it from quietly coming back: no file shipped in the free plugin may mention it.
 */
class NoThemeLayoutsInFreeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    /** Matched case-insensitively. */
    private const FORBIDDEN = [
        'ThemeLayouts',
        'list_theme_layouts',
        'listThemeLayouts',
        'theme-layouts',
        'theme_layouts',
        'et_header_layout',
        'et_footer_layout',
        'clearLayoutCaches',
        'Theme Builder',
        'header and footer',
        'header/footer',
    ];

    /** @return array<string,array{string}> */
    public static function files(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $rel = substr( $file->getPathname(), strlen( self::ROOT ) + 1 );
            foreach ( [ 'validator/', 'assets/', 'languages/' ] as $skip ) {
                if ( str_starts_with( $rel, $skip ) ) {
                    continue 2;
                }
            }
            if ( ! in_array( $file->getExtension(), [ 'php', 'txt', 'json' ], true ) ) {
                continue;
            }
            $out[ $rel ] = [ $rel ];
        }
        ksort( $out );

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testFileHasNoThemeLayoutCode( string $rel ): void
    {
        $text = (string) file_get_contents( self::ROOT . '/' . $rel );
        foreach ( self::FORBIDDEN as $needle ) {
            $this->assertFalse( stripos( $text, $needle ) !== false, "{$rel} still mentions '{$needle}' (header/footer editing belongs to the Pro add-on)" );
        }
    }

    public function testTheGuardSeesTheSourceTree(): void
    {
        $this->assertContains( 'src/McpHandler.php', array_keys( self::files() ), 'the file scan must include the plugin source' );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails on exactly the 12 known files**

Run: `./vendor/bin/phpunit tests/NoThemeLayoutsInFreeTest.php 2>&1 | grep -E "^[0-9]+\) |still mentions" | sed -E 's/^[0-9]+\) //' | cut -c1-110`

Expected: failures for these files and no others: `readme.txt`, `src/AdminPage.php`, `src/DiviCompat.php`, `src/EditTarget.php`, `src/HistoryService.php`, `src/McpHandler.php`, `src/OpenApiSpec.php`, `src/RestController.php`, `src/SiteGuide.php`, `src/StyleGuide.php`, `src/ThemeLayouts.php`, `src/autoload.php`. `testTheGuardSeesTheSourceTree` passes. If another file fails, stop and look at what it says before going on: it is either a real extra reference to remove or a token that is too broad (then narrow the token and say why in the commit).

- [ ] **Step 3: Commit (red on purpose, on the feature branch only)**

```bash
git add tests/NoThemeLayoutsInFreeTest.php
git commit -m "test: guard that the free plugin has no header/footer (Theme Builder) editing code

Red until the removal tasks land; each file turns green as it is cleaned.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Remove the core classes, history and Dashboard parts

**Files:**
- Delete: `wp-plugin/src/ThemeLayouts.php`, `tests/ThemeLayoutsTest.php`
- Rewrite: `wp-plugin/src/EditTarget.php`, `tests/EditTargetTest.php`, `tests/AdminRestoreGateTest.php`
- Modify: `wp-plugin/src/autoload.php`, `wp-plugin/src/DiviCompat.php`, `wp-plugin/src/HistoryService.php`, `wp-plugin/src/AdminPage.php`, `tests/HistoryServiceTest.php`, `tests/DryRunHandlersTest.php`, `tests/HistoryMigrationTest.php`, `tests/GlobalStylesLockstepTest.php`, `tests/StyleAdvisoryHandlersTest.php`
- Test: `tests/EditTargetTest.php`, `tests/AdminRestoreGateTest.php`, `tests/NoThemeLayoutsInFreeTest.php`

**Interfaces:**
- Consumes: Task 2's guard.
- Produces: `EditTarget::resolve( int $id ): array{post: ?\WP_Post, kind: ?string, error: ?string}` with `kind` only `'page'` or `null`, and the constants `EditTarget::NOT_FOUND` / `EditTarget::FORBIDDEN` (unchanged names; the 9 call sites in `McpHandler`/`RestController` keep working). `AdminPage::mayRestore( int $id ): bool` (page-only through `EditTarget`). `HistoryService::recent()` rows are `{page_id, title, version_id, saved_at, tool}` (no `kind`).

- [ ] **Step 1: Write the new page-only tests (they fail against the old EditTarget)**

Overwrite `tests/EditTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\EditTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';

/** The one rule for "which post may the plugin read and edit": a page the user can edit. Nothing else exists. */
class EditTargetTest extends TestCase
{
    private function post( int $id, string $type, string $status = 'publish' ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = [];
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true ];

        $this->post( 5, 'page' );
        $this->post( 6, 'post' );
        $this->post( 7, 'attachment' );
        $this->post( 201, 'et_header_layout' );
        $this->post( 202, 'et_footer_layout' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 200, 'et_template' );
        $this->post( 100, 'et_theme_builder' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
    }

    public function testAPageTheUserCanEditResolves(): void
    {
        $r = EditTarget::resolve( 5 );
        $this->assertSame( [ 5, 'page', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );
    }

    public function testAPageTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $r = EditTarget::resolve( 5 );
        $this->assertSame( EditTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );
        $this->assertNull( $r['kind'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
            [ 0, 'id zero' ],
            [ -3, 'negative id' ],
            [ 999, 'unknown id' ],
            [ 6, 'a blog post' ],
            [ 7, 'an attachment' ],
            [ 201, 'a header layout id is just another non-page post' ],
            [ 202, 'a footer layout id' ],
            [ 203, 'a body layout id' ],
            [ 200, 'a template' ],
            [ 100, 'the theme builder post' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testEverythingThatIsNotAPageIsNotFoundNeverForbidden( int $id, string $why ): void
    {
        $r = EditTarget::resolve( $id );
        $this->assertSame( EditTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
    }

    public function testANonPageIsNotFoundEvenWhenTheUserCannotEditIt(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'], 'nothing is leaked about other post types' );
    }
}
```

Overwrite `tests/AdminRestoreGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/** The Dashboard "Restore" button uses the same "which post may the plugin touch" rule as the tools: pages only. */
class AdminRestoreGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true ];
        foreach ( [ 5 => 'page', 6 => 'post', 201 => 'et_header_layout', 202 => 'et_footer_layout' ] as $id => $type ) {
            $p              = new \WP_Post();
            $p->ID          = $id;
            $p->post_type   = $type;
            $p->post_status = 'publish';
            $GLOBALS['__wp_posts'][ $id ] = $p;
        }
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']   = true;
        $GLOBALS['__wp_posts'] = [];
    }

    public function testAPageMayBeRestored(): void
    {
        $this->assertTrue( AdminPage::mayRestore( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testNothingElseMayBeRestored( int $id, string $why ): void
    {
        $this->assertFalse( AdminPage::mayRestore( $id ), $why );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return EditTargetTest::neverEditable();
    }

    public function testAPageTheUserCannotEditMayNotBeRestored(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertFalse( AdminPage::mayRestore( 5 ) );
    }
}
```

- [ ] **Step 2: Run them and confirm the layout cases fail against the old code**

Run: `./vendor/bin/phpunit tests/EditTargetTest.php tests/AdminRestoreGateTest.php 2>&1 | tail -15`

Expected: FAIL. `neverEditable` cases for ids 201, 202 ("a header layout id…", "a footer layout id") fail because the old `EditTarget` returns `ok` for live layouts or errors on missing `ThemeLayouts` shims (either failure proves the tests exercise the removal).

- [ ] **Step 3: Rewrite `wp-plugin/src/EditTarget.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may the plugin read and edit": a page the current user can edit.
 * Anything else is reported like a missing page, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'page' or null
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post || 'page' !== $post->post_type ) {
            return self::fail( self::NOT_FOUND );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => 'page', 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
```

- [ ] **Step 4: Delete `ThemeLayouts` and its test, and scripted edits to the other source files**

```bash
git rm -q wp-plugin/src/ThemeLayouts.php tests/ThemeLayoutsTest.php
python3 - <<'PY'
import re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

def cut(s, start, end, keep_end=True):
    i = s.index(start)
    j = s.index(end, i)
    return s[:i] + s[j:]

# autoload: drop the ThemeLayouts require (EditTarget stays)
def autoload(s):
    old = "require_once __DIR__ . '/ThemeLayouts.php';\n"
    assert old in s
    return s.replace(old, '')
edit('wp-plugin/src/autoload.php', autoload)

# DiviCompat: drop clearLayoutCaches
edit('wp-plugin/src/DiviCompat.php', lambda s: cut(s, "    /**\n     * After an edit to a Theme Builder layout", "    private static function majorMinor("))

# HistoryService: no afterWrite, no kind
def history(s):
    call = "        self::afterWrite( $post );\n"
    assert s.count(call) == 2
    s = s.replace(call, '')
    s = cut(s, "    /** A header/footer layout appears on every page", "    /** @return array{stored:bool, version_id:?int, reason:?string} */")
    old = "list<array{page_id:int, title:string, kind:string, version_id:int, saved_at:mixed, tool:mixed}>"
    assert old in s
    s = s.replace(old, "list<array{page_id:int, title:string, version_id:int, saved_at:mixed, tool:mixed}>")
    old = ("            $rows[] = $row + [\n"
           "                'title' => $post ? (string) ( $post->post_title ?? '' ) : '',\n"
           "                'kind'  => $post ? ( ThemeLayouts::kindOf( (string) $post->post_type ) ?? 'page' ) : 'page',\n"
           "            ];\n")
    assert old in s
    return s.replace(old, "            $rows[] = $row + [ 'title' => $post ? (string) ( $post->post_title ?? '' ) : '' ];\n")
edit('wp-plugin/src/HistoryService.php', history)

# AdminPage: no historyRowTitle, no Features card, 16 tools
def admin(s):
    s = s.replace("The Restore button may touch exactly what the tools may: a page, or a live header/footer the user may edit.",
                  "The Restore button may touch exactly what the tools may: a page the user can edit.")
    s = cut(s, "    /**\n     * Dashboard history row title", "    public function handleRestorePage")
    old = "$title = self::historyRowTitle( $row ); ?>"
    assert old in s
    s = s.replace(old, "$title = '' === $row['title'] ? __( '(no title)', 'jhmg-ai-editor-for-divi-5' ) : $row['title']; ?>")
    s, n = re.subn(r"^ *\[ __\( 'Edit your existing header and footer'.*\n", '', s, flags=re.M)
    assert n == 1
    assert 'the 17 tools your AI assistant can call' in s
    return s.replace('the 17 tools your AI assistant can call', 'the 16 tools your AI assistant can call')
edit('wp-plugin/src/AdminPage.php', admin)
PY
php -l wp-plugin/src/HistoryService.php && php -l wp-plugin/src/AdminPage.php && php -l wp-plugin/src/DiviCompat.php && php -l wp-plugin/src/autoload.php
```

Expected: four `No syntax errors detected`. (A failed `assert` or `index` means the file differs from what this plan was written against: stop and inspect it, do not guess.)

- [ ] **Step 5: Trim the shared tests that referenced the removed code**

```bash
python3 - <<'PY'
import re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

def drop_lines(*needles):
    def fn(s):
        for n in needles:
            assert n in s, n
            s = s.replace(n, '')
        return s
    return fn

TL = "require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';\n"
DC = "require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';\n"
SH = "require_once __DIR__ . '/DiviThemeBuilderShims.php';\n"

edit('tests/DryRunHandlersTest.php', drop_lines(TL, DC))
edit('tests/HistoryMigrationTest.php', drop_lines(TL, DC))
edit('tests/GlobalStylesLockstepTest.php', drop_lines(TL, SH))
edit('tests/StyleAdvisoryHandlersTest.php', drop_lines(TL, SH))

def history_test(s):
    for n in (SH, TL, DC):
        assert n in s, n
        s = s.replace(n, '')
    # drop the three layout tests added in 4.3.0
    for name in ('testWritingAHeaderLayoutClearsDivisCachesButWritingAPageDoesNot',
                 'testAFailedLayoutWriteDoesNotClearCaches',
                 'testRecentRowsCarryTheKindOfEachEditedPost'):
        m = re.search(r"\n    public function " + name + r"\(\): void\n    \{.*?\n    \}\n", s, flags=re.S)
        assert m, name
        s = s[:m.start()] + s[m.end():]
    return s
edit('tests/HistoryServiceTest.php', history_test)
PY
git grep -n "DiviThemeBuilderShims" -- tests | grep -v "^tests/DiviThemeBuilderShims.php"
```

Expected from the final `git grep`: only `tests/ThemeLayoutHandlersTest.php` and `tests/ThemeLayoutsLockstepTest.php` (both deleted in Step 6). Task 6 deletes the shim itself when nothing else references it.

- [ ] **Step 6: Delete the two layout handler tests (their subject is gone; both are parked from Task 1)**

```bash
git rm -q tests/ThemeLayoutHandlersTest.php tests/ThemeLayoutsLockstepTest.php
```

- [ ] **Step 7: Run the new tests, then the whole suite**

Run: `./vendor/bin/phpunit tests/EditTargetTest.php tests/AdminRestoreGateTest.php 2>&1 | tail -4`

Expected: `OK`.

Run: `./vendor/bin/phpunit 2>&1 | tail -15`

Expected: every test passes EXCEPT the guard cases for the files Task 4 and Task 5 still own: `src/McpHandler.php`, `src/RestController.php`, `src/OpenApiSpec.php`, `src/SiteGuide.php`, `src/StyleGuide.php`, `readme.txt`. The guard cases for `src/ThemeLayouts.php` (file gone, so no case), `src/EditTarget.php`, `src/DiviCompat.php`, `src/HistoryService.php`, `src/AdminPage.php` and `src/autoload.php` are green. Any other failure: read it. A test that still fails because it requires a deleted file or the shim means a `require_once` was missed above; restore only the require it needs, nothing else.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor: free plugin drops ThemeLayouts; EditTarget is page-only again

Removes ThemeLayouts, the layout half of EditTarget, DiviCompat::clearLayoutCaches,
HistoryService's layout cache clear and the row 'kind', and the Dashboard
header/footer title and Restore rules. Page-only EditTarget and Restore tests
replace the layout ones (parked in pro-addon/staged-4.3.0).

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Remove the tool, route and OpenAPI entries; recount to 16

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `wp-plugin/readme.txt` (tool bullet and count only), `tests/WpOrgComplianceTest.php`, `tests/DryRunLockstepTest.php`
- Create: `tests/RemovedThemeLayoutsToolTest.php`

**Interfaces:**
- Consumes: Task 3's page-only `EditTarget`.
- Produces: MCP `tools/list` without `list_theme_layouts`; REST without `/theme-layouts`; OpenAPI without `listThemeLayouts`, `ThemeLayoutRef`, `ThemeTemplate`; 16 tools / 16 operations; page-tool descriptions back to "page" wording.

- [ ] **Step 1: Write the failing test**

Create `tests/RemovedThemeLayoutsToolTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * Header/footer editing moved to the Pro add-on: a client that still calls the old tool gets the normal
 * unknown-tool error, and a layout id is just another post id the page tools do not know.
 */
class RemovedThemeLayoutsToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']  = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        foreach ( [ 5 => 'page', 6 => 'post', 201 => 'et_header_layout', 202 => 'et_footer_layout' ] as $id => $type ) {
            $p               = new \WP_Post();
            $p->ID           = $id;
            $p->post_type    = $type;
            $p->post_status  = 'publish';
            $p->post_title   = "Post {$id}";
            $p->post_content = 'CONTENT';
            $GLOBALS['__wp_posts'][ $id ] = $p;
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @return array<string,mixed> */
    private function mcp( string $method, array $params = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testToolsListDoesNotAdvertiseTheRemovedTool(): void
    {
        $names = array_column( $this->mcp( 'tools/list' )['result']['tools'], 'name' );
        $this->assertNotContains( 'list_theme_layouts', $names );
        $this->assertContains( 'get_page_layout', $names, 'the page tools are still there' );
    }

    public function testCallingTheRemovedToolIsAnUnknownToolError(): void
    {
        $data = $this->mcp( 'tools/call', [ 'name' => 'list_theme_layouts', 'arguments' => [] ] );
        $this->assertSame( -32602, $data['error']['code'] );
        $this->assertStringContainsString( 'Unknown tool: list_theme_layouts', $data['error']['message'] );
    }

    public function testALayoutIdIsNotFoundForTheMcpPageTools(): void
    {
        $calls = [
            'get_page_layout'   => [ 'page_id' => 201 ],
            'edit_page_content' => [ 'page_id' => 201, 'find' => 'a', 'replace' => 'b' ],
            'list_page_history' => [ 'page_id' => 202 ],
        ];
        foreach ( $calls as $tool => $args ) {
            $data = $this->mcp( 'tools/call', [ 'name' => $tool, 'arguments' => $args ] );
            $this->assertSame( -32602, $data['error']['code'], $tool );
            $this->assertSame( 'Page ' . $args['page_id'] . ' not found.', $data['error']['message'], $tool );
        }
    }

    public function testALayoutIdIsNotFoundForTheRestPageTools(): void
    {
        $r = ( new RestController() )->get_page( new \WP_REST_Request( [], [ 'id' => 201 ] ) );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'not_found', $r->code );
        $this->assertSame( 404, $r->data['status'] );
    }

    public function testAPageStillReadsThroughRestAndMcp(): void
    {
        $r = ( new RestController() )->get_page( new \WP_REST_Request( [], [ 'id' => 5 ] ) );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 5, $r->get_data()['post_id'] );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails for the right reason**

Run: `./vendor/bin/phpunit tests/RemovedThemeLayoutsToolTest.php 2>&1 | tail -20`

Expected: FAIL in `testToolsListDoesNotAdvertiseTheRemovedTool` (the tool is still listed), `testCallingTheRemovedToolIsAnUnknownToolError` (it is still handled) and `testALayoutIdIsNotFoundForTheMcpPageTools` is already green because `EditTarget` is page-only; `testAPageStillReadsThroughRestAndMcp` green. If the test errors out with a missing class or function instead of failing on those assertions, add the one missing `require_once` copied from the top of `tests/DryRunHandlersTest.php` and re-run.

- [ ] **Step 3: Remove the code from the three handlers**

```bash
python3 - <<'PY'
import re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

def drop_re(s, pattern, count=1):
    s, n = re.subn(pattern, '', s, flags=re.S)
    assert n == count, (pattern, n)
    return s

def cut_checked(s, start, end, forbid=('global',)):
    i = s.index(start)
    j = s.index(end, i)
    removed = s[i:j].lower()
    for f in forbid:
        assert f not in removed, 'segment would also remove: ' + f
    return s[:i] + s[j:]

def mcp(s):
    s = drop_re(s, r"            \[\n                'name'        => 'list_theme_layouts',\n.*?\n            \],\n")
    old = "            'list_theme_layouts' => $this->toolListThemeLayouts($id),\n"
    assert old in s
    s = s.replace(old, '')
    s = drop_re(s, r"    private function toolListThemeLayouts\(mixed \$id\): WP_REST_Response\n    \{.*?\n    \}\n\n")
    sentence = " A header or footer layout id from list_theme_layouts also works."
    assert s.count(sentence) == 6, s.count(sentence)
    s = s.replace(sentence, '')
    param = " (or a header/footer layout id from list_theme_layouts)"
    assert s.count(param) == 6, s.count(param)
    return s.replace(param, '')
edit('wp-plugin/src/McpHandler.php', mcp)

def rest(s):
    s = drop_re(s, r"        // GET /theme-layouts[^\n]*\n        register_rest_route\(self::NS, '/theme-layouts', \[.*?\n        \]\);\n\n")
    return drop_re(s, r"    public function theme_layouts\(WP_REST_Request \$request\): WP_REST_Response\|WP_Error\n    \{.*?\n    \}\n\n")
edit('wp-plugin/src/RestController.php', rest)

def openapi(s):
    s = cut_checked(s, "                    'ThemeLayoutRef' => [", "                    'DryRunAttr' => [")
    s = cut_checked(s, "                '/theme-layouts' => [", "                '/global-styles' => [")
    old = "'description' => 'page, et_header_layout or et_footer_layout'"
    assert old in s
    return s.replace(old, "'description' => 'The WordPress post type (always page)'")
edit('wp-plugin/src/OpenApiSpec.php', openapi)

def readme(s):
    s, n = re.subn(r"^\* `list_theme_layouts`:[^\n]*\n", '', s, flags=re.M)
    assert n == 1
    assert '= What your AI assistant can do (17 tools) =' in s
    return s.replace('= What your AI assistant can do (17 tools) =', '= What your AI assistant can do (16 tools) =')
edit('wp-plugin/readme.txt', readme)
PY
for f in McpHandler RestController OpenApiSpec; do php -l wp-plugin/src/$f.php; done
git diff --stat
```

Expected: three `No syntax errors detected`. `git diff --stat` shows only `McpHandler.php`, `RestController.php`, `OpenApiSpec.php` and `readme.txt`, each as mostly deletions (McpHandler a few dozen lines, RestController about 25, OpenApiSpec about 60). A failed `assert` means the file differs from what this plan was written against: stop and inspect it. OpenApiSpec's `cut_checked` refuses to remove a segment that mentions "global" (it would then be swallowing the 4.4.0 global-styles entries); the `/theme-layouts` path ends where `/global-styles` begins.

- [ ] **Step 4: Recount the two pinned tests (justified)**

```bash
python3 - <<'PY'
import re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

def compliance(s):
    old = "'list_media_images', 'list_page_history', 'list_theme_layouts', 'restore_page_version'"
    assert old in s
    s = s.replace(old, "'list_media_images', 'list_page_history', 'restore_page_version'")
    old = ("        // 4.4.0 adds one READ-ONLY tool (list_global_styles: the site's own presets and colors); nothing is gated, nothing writes a setting.\n"
           "        $this->assertCount(17, $tools);")
    assert old in s
    s = s.replace(old,
        "        // 16 tools: 4.4.0 adds one READ-ONLY tool (list_global_styles: the site's own presets and colors) and header/footer\n"
        "        // editing (list_theme_layouts, never released) moved out to the separate Pro add-on, so the pinned list shrinks by one.\n"
        "        // Nothing is gated and nothing writes a setting; no rule of this test is loosened.\n"
        "        $this->assertCount(16, $tools);")
    old = "$this->assertStringContainsString('(17 tools)', $r);"
    assert old in s
    return s.replace(old, "$this->assertStringContainsString('(16 tools)', $r);")
edit('tests/WpOrgComplianceTest.php', compliance)

def lockstep(s):
    s, n = re.subn(r"        \$this->assertCount\( 17, \$ops, 'dry_run is a flag[^\n]*\n",
                   "        $this->assertCount( 16, $ops, 'dry_run is a flag, not a new operation (the 16th operation is the read-only list_global_styles)' );\n", s)
    assert n == 1
    return s
edit('tests/DryRunLockstepTest.php', lockstep)
PY
git diff tests/WpOrgComplianceTest.php tests/DryRunLockstepTest.php | grep '^[+-]' | grep -v '^+++\|^---' | cut -c1-170
```

Expected: the diff shows exactly the list shrinking by `list_theme_layouts`, the count 17 to 16 with the new comment, the readme assertion `(17 tools)` to `(16 tools)` (it pins the line Step 3 already changed in `readme.txt`), and the lockstep count 17 to 16.

- [ ] **Step 5: Run the new test and the whole suite**

Run: `./vendor/bin/phpunit tests/RemovedThemeLayoutsToolTest.php 2>&1 | tail -4`

Expected: `OK`.

Run: `./vendor/bin/phpunit 2>&1 | tail -15`

Expected: green except the guard cases for `src/SiteGuide.php`, `src/StyleGuide.php` and `readme.txt` (Task 5). If another suite test fails on a count (for example a lockstep test pinning the number of OpenAPI paths), read what it pins; if it pins the 17 operations, change it to 16 with the same justification, and say so in the commit message. Do not touch any other assertion.

- [ ] **Step 6: Check the real dev site (the dev plugin folder is a bind mount of `wp-plugin/`)**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval '
wp_set_current_user( 1 );
$r = rest_do_request( new WP_REST_Request( "GET", "/ai-editor-divi5/v1/theme-layouts" ) );
echo "GET /theme-layouts: ", $r->get_status(), "\n";
$r = rest_do_request( new WP_REST_Request( "GET", "/ai-editor-divi5/v1/pages/224959" ) );
echo "GET /pages/224959 (a Theme Builder header): ", $r->get_status(), " ", $r->get_data()["code"] ?? "", "\n";
$r = rest_do_request( new WP_REST_Request( "GET", "/ai-editor-divi5/v1/global-styles" ) );
echo "GET /global-styles: ", $r->get_status(), "\n";
$spec = AiEditorDivi5\WP\OpenApiSpec::spec( home_url(), "4.4.0" );
$ops = 0; foreach ( $spec["paths"] as $p ) { $ops += count( $p ); }
echo "OpenAPI operations: $ops\n";'
```

Expected: `GET /theme-layouts: 404`, `GET /pages/224959 ...: 404 not_found`, `GET /global-styles: 200`, `OpenAPI operations: 16`. The first three lines are the proof that matters; the operation count is cross-checked by the lockstep test.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: remove list_theme_layouts, /theme-layouts and their OpenAPI entries (16 tools)

The pinned tool list in WpOrgComplianceTest and the lockstep operation count go
from 17 to 16 because a tool was removed; no rule is loosened. Page-tool
descriptions are back to page wording. Old clients calling the removed tool get
the normal unknown-tool error.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Guides and readme

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/readme.txt`, `tests/StyleGuideTest.php`, `tests/SiteGuideTest.php`

**Interfaces:**
- Consumes: Task 4's 16-tool readme list.
- Produces: guides and readme with no header/footer editing text; readme Stable tag and plugin version stay `4.4.0`.

- [ ] **Step 1: Remove the two 4.3.0 guide tests (they assert the text being removed)**

```bash
python3 - <<'PY'
import re
for path, name in (('tests/StyleGuideTest.php', 'testHeaderAndFooterSectionTeachesListThemeLayouts'),
                   ('tests/SiteGuideTest.php', 'testBlueprintMentionsEditingHeaderAndFooterLayouts')):
    s = open(path, encoding='utf-8').read()
    m = re.search(r"\n    public function " + name + r"\(\): void\n    \{.*?\n    \}\n", s, flags=re.S)
    assert m, name
    open(path, 'w', encoding='utf-8').write(s[:m.start()] + s[m.end():])
PY
./vendor/bin/phpunit tests/StyleGuideTest.php tests/SiteGuideTest.php 2>&1 | tail -3
```

Expected: `OK` (the other guide tests still pass against the unchanged guides).

- [ ] **Step 2: Edit the guides and the readme**

```bash
python3 - <<'PY'
import re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

def style(s):
    i = s.index("## Header and footer\n")
    j = s.index("## Match the site (colors and presets)", i)   # the 4.4.0 section that follows it
    assert 'Theme Builder' in s[i:j] and 'global' not in s[i:j].lower() and len(s[i:j]) < 1500
    return s[:i] + s[j:]
edit('wp-plugin/src/StyleGuide.php', style)

def site(s):
    old = ("- Header/footer: the owner's existing Theme Builder header and footer can be previewed and\n"
           "  edited (list_theme_layouts, then pass the layout_id as page_id; always `dry_run` first,\n"
           "  they appear on every page; only entries marked `editable: true` can be edited). Creating them and choosing where they apply is done by the\n"
           "  owner in Divi's Theme Builder. Otherwise they are the active theme's (the nav menu\n"
           "  drives the header).\n")
    assert old in s
    return s.replace(old, "- The header, footer and navigation come from the active theme's own templates (the\n  nav menu drives the header); this plugin does not change them.\n")
edit('wp-plugin/src/SiteGuide.php', site)

def readme(s):
    s, n = re.subn(r"^\* \*\*Header and footer too\.\*\*[^\n]*\n", '', s, flags=re.M)
    assert n == 1
    old = "edit and undo the content of pages and of your existing Theme Builder header and footer, create pages"
    assert old in s
    s = s.replace(old, "edit and undo the content of pages, create pages")
    old = "Editing a header or footer needs a user whom Divi allows to use its Theme Builder (by default, administrators), and the tools cannot create, delete, assign or publish Theme Builder templates. They cannot install"
    assert old in s
    s = s.replace(old, "They cannot install")
    s, n = re.subn(r"= 4\.3\.0 =\n\* New: your AI assistant can now preview and edit.*?\n\* It cannot create, delete, assign or publish Theme Builder templates, and body layouts are not editable\.\n\n", '', s, flags=re.S)
    assert n == 1
    s, n = re.subn(r"= 4\.3\.0 =\nAdds editing of your existing Theme Builder header and footer[^\n]*\n\n", '', s)
    assert n == 1
    return s
edit('wp-plugin/readme.txt', readme)
PY
php -l wp-plugin/src/StyleGuide.php; php -l wp-plugin/src/SiteGuide.php
grep -n -i -E "theme builder|header and footer|header/footer|theme_layout|4\.3\.0" wp-plugin/readme.txt wp-plugin/src/StyleGuide.php wp-plugin/src/SiteGuide.php || echo "no references left"
grep -n "Stable tag\|^= 4\." wp-plugin/readme.txt | head -6
```

Expected: two `No syntax errors detected`; `no references left`; `Stable tag: 4.4.0` and the changelog headings run `4.4.0`, `4.2.0`, `4.1.0`, ... with no `4.3.0`.

- [ ] **Step 3: Run the whole suite, including the guard**

Run: `./vendor/bin/phpunit 2>&1 | tail -6`

Expected: all green, `NoThemeLayoutsInFreeTest` included (every file case passes). Record the final count (the replay printed `OK (548 tests, 3911 assertions)`; it is lower than 567 because the layout-specific tests left).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "docs: guides and readme no longer mention header/footer editing; no 4.3.0 changelog

4.3.0 was never published, so its changelog and upgrade notice entries are
deleted and the next release stays 4.4.0.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Repo docs, zip, Plugin Check, real-site checks, screenshot

**Files:**
- Modify: `Makefile`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-01-theme-layouts-design.md`, `jhmg-ai-editor-for-divi-5.zip`, `wporg-assets/screenshot-3.png`
- Delete: `scripts/verify-theme-layouts.php`, `tests/DiviThemeBuilderShims.php` (only if unreferenced)

**Interfaces:**
- Consumes: Tasks 1 to 5.
- Produces: a rebuilt 4.4.0 zip with 16 tools, Plugin Check clean (dev copy and extracted zip), `make verify-global-styles` still passing, updated docs, a regenerated Features screenshot.

- [ ] **Step 1: Remove the verify target and script, and the orphaned shim**

```bash
python3 - <<'PY'
s = open('Makefile', encoding='utf-8').read()
assert ' verify-theme-layouts' in s
s = s.replace(' verify-theme-layouts', '', 1)
start = s.index("# ---------------------------------------------------------------\n# verify-theme-layouts")
end = s.index("# ---------------------------------------------------------------", start + 10)
# the block is: separator, comment, separator, target; cut through the target's last line
tail = s.index("\n\n", start)
s = s[:start] + s[tail + 2:]
open('Makefile', 'w', encoding='utf-8').write(s)
PY
git diff Makefile | grep '^[+-]' | grep -v '^+++\|^---'
git rm -q scripts/verify-theme-layouts.php
git grep -l "DiviThemeBuilderShims" -- tests | grep -v "^tests/DiviThemeBuilderShims.php" || git rm -q tests/DiviThemeBuilderShims.php
make -n verify-global-styles | head -2
```

Expected: the Makefile diff removes `verify-theme-layouts` from `.PHONY` and removes the three-line comment banner plus the target (no other target touched); `make -n verify-global-styles` still prints its command. If `git grep` finds another test still requiring the shim, the `||` branch does not run and the shim stays.

- [ ] **Step 2: Update CLAUDE.md**

Use `Read` on `CLAUDE.md` and `Edit` for each of these (line numbers are from `grep -n -i -E "17 |theme.layout|header|footer|theme builder" CLAUDE.md`; re-run it first):

1. The tool-table paragraph "**17 MCP tools, all free:** ..." becomes **16**: delete `` `list_theme_layouts` `` and its parenthetical from that sentence (it is the read-only `ThemeLayouts` mention); keep `list_global_styles`.
2. The Dry-run paragraph's last sentence becomes: "It is a flag, not a tool: the list stayed at 15 tools / 15 operations in 4.2.0 (4.4.0 adds the read-only `list_global_styles`: 16)."
3. Replace the whole "Theme layouts (v4.3.0): ..." paragraph with:

```markdown
Theme layouts (Pro add-on): editing the existing LIVE Theme Builder header and footer (`et_header_layout` /
`et_footer_layout`) was built as 4.3.0 and then MOVED to the Pro add-on before it was ever uploaded (2026-10-02;
`docs/superpowers/specs/2026-10-02-pro-addon-design.md`). The free plugin has NO header/footer or Theme Builder code
(`tests/NoThemeLayoutsInFreeTest.php` enforces it); `EditTarget::resolve()` is page-only. The source is parked in
`pro-addon/staged-4.3.0/`. Verified 2026-10-02 on Divi 5.14: a REAL builder-made header (menu, search, social follow),
footer (text, button, social follow, code) and body layout all pass the validator (`fixtures/valid/themebuilder-*.json`);
that proof stays here because it proves the validator.
```

4. Delete the `make verify-theme-layouts` row from the entry-points table.
5. In "Current state": delete the "4.3.0 = header/footer release" bullet; in the 4.4.0 bullet replace "Ships on top of 4.3.0, after the first WordPress.org approval." with "Ships after the first WordPress.org approval (the free re-cut removed the unreleased 4.3.0 header/footer code: 16 tools, merged only after the owner approves)." and replace the cards sentence with "The Features screenshot was regenerated after the re-cut." Update the "Header/footer are the active theme's (nav menu drives the header)." line only if it contradicts nothing (leave it).
6. In the `WpOrgComplianceTest` description (the "17-tool list" mention) change 17 to 16.

Then verify: `grep -n -i "list_theme_layouts\|17 tools\|17-tool\|verify-theme-layouts" CLAUDE.md` prints only the Pro add-on paragraph's mention (the moved tool's name inside the "Theme layouts (Pro add-on)" paragraph is not present, so expect no output).

- [ ] **Step 3: Mark the 4.3.0 spec as moved**

Insert directly under the title line of `docs/superpowers/specs/2026-10-01-theme-layouts-design.md`:

```markdown
> **MOVED 2026-10-02:** this feature was never released from the free plugin; it moved to the Pro add-on (`docs/superpowers/specs/2026-10-02-pro-addon-design.md`). The free plugin no longer contains it. Kept for the design history and the evidence it records.
```

- [ ] **Step 4: Full suite, then the real-site global-styles check**

```bash
./vendor/bin/phpunit 2>&1 | tail -4
make verify-global-styles 2>&1 | tail -6
```

Expected: `OK (...)` and the verify script's final summary line reporting all checks passed (25/25 before this change; the count does not change because this feature does not touch it). If it reports a failure, stop and report it.

- [ ] **Step 5: Rebuild the zip and run Plugin Check on the dev copy and the extracted zip**

```bash
bash scripts/build-plugin-zip.sh
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5
rm -rf /tmp/aied-zipcheck && mkdir /tmp/aied-zipcheck && unzip -q jhmg-ai-editor-for-divi-5.zip -d /tmp/aied-zipcheck
docker compose cp /tmp/aied-zipcheck/jhmg-ai-editor-for-divi-5 wpcli:/var/www/html/wp-content/plugins/aied-zipcheck
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check aied-zipcheck --slug=jhmg-ai-editor-for-divi-5
docker compose exec -T -u root wpcli rm -rf /var/www/html/wp-content/plugins/aied-zipcheck
rm -rf /tmp/aied-zipcheck
unzip -l jhmg-ai-editor-for-divi-5.zip | grep -c -i "ThemeLayouts" || true
```

Expected: the build prints `Packaging jhmg-ai-editor-for-divi-5 version 4.4.0`; Plugin Check prints `Success: Checks complete. No errors found.` twice; the last command prints `0`. NEVER run `wp plugin delete`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "release: free 4.4.0 re-cut (16 tools), docs and rebuilt zip

Removes verify-theme-layouts (parked), updates CLAUDE.md and marks the 4.3.0 spec
as moved to the Pro add-on. Zip rebuilt; Plugin Check clean on the dev copy and
the extracted zip; verify-global-styles passes.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Regenerate the Features screenshot (CONTROLLER ONLY, needs the owner's consent)**

The art script needs a logged-in admin cookie. Creating one by hand was refused by the permission classifier on 2026-10-02 until the owner explicitly allowed it, and that consent was for that session. Ask the owner again before doing this. If they agree:

```bash
SP=<scratchpad dir>
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval '
$u = get_users(["role"=>"administrator","number"=>1])[0];
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
echo json_encode(["cookies"=>[
 ["name"=>LOGGED_IN_COOKIE,"value"=>wp_generate_auth_cookie($u->ID,$exp,"logged_in",$tok),"domain"=>"localhost","path"=>"/"],
 ["name"=>AUTH_COOKIE,"value"=>wp_generate_auth_cookie($u->ID,$exp,"auth",$tok),"domain"=>"localhost","path"=>"/wp-admin"],
]]);' > $SP/cookies.json
chmod 600 $SP/cookies.json
AIED_COOKIES_FILE=$SP/cookies.json node scripts/build-wporg-art.cjs shots 3
rm -f $SP/cookies.json
```

Read `wporg-assets/screenshot-3.png` and confirm: the "Edit your existing header and footer" card is gone, "Match your site's colors and presets" is still there, no API key or localhost URL is visible. Show it to the owner. Commit only after the owner approves: `git add wporg-assets/screenshot-3.png` and commit `art: Features screenshot after the free re-cut` with the Co-Authored-By line.

---

## Self-Review (run against the spec's Phase 0 section)

**Spec coverage**

| Spec requirement | Task |
|---|---|
| Remove `ThemeLayouts` | 3 |
| Layout half of `EditTarget` removed (page-only again) | 3 |
| Remove `list_theme_layouts` MCP tool, `/theme-layouts` REST route, OpenAPI entry (and its two schemas) | 4 |
| Remove Dashboard header/footer labels and the layout Restore button rule | 3 |
| Remove `DiviCompat::clearLayoutCaches()` and its call in `HistoryService` | 3 |
| Remove the Features card | 3 (card line in `AdminPage`) + screenshot in 6 |
| Remove every readme mention (description, FAQ, changelog 4.3.0, upgrade notice, tool list, count) | 4 (tool bullet and count) and 5 (rest) |
| Guides no longer teach header/footer editing | 5 |
| `WpOrgComplianceTest` pinned list 17 to 16, justified, no rule loosened | 4 |
| Theme-layout unit tests and `make verify-theme-layouts` move to the add-on | 1 (parked) and 3, 6 (deleted from the main suite) |
| `fixtures/valid/themebuilder-*.json` stay | untouched in every task |
| Ships as 4.4.0, 4.3.0 number skipped, changelog entry deleted | 5 (no version change), 6 |
| Rebuild zip, Plugin Check on dev copy and zip, regenerate screenshot, update CLAUDE.md (16 tools) | 6 |
| No quota or limit added to the free plugin | Global Constraints (nothing is added) |

**Placeholder scan:** none. Every code and edit step shows its exact content; the only things left to the executor's judgment are named explicitly (the two "if another test still requires the shim / a count" branches, and the CLAUDE.md edits, which give the exact replacement text per passage).

**Type consistency:** `EditTarget::resolve` / `NOT_FOUND` / `FORBIDDEN` (Task 3) are the names used by the unchanged call sites and by Task 4's test; `AdminPage::mayRestore` is the existing method kept by Task 3 and tested there; `HistoryService::recent` rows lose only `kind`, which nothing else reads once `historyRowTitle` is gone (checked by the suite run in Task 3).

**Review Focus:** all five lines have a named test: (1) and (2) in `RemovedThemeLayoutsToolTest`, (2) also in `EditTargetTest`; (3) `AdminRestoreGateTest`; (4) `HistoryServiceTest` (existing row-shape tests) plus the unchanged "(no title)" fallback restored in `AdminPage`; (5) `NoThemeLayoutsInFreeTest`.

**Replayed:** Tasks 1 to 5 and Task 6 Step 1 were replayed in a scratch git worktree starting from `main` (the extracted code blocks, run in order). The guard went red on exactly the 12 expected files, each task's expected failures matched, and the end state is `OK (548 tests, 3911 assertions)` with the guard green. The replay found and fixed three bugs in earlier drafts of this plan: the OpenAPI `/theme-layouts` cut would have swallowed `/global-styles`; the `StyleGuide` cut would have swallowed the 4.4.0 "Match the site" section (both are now bounded by the next section's heading, and the scripts assert nothing global is removed); and `WpOrgComplianceTest` pins `(17 tools)` in the readme as well as the list. Not replayed (they need the real repo and Docker): Task 6 Steps 2 to 7 (CLAUDE.md, spec note, zip, Plugin Check, `make verify-global-styles`, the live-site check in Task 4 Step 6, the screenshot).

## Handoff (the controller's checklist after Task 6)

1. `git log --oneline main..feat/free-recut` shows the 6 commits (plus the screenshot commit if approved).
2. Report to the owner: final test count, Plugin Check results, `make verify-global-styles` result, and anything the executor had to decide (the shim kept or deleted; any extra count fixed).
3. Ask before merging `feat/free-recut` into `main`. Nothing is pushed or uploaded.
4. After the merge: write Plan 2 (Pro add-on skeleton, batches, settings tools) from the spec, starting from `pro-addon/staged-4.3.0/`.
