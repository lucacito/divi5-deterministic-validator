# Pro Add-on Skeleton (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `pro-addon/` from a staging folder into a real, loadable, licensed WordPress plugin ("JHMG AI Editor for Divi 5 Pro" 0.1.0) with its own test harness, that attaches to the free plugin only through the `jhmg_aied_*` hooks. It registers NO Pro tools yet (they come in the next plan); it proves the plumbing: compatibility guard, licensing with the updater, a tool registry gated by the licence, a Pro admin tab, a safety test, an uninstall and a zip.

**Architecture:** A separate plugin (`AiEditorDivi5\Pro`, slug `jhmg-ai-editor-for-divi-5-pro`) that boots on `plugins_loaded` priority 20 only if the free plugin is active at or above `Compat::MIN_FREE` and its classes exist. `ToolRegistry` is the single place that talks to the free plugin's `jhmg_aied_mcp_tools` / `jhmg_aied_mcp_call` hooks: it advertises and claims tools only while `Licensing::isPremium()`, checks each tool's capability, and turns tool exceptions into JSON-RPC errors that never leak exception text. `ProPage` adds one "Pro" admin tab (licence entry, tool list). `Licensing` is the staged sticky-unlock adapter over the canonical divi5lab `LicenseClient` (with its updater).

**Tech Stack:** PHP 8.1+, PHPUnit 11 (`./vendor/bin/phpunit -c pro-addon/phpunit.xml`), the main suite's WordPress shims in `tests/`, Bash + sed for the licence-client sync, Docker dev site (Divi 5.14) for the live check.

**Spec:** `docs/superpowers/specs/2026-10-02-pro-addon-design.md` ("Architecture", "Licensing and distribution", "Build order" steps 1). This is Plan 2 of the build; Phase 0 (the free re-cut) is done. The spec's later phases become separate plans: 3 = batch store, undo and the settings tools; 4 = Theme Builder layout tools and templates; 5 = find/replace, audit, REST/OpenAPI parity and live verification.

## Global Constraints

- Local commits only. NEVER push, upload or send anything. Work on a new branch `feat/pro-skeleton` cut from `main` (`git switch -c feat/pro-skeleton`). Ask the owner before merging to main.
- Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- `wp-plugin/` and the validator (`src/`, `wp-plugin/validator/`) are NOT touched. This plan edits only `pro-addon/`, `scripts/`, `Makefile`, `docker-compose.yml`, `.gitignore`, `CLAUDE.md` and `docs/`.
- The add-on extends the free plugin ONLY through the `jhmg_aied_*` hooks (`docs/EXTENDING.md`). It checks capabilities itself, only claims its own tool names, and never overrides a built-in tool.
- No Pro tool is registered in this plan (`Tools::all()` returns `[]`).
- Identity (verbatim): slug and folder `jhmg-ai-editor-for-divi-5-pro`, main file `jhmg-ai-editor-for-divi-5-pro.php`, text domain `jhmg-ai-editor-for-divi-5-pro`, namespace `AiEditorDivi5\Pro`, constants `AIED_PRO_VERSION` (`0.1.0`), `AIED_PRO_FILE`, `AIED_PRO_PRODUCT` (`ai-editor-divi5-pro`), option and transient prefix `aied_pro`, minimum free plugin version `4.4.0`, the free plugin's admin page slug `ai-editor-divi5`.
- Unlock rule (unchanged from the staged adapter): the first successful activation unlocks Pro persistently. Only an explicit server verdict of `revoked` or `invalid` re-locks. Lapsed, expired, cancelled, offline, rate-limited or 5xx NEVER lock.
- Remote calls: `https://divi5lab.com` only, for licence and updates, and only from `src/Licensing/LicenseClient.php`.
- No `eval`/exec-family function, no PHP execution of stored text, no writes to users, plugins or Divi presets/colors/variables anywhere in the add-on.
- The canonical licence client lives in the separate `../layoutlab` repo: read it, never edit it. (The product id `ai-editor-divi5-pro` is the one the old in-plugin licensing used with the divi5lab server; confirming the server still knows it is the owner's task and is listed in the Handoff.)
- Never run `wp plugin delete` (it runs uninstall.php against the shared dev DB). The Pro plugin is activated on the dev site only for the live check and deactivated afterwards.

## Review Focus

Failure modes the spec implies but a straight build would not exercise; each has a test in the owning task:

1. The free plugin missing, inactive, older than 4.4.0, or missing a class the add-on needs: the add-on registers nothing, shows one escaped admin notice (admin only) and never fatals. (Task 2, `CompatTest` + `PluginTest`)
2. Locked state (never activated, or revoked): no Pro tool is advertised or callable, even by name, even if the licence is revoked between listing and calling. A lapsed or offline licence never locks. (Task 3 `LicensingTest`, Task 4 `ToolRegistryTest`)
3. A user without the tool's capability, a tool that throws, or a tool that rejects its arguments: a JSON-RPC error, and exception text from unexpected failures never reaches the client. (Task 4)
4. The licence key is never printed in full in admin HTML; the activate/deactivate handlers refuse without `manage_options`, without a valid nonce, and on a non-POST request. (Task 5, `ProPageTest`)
5. The updater only accepts an update whose package is an `https` URL on the licence host and a genuinely newer version. (Task 3)
6. Remote calls and dangerous functions: nothing in the add-on source can call out to a host other than divi5lab.com, call eval/exec, or write users/plugins/Divi presets, and the safety scan proves it catches planted violations. (Task 6, `ProSafetyTest`)

---

## File structure

| File | Change |
|---|---|
| `pro-addon/staged/{MenuBuilder,CustomCss,PhpProposals}.php`, `pro-addon/staged/PhpProposalsTest.php` | Move out of `src/` and `tests/` (live code only in `src/`); the next plan integrates them |
| `pro-addon/phpunit.xml`, `pro-addon/tests/bootstrap.php`, `pro-addon/tests/HarnessTest.php` | Create: the Pro test harness |
| `Makefile` | Add `test-pro`, `build-pro`, `verify-pro-skeleton` |
| `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` | Create: main plugin file |
| `pro-addon/src/autoload.php` | Create: requires every live class |
| `pro-addon/src/Compat.php`, `src/Plugin.php` | Create: compatibility guard and boot |
| `pro-addon/src/Licensing.php`, `src/Licensing/LicenseClient.php` | Rework: namespace `AiEditorDivi5\Pro`; the client is generated by `scripts/sync-pro-license-client.sh` |
| `pro-addon/src/ProTool.php`, `src/ToolRegistry.php`, `src/Tools.php` | Create: tool contract, gated registry, the (empty) tool list |
| `pro-addon/src/ProPage.php` | Create: the Pro admin tab and its two admin-post handlers |
| `pro-addon/uninstall.php` | Create |
| `scripts/sync-pro-license-client.sh`, `scripts/build-pro-zip.sh`, `scripts/verify-pro-skeleton.php` | Create |
| `pro-addon/tests/*.php` | Create/adapt: `CompatTest`, `PluginTest`, `FreeApiCompatTest`, `MainFileTest`, `LicensingTest`, `ToolRegistryTest`, `ToolsTest`, `ProPageTest`, `UninstallTest`, `ProSafetyTest`, `ProSafetyScanner` |
| `docker-compose.yml`, `.gitignore`, `pro-addon/README.md`, `CLAUDE.md` | Mount, ignore `dist/`, docs |

---

### Task 1: Test harness, and move the staged files out of `src/`

**Files:**
- Move: `pro-addon/src/MenuBuilder.php`, `pro-addon/src/CustomCss.php`, `pro-addon/src/PhpProposals.php`, `pro-addon/tests/PhpProposalsTest.php` into `pro-addon/staged/`
- Create: `pro-addon/phpunit.xml`, `pro-addon/tests/bootstrap.php`, `pro-addon/tests/HarnessTest.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the main suite's shims (`tests/bootstrap.php`, `tests/WpRestShims.php`).
- Produces: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` (also `make test-pro`) running the Pro suite; Pro test shims for `is_admin`, `admin_url`, `add_query_arg`, `sanitize_key`, `wp_nonce_field`, `check_admin_referer`, `wp_die`, `wp_safe_redirect`; constants `AIED_PRO_VERSION`, `AIED_PRO_FILE`, `AIED_PRO_PRODUCT`. After this task `pro-addon/src/` holds only the Licensing files and `pro-addon/tests/` holds `LicensingTest.php` (reworked in Task 3; until then it is excluded from the Pro suite, see Step 3).

- [ ] **Step 1: Create the branch and confirm a green start**

```bash
git switch -c feat/pro-skeleton
git status --short
./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: no status output; `OK (548 tests, 3911 assertions)`.

- [ ] **Step 2: Write the failing smoke test**

Create `pro-addon/tests/HarnessTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/** The Pro suite runs on the main suite's WordPress shims plus a handful of its own. */
class HarnessTest extends TestCase
{
    public function testTheSharedWordPressShimsAreLoaded(): void
    {
        $this->assertTrue( function_exists( 'get_option' ) );
        $this->assertTrue( function_exists( 'apply_filters' ) );
        $this->assertTrue( class_exists( \WP_REST_Response::class ) );
        $this->assertTrue( function_exists( 'current_user_can' ) );
    }

    public function testTheProConstantsAreDefined(): void
    {
        $this->assertSame( 'ai-editor-divi5-pro', AIED_PRO_PRODUCT );
        $this->assertFileExists( dirname( AIED_PRO_FILE ) . '/phpunit.xml' );
        $this->assertNotSame( '', AIED_PRO_VERSION );
    }

    public function testTheProAdminShimsExist(): void
    {
        foreach ( [ 'is_admin', 'admin_url', 'add_query_arg', 'sanitize_key', 'wp_nonce_field', 'check_admin_referer', 'wp_die', 'wp_safe_redirect' ] as $fn ) {
            $this->assertTrue( function_exists( $fn ), $fn );
        }
        $this->assertSame( 'https://example.com/wp-admin/admin.php?page=x&tab=pro', add_query_arg( [ 'page' => 'x', 'tab' => 'pro' ], admin_url( 'admin.php' ) ) );
    }
}
```

- [ ] **Step 3: Run it and confirm it fails (no harness yet)**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml 2>&1 | tail -5`

Expected: FAIL / error: the configuration file `pro-addon/phpunit.xml` does not exist.

- [ ] **Step 4: Move the staged files and write the harness**

```bash
mkdir -p pro-addon/staged
git mv pro-addon/src/MenuBuilder.php pro-addon/src/CustomCss.php pro-addon/src/PhpProposals.php pro-addon/staged/
git mv pro-addon/tests/PhpProposalsTest.php pro-addon/staged/PhpProposalsTest.php
# LicensingTest is reworked in Task 3; park it out of the suite until then.
git mv pro-addon/tests/LicensingTest.php pro-addon/staged/LicensingTest.php
ls pro-addon/src pro-addon/src/Licensing pro-addon/staged pro-addon/tests
```

Expected: `src` holds `Licensing.php` and `Licensing/`; `staged` holds the three classes and two tests; `tests` holds `HarnessTest.php` only.

Create `pro-addon/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="../vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Pro add-on">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Create `pro-addon/tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Pro add-on: the main suite's shared WordPress shims, plus the few the
 * add-on's admin code needs. Every definition is guarded so it never clashes with another shim.
 */

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../tests/WpRestShims.php';

if ( ! defined( 'AIED_PRO_VERSION' ) ) {
    define( 'AIED_PRO_VERSION', '0.1.0' );
}
if ( ! defined( 'AIED_PRO_FILE' ) ) {
    define( 'AIED_PRO_FILE', (string) realpath( __DIR__ . '/..' ) . '/jhmg-ai-editor-for-divi-5-pro.php' );
}
if ( ! defined( 'AIED_PRO_PRODUCT' ) ) {
    define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool { return (bool) ( $GLOBALS['__wp_is_admin'] ?? false ); }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) { return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( (array) $args ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">'; }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( $action = -1 ) {
        if ( ( $_REQUEST['_wpnonce'] ?? '' ) !== 'nonce-for-' . $action ) {
            throw new \RuntimeException( 'check_admin_referer failed' );
        }

        return 1;
    }
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '' ) { throw new \RuntimeException( 'wp_die: ' . $message ); }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $url ) { $GLOBALS['__wp_redirects'][] = (string) $url; return true; }
}
```

- [ ] **Step 5: Add the Makefile targets (`test-pro` now; the other two come in Task 6)**

In `Makefile`, add `test-pro` to the `.PHONY` line, and add after the `test:` target:

```makefile
# ---------------------------------------------------------------
# test-pro — run the Pro add-on's own PHPUnit suite
# ---------------------------------------------------------------
test-pro:
	@./vendor/bin/phpunit -c pro-addon/phpunit.xml
```

(The recipe line starts with a TAB.)

- [ ] **Step 6: Run the Pro suite and the main suite**

Run: `make test-pro 2>&1 | tail -4`

Expected: `OK (3 tests, 16 assertions)`.

Run: `./vendor/bin/phpunit 2>&1 | tail -3`

Expected: `OK (548 tests, 3911 assertions)` (the main suite is unaffected; it never scans `pro-addon/`).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore(pro): Pro test harness; move the staged classes out of src/

pro-addon/src now holds live code only. MenuBuilder, CustomCss, PhpProposals and
their tests (and the old LicensingTest, until it is reworked) sit in pro-addon/staged/.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Main file, compatibility guard, boot, and the free-API contract tests

**Files:**
- Create: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/src/autoload.php`, `pro-addon/src/Compat.php`, `pro-addon/src/Plugin.php`
- Test: `pro-addon/tests/CompatTest.php`, `pro-addon/tests/PluginTest.php`, `pro-addon/tests/FreeApiCompatTest.php`, `pro-addon/tests/MainFileTest.php`

**Interfaces:**
- Consumes: Task 1's harness; the free plugin's classes (`wp-plugin/src/autoload.php`).
- Produces:
  - `Compat::MIN_FREE` (`'4.4.0'`), `Compat::REQUIRED_CLASSES` (list of fully qualified free class names), `Compat::check( ?string $freeVersion, callable $classExists, string $min = self::MIN_FREE ): array{ok:bool, reason:?string, message:string}` with reasons `free_missing`, `free_too_old`, `class_missing`.
  - `Plugin::boot(): void` (the `plugins_loaded` callback) and `Plugin::bootWith( ?string $freeVersion, callable $classExists ): bool` (true when wired). `Plugin::wire()` is private and empty in this task.
  - `src/autoload.php` requiring each live class (later tasks append lines).

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/CompatTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Compat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Compat.php';

/** The add-on only runs against a free plugin it understands. */
class CompatTest extends TestCase
{
    private static function all(): \Closure
    {
        return static fn ( string $c ): bool => true;
    }

    public function testAMissingFreePluginIsReported(): void
    {
        foreach ( [ null, '' ] as $none ) {
            $r = Compat::check( $none, self::all() );
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'free_missing', $r['reason'] );
            $this->assertStringContainsString( 'JHMG AI Editor for Divi 5', $r['message'] );
        }
    }

    public function testAFreePluginBelowTheMinimumIsTooOld(): void
    {
        $r = Compat::check( '4.3.9', self::all() );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'free_too_old', $r['reason'] );
        $this->assertStringContainsString( Compat::MIN_FREE, $r['message'] );
        $this->assertStringContainsString( '4.3.9', $r['message'] );
    }

    public function testTheMinimumAndNewerAreAccepted(): void
    {
        foreach ( [ '4.4.0', '4.4.1', '4.10.0', '5.0.0' ] as $v ) {
            $r = Compat::check( $v, self::all() );
            $this->assertTrue( $r['ok'], $v );
            $this->assertNull( $r['reason'] );
        }
        $this->assertSame( '4.4.0', Compat::MIN_FREE );
    }

    public function testAMissingFreeClassIsReportedByName(): void
    {
        $r = Compat::check( '4.4.0', static fn ( string $c ): bool => $c !== 'AiEditorDivi5\\WP\\HistoryService' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'class_missing', $r['reason'] );
        $this->assertStringContainsString( 'AiEditorDivi5\\WP\\HistoryService', $r['message'] );
    }

    public function testEveryRequiredClassIsChecked(): void
    {
        $asked = [];
        Compat::check( '4.4.0', static function ( string $c ) use ( &$asked ): bool {
            $asked[] = $c;

            return true;
        } );
        $this->assertSame( Compat::REQUIRED_CLASSES, $asked );
    }

    public function testTheVersionIsCheckedBeforeTheClasses(): void
    {
        $asked = 0;
        Compat::check( '4.0.0', static function () use ( &$asked ): bool {
            $asked++;

            return true;
        } );
        $this->assertSame( 0, $asked, 'an old free plugin is reported without touching its classes' );
    }
}
```

Create `pro-addon/tests/PluginTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** Booting against an unusable free plugin registers nothing and warns, in the admin only. */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_filters']  = [];
        $GLOBALS['__wp_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters']  = [];
        $GLOBALS['__wp_is_admin'] = false;
    }

    public function testAMissingFreePluginRegistersNothingOutsideTheAdmin(): void
    {
        $this->assertFalse( Plugin::bootWith( null, static fn (): bool => true ) );
        $this->assertSame( [], $GLOBALS['__wp_filters'] );
    }

    public function testAMissingFreePluginShowsOneEscapedNoticeInTheAdmin(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->assertFalse( Plugin::bootWith( null, static fn (): bool => true ) );
        $this->assertCount( 1, $GLOBALS['__wp_filters']['admin_notices'] );
        $this->assertSame( [ 'admin_notices' ], array_keys( $GLOBALS['__wp_filters'] ), 'nothing else is registered' );

        ob_start();
        $GLOBALS['__wp_filters']['admin_notices'][0]();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'JHMG AI Editor for Divi 5 Pro', $html );
        $this->assertStringContainsString( 'free plugin', $html );
    }

    public function testATooOldFreePluginDoesNotBoot(): void
    {
        $GLOBALS['__wp_is_admin'] = true;
        $this->assertFalse( Plugin::bootWith( '4.3.0', static fn (): bool => true ) );
        $this->assertSame( [ 'admin_notices' ], array_keys( $GLOBALS['__wp_filters'] ) );
    }

    public function testAFreePluginMissingAClassDoesNotBoot(): void
    {
        $this->assertFalse( Plugin::bootWith( '4.4.0', static fn ( string $c ): bool => false ) );
    }

    public function testACompatibleFreePluginBoots(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
    }
}
```

Create `pro-addon/tests/FreeApiCompatTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

/**
 * The add-on calls a small set of the free plugin's classes and hooks directly (spec decision: one
 * shared write path and one undo history). If the free plugin changes any of these, this test fails
 * first, instead of the add-on failing at runtime on a customer's site.
 */
class FreeApiCompatTest extends TestCase
{
    /** @return list<array{string,string,array<string,string>,string}> */
    public static function signatures(): array
    {
        $w = 'AiEditorDivi5\\WP\\';

        return [
            [ $w . 'HistoryService', 'write', [ 'pageId' => 'int', 'newContent' => 'string', 'tool' => 'string' ], 'array' ],
            [ $w . 'HistoryService', 'restore', [ 'pageId' => 'int', 'versionId' => 'int' ], 'array' ],
            [ $w . 'HistoryService', 'entry', [ 'pageId' => 'int', 'versionId' => 'int' ], '?array' ],
            [ $w . 'HistoryService', 'listFor', [ 'pageId' => 'int' ], 'array' ],
            [ $w . 'PageEditor', 'apply', [ 'content' => 'string', 'find' => 'string', 'replace' => 'string', 'expectCount' => '?int' ], 'array' ],
            [ $w . 'EditTarget', 'resolve', [ 'id' => 'int' ], 'array' ],
            [ $w . 'DryRun', 'preview', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'attach', [ 'payload' => 'array', 'content' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'advise', [ 'content' => 'string', 'known' => 'array' ], '?array' ],
        ];
    }

    /**
     * @param array<string,string> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'signatures' )]
    public function testTheFreeMethodKeepsItsSignature( string $class, string $method, array $params, string $returns ): void
    {
        $m = new \ReflectionMethod( $class, $method );
        $this->assertTrue( $m->isStatic() && $m->isPublic(), "{$class}::{$method} must stay public static" );
        $actual = [];
        foreach ( $m->getParameters() as $p ) {
            $actual[ $p->getName() ] = (string) $p->getType();
        }
        $this->assertSame( $params, $actual, "{$class}::{$method} parameters changed" );
        $this->assertSame( $returns, (string) $m->getReturnType(), "{$class}::{$method} return type changed" );
    }

    public function testTheEditTargetErrorConstantsStayTheSame(): void
    {
        $this->assertSame( 'not_found', \AiEditorDivi5\WP\EditTarget::NOT_FOUND );
        $this->assertSame( 'forbidden', \AiEditorDivi5\WP\EditTarget::FORBIDDEN );
    }

    public function testTheExtensionGuardKeepsBuiltInsFirst(): void
    {
        $m     = new \ReflectionMethod( \AiEditorDivi5\WP\ExtensionGuard::class, 'tools' );
        $types = array_map( static fn ( \ReflectionParameter $p ): string => (string) $p->getType(), $m->getParameters() );
        $this->assertSame( [ 'array', 'mixed' ], $types );
        $this->assertSame( 'array', (string) $m->getReturnType() );
    }

    public function testEveryHookTheAddOnUsesIsStillFiredByTheFreePlugin(): void
    {
        $source = '';
        foreach ( glob( __DIR__ . '/../../wp-plugin/src/*.php' ) ?: [] as $file ) {
            $source .= (string) file_get_contents( $file );
        }
        foreach ( [ 'jhmg_aied_mcp_tools', 'jhmg_aied_mcp_call', 'jhmg_aied_admin_tabs', 'jhmg_aied_render_admin_tab', 'jhmg_aied_register_rest_routes', 'jhmg_aied_openapi_paths', 'jhmg_aied_openapi_schemas' ] as $hook ) {
            $this->assertStringContainsString( "'{$hook}'", $source, "the free plugin no longer fires {$hook}" );
        }
    }

    public function testEveryRequiredClassExistsInTheFreePlugin(): void
    {
        require_once __DIR__ . '/../src/Compat.php';
        foreach ( \AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES as $class ) {
            $this->assertTrue( class_exists( $class ), $class );
        }
    }
}
```

Create `pro-addon/tests/MainFileTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

class MainFileTest extends TestCase
{
    private function header(): string
    {
        return (string) file_get_contents( AIED_PRO_FILE );
    }

    public function testTheHeaderAndTheConstantAgreeOnTheVersion(): void
    {
        $h = $this->header();
        $this->assertSame( 1, preg_match( "/define\(\s*'AIED_PRO_VERSION',\s*'([^']+)'\s*\);/", $h, $m ) );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s+' . preg_quote( $m[1], '/' ) . '\s*$/m', $h );
    }

    public function testTheIdentityIsExactlyAsSpecified(): void
    {
        $h = $this->header();
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Plugin Name:\s+JHMG AI Editor for Divi 5 Pro\s*$/m', $h );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Text Domain:\s+jhmg-ai-editor-for-divi-5-pro\s*$/m', $h );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Requires PHP:\s+8\.1\s*$/m', $h );
        $this->assertStringContainsString( "define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );", $h );
        $this->assertSame( 'jhmg-ai-editor-for-divi-5-pro.php', basename( AIED_PRO_FILE ) );
    }

    public function testItBootsAfterTheFreePluginHasLoaded(): void
    {
        $this->assertStringContainsString( "add_action( 'plugins_loaded', [ AiEditorDivi5\\Pro\\Plugin::class, 'boot' ], 20 );", $this->header() );
    }

    public function testItExitsWhenLoadedOutsideWordPress(): void
    {
        $this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $this->header() );
    }
}
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `make test-pro 2>&1 | tail -12`

Expected: the run aborts with a PHP fatal (`Failed opening required '.../pro-addon/src/Compat.php'`): `CompatTest` cannot load the class that does not exist yet.

- [ ] **Step 3: Write the code**

Create `pro-addon/src/Compat.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether the free plugin is there and new enough for the add-on to run. Pure: the caller passes
 * the free plugin's version and a class-existence callback, so it is unit-testable.
 */
final class Compat
{
    public const MIN_FREE = '4.4.0';

    /** The free plugin's classes the add-on calls directly (kept in step by tests/FreeApiCompatTest.php). */
    public const REQUIRED_CLASSES = [
        'AiEditorDivi5\\WP\\HistoryService',
        'AiEditorDivi5\\WP\\EditTarget',
        'AiEditorDivi5\\WP\\PageEditor',
        'AiEditorDivi5\\WP\\DryRun',
        'AiEditorDivi5\\WP\\StyleReferences',
        'AiEditorDivi5\\WP\\ExtensionGuard',
    ];

    /**
     * @param callable(string):bool $classExists
     * @return array{ok:bool, reason:?string, message:string}
     */
    public static function check( ?string $freeVersion, callable $classExists, string $min = self::MIN_FREE ): array
    {
        if ( null === $freeVersion || '' === $freeVersion ) {
            return self::fail( 'free_missing', 'needs the free plugin "JHMG AI Editor for Divi 5" to be installed and active. The Pro tools stay off until it is.' );
        }
        if ( version_compare( $freeVersion, $min, '<' ) ) {
            return self::fail( 'free_too_old', sprintf( 'needs the free plugin "JHMG AI Editor for Divi 5" version %s or newer (you have %s). Update the free plugin; the Pro tools stay off until then.', $min, $freeVersion ) );
        }
        foreach ( self::REQUIRED_CLASSES as $class ) {
            if ( ! $classExists( $class ) ) {
                return self::fail( 'class_missing', sprintf( 'cannot find %s in the free plugin, so the Pro tools stay off. Update both plugins to their latest versions.', $class ) );
            }
        }

        return [ 'ok' => true, 'reason' => null, 'message' => '' ];
    }

    /** @return array{ok:bool, reason:?string, message:string} */
    private static function fail( string $reason, string $message ): array
    {
        return [ 'ok' => false, 'reason' => $reason, 'message' => $message ];
    }
}
```

Create `pro-addon/src/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Boots the add-on, but only against a free plugin it understands. */
final class Plugin
{
    /** plugins_loaded priority 20: the free plugin has already loaded its classes by then. */
    public static function boot(): void
    {
        self::bootWith(
            defined( 'AI_EDITOR_DIVI5_VERSION' ) ? (string) AI_EDITOR_DIVI5_VERSION : null,
            static fn ( string $class ): bool => class_exists( $class )
        );
    }

    /**
     * @param callable(string):bool $classExists
     * @return bool whether the add-on was wired up
     */
    public static function bootWith( ?string $freeVersion, callable $classExists ): bool
    {
        $check = Compat::check( $freeVersion, $classExists );
        if ( ! $check['ok'] ) {
            if ( is_admin() ) {
                add_action( 'admin_notices', static function () use ( $check ): void {
                    printf(
                        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                        esc_html( 'JHMG AI Editor for Divi 5 Pro' ),
                        esc_html( $check['message'] )
                    );
                } );
            }

            return false;
        }
        self::wire();

        return true;
    }

    /** Everything that attaches to the free plugin or to WordPress; only reached when the free plugin is compatible. */
    private static function wire(): void
    {
    }
}
```

Create `pro-addon/src/autoload.php`:

```php
<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/Compat.php';
require_once __DIR__ . '/Plugin.php';
```

Create `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`:

```php
<?php
/**
 * Plugin Name:       JHMG AI Editor for Divi 5 Pro
 * Description:       Pro add-on for JHMG AI Editor for Divi 5: site-level control, Theme Builder templates and site-wide changes with one-step undo. Requires the free plugin.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * Author URI:        https://divi5lab.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jhmg-ai-editor-for-divi-5-pro
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIED_PRO_VERSION', '0.1.0' );
define( 'AIED_PRO_FILE', __FILE__ );
define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
define( 'AIED_PRO_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, AIED_PRO_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>JHMG AI Editor for Divi 5 Pro</strong> requires PHP %s or higher and has been disabled. Your server runs PHP %s.</p></div>',
            esc_html( AIED_PRO_MIN_PHP ),
            esc_html( PHP_VERSION )
        );
    } );

    return;
}

require_once __DIR__ . '/src/autoload.php';

add_action( 'plugins_loaded', [ AiEditorDivi5\Pro\Plugin::class, 'boot' ], 20 );
```

- [ ] **Step 4: Run the Pro suite**

Run: `make test-pro 2>&1 | tail -6`

Expected: `OK (...)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(pro): main plugin file, compatibility guard, boot, free-API contract tests

The add-on boots on plugins_loaded 20 only against a free plugin >= 4.4.0 whose
classes exist; otherwise it registers nothing and shows one escaped admin notice.
FreeApiCompatTest pins the free signatures and hooks the add-on will use.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Licensing, with the updater

**Files:**
- Create: `scripts/sync-pro-license-client.sh`
- Generate: `pro-addon/src/Licensing/LicenseClient.php` (overwritten by the sync)
- Rewrite: `pro-addon/src/Licensing.php`
- Move and adapt: `pro-addon/staged/LicensingTest.php` to `pro-addon/tests/LicensingTest.php`
- Create: `pro-addon/tests/LicenseClientFileTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`, `pro-addon/tests/PluginTest.php`

**Interfaces:**
- Consumes: the canonical client at `../layoutlab/lib/license-server/php-client/class-license-client.php` (read-only); Task 2's `Plugin::wire()`.
- Produces: `AiEditorDivi5\Pro\Licensing` with `client(): LicenseClient`, `isPremium(): bool`, `activate( string $key ): array{ok:bool,error:?string}`, `deactivate(): void`, `refresh( bool $force = false ): void`, `status(): array{valid:bool,status:?string,expires:?int,reason:string}`, `clear(): void`, `resetForTests(): void`, constant `UPGRADE_URL`. `AiEditorDivi5\Pro\Licensing\LicenseClient` has the updater (`inject_update`). Options: `aied_pro_license_key`, `aied_pro_license_state`, `aied_pro_update_blocked`, `aied_pro_premium_unlocked`. `Plugin::wire()` hooks `pre_set_site_transient_update_plugins`, `admin_init` and `admin_notices` (only on the free plugin's admin page).

- [ ] **Step 1: Adapt the staged licensing test (it will fail: the code is not reworked yet)**

```bash
git mv pro-addon/staged/LicensingTest.php pro-addon/tests/LicensingTest.php
python3 - <<'PY'
import re
p = 'pro-addon/tests/LicensingTest.php'
s = open(p, encoding='utf-8').read()

def sub(old, new, count=None):
    global s
    assert old in s, old
    if count is not None:
        assert s.count(old) == count, (old, s.count(old))
    s = s.replace(old, new)

sub("namespace Divi5Validator\\Tests;", "namespace AiEditorDivi5\\Pro\\Tests;")
sub("use AiEditorDivi5\\WP\\Licensing;", "use AiEditorDivi5\\Pro\\Licensing;")
sub("aied_license_state", "aied_pro_license_state", 2)

# the staged test asserted the WordPress.org variant had NO updater; the add-on's client has one
m = re.search(r"\n    public function testClientHasNoUpdaterForWordPressOrg\(\): void\n    \{.*?\n    \}\n", s, flags=re.S)
assert m
new_tests = '''
    public function testClientCarriesTheUpdater(): void
    {
        $this->assertTrue( method_exists( Licensing::client(), 'inject_update' ) );
    }

    public function testTheClientIsScopedToTheProAddOn(): void
    {
        $this->activatePremium();
        $this->assertArrayHasKey( 'aied_pro_license_key', $GLOBALS['__wp_options'] );
        $this->assertArrayNotHasKey( 'aied_license_key', $GLOBALS['__wp_options'], 'never collides with the old in-plugin licensing options' );
        $this->assertTrue( (bool) $GLOBALS['__wp_options']['aied_pro_premium_unlocked'] );
    }

    public function testTheUpdaterOnlyAcceptsAnHttpsPackageOnTheLicenceHost(): void
    {
        $this->activatePremium();
        $base = plugin_basename( AIED_PRO_FILE );
        foreach ( [ 'https://evil.example/pkg.zip', 'http://divi5lab.com/pkg.zip', 'https://divi5lab.com.evil.example/pkg.zip' ] as $package ) {
            $GLOBALS['__wp_transients'] = [];
            $this->queue( 200, [ 'update' => true, 'version' => '9.9.9', 'package' => $package ] );
            $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
            $this->assertSame( [], (array) $t->response, $package );
        }
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [ 'update' => true, 'version' => '9.9.9', 'package' => 'https://divi5lab.com/downloads/pro.zip' ] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertArrayHasKey( $base, (array) $t->response );
        $this->assertSame( '9.9.9', $t->response[ $base ]->new_version );
    }

    public function testTheUpdaterIgnoresAVersionThatIsNotNewer(): void
    {
        $this->activatePremium();
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [ 'update' => true, 'version' => AIED_PRO_VERSION, 'package' => 'https://divi5lab.com/downloads/pro.zip' ] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertSame( [], (array) $t->response );
    }

    public function testTheUpdaterToleratesAReplyWithNoUpdate(): void
    {
        $GLOBALS['__wp_transients'] = [];
        $this->queue( 200, [] );
        $t = Licensing::client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertIsObject( $t );
        $this->assertSame( [], (array) $t->response );
    }
'''
s = s[:m.start()] + new_tests + s[m.end():]
open(p, 'w', encoding='utf-8').write(s)
PY
php -l pro-addon/tests/LicensingTest.php
```

Create `pro-addon/tests/LicenseClientFileTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/** The licence client is generated from the canonical divi5lab client; nothing of another product may remain. */
class LicenseClientFileTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents( __DIR__ . '/../src/Licensing/LicenseClient.php' );
    }

    public function testItIsRewrittenForTheProAddOn(): void
    {
        $f = $this->source();
        $this->assertStringContainsString( 'namespace AiEditorDivi5\\Pro\\Licensing;', $f );
        $this->assertStringContainsString( "'jhmg-ai-editor-for-divi-5-pro'", $f );
        $this->assertStringContainsString( 'AI Editor for Divi 5 Pro', $f );
        $this->assertStringContainsString( 'tab=pro', $f );
        $this->assertStringContainsString( 'admin.php?page=', $f );
    }

    public function testNothingOfAnotherProductRemains(): void
    {
        $f = $this->source();
        foreach ( [ 'ElementorDivi5Converter', 'jhmg-converter-for-elementor-to-divi-pro', 'JHMG Converter Pro', 'tools.php', 'tab=license' ] as $stale ) {
            $this->assertStringNotContainsString( $stale, $f, $stale );
        }
    }

    public function testItCarriesTheUpdaterAndOnlyTheLicenceApiPaths(): void
    {
        $f = $this->source();
        $this->assertStringContainsString( 'function inject_update', $f );
        foreach ( [ '/api/license/activate', '/api/license/validate', '/api/license/deactivate', '/api/plugin/update-check' ] as $path ) {
            $this->assertStringContainsString( $path, $f, $path );
        }
    }
}
```

Add to `pro-addon/tests/PluginTest.php`, before the final `}`:

```php

    public function testACompatibleBootRegistersTheLicenceAndUpdateHooks(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        foreach ( [ 'pre_set_site_transient_update_plugins', 'admin_init', 'admin_notices' ] as $hook ) {
            $this->assertArrayHasKey( $hook, $GLOBALS['__wp_filters'], $hook );
        }
    }

    public function testTheAdminInitRefreshDoesNotCallTheServerWithoutAKey(): void
    {
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_http_log'] = [];
        Plugin::bootWith( '4.4.0', static fn (): bool => true );
        foreach ( $GLOBALS['__wp_filters']['admin_init'] as $fn ) {
            $fn();
        }
        $this->assertSame( [], $GLOBALS['__wp_http_log'], 'no licence key means no remote call' );
    }
```

- [ ] **Step 2: Run the Pro suite and confirm it fails**

Run: `make test-pro 2>&1 | tail -12`

Expected: errors: `AiEditorDivi5\Pro\Licensing` / `AiEditorDivi5\Pro\Licensing\LicenseClient` not found (the staged files still use the old namespaces) and the new `PluginTest` cases fail.

- [ ] **Step 3: Create the sync script and generate the client**

Create `scripts/sync-pro-license-client.sh`:

```bash
#!/usr/bin/env bash
#
# Generate the Pro add-on's licence client from the canonical divi5lab client in the separate
# `layoutlab` repo (read-only: this script never writes there). The canonical source hardcodes
# another product's namespace and text domain, so each consumer rewrites them with sed, exactly
# like layoutlab/scripts/sync-license-client.sh does for its own destinations.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${AIED_LICENSE_CLIENT_SRC:-$ROOT/../layoutlab/lib/license-server/php-client/class-license-client.php}"
DEST="$ROOT/pro-addon/src/Licensing/LicenseClient.php"

if [ ! -f "$SRC" ]; then
  echo "[sync] canonical licence client not found: $SRC" >&2
  echo "[sync] set AIED_LICENSE_CLIENT_SRC to its path" >&2
  exit 1
fi

mkdir -p "$(dirname "$DEST")"
sed \
  -e 's/ElementorDivi5Converter\\Pro\\Licensing/AiEditorDivi5\\Pro\\Licensing/g' \
  -e 's/jhmg-converter-for-elementor-to-divi-pro/jhmg-ai-editor-for-divi-5-pro/g' \
  -e 's/JHMG Converter Pro/AI Editor for Divi 5 Pro/g' \
  -e 's/tools\.php/admin.php/g' \
  -e 's/tab=license/tab=pro/g' \
  "$SRC" > "$DEST"

php -l "$DEST" >/dev/null
echo "[sync] wrote $DEST"
```

Run:

```bash
chmod +x scripts/sync-pro-license-client.sh
bash scripts/sync-pro-license-client.sh
git diff --stat pro-addon/src/Licensing/LicenseClient.php | tail -1
```

Expected: `[sync] wrote .../pro-addon/src/Licensing/LicenseClient.php`; the diff shows the file grew (the updater code is now present).

- [ ] **Step 4: Rewrite `Licensing.php` and wire it**

Overwrite `pro-addon/src/Licensing.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

use AiEditorDivi5\Pro\Licensing\LicenseClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Premium gate: an adapter over the divi5lab licence-server client.
 *
 * Enforcement: the first successful activation sets a persistent unlock; only an explicit server
 * verdict of `revoked` or `invalid` (invalid_key) re-locks. A lapse (expired/canceled/past_due) and
 * transient failures (offline/429/5xx) NEVER re-lock: lapsed customers keep what they activated and
 * only lose updates and support.
 */
final class Licensing
{
    private const UNLOCKED_OPTION = 'aied_pro_premium_unlocked';
    private const LOCKING         = [ 'revoked', 'invalid' ];

    public const UPGRADE_URL = 'https://divi5lab.com/plugins/divi-5-ai-editor';

    private static ?LicenseClient $client = null;

    public static function client(): LicenseClient
    {
        if ( null === self::$client ) {
            self::$client = new LicenseClient(
                AIED_PRO_PRODUCT,
                AIED_PRO_VERSION,
                defined( 'AIED_PRO_API_BASE' ) ? (string) AIED_PRO_API_BASE : 'https://divi5lab.com',
                plugin_basename( AIED_PRO_FILE ),
                'ai-editor-divi5',
                self::UPGRADE_URL,
                'aied_pro'
            );
        }

        return self::$client;
    }

    /** @internal test isolation only */
    public static function resetForTests(): void
    {
        self::$client = null;
    }

    public static function isPremium(): bool
    {
        if ( ! get_option( self::UNLOCKED_OPTION ) ) {
            return false;
        }
        $state = self::client()->get_state();

        return ! in_array( $state['status'] ?? '', self::LOCKING, true );
    }

    /** @return array{ok: bool, error: ?string} */
    public static function activate( string $key ): array
    {
        $res = self::client()->activate( trim( $key ) );
        if ( $res['ok'] ) {
            update_option( self::UNLOCKED_OPTION, 1, false );
        }

        return [ 'ok' => (bool) $res['ok'], 'error' => $res['error'] ];
    }

    public static function deactivate(): void
    {
        self::client()->deactivate();
        delete_option( self::UNLOCKED_OPTION );
    }

    public static function refresh( bool $force = false ): void
    {
        self::client()->refresh( $force );
    }

    /**
     * @return array{valid: bool, status: ?string, expires: ?int, reason: string}
     */
    public static function status(): array
    {
        if ( null === self::client()->get_key() ) {
            return [ 'valid' => false, 'status' => null, 'expires' => null, 'reason' => 'no_key' ];
        }
        $state   = self::client()->get_state();
        $status  = (string) ( $state['status'] ?? 'unknown' );
        $expires = null;
        if ( ! empty( $state['expires'] ) ) {
            $ts      = strtotime( (string) $state['expires'] );
            $expires = false !== $ts ? $ts : null;
        }

        return [ 'valid' => self::isPremium(), 'status' => $status, 'expires' => $expires, 'reason' => $status ];
    }

    /** Local-only wipe (uninstall). Never performs HTTP. */
    public static function clear(): void
    {
        foreach ( [ self::UNLOCKED_OPTION, 'aied_pro_license_key', 'aied_pro_license_state', 'aied_pro_update_blocked' ] as $option ) {
            delete_option( $option );
        }
    }
}
```

Apply the wiring edits:

```bash
python3 - <<'PY'
def edit(path, old, new):
    s = open(path, encoding='utf-8').read()
    assert old in s, (path, old)
    open(path, 'w', encoding='utf-8').write(s.replace(old, new, 1))

edit('pro-addon/src/autoload.php',
     "require_once __DIR__ . '/Compat.php';\n",
     "require_once __DIR__ . '/Compat.php';\nrequire_once __DIR__ . '/Licensing/LicenseClient.php';\nrequire_once __DIR__ . '/Licensing.php';\n")

edit('pro-addon/src/Plugin.php',
     "    private static function wire(): void\n    {\n    }\n",
     """    private static function wire(): void
    {
        add_filter( 'pre_set_site_transient_update_plugins', [ Licensing::client(), 'inject_update' ] );
        add_action( 'admin_init', static function (): void {
            Licensing::refresh();
        } );
        add_action( 'admin_notices', static function (): void {
            if ( 'ai-editor-divi5' === sanitize_key( wp_unslash( (string) ( $_GET['page'] ?? '' ) ) ) ) {
                Licensing::client()->status_notice();
            }
        } );
    }
""")
PY
php -l pro-addon/src/Plugin.php && php -l pro-addon/src/Licensing.php && php -l pro-addon/src/autoload.php
```

- [ ] **Step 5: Run the Pro suite**

Run: `make test-pro 2>&1 | tail -8`

Expected: `OK (...)` (all licensing matrix tests, the updater tests, the file tests and the plugin tests pass).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(pro): licensing with the updater (sticky unlock) and the licence-client sync

The client is generated from the canonical divi5lab client in the layoutlab repo
(read-only) with the add-on's namespace and text domain. Sticky-unlock adapter and
its matrix tests are ported; the updater only accepts an https package on the
licence host and a newer version. Wired on plugins_loaded: update filter, daily
refresh on admin_init, status notice on the plugin's own admin page only.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tool contract, licence-gated registry, and the (empty) tool list

**Files:**
- Create: `pro-addon/src/ProTool.php`, `pro-addon/src/ToolRegistry.php`, `pro-addon/src/Tools.php`, `pro-addon/tests/FakeTool.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`, `pro-addon/tests/PluginTest.php`
- Test: `pro-addon/tests/ToolRegistryTest.php`, `pro-addon/tests/ToolsTest.php`

**Interfaces:**
- Consumes: Task 3's `Licensing::isPremium()`; the free plugin's `jhmg_aied_mcp_tools` (filter, array in/out) and `jhmg_aied_mcp_call` (filter `( null $response, string $name, array $arguments, mixed $rpcId )`), and `ExtensionGuard::tools()`.
- Produces:
  - `interface ProTool { name(): string; capability(): string; definition(): array; run( array $arguments ): array }`. A tool returns its result payload; it throws `\InvalidArgumentException( $safeMessage )` for a bad argument (mapped to JSON-RPC `-32602` with that message); any other `\Throwable` becomes `-32603` with a fixed message.
  - `new ToolRegistry( list<ProTool> $tools, \Closure $unlocked )` with `definitions( mixed $tools ): mixed`, `claim( mixed $response, string $name, array $arguments, mixed $rpcId ): mixed`, `names(): list<string>`, `register(): void`.
  - `Tools::all(): list<ProTool>` (empty now).

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/FakeTool.php` (a configurable stand-in for a Pro tool, shared by `ToolRegistryTest` and `ProPageTest`):

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;

require_once __DIR__ . '/../src/ProTool.php';

/** A configurable stand-in for a Pro tool. */
final class FakeTool implements ProTool
{
    public int $runs = 0;

    /** @param array<string,mixed> $payload */
    public function __construct(
        private string $name = 'fake_pro_tool',
        private string $capability = 'manage_options',
        private ?\Throwable $throws = null,
        private array $payload = [ 'ok' => true ]
    ) {
    }

    public function name(): string { return $this->name; }

    public function capability(): string { return $this->capability; }

    public function definition(): array
    {
        return [ 'name' => $this->name, 'description' => 'A fake Pro tool.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ] ];
    }

    public function run( array $arguments ): array
    {
        $this->runs++;
        if ( null !== $this->throws ) {
            throw $this->throws;
        }

        return $this->payload + [ 'args' => $arguments ];
    }
}
```

Create `pro-addon/tests/ToolRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\WP\ExtensionGuard;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/ProTool.php';
require_once __DIR__ . '/../src/ToolRegistry.php';
require_once __DIR__ . '/FakeTool.php';

/** The registry is the only place the add-on talks to the free plugin's tool hooks. */
class ToolRegistryTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->unlocked = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @param list<ProTool> $tools */
    private function registry( array $tools ): ToolRegistry
    {
        return new ToolRegistry( $tools, fn (): bool => $this->unlocked );
    }

    /** @return array<string,mixed> */
    private function data( mixed $response ): array
    {
        $this->assertInstanceOf( \WP_REST_Response::class, $response );

        return (array) $response->get_data();
    }

    public function testLockedMeansNoToolIsAdvertisedOrClaimed(): void
    {
        $this->unlocked = false;
        $tool           = new FakeTool();
        $r              = $this->registry( [ $tool ] );
        $this->assertSame( [], $r->definitions( [] ) );
        $this->assertNull( $r->claim( null, 'fake_pro_tool', [], 1 ) );
        $this->assertSame( 0, $tool->runs );
    }

    public function testUnlockedAppendsTheToolDefinitionsAfterTheBuiltIns(): void
    {
        $r    = $this->registry( [ new FakeTool() ] );
        $out  = $r->definitions( [ [ 'name' => 'get_page_layout' ] ] );
        $this->assertSame( [ 'get_page_layout', 'fake_pro_tool' ], array_column( $out, 'name' ) );
        $this->assertSame( [ 'fake_pro_tool' ], $r->names() );
    }

    public function testAnUnusableToolListIsReturnedUntouched(): void
    {
        $r = $this->registry( [ new FakeTool() ] );
        $this->assertSame( 'oops', $r->definitions( 'oops' ) );
        $this->assertNull( $r->definitions( null ) );
    }

    public function testABuiltInToolAlwaysWinsACollisionThroughTheFreePluginsGuard(): void
    {
        $builtIn = [ [ 'name' => 'get_page_layout', 'description' => 'built-in' ] ];
        $r       = $this->registry( [ new FakeTool( 'get_page_layout' ) ] );
        $merged  = ExtensionGuard::tools( $builtIn, $r->definitions( $builtIn ) );
        $this->assertCount( 1, $merged );
        $this->assertSame( 'built-in', $merged[0]['description'] );
    }

    public function testOnlyItsOwnToolNamesAreClaimed(): void
    {
        $tool = new FakeTool();
        $r    = $this->registry( [ $tool ] );
        $this->assertNull( $r->claim( null, 'some_other_tool', [], 1 ), 'foreign names fall through' );
        $other = new \WP_REST_Response( [ 'claimed' => 'by another add-on' ] );
        $this->assertSame( $other, $r->claim( $other, 'fake_pro_tool', [], 1 ), 'an earlier add-on keeps its claim' );
        $this->assertSame( 0, $tool->runs );
    }

    public function testASuccessfulCallReturnsTheBuiltInResponseShape(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 2 ] );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [ 'x' => 1 ], 'req-9' ) );
        $this->assertSame( '2.0', $d['jsonrpc'] );
        $this->assertSame( 'req-9', $d['id'] );
        $this->assertSame( 'text', $d['result']['content'][0]['type'] );
        $this->assertSame( [ 'done' => 2, 'args' => [ 'x' => 1 ] ], json_decode( $d['result']['content'][0]['text'], true ) );
        $this->assertSame( 1, $tool->runs );
    }

    public function testAUserWithoutTheCapabilityIsRefusedAndTheToolNeverRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];   // manage_options is not granted
        $tool                 = new FakeTool();
        $d                    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 3 ) );
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertStringContainsString( 'fake_pro_tool', $d['error']['message'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testABadArgumentIsAJsonRpcInvalidParamsErrorWithTheToolsOwnMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 4 ) );
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertSame( '"page_id" must be an integer.', $d['error']['message'] );
    }

    public function testAnUnexpectedFailureNeverLeaksItsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET db password=hunter2 at /var/www/x.php' ) );
        $d    = $this->data( $this->registry( [ $tool ] )->claim( null, 'fake_pro_tool', [], 5 ) );
        $this->assertSame( -32603, $d['error']['code'] );
        $this->assertStringNotContainsString( 'SECRET', (string) json_encode( $d ) );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $d ) );
    }

    public function testALicenceRevokedBetweenListingAndCallingStopsTheCall(): void
    {
        $tool = new FakeTool();
        $r    = $this->registry( [ $tool ] );
        $this->assertSame( [ 'fake_pro_tool' ], array_column( $r->definitions( [] ), 'name' ) );
        $this->unlocked = false;
        $this->assertNull( $r->claim( null, 'fake_pro_tool', [], 6 ) );
        $this->assertSame( 0, $tool->runs );
    }

    public function testRegisterAttachesToTheFreePluginsToolHooks(): void
    {
        $tool = new FakeTool();
        $this->registry( [ $tool ] )->register();
        $list = apply_filters( 'jhmg_aied_mcp_tools', [] );
        $this->assertSame( [ 'fake_pro_tool' ], array_column( $list, 'name' ) );
        $res = apply_filters( 'jhmg_aied_mcp_call', null, 'fake_pro_tool', [], 7 );
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 1, $tool->runs );
    }
}
```

Create `pro-addon/tests/ToolsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ProTool.php';
require_once __DIR__ . '/../src/Tools.php';

/** Guards the tool list as the next plans fill it: no shadowing, consistent names, and the OpenAPI operation budget. */
class ToolsTest extends TestCase
{
    /** @return list<string> */
    private function builtInToolNames(): array
    {
        $mcp = (string) file_get_contents( __DIR__ . '/../../wp-plugin/src/McpHandler.php' );
        preg_match_all( "/^\s+'name'\s+=> '([a-z_]+)',$/m", $mcp, $m );

        return $m[1];
    }

    public function testEveryToolIsAProToolWithAMatchingDefinition(): void
    {
        foreach ( Tools::all() as $tool ) {
            $this->assertInstanceOf( ProTool::class, $tool );
            $this->assertSame( $tool->name(), $tool->definition()['name'] );
            $this->assertNotSame( '', $tool->capability() );
        }
        $this->assertTrue( true, 'the list may be empty before the tool plans land' );
    }

    public function testNoToolShadowsABuiltInOrAnotherProTool(): void
    {
        $names = array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() );
        $this->assertSame( [], array_intersect( $names, $this->builtInToolNames() ), 'a Pro tool must not reuse a built-in name' );
        $this->assertSame( $names, array_values( array_unique( $names ) ), 'Pro tool names are unique' );
    }

    public function testTheChatGptOperationBudgetIsRespected(): void
    {
        // The ChatGPT importer caps an OpenAPI spec at 30 operations: free built-ins plus Pro tools must fit.
        $this->assertLessThanOrEqual( 29, count( $this->builtInToolNames() ) + count( Tools::all() ) );
    }
}
```

Add to `pro-addon/tests/PluginTest.php`, before the final `}`:

```php

    public function testACompatibleBootRegistersTheToolHooksWithNoToolsYet(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'jhmg_aied_mcp_tools', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_mcp_call', $GLOBALS['__wp_filters'] );
        $this->assertSame( [ [ 'name' => 'get_page_layout' ] ], apply_filters( 'jhmg_aied_mcp_tools', [ [ 'name' => 'get_page_layout' ] ] ), 'no Pro tools exist yet' );
    }
```

- [ ] **Step 2: Run and confirm they fail**

Run: `make test-pro 2>&1 | tail -10`

Expected: the run aborts with a PHP fatal (`Failed opening required '.../pro-addon/src/ProTool.php'`): the new tests cannot load classes that do not exist yet.

- [ ] **Step 3: Write the code**

Create `pro-addon/src/ProTool.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Pro MCP tool. The registry owns advertising, the licence gate, the capability check and the
 * JSON-RPC shapes; a tool only declares itself and does its work.
 */
interface ProTool
{
    /** The MCP tool name. It must not equal a built-in tool name (the free plugin drops such a collision). */
    public function name(): string;

    /** The WordPress capability the current user needs (the free plugin only authenticates; the add-on authorises). */
    public function capability(): string;

    /** @return array{name:string, description:string, inputSchema:array<string,mixed>} */
    public function definition(): array;

    /**
     * Do the work. Type-check every argument: throw \InvalidArgumentException with a message that is safe to
     * show the client for a bad argument. Any other throwable is reported as a generic internal error.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> the result payload (JSON-encoded by the registry)
     */
    public function run( array $arguments ): array;
}
```

Create `pro-addon/src/ToolRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only place the add-on talks to the free plugin's tool hooks. Tools are advertised and callable only
 * while the licence is unlocked; each call re-checks the unlock and the tool's capability.
 */
final class ToolRegistry
{
    /** @var array<string,ProTool> */
    private array $tools = [];

    /**
     * @param list<ProTool> $tools
     * @param \Closure():bool $unlocked
     */
    public function __construct( array $tools, private \Closure $unlocked )
    {
        foreach ( $tools as $tool ) {
            $this->tools[ $tool->name() ] = $tool;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys( $this->tools );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_mcp_tools', [ $this, 'definitions' ] );
        add_filter( 'jhmg_aied_mcp_call', [ $this, 'claim' ], 10, 4 );
    }

    /** `jhmg_aied_mcp_tools`: append the Pro tool definitions while unlocked. */
    public function definitions( mixed $tools ): mixed
    {
        if ( ! is_array( $tools ) || ! ( $this->unlocked )() ) {
            return $tools;
        }
        foreach ( $this->tools as $tool ) {
            $tools[] = $tool->definition();
        }

        return $tools;
    }

    /**
     * `jhmg_aied_mcp_call`: claim a call for one of our tools, or return `$response` untouched.
     *
     * @param array<string,mixed> $arguments
     */
    public function claim( mixed $response, string $name, array $arguments, mixed $rpcId ): mixed
    {
        if ( null !== $response || ! isset( $this->tools[ $name ] ) || ! ( $this->unlocked )() ) {
            return $response;
        }
        $tool = $this->tools[ $name ];
        if ( ! current_user_can( $tool->capability() ) ) {
            return self::error( $rpcId, -32602, "You do not have permission to use {$name}." );
        }
        try {
            $payload = $tool->run( $arguments );
        } catch ( \InvalidArgumentException $e ) {
            return self::error( $rpcId, -32602, $e->getMessage() );
        } catch ( \Throwable $e ) {
            return self::error( $rpcId, -32603, 'The tool failed unexpectedly.' );
        }

        return new \WP_REST_Response(
            [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $payload ) ] ] ] ],
            200
        );
    }

    private static function error( mixed $rpcId, int $code, string $message ): \WP_REST_Response
    {
        return new \WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 );
    }
}
```

Create `pro-addon/src/Tools.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The list of Pro tools. Empty in this release of the skeleton; the tool plans add to it. */
final class Tools
{
    /** @return list<ProTool> */
    public static function all(): array
    {
        return [];
    }
}
```

Wire it:

```bash
python3 - <<'PY'
def edit(path, old, new):
    s = open(path, encoding='utf-8').read()
    assert old in s, (path, old)
    open(path, 'w', encoding='utf-8').write(s.replace(old, new, 1))

edit('pro-addon/src/autoload.php',
     "require_once __DIR__ . '/Licensing.php';\n",
     "require_once __DIR__ . '/Licensing.php';\nrequire_once __DIR__ . '/ProTool.php';\nrequire_once __DIR__ . '/ToolRegistry.php';\nrequire_once __DIR__ . '/Tools.php';\n")

edit('pro-addon/src/Plugin.php',
     """                Licensing::client()->status_notice();
            }
        } );
    }
""",
     """                Licensing::client()->status_notice();
            }
        } );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $registry->register();
    }
""")
PY
php -l pro-addon/src/ToolRegistry.php && php -l pro-addon/src/Plugin.php
```

- [ ] **Step 4: Run the Pro suite**

Run: `make test-pro 2>&1 | tail -8`

Expected: `OK (...)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(pro): licence-gated ToolRegistry on the free plugin's tool hooks

Tools are advertised and callable only while the licence is unlocked (rechecked at
call time); each call checks the tool's capability; a bad argument is a -32602
with the tool's own message; any other failure is a generic -32603 that never
leaks exception text. Tools::all() is empty until the tool plans land.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The Pro admin tab

**Files:**
- Create: `pro-addon/src/ProPage.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`, `pro-addon/tests/PluginTest.php`
- Test: `pro-addon/tests/ProPageTest.php`

**Interfaces:**
- Consumes: Task 3's `Licensing`; Task 4's `ToolRegistry::names()`; the free plugin's `jhmg_aied_admin_tabs` (filter, slug => label) and `jhmg_aied_render_admin_tab` (action, receives the tab slug), and its admin page slug `ai-editor-divi5` (it renders the tab inside its own shell for users with `manage_options`).
- Produces: `new ProPage( ToolRegistry $registry, ?\Closure $terminate = null )` with `register(): void`, `tabs( mixed $tabs ): mixed`, `render( string $tab ): void`, `handleActivate(): void`, `handleDeactivate(): void`, `static maskKey( string $key ): string`; constants `TAB` (`pro`), `MENU_SLUG`, `ACTION_ACTIVATE` (`aied_pro_activate`), `ACTION_DEACTIVATE` (`aied_pro_deactivate`). The `$terminate` closure runs after each redirect (default: `exit`), so tests can disable it.

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/ProPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing;
use AiEditorDivi5\Pro\ProPage;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';

class ProPageTest extends TestCase
{
    private const KEY = 'JHMG-ABCD-EFGH-IJKL-MNOP';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_http_queue'] = [];
        $GLOBALS['__wp_http_log']   = [];
        $GLOBALS['__wp_redirects']  = [];
        $GLOBALS['__wp_filters']    = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET                      = [];
        $_POST                     = [];
        $_REQUEST                  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Licensing::resetForTests();
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<FakeTool> $tools */
    private function page( array $tools = [] ): ProPage
    {
        return new ProPage( new ToolRegistry( $tools, static fn (): bool => Licensing::isPremium() ), static function (): void {} );
    }

    private function render( ProPage $page, string $tab = 'pro' ): string
    {
        ob_start();
        $page->render( $tab );

        return (string) ob_get_clean();
    }

    private function activated(): void
    {
        update_option( 'aied_pro_license_key', self::KEY );
        update_option( 'aied_pro_license_state', [ 'status' => 'active', 'expires' => '2027-07-12T00:00:00.000Z', 'checked_at' => time() ] );
        update_option( 'aied_pro_premium_unlocked', 1 );
        Licensing::resetForTests();
    }

    private function asPost( string $action, array $post = [] ): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = $post;
        $_REQUEST                  = $post + [ '_wpnonce' => 'nonce-for-' . $action ];
    }

    // ---- registration ----------------------------------------------------------------------

    public function testTheTabIsAddedAndOtherTabsAreKept(): void
    {
        $page = $this->page();
        $this->assertSame( [ 'x' => 'X', 'pro' => 'Pro' ], $page->tabs( [ 'x' => 'X' ] ) );
        $this->assertSame( 'junk', $page->tabs( 'junk' ) );
    }

    public function testRegisterAttachesTheFreePluginsTabHooksAndTheTwoAdminPostActions(): void
    {
        $this->page()->register();
        $this->assertSame( [ 'pro' => 'Pro' ], apply_filters( 'jhmg_aied_admin_tabs', [] ) );
        foreach ( [ 'jhmg_aied_render_admin_tab', 'admin_post_aied_pro_activate', 'admin_post_aied_pro_deactivate' ] as $hook ) {
            $this->assertArrayHasKey( $hook, $GLOBALS['__wp_filters'], $hook );
        }
    }

    // ---- rendering -------------------------------------------------------------------------

    public function testItRendersOnlyItsOwnTabAndOnlyForAdministrators(): void
    {
        $this->assertSame( '', $this->render( $this->page(), 'features' ) );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->render( $this->page() ), 'manage_options is required' );
    }

    public function testWithoutALicenceItOffersActivationAndShowsNoTools(): void
    {
        $html = $this->render( $this->page() );
        $this->assertStringContainsString( 'No licence activated', $html );
        $this->assertStringContainsString( 'name="license_key"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_activate', $html );
        $this->assertStringNotContainsString( 'nonce-for-aied_pro_deactivate', $html );
        $this->assertStringContainsString( 'Activate a licence to unlock the Pro tools', $html );
    }

    public function testWithALicenceItShowsStatusExpiryAndANeverFullKey(): void
    {
        $this->activated();
        $html = $this->render( $this->page() );
        $this->assertStringContainsString( 'Active', $html );
        $this->assertStringContainsString( '2027-07-12', $html );
        $this->assertStringContainsString( 'MNOP', $html );
        $this->assertStringNotContainsString( self::KEY, $html );
        $this->assertStringNotContainsString( 'JHMG-ABCD', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_deactivate', $html );
        $this->assertStringNotContainsString( 'name="license_key"', $html );
    }

    public function testUnlockedItListsTheToolsOrSaysThereAreNone(): void
    {
        $this->activated();
        $this->assertStringContainsString( 'No Pro tools are included in this version yet', $this->render( $this->page() ) );
        $this->assertStringContainsString( '<code>fake_pro_tool</code>', $this->render( $this->page( [ new FakeTool() ] ) ) );
    }

    public function testALapsedLicenceIsHonestButKeepsTheToolsListed(): void
    {
        $this->activated();
        update_option( 'aied_pro_license_state', [ 'status' => 'expired', 'expires' => '2026-01-01T00:00:00.000Z', 'checked_at' => time() ] );
        Licensing::resetForTests();
        $html = $this->render( $this->page( [ new FakeTool() ] ) );
        $this->assertStringContainsString( 'Expired', $html );
        $this->assertStringContainsString( '<code>fake_pro_tool</code>', $html, 'a lapse never locks' );
    }

    public function testANoticeFromTheRedirectIsShownAndEscaped(): void
    {
        $_GET['aied_pro_notice'] = 'activated';
        $this->assertStringContainsString( 'Licence activated', $this->render( $this->page() ) );
        $_GET['aied_pro_notice'] = '<script>alert(1)</script>';
        $html                    = $this->render( $this->page() );
        $this->assertStringNotContainsString( '<script>', $html );
        $_GET['aied_pro_notice'] = 'not-a-notice';
        $this->assertStringNotContainsString( 'notice-success', $this->render( $this->page() ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'keys' )]
    public function testMaskKeyNeverRevealsMoreThanTheLastFourCharacters( string $key, string $masked ): void
    {
        $this->assertSame( $masked, ProPage::maskKey( $key ) );
    }

    /** @return list<array{string,string}> */
    public static function keys(): array
    {
        return [
            [ '', '' ],
            [ 'abc', '***' ],
            [ 'abcd', '****' ],
            [ 'abcde', '*bcde' ],
            [ 'JHMG-ABCD-EFGH-IJKL-MNOP', '********************MNOP' ],
        ];
    }

    // ---- handlers --------------------------------------------------------------------------

    public function testActivationRefusesWithoutManageOptions(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->expectExceptionMessage( 'wp_die' );
        $this->page()->handleActivate();
    }

    public function testActivationRefusesABadNonce(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY, '_wpnonce' => 'forged' ] );
        $this->expectExceptionMessage( 'check_admin_referer failed' );
        $this->page()->handleActivate();
    }

    public function testActivationRefusesANonPostRequest(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectExceptionMessage( 'wp_die' );
        $this->page()->handleActivate();
    }

    public function testAnEmptyKeyRedirectsWithoutCallingTheServer(): void
    {
        $this->asPost( 'aied_pro_activate', [ 'license_key' => '   ' ] );
        $this->page()->handleActivate();
        $this->assertCount( 1, $GLOBALS['__wp_redirects'] );
        $this->assertStringContainsString( 'aied_pro_notice=empty_key', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testASuccessfulActivationUnlocksAndRedirectsToTheProTab(): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 200, 'body' => [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => null ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => '  ' . self::KEY . ' ' ] );
        $this->page()->handleActivate();
        $this->assertTrue( Licensing::isPremium() );
        $url = $GLOBALS['__wp_redirects'][0];
        $this->assertStringContainsString( 'page=ai-editor-divi5', $url );
        $this->assertStringContainsString( 'tab=pro', $url );
        $this->assertStringContainsString( 'aied_pro_notice=activated', $url );
        $this->assertSame( self::KEY, json_decode( (string) $GLOBALS['__wp_http_log'][0]['payload'], true )['key'], 'the key is trimmed' );
    }

    public function testAFailedActivationStaysLocked(): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 404, 'body' => [ 'error' => 'invalid_key' ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertFalse( Licensing::isPremium() );
        $this->assertStringContainsString( 'aied_pro_notice=activate_failed', $GLOBALS['__wp_redirects'][0] );
    }

    public function testDeactivationReleasesTheLicenceAndLocks(): void
    {
        $this->activated();
        $GLOBALS['__wp_http_queue'][] = [ 'code' => 200, 'body' => [ 'ok' => true ] ];
        $this->asPost( 'aied_pro_deactivate' );
        $this->page()->handleDeactivate();
        $this->assertFalse( Licensing::isPremium() );
        $this->assertStringContainsString( '/api/license/deactivate', $GLOBALS['__wp_http_log'][0]['url'] );
        $this->assertStringContainsString( 'aied_pro_notice=deactivated', $GLOBALS['__wp_redirects'][0] );
    }

    public function testDeactivationRefusesBadNonceAndMissingCapability(): void
    {
        $this->activated();
        $this->asPost( 'aied_pro_deactivate', [ '_wpnonce' => 'forged' ] );
        try {
            $this->page()->handleDeactivate();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }
        $this->assertTrue( Licensing::isPremium(), 'nothing was changed' );

        $this->asPost( 'aied_pro_deactivate' );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        try {
            $this->page()->handleDeactivate();
            $this->fail( 'a user without manage_options must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertTrue( Licensing::isPremium() );
    }
}
```

Add to `pro-addon/tests/PluginTest.php`, before the final `}`:

```php

    public function testACompatibleBootRegistersTheProAdminTab(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertSame( [ 'pro' => 'Pro' ], apply_filters( 'jhmg_aied_admin_tabs', [] ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_activate', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_deactivate', $GLOBALS['__wp_filters'] );
    }
```

- [ ] **Step 2: Run and confirm they fail**

Run: `make test-pro 2>&1 | tail -10`

Expected: errors: `ProPage` not found (`ProPageTest`, `PluginTest::testACompatibleBootRegistersTheProAdminTab`). `ToolRegistryTest` still passes with the shared `FakeTool`.

- [ ] **Step 3: Write `ProPage.php` and wire it**

Create `pro-addon/src/ProPage.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Pro" tab inside the free plugin's admin page: licence entry and the list of Pro tools. Both
 * handlers are POST-only admin-post actions that need `manage_options` and a valid nonce.
 */
final class ProPage
{
    public const TAB               = 'pro';
    public const MENU_SLUG         = 'ai-editor-divi5';
    public const ACTION_ACTIVATE   = 'aied_pro_activate';
    public const ACTION_DEACTIVATE = 'aied_pro_deactivate';

    private \Closure $terminate;

    public function __construct( private ToolRegistry $registry, ?\Closure $terminate = null )
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_admin_tabs', [ $this, 'tabs' ] );
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_ACTIVATE, [ $this, 'handleActivate' ] );
        add_action( 'admin_post_' . self::ACTION_DEACTIVATE, [ $this, 'handleDeactivate' ] );
    }

    /** `jhmg_aied_admin_tabs`: add the Pro tab (always, so a locked add-on can still take a licence key). */
    public function tabs( mixed $tabs ): mixed
    {
        if ( ! is_array( $tabs ) ) {
            return $tabs;
        }
        $tabs[ self::TAB ] = __( 'Pro', 'jhmg-ai-editor-for-divi-5-pro' );

        return $tabs;
    }

    /** Never more than the last four characters of the key. */
    public static function maskKey( string $key ): string
    {
        $len = strlen( $key );
        if ( $len <= 4 ) {
            return str_repeat( '*', $len );
        }

        return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
    }

    /** `jhmg_aied_render_admin_tab` */
    public function render( string $tab ): void
    {
        if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status  = Licensing::status();
        $key     = Licensing::client()->get_key();
        $premium = Licensing::isPremium();
        $names   = $this->registry->names();
        $notices = [
            'activated'       => [ 'success', __( 'Licence activated. The Pro tools are unlocked.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'activate_failed' => [ 'error', __( 'The licence could not be activated. Check the key and try again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'empty_key'       => [ 'error', __( 'Enter your licence key.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'deactivated'     => [ 'success', __( 'Licence deactivated on this site.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        $notice = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro">
            <h2><?php esc_html_e( 'Pro licence', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>
            <p>
                <strong><?php echo esc_html( self::statusLabel( $status['reason'] ) ); ?></strong>
                <?php if ( null !== $status['expires'] ) : ?>
                    &middot; <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'renews or expires %s', 'jhmg-ai-editor-for-divi-5-pro' ), gmdate( 'Y-m-d', $status['expires'] ) ) ); ?>
                <?php endif; ?>
            </p>
            <?php if ( null !== $key ) : ?>
                <p><?php esc_html_e( 'Licence key:', 'jhmg-ai-editor-for-divi-5-pro' ); ?> <code><?php echo esc_html( self::maskKey( $key ) ); ?></code></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DEACTIVATE ); ?>">
                    <?php wp_nonce_field( self::ACTION_DEACTIVATE ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Deactivate on this site', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ACTIVATE ); ?>">
                    <?php wp_nonce_field( self::ACTION_ACTIVATE ); ?>
                    <p><input type="text" name="license_key" class="regular-text" autocomplete="off" placeholder="JHMG-XXXX-XXXX-XXXX-XXXX"></p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate licence', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="aied-card aied-pro">
            <h2><?php esc_html_e( 'Pro tools', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( ! $premium ) : ?>
                <p><?php esc_html_e( 'Activate a licence to unlock the Pro tools.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php elseif ( [] === $names ) : ?>
                <p><?php esc_html_e( 'No Pro tools are included in this version yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php else : ?>
                <ul>
                    <?php foreach ( $names as $name ) : ?>
                        <li><code><?php echo esc_html( $name ); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleActivate(): void
    {
        $this->guard( self::ACTION_ACTIVATE );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $key = isset( $_POST['license_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['license_key'] ) ) ) : '';
        if ( '' === $key ) {
            $this->redirect( 'empty_key' );

            return;
        }
        $res = Licensing::activate( $key );
        $this->redirect( $res['ok'] ? 'activated' : 'activate_failed' );
    }

    public function handleDeactivate(): void
    {
        $this->guard( self::ACTION_DEACTIVATE );
        Licensing::deactivate();
        $this->redirect( 'deactivated' );
    }

    private function guard( string $nonceAction ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        check_admin_referer( $nonceAction );
    }

    private function redirect( string $notice ): void
    {
        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => self::TAB, 'aied_pro_notice' => $notice ], admin_url( 'admin.php' ) ) );
        ( $this->terminate )();
    }

    private static function statusLabel( string $reason ): string
    {
        return match ( $reason ) {
            'no_key'   => __( 'No licence activated', 'jhmg-ai-editor-for-divi-5-pro' ),
            'active'   => __( 'Active', 'jhmg-ai-editor-for-divi-5-pro' ),
            'expired'  => __( 'Expired (your Pro tools keep working; updates and support stop)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'canceled' => __( 'Cancelled (your Pro tools keep working; updates and support stop)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'revoked'  => __( 'Revoked (the Pro tools are locked)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'invalid'  => __( 'Invalid (the Pro tools are locked)', 'jhmg-ai-editor-for-divi-5-pro' ),
            default    => __( 'Status unknown', 'jhmg-ai-editor-for-divi-5-pro' ),
        };
    }
}
```

```bash
python3 - <<'PY'
def edit(path, old, new):
    s = open(path, encoding='utf-8').read()
    assert old in s, (path, old)
    open(path, 'w', encoding='utf-8').write(s.replace(old, new, 1))

edit('pro-addon/src/autoload.php',
     "require_once __DIR__ . '/Tools.php';\n",
     "require_once __DIR__ . '/Tools.php';\nrequire_once __DIR__ . '/ProPage.php';\n")

edit('pro-addon/src/Plugin.php',
     "        $registry->register();\n    }\n",
     "        $registry->register();\n\n        ( new ProPage( $registry ) )->register();\n    }\n")
PY
php -l pro-addon/src/ProPage.php && php -l pro-addon/src/Plugin.php
```

- [ ] **Step 4: Run the Pro suite**

Run: `make test-pro 2>&1 | tail -8`

Expected: `OK (...)`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(pro): the Pro admin tab (licence entry, tool list) on the free plugin's tab hooks

Activate and deactivate are POST-only admin-post actions that need manage_options
and a valid nonce; the licence key is never shown in full; a lapsed licence is shown
honestly and keeps the tools listed.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Safety scan, uninstall, zip, dev-site mount and live check, docs

**Files:**
- Create: `pro-addon/tests/ProSafetyScanner.php`, `pro-addon/tests/ProSafetyTest.php`, `pro-addon/tests/UninstallTest.php`, `pro-addon/uninstall.php`, `scripts/build-pro-zip.sh`, `scripts/verify-pro-skeleton.php`
- Modify: `Makefile`, `docker-compose.yml`, `.gitignore`, `pro-addon/README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: everything from Tasks 1 to 5.
- Produces: `ProSafetyScanner::violations( string $php ): list<string>`, `::hosts( string $php ): list<string>`, `::usesRemoteHttp( string $php ): bool`; `make build-pro` writing `dist/jhmg-ai-editor-for-divi-5-pro.zip` (not committed); `make verify-pro-skeleton`; the Pro plugin folder mounted into the dev site's WordPress and wp-cli containers.

- [ ] **Step 1: Write the failing safety and uninstall tests**

Create `pro-addon/tests/ProSafetyScanner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

/** Static checks over the add-on's PHP source (comments are ignored). */
final class ProSafetyScanner
{
    /** @var array<string,string> label => pattern */
    private const BANNED = [
        'eval'            => '/\beval\s*\(/i',
        'exec family'     => '/\b(?:shell_exec|exec|passthru|proc_open|popen|system|pcntl_exec)\s*\(/i',
        'create_function' => '/\bcreate_function\s*\(/i',
        'unserialize'     => '/\bunserialize\s*\(/i',
        'write users'     => '/\b(?:wp_insert_user|wp_create_user|wp_update_user|wp_delete_user)\s*\(/i',
        'manage plugins'  => '/\b(?:activate_plugin|activate_plugins|deactivate_plugins|delete_plugins|plugins_api)\b/i',
        'divi presets'    => '/(?:et_divi_builder_global_presets_d5|et_global_data|et_divi_global_variables|GlobalPreset::)/',
    ];

    /** @return list<string> labels of the banned constructs found */
    public static function violations( string $php ): array
    {
        $code  = self::withoutComments( $php );
        $found = [];
        foreach ( self::BANNED as $label => $pattern ) {
            if ( 1 === preg_match( $pattern, $code ) ) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** @return list<string> the host of every http(s) URL inside a PHP string literal */
    public static function hosts( string $php ): array
    {
        $hosts = [];
        foreach ( token_get_all( $php ) as $tok ) {
            if ( is_array( $tok ) && in_array( $tok[0], [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ], true ) ) {
                if ( preg_match_all( '#https?://([a-z0-9.-]+)#i', $tok[1], $m ) ) {
                    foreach ( $m[1] as $host ) {
                        $hosts[] = strtolower( $host );
                    }
                }
            }
        }

        return array_values( array_unique( $hosts ) );
    }

    public static function usesRemoteHttp( string $php ): bool
    {
        return 1 === preg_match( '/\b(?:wp_remote_(?:get|post|request|head)|wp_safe_remote_\w+|curl_init|curl_exec|fsockopen|stream_socket_client)\s*\(/i', self::withoutComments( $php ) );
    }

    private static function withoutComments( string $php ): string
    {
        $out = '';
        foreach ( token_get_all( $php ) as $tok ) {
            if ( is_array( $tok ) ) {
                if ( in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }
}
```

Create `pro-addon/tests/ProSafetyTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ProSafetyScanner.php';

/**
 * The add-on is not on WordPress.org, but its promises are the same: it never runs code it did not ship,
 * never writes users, plugins or Divi presets, and only talks to the licence host, from one file.
 */
class ProSafetyTest extends TestCase
{
    private const ROOT          = __DIR__ . '/..';
    private const ALLOWED_HOSTS = [ 'divi5lab.com' ];
    private const REMOTE_FILE   = 'src/Licensing/LicenseClient.php';

    /** @return array<string,array{string}> */
    public static function files(): array
    {
        $out = [
            'jhmg-ai-editor-for-divi-5-pro.php' => [ 'jhmg-ai-editor-for-divi-5-pro.php' ],
            'uninstall.php'                     => [ 'uninstall.php' ],
        ];
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( 'php' === $file->getExtension() ) {
                $rel         = 'src/' . substr( $file->getPathname(), strlen( self::ROOT . '/src/' ) );
                $out[ $rel ] = [ $rel ];
            }
        }
        ksort( $out );

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testNoShippedFileUsesABannedConstruct( string $rel ): void
    {
        $this->assertSame( [], \AiEditorDivi5\Pro\Tests\ProSafetyScanner::violations( (string) file_get_contents( self::ROOT . '/' . $rel ) ), $rel );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'files' )]
    public function testNoShippedFileMentionsAnotherHost( string $rel ): void
    {
        $hosts = ProSafetyScanner::hosts( (string) file_get_contents( self::ROOT . '/' . $rel ) );
        $this->assertSame( [], array_values( array_diff( $hosts, self::ALLOWED_HOSTS ) ), $rel );
    }

    public function testOnlyTheLicenceClientCanCallOut(): void
    {
        $callers = [];
        foreach ( array_keys( self::files() ) as $rel ) {
            if ( ProSafetyScanner::usesRemoteHttp( (string) file_get_contents( self::ROOT . '/' . $rel ) ) ) {
                $callers[] = $rel;
            }
        }
        $this->assertSame( [ self::REMOTE_FILE ], $callers );
    }

    public function testTheScannerCatchesPlantedViolations(): void
    {
        $this->assertSame( [ 'eval' ], ProSafetyScanner::violations( '<?php eval( $x );' ) );
        $this->assertContains( 'exec family', ProSafetyScanner::violations( '<?php shell_exec( "ls" );' ) );
        $this->assertContains( 'exec family', ProSafetyScanner::violations( '<?php system ( $c );' ) );
        $this->assertContains( 'unserialize', ProSafetyScanner::violations( '<?php unserialize( $d );' ) );
        $this->assertContains( 'write users', ProSafetyScanner::violations( '<?php wp_insert_user( [] );' ) );
        $this->assertContains( 'manage plugins', ProSafetyScanner::violations( '<?php deactivate_plugins( "x" );' ) );
        $this->assertContains( 'divi presets', ProSafetyScanner::violations( "<?php update_option( 'et_divi_builder_global_presets_d5', [] );" ) );
        $this->assertContains( 'divi presets', ProSafetyScanner::violations( '<?php GlobalPreset::save( 1 );' ) );
    }

    public function testTheScannerIgnoresCommentsAndCleanCode(): void
    {
        $this->assertSame( [], ProSafetyScanner::violations( "<?php\n// never eval( anything )\n/** Not exec( this ) either */\n\$a = 1;" ) );
        $this->assertSame( [], ProSafetyScanner::violations( '<?php register_activation_hook( __FILE__, "x" ); $this->evaluate( 1 );' ) );
    }

    public function testTheHostScannerFindsLiteralsOnly(): void
    {
        $this->assertSame( [ 'evil.example' ], ProSafetyScanner::hosts( "<?php \$u = 'https://evil.example/x'; // https://ignored.example" ) );
        $this->assertSame( [ 'divi5lab.com' ], ProSafetyScanner::hosts( '<?php $u = "https://divi5lab.com/api";' ) );
        $this->assertTrue( ProSafetyScanner::usesRemoteHttp( '<?php wp_remote_get( $u );' ) );
        $this->assertTrue( ProSafetyScanner::usesRemoteHttp( '<?php curl_exec( $h );' ) );
        $this->assertFalse( ProSafetyScanner::usesRemoteHttp( '<?php // wp_remote_get( $u );' ) );
    }
}
```

Create `pro-addon/tests/UninstallTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    public function testItExitsUnlessWordPressIsUninstallingThePlugin(): void
    {
        $this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {", (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/uninstall.php' ) );
    }

    public function testItWipesTheLocalLicenceStateAndNeverCallsTheServer(): void
    {
        $GLOBALS['__wp_options'] = [
            'aied_pro_license_key'      => 'KEY',
            'aied_pro_license_state'    => [ 'status' => 'active' ],
            'aied_pro_premium_unlocked' => 1,
            'aied_pro_update_blocked'   => '1',
            'unrelated_option'          => 'keep',
        ];
        $GLOBALS['__wp_http_log'] = [];
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php' );
        }
        require dirname( AIED_PRO_FILE ) . '/uninstall.php';

        $this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }
}
```

- [ ] **Step 2: Run and confirm the new tests fail**

Run: `make test-pro 2>&1 | tail -12`

Expected: failures: `ProSafetyTest` cases for `uninstall.php` (file missing) and `UninstallTest` (file missing). The scanner self-tests and the cases for existing files pass (this proves the scan works on the real tree before the file exists).

- [ ] **Step 3: Write `uninstall.php`**

Create `pro-addon/uninstall.php`:

```php
<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/src/Licensing.php';

// Local state only: uninstalling never calls the licence server.
AiEditorDivi5\Pro\Licensing::clear();
```

- [ ] **Step 4: Run the Pro suite**

Run: `make test-pro 2>&1 | tail -6`

Expected: `OK (...)`.

- [ ] **Step 5: Zip script, ignore `dist/`, Makefile targets, live-check script**

Create `scripts/build-pro-zip.sh`:

```bash
#!/usr/bin/env bash
#
# Build the installable Pro add-on zip: dist/jhmg-ai-editor-for-divi-5-pro.zip. It is sold from
# divi5lab.com, never uploaded to WordPress.org, and `dist/` is git-ignored. Only the main file,
# uninstall.php and src/ ship; tests, staged files and docs stay out.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SRC="pro-addon"
SLUG="jhmg-ai-editor-for-divi-5-pro"
MAIN="$SLUG.php"
OUT_DIR="$ROOT/dist"
OUT="$OUT_DIR/$SLUG.zip"

if [ ! -f "$SRC/$MAIN" ]; then
  echo "[build-pro] ERROR: $SRC/$MAIN not found - run from the repo root." >&2
  exit 1
fi

VERSION="$(grep -E "^\s*\*\s*Version:" "$SRC/$MAIN" | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
echo "[build-pro] Packaging $SLUG version $VERSION"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
cp "$SRC/$MAIN" "$SRC/uninstall.php" "$STAGE/$SLUG/"
cp -R "$SRC/src" "$STAGE/$SLUG/src"
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$OUT_DIR"
rm -f "$OUT"
( cd "$STAGE" && zip -q -r "$OUT" "$SLUG" )
echo "[build-pro] wrote $OUT ($(wc -c < "$OUT" | tr -d ' ') bytes)"
```

Create `scripts/verify-pro-skeleton.php`:

```php
<?php
/**
 * Real-site check of the Pro add-on skeleton: loaded, compatible with the free plugin, tab registered,
 * no Pro tools while locked, no remote call without a key, and the only host it would ever contact is
 * divi5lab.com. Read-only: it blocks every outgoing HTTP request for the duration of the check.
 *
 * Run: make verify-pro-skeleton   (needs `make up`, the free plugin and the Pro add-on active)
 */

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

$blocked = [];
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$blocked ) {
    $blocked[] = (string) $url;

    return new WP_Error( 'blocked', 'blocked by verify-pro-skeleton' );
}, 10, 3 );

$check( defined( 'AIED_PRO_VERSION' ), 'the add-on is loaded (' . ( defined( 'AIED_PRO_VERSION' ) ? AIED_PRO_VERSION : '?' ) . ')' );

$free   = defined( 'AI_EDITOR_DIVI5_VERSION' ) ? (string) AI_EDITOR_DIVI5_VERSION : null;
$compat = \AiEditorDivi5\Pro\Compat::check( $free, 'class_exists' );
$check( $compat['ok'], 'compatible with the free plugin ' . ( $free ?? '(missing)' ) . ' (minimum ' . \AiEditorDivi5\Pro\Compat::MIN_FREE . ')' );

$tabs = apply_filters( 'jhmg_aied_admin_tabs', [] );
$check( isset( $tabs['pro'] ), 'the Pro admin tab is registered through jhmg_aied_admin_tabs' );

$check( \AiEditorDivi5\Pro\Licensing::isPremium() === false, 'locked on this site (no licence activated)' );
$check( \AiEditorDivi5\Pro\Licensing::status()['reason'] === 'no_key', 'licence status is no_key' );

$builtIn = [ [ 'name' => 'get_page_layout' ] ];
$check( apply_filters( 'jhmg_aied_mcp_tools', $builtIn ) === $builtIn, 'no Pro tool is advertised while locked' );
$check( null === apply_filters( 'jhmg_aied_mcp_call', null, 'any_pro_tool', [], 1 ), 'no Pro tool is callable while locked' );

\AiEditorDivi5\Pro\Licensing::refresh( true );
$check( [] === $blocked, 'no remote call is made without a licence key' );

\AiEditorDivi5\Pro\Licensing::client()->inject_update( (object) [ 'response' => [] ] );
$hosts = array_unique( array_map( static fn ( string $u ): string => (string) wp_parse_url( $u, PHP_URL_HOST ), $blocked ) );
$check( [] !== $blocked, 'the updater tried to contact the update server (blocked here)' );
$check( [ 'divi5lab.com' ] === array_values( $hosts ), 'the only host contacted is divi5lab.com (' . implode( ', ', $hosts ) . ')' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'The Pro add-on skeleton is verified on the real site.' );
```

Edit `Makefile` and `.gitignore`:

```bash
python3 - <<'PY'
s = open('Makefile', encoding='utf-8').read()
old = ' test-pro'
assert old in s.splitlines()[0]
s = s.replace(' test-pro', ' test-pro build-pro verify-pro-skeleton', 1)
anchor = "test-pro:\n\t@./vendor/bin/phpunit -c pro-addon/phpunit.xml\n"
assert anchor in s
s = s.replace(anchor, anchor + """
# ---------------------------------------------------------------
# build-pro — build dist/jhmg-ai-editor-for-divi-5-pro.zip (sold from divi5lab.com; git-ignored)
# ---------------------------------------------------------------
build-pro:
\t@bash scripts/build-pro-zip.sh

# ---------------------------------------------------------------
# verify-pro-skeleton — real-site check of the Pro add-on (Docker site; the free and Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-skeleton:
\t@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-skeleton.php --user=1
""", 1)
open('Makefile', 'w', encoding='utf-8').write(s)

g = open('.gitignore', encoding='utf-8').read()
if 'dist/' not in g:
    open('.gitignore', 'w', encoding='utf-8').write(g.rstrip('\n') + '\n\n# Built Pro add-on zip (sold from divi5lab.com)\ndist/\n')
PY
make -n build-pro verify-pro-skeleton | head -4
git diff Makefile | grep '^[+-]' | grep -v '^+++\|^---'
```

Expected: `make -n` prints the `bash scripts/build-pro-zip.sh` and `docker compose exec ...verify-pro-skeleton.php` commands; the Makefile diff shows only the `.PHONY` additions and the two new targets.

- [ ] **Step 6: Build the zip**

```bash
make build-pro
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | awk '{print $4}' | grep -v '^$' | sort
```

Expected: `[build-pro] Packaging jhmg-ai-editor-for-divi-5-pro version 0.1.0`; the listing contains exactly `jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php`, `.../uninstall.php`, and `.../src/*` (Compat, Licensing, Licensing/LicenseClient, Plugin, ProPage, ProTool, ToolRegistry, Tools, autoload) and NOTHING from `tests/`, `staged/` or `README.md`. `git status --short` does not list `dist/`.

- [ ] **Step 7: Mount the add-on into the dev site and run the live check**

```bash
python3 - <<'PY'
p = 'docker-compose.yml'
s = open(p, encoding='utf-8').read()
old = "      - ./wp-plugin:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5:ro\n"
assert s.count(old) == 2, s.count(old)
s = s.replace(old, old + "      - ./pro-addon:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-pro:ro\n")
open(p, 'w', encoding='utf-8').write(s)
PY
docker compose up -d wordpress wpcli
docker compose ps
docker compose exec -T wordpress ls /var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-pro | head -3
WPC="docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp"
$WPC plugin activate jhmg-ai-editor-for-divi-5-pro
make verify-pro-skeleton
$WPC plugin deactivate jhmg-ai-editor-for-divi-5-pro
$WPC plugin list --fields=name,status,version | grep jhmg
```

Expected: the containers are `Up`; the listing shows `jhmg-ai-editor-for-divi-5-pro.php`; activation prints `Success: Plugin 'jhmg-ai-editor-for-divi-5-pro' activated.`; `make verify-pro-skeleton` prints 10 `PASS` lines (loaded, compatible with the free plugin 4.4.0 (minimum 4.4.0), tab registered, locked, status no_key, no tool advertised, no tool callable, no remote call without a key, the updater tried to contact the update server, the only host is divi5lab.com) and `Success: The Pro add-on skeleton is verified on the real site.`; deactivation prints `Success`; the plugin list shows the free plugin `active` and the Pro plugin `inactive`. If the shell does not word-split `$WPC` (zsh), run the three `wp` commands in full instead. NEVER run `wp plugin delete`. If a check FAILS, stop and report it with the output.

- [ ] **Step 8: Docs**

Overwrite `pro-addon/README.md`:

```markdown
# pro-addon/ — JHMG AI Editor for Divi 5 Pro (separate plugin)

The Pro add-on for the free WordPress.org plugin in `wp-plugin/`. It is a **separate plugin**, sold and hosted at divi5lab.com, never shipped in the free zip and never uploaded to WordPress.org. It extends the free plugin only through the `jhmg_aied_*` hooks (`docs/EXTENDING.md`) and calls a small set of the free plugin's classes (pinned by `tests/FreeApiCompatTest.php`).

Spec: `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Plans: `docs/superpowers/plans/`.

## What exists (0.1.0, the skeleton)

| Path | What it is |
|---|---|
| `jhmg-ai-editor-for-divi-5-pro.php` | main plugin file; boots on `plugins_loaded` 20 |
| `src/Compat.php`, `src/Plugin.php` | only runs against a free plugin >= `Compat::MIN_FREE` whose classes exist; otherwise registers nothing and shows one admin notice |
| `src/Licensing.php`, `src/Licensing/LicenseClient.php` | sticky-unlock licensing; the client is GENERATED by `scripts/sync-pro-license-client.sh` from the canonical divi5lab client (`../layoutlab`, read-only) and carries the updater |
| `src/ProTool.php`, `src/ToolRegistry.php`, `src/Tools.php` | the tool contract and the licence-gated registry; `Tools::all()` is empty until the tool plans land |
| `src/ProPage.php` | the "Pro" admin tab (licence entry, tool list) |
| `uninstall.php` | wipes the local licence state; never calls the server |
| `tests/` | the Pro suite: `make test-pro` |
| `staged/` | not-yet-integrated source from the 4.0.0 removal (`MenuBuilder`, `CustomCss`, `PhpProposals` and a test) |
| `staged-4.3.0/` | the 4.3.0 header/footer editing removed from the free plugin (copies; see its note below) |

`make test-pro` runs the Pro tests; `make build-pro` builds `dist/jhmg-ai-editor-for-divi-5-pro.zip` (git-ignored); `make verify-pro-skeleton` is the real-site check (activate the add-on on the Docker site first).

## `staged-4.3.0/`

The `src` and `tests` files are verbatim copies of the 4.4.0-tree files for header/footer editing. `4.3.0-free-plugin.diff` is the original 4.3.0 merge diff (837b712), whose handler and guide hunks predate the 4.4.0 edits to the same files; the free re-cut's own removal commits (5cf40c0, fd4e50d, cd5fffe) are the exact current removal hunks. Not run, not shipped.

## Contract

It must not need any change to the free plugin beyond the documented hooks. Nothing here is ever loaded by the free plugin's zip, and `phpunit.xml` at the repo root never scans this folder.
```

Update `CLAUDE.md` with this script (every `assert` must pass; if one fails, the file changed since this plan was written: stop and look):

```bash
python3 - <<'PY'
p = 'CLAUDE.md'
s = open(p, encoding='utf-8').read()

def rep(old, new):
    global s
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)

rep("""at divi5lab.com, not on WordPress.org). Its source material is staged in `pro-addon/`
(licence client, front page/menu, custom CSS, PHP proposals); its own spec and plan
are still pending. The old""",
    """at divi5lab.com, not on WordPress.org). Its spec is
`docs/superpowers/specs/2026-10-02-pro-addon-design.md`; the skeleton is built in `pro-addon/`
and the tool plans follow. The old""")

rep("""- **The Pro add-on staging area** (`pro-addon/`) — not shipped, not built, not tested
  by `make test` (see `pro-addon/README.md`).""",
    """- **The Pro add-on** (`pro-addon/`) — a separate plugin ("JHMG AI Editor for Divi 5 Pro", sold at divi5lab.com,
  never in the WordPress.org zip). Skeleton 0.1.0: compat guard, licensing with the updater, a licence-gated tool
  registry (no tools yet), the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
  by `make test` (see `pro-addon/README.md`).""")

anchor = "| `make verify-global-styles` |"
i = s.index(anchor)
j = s.index("\n", i) + 1
s = s[:j] + (
    "| `make test-pro` | Run the Pro add-on's own PHPUnit suite (`pro-addon/phpunit.xml`) |\n"
    "| `make build-pro` | Build `dist/jhmg-ai-editor-for-divi-5-pro.zip` (sold from divi5lab.com; `dist/` is git-ignored) |\n"
    "| `make verify-pro-skeleton` | Real-site check of the Pro add-on skeleton (Docker site; the free and Pro plugins must be active) |\n"
) + s[j:]

anchor = "## Current state\n\n"
assert s.count(anchor) == 1
s = s.replace(anchor, anchor + (
    "- **Pro add-on skeleton 0.1.0** (branch `feat/pro-skeleton`, local; NOT merged, NOT released): compat guard,\n"
    "  licensing with the updater, licence-gated `ToolRegistry` (no tools yet), Pro admin tab, safety test, zip,\n"
    "  `make verify-pro-skeleton` passing on the dev site. Next: Plan 3 (batch store, undo, the five settings tools).\n"
))
open(p, 'w', encoding='utf-8').write(s)
PY
grep -n "staging area\|still pending" CLAUDE.md || echo "no stale Pro add-on wording"
```

Expected: `no stale Pro add-on wording`.

- [ ] **Step 9: Whole checks, then commit**

```bash
make test-pro 2>&1 | tail -3
./vendor/bin/phpunit 2>&1 | tail -3
git status --short
git add -A
git commit -m "feat(pro): safety scan, uninstall, zip, dev-site mount and live check, docs

ProSafetyTest bans eval/exec-family/unserialize and writes to users, plugins and Divi
presets, allows only divi5lab.com, and only the licence client may call out; the
scanner is proven on planted violations. make build-pro, make verify-pro-skeleton
(10 PASS on the real site), pro-addon README and CLAUDE.md updated.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Expected: the Pro suite `OK (119 tests, 333 assertions)` (the replay's count), the main suite `OK (548 tests, 3911 assertions)` (unchanged: nothing in `wp-plugin/` or `tests/` moved), and `git status` lists nothing under `dist/`.

---

## Self-Review (run against the spec)

**Spec coverage** (spec sections "Architecture", "Licensing and distribution", "Build order" step 1)

| Spec requirement | Task |
|---|---|
| Separate plugin: slug, namespace `AiEditorDivi5\Pro`, constants `AIED_PRO_*`, option/table prefix `aied_pro` | 2, 3 |
| `pro-addon/` becomes real source; staged files kept as the starting point | 1 (moved to `staged/`), 3 (licensing reworked) |
| Own `phpunit.xml`, bootstrap, `make test-pro`, `scripts/build-pro-zip.sh` | 1, 6 |
| Loads only with the free plugin at or above the declared minimum; otherwise one notice and nothing registered | 2 |
| Hooks only: tools/call, tools list, admin tabs/render | 4, 5 |
| Every tool checks capability, type-checks, returns built-in response shapes | 4 (capability, `-32602`/`-32603`, shapes) |
| Compatibility test fails if `HistoryService`/`EditTarget`/`Validator` signatures change | 2 (`FreeApiCompatTest`; the `Validator` is the bundled validator the add-on reaches through the free classes, so its signature is covered by the validator's own suite) |
| Licensing: reuse staged adapter and canonical client WITH updater; unlock rule; locked state registers no tool and shows only the tab | 3, 4, 5 |
| Remote calls: divi5lab.com only, licence and updates, daily cache, 3-day grace | 3 (canonical client), 6 (`ProSafetyTest`) |
| Admin: one Pro tab with Licence + tool list (Recent batches comes with the batch store) | 5 |
| One dismissible card in the free plugin stays the only mention | untouched (`wp-plugin/` unchanged) |
| Guard test: bans eval/exec, PHP execution of stored snippets, writes to users/presets; hosts allowlist | 6 |
| Zip built from the add-on, not the free zip | 6 |
| 29 of 30 OpenAPI operations budget | 4 (`ToolsTest`) |
| Tool plans, batch store, undo, Recent-batches panel, REST/OpenAPI parity | out of this plan (Plans 3 to 5) |

**Placeholder scan:** none. Every file is shown in full or edited by an asserting script; the only judgement left to the executor is named (zsh `$WPC` splitting, stop-on-FAIL).

**Type consistency:** `Compat::check(?string, callable, string): array` (Task 2) is the signature `Plugin::bootWith` and `scripts/verify-pro-skeleton.php` call; `Licensing::{client,isPremium,activate,deactivate,refresh,status,clear,resetForTests}` (Task 3) are the names Tasks 4 to 6 use; `ProTool`/`ToolRegistry::{definitions,claim,names,register}` (Task 4) match `ProPage`'s use of `names()`; `FakeTool` (shared file, Task 4) is required by both `ToolRegistryTest` and `ProPageTest`; the option names `aied_pro_*` agree between `Licensing`, `UninstallTest` and `ProPageTest`.

**Review Focus:** (1) `CompatTest` + `PluginTest`; (2) `LicensingTest` matrix + `ToolRegistryTest::testLockedMeans...` and `...RevokedBetweenListingAndCalling...`; (3) `ToolRegistryTest` (capability, bad argument, no leak); (4) `ProPageTest` (masking, caps, nonce, non-POST); (5) `LicensingTest` updater tests; (6) `ProSafetyTest` incl. planted violations and the single remote file.

**Replayed:** Tasks 1 to 6 (every file block and script, in order, including the red runs) were replayed in a scratch git worktree starting from `main`. Pro suite after each task: 3, 31, 54, 69, 91, 119 tests, all green; the red runs failed for the stated reasons; the main suite stayed `OK (548 tests, 3911 assertions)`; `make build-pro` produced the expected zip contents; the Makefile, `.gitignore`, `docker-compose.yml` and `CLAUDE.md` edit scripts applied cleanly to the real files (on copies). The replay found and fixed: the version-extraction `sed` in `build-pro-zip.sh` (BSD `sed` mangles `\s`, now `[[:space:]]`), the Task 2 and Task 4 red-run wording (the run aborts with a fatal, it does not list errors), and a stale assertion count. One environment note: `scripts/sync-pro-license-client.sh` finds the canonical client at `../layoutlab/...` relative to the repo; when run from a different checkout location, set `AIED_LICENSE_CLIENT_SRC` to `/Users/Lucas/Documents/JHMG-Local/layoutlab/lib/license-server/php-client/class-license-client.php`. Not replayed (they need the real Docker site): Task 6 Step 7 (container recreate, plugin activate, `make verify-pro-skeleton`, deactivate) and the `pro-addon/README.md` text.

## Handoff (the controller's checklist after Task 6)

1. `git log --oneline main..feat/pro-skeleton` shows the 6 commits; report test counts for `make test-pro` and the main suite, the `make verify-pro-skeleton` output, and the zip listing.
2. Ask the owner to confirm the licence-server product id `ai-editor-divi5-pro` still exists on divi5lab.com, and the product page URL `https://divi5lab.com/plugins/divi-5-ai-editor` (both reused from the old in-plugin licensing, not verified against the server). Without them a real activation will fail with `product_mismatch`; nothing else here depends on it.
3. Note for the owner: the canonical licence client lives in the `layoutlab` repo and its `sync-license-client.sh` has no destination for this add-on; `scripts/sync-pro-license-client.sh` in THIS repo generates it (read-only on layoutlab). Adding a layoutlab destination is the owner's call.
4. Ask before merging to main. Nothing is pushed or uploaded.
5. After the merge: write Plan 3 (batch store + undo + the five settings tools) from the spec, starting from `pro-addon/staged/`.
