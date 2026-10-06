# Pro `manage_global_variables` (Pro add-on 0.8.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI assistant create, edit and archive the site's Divi **numbers** and **strings** global variables from the Pro add-on, previewed, validated, locked and undoable (MCP + REST only).

**Architecture:** A copy of the proven `manage_global_colors` pattern. `GlobalVariables` is the only code that talks to Divi's `GlobalData` variable functions and holds the value guards and the pre-flight; `GlobalVariablePlanner` is a pure change planner; `GlobalVariableKind` (a `SiteLockingKind`, merged 2026-10-06) records and undoes each item under the site-wide `GlobalVariablesLock`; `ManageGlobalVariables` is the tool (preview token, one complete write, read-back, batch). Nothing in the free plugin or the validator changes.

**Tech Stack:** PHP 8 (strict types), PHPUnit (`make test-pro`), WP-CLI + Docker for the live gate, Divi 5.14 (`divi/Divi.zip`, read-only source).

**Spec:** `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md` (read it first; the colours spec `docs/superpowers/specs/2026-10-05-pro-global-colors-design.md` and code `pro-addon/src/{Settings/GlobalColors*,Kinds/GlobalColorKind,Tools/ManageGlobalColors}.php` are the template).

## Global Constraints

- Pro add-on only. Never touch `wp-plugin/`, `src/` (the validator) or `wp-plugin/validator/`. Nothing licence-gated or remote enters `wp-plugin/`.
- Types written: `numbers` and `strings` ONLY. Every other group (`images`, `links`, `fonts`, `gradients`) is carried through untouched. No delete: `status` is `active` or `archived` (Divi's own pair).
- Divi is reached ONLY through `ET\Builder\Packages\GlobalData\GlobalData::get_global_variables()` / `::set_global_variables()` (never the option name `global_variables`), each behind `is_callable` guards; a missing or throwing function means refuse and write nothing.
- `set_global_variables()` REPLACES the whole map and silently does nothing without `edit_theme_options` + Divi's `et_pb_is_allowed( 'variables_manager' )`: check both up front.
- Tool: `manage_global_variables`, capability `edit_theme_options`, 16th Pro tool, MCP + REST only (`ProOpenApi::MCP_REST_ONLY`; the ChatGPT spec stays 16 free + 14 Pro = 30 operations). Description at most 300 characters. `variables` 1 to 50 entries.
- Preview token: `PreviewToken` (30 minutes, single use, bound to the user and the arguments). Real runs and real undos hold `GlobalVariablesLock` (prefix `aied_pro_buildlock_gvars_`, 30 s expiry) from before the first read; a dry run takes no lock.
- Value guards (values reach the site's CSS): numbers = a number with an optional unit (`px rem em % vh vw vmin vmax ch svh dvh lvh`) or `calc/clamp/min/max(...)` of those, at most 100 characters, nesting depth at most 4; strings = 1 to 200 characters, no control characters, `{ } ; \ < > " ' \``, not containing `url(`, `@import`, `expression(` or `$variable(` (case-insensitive); label 1 to 60 characters (`GlobalColors::cleanLabel`) and never `empty()` in PHP's sense (Divi drops a label of `0`); id `^gvid-[A-Za-z0-9-]{3,64}$`.
- Every refusal throws `\InvalidArgumentException` with a plain message and changes nothing.
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Work on branch `feat/pro-global-variables` (already created; the spec is its first commit). Never commit `CLAUDE.md`. Never push, deploy, upload or release.
- Add-on version 0.8.0 (header, `AIED_PRO_VERSION`, `readme.txt`, `README.md`, `tests/bootstrap.php`).
- Tests: `make test-pro` (Pro suite, currently 1136) and `make test` (free suite, 805) must stay green; run one file with `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/<File>.php`.

## Review Focus

Failure modes the spec implies that a person would hit; each has a test in the named task.

1. A site whose stored data holds a `colors` group with entries (Divi's sanitiser erases it on every save), an unmanaged group entry Divi would drop (empty label, non-`gvid-` id), or a customizer font: refuse naming the cause, never silently erase, never send the customizer fonts back (Task 2 `unsafeEntries` table, Task 6 pre-flight test).
2. Values with CSS metacharacters, functions, `!important`, `var()`, an over-long value, nesting too deep, or multi-byte text: refused or accepted exactly per the tables (Task 3).
3. A user with `edit_theme_options` but without Divi's Variables Manager permission, where Divi would silently write nothing: refused up front, even for a dry run (Task 6), and a write Divi ignored is reported, never claimed (Task 6 `mutate` test).
4. An undo colliding with another variable change, and the undo of a create racing a later edit: lock held from before the first read, busy reports `busy` with no read or write, create-removal refused once the entry changed, even with `force` (Task 5, Task 6).
5. A label of `0`, a type with no variables yet (first order is 1), stored `order` values that are strings or missing, and creating variables in both types in one call (Task 4).

---

### Task 1: Live evidence spike (Divi 5.14 on the Docker site)

Spec evidence comes from reading Divi's source; this task proves it on the real Divi before any code depends on it, and settles the open string-value question. Output: a committed evidence file.

**Files:**
- Create: `scripts/spike-global-variables.php`
- Create: `docs/global-variables-evidence-5.14.md`

**Interfaces:**
- Consumes: the Docker dev site (`make up` done), Divi active.
- Produces: `docs/global-variables-evidence-5.14.md` with the headings listed in Step 3. Later tasks read it (Task 3 Step 6).

- [ ] **Step 1: Write the spike script**

Create `scripts/spike-global-variables.php`:

```php
<?php
/**
 * ONE-OFF evidence spike for the Pro manage_global_variables tool (not a gate, not kept in the Makefile).
 * Reads Divi 5.14's real variable storage and does a self-restoring write round trip with two throwaway variables.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-global-variables.php --user=1
 * Dev sites only (scripts/dev-guard.php). The stored option is snapshotted first and put back byte for byte at the end.
 * The page is fetched through the Docker network (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to change that.
 */

use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$raw = static function () {
    return maybe_unserialize( et_get_option( 'global_variables', [], '', true, false, '', '', true ) );
};
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
/** The home page HTML plus every same-site stylesheet it links. */
$render = static function () use ( $fetch ): string {
    $html = $fetch( home_url( '/' ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 25 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
$context = static function ( string $haystack, string $needle ): array {
    $out = [];
    $off = 0;
    while ( count( $out ) < 3 && false !== ( $pos = strpos( $haystack, $needle, $off ) ) ) {
        $out[] = substr( $haystack, max( 0, $pos - 120 ), strlen( $needle ) + 240 );
        $off   = $pos + strlen( $needle );
    }

    return $out;
};
$clearCaches = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) ) {
        ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
    }
};

$snapshot = $raw();
$out      = [ 'divi_user_can_edit_theme_options' => current_user_can( 'edit_theme_options' ), 'variables_manager_allowed' => function_exists( 'et_pb_is_allowed' ) ? (bool) et_pb_is_allowed( 'variables_manager' ) : null ];

// 1. What is stored, and what Divi's own read adds.
$out['raw_is_array'] = is_array( $snapshot );
$out['raw_groups']   = [];
foreach ( (array) $snapshot as $type => $group ) {
    $out['raw_groups'][ $type ] = is_array( $group ) ? count( $group ) : gettype( $group );
}
$out['raw_sample_per_group'] = [];
foreach ( (array) $snapshot as $type => $group ) {
    if ( is_array( $group ) && [] !== $group ) {
        $id                                   = (string) array_key_first( $group );
        $entry                                = $group[ $id ];
        $out['raw_sample_per_group'][ $type ] = [ 'id' => $id, 'entry' => $entry, 'field_types' => array_map( 'gettype', (array) $entry ) ];
    }
}
$read = GlobalData::get_global_variables();
$out['read_groups']             = array_map( static fn ( $g ): int => count( (array) $g ), (array) $read );
$out['read_fonts_ids']          = array_keys( (array) ( ( (array) $read )['fonts'] ?? [] ) );
$out['customizer_fonts_ids']    = array_keys( (array) GlobalData::$customizer_fonts );
$firstNumber                    = (array) ( ( (array) $read )['numbers'] ?? [] );
$out['read_sample_has_allowed'] = [] === $firstNumber ? null : isset( ( (array) reset( $firstNumber ) )['allowedActions'] );

$restored = false;
try {
    // 2. A write of exactly what is stored (six groups only): does Divi leave the option unchanged?
    $six = [];
    foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ] as $t ) {
        if ( ! empty( $snapshot[ $t ] ) && is_array( $snapshot[ $t ] ) ) {
            $six[ $t ] = $snapshot[ $t ];
        }
    }
    GlobalData::set_global_variables( $six );
    $out['noop_roundtrip_identical'] = serialize( $raw() ) === serialize( $snapshot );
    if ( ! $out['noop_roundtrip_identical'] ) {
        $out['noop_roundtrip_after'] = $raw();
    }

    // 3. Two throwaway variables: how Divi stores them.
    $numId  = 'gvid-aiedspike-0000-4000-8000-000000000001';
    $strId  = 'gvid-aiedspike-0000-4000-8000-000000000002';
    $marker = 'AIEDSPIKEMARKER Hello world';
    $with   = $six;
    $with['numbers'][ $numId ] = [ 'label' => 'AIED spike number', 'value' => 'clamp(1rem, 2vw + 1rem, 3rem)', 'order' => 99, 'status' => 'active' ];
    $with['strings'][ $strId ] = [ 'label' => 'AIED spike string', 'value' => $marker, 'order' => 99, 'status' => 'active' ];
    GlobalData::set_global_variables( $with );
    $after                       = $raw();
    $out['stored_number']        = $after['numbers'][ $numId ] ?? null;
    $out['stored_string']        = $after['strings'][ $strId ] ?? null;
    $out['stored_order_type']    = gettype( $after['numbers'][ $numId ]['order'] ?? null );
    $out['untouched_unchanged']  = array_diff_key( (array) $after['numbers'], [ $numId => 1 ] ) === (array) ( $six['numbers'] ?? [] );

    // 4. Does Divi emit a variable's value into the page or its CSS, without anything citing it?
    $clearCaches();
    $css                               = $render();
    $out['emit_page_bytes']            = strlen( $css );
    $out['emit_number_id_contexts']    = $context( $css, $numId );
    $out['emit_string_id_contexts']    = $context( $css, $strId );
    $out['emit_string_marker_contexts'] = $context( $css, $marker );
    $out['emit_any_gvid_definitions']  = preg_match_all( '/--gvid-[a-z0-9-]+\s*:/i', $css );

    // 5. Archived: still emitted?
    $arch                        = $with;
    $arch['numbers'][ $numId ]['status'] = 'archived';
    GlobalData::set_global_variables( $arch );
    $clearCaches();
    $css2                              = $render();
    $out['archived_number_emitted']    = false !== strpos( $css2, $numId );
    $out['archived_stored_status']     = $raw()['numbers'][ $numId ]['status'] ?? null;
} finally {
    et_update_option( 'global_variables', $snapshot, false, '', '', true );
    $clearCaches();
    $restored = serialize( $raw() ) === serialize( $snapshot );
}
$out['restored_byte_identical'] = $restored;

WP_CLI::log( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
if ( ! $restored ) {
    WP_CLI::error( 'The stored variables were NOT restored byte for byte: fix this by hand before anything else (dev site).' );
}
WP_CLI::success( 'Spike finished; the stored variables are exactly as they were.' );
```

- [ ] **Step 2: Run it**

Run: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-global-variables.php --user=1`
Expected: a JSON block, then `Success: Spike finished; the stored variables are exactly as they were.` If it prints `Error: ... NOT restored`, stop and fix the dev site by hand. If the page fetch returns nothing (`emit_page_bytes` 0), set `AIED_LOOPBACK_BASE` to a URL the wpcli container can reach and rerun.

- [ ] **Step 3: Write the evidence file**

Create `docs/global-variables-evidence-5.14.md` with the pasted JSON in a fenced block and these headings, each answered in one or two sentences from the output (no guessing; write "not observed" where the output does not say):
1. `## Stored shape` (groups present on the dev site, field names and PHP types per group, whether `order` is stored as a string or an integer after Divi's save).
2. `## Customizer fonts` (the ids `read_fonts_ids` and `customizer_fonts_ids`; confirm they do not start with `gvid-`).
3. `## No-op round trip` (`noop_roundtrip_identical`; if false, what Divi changed).
4. `## Permission` (`variables_manager_allowed`; note that the silent no-op without it is from the source, not exercised live).
5. `## Emission` (does the number id, the string id or the string marker appear in the page or its CSS without anything citing them; paste the contexts; if the marker appears, say whether it is inside a `<style>` block or a CSS declaration and whether Divi escaped it).
6. `## Archived` (`archived_number_emitted`, `archived_stored_status`).
7. `## Decisions for the guards` (one line: "string guard stays as the spec" OR "string guard must also refuse <exact form>, because <evidence>").

- [ ] **Step 4: Commit**

```bash
git add scripts/spike-global-variables.php docs/global-variables-evidence-5.14.md
git commit -m "docs: live evidence for Divi 5.14 global variables (storage, round trip, emission)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Test infrastructure and the `GlobalVariables` accessor

**Files:**
- Modify: `pro-addon/tests/DiviGlobalDataShims.php` (add the variables API to the same `GlobalData` class and `et_pb_is_allowed`)
- Create: `pro-addon/tests/GlobalVariablesFixture.php`
- Create: `pro-addon/src/Settings/GlobalVariables.php`
- Modify: `pro-addon/src/autoload.php` (require the new class)
- Test: `pro-addon/tests/GlobalVariablesTest.php`

**Interfaces:**
- Consumes: `GlobalColors::cleanLabel( string ): ?string` (existing), `GlobalColors::MAX_LABEL`.
- Produces (all `public static` on `AiEditorDivi5\Pro\Settings\GlobalVariables`):
  - `const DATA_CLASS`, `const TYPES` (six), `const MANAGED` (`['numbers','strings']`), `const STATUSES` (`['active','archived']`)
  - `available( ?string $class = null ): bool`
  - `permitted(): bool` (edit_theme_options AND Divi's `variables_manager`)
  - `read(): ?array` → `array<string, array<string, array<string,mixed>>>` (type => id => entry), entries without `allowedActions`, customizer fonts removed, `null` when unreadable
  - `state( ?array $entry ): ?array` → `['label'=>string,'value'=>string,'status'=>string]` or null
  - `find( array $all, string $id ): ?array` → `['type'=>string,'entry'=>array]` or null
  - `nextOrder( array $group ): int`
  - `sendMap( array $all ): array` (the groups that are not empty)
  - `write( array $map ): array{threw:bool, after:?array}`
  - `unsafeEntries( array $map ): list<array{id:string,reason:string}>`
  - `validId( string ): bool`
- Test helpers produced: `GlobalVariablesFixture::{N1,N2,S1,IMG}`, `::stored()`, `::reset( array $extraStored = [] )`, `::clear()`, and the shim state `$GLOBALS['__divi_gv']` with keys `stored, cache, gets, set_calls, throw_get, throw_set, mutate, on_get`; the role switch is `$GLOBALS['__divi_role_allowed']` (bool, default true).

- [ ] **Step 1: Extend the Divi shim**

In `pro-addon/tests/DiviGlobalDataShims.php`, inside `class GlobalData` (after `set_global_colors`, before the closing `}` of the class) add:

```php
        /** @var array<string,array<string,mixed>> Divi's two built-in font variables (their ids do NOT start with gvid-). */
        public static $customizer_fonts = [
            '--et_global_heading_font' => [ 'label' => 'Heading', 'option_name' => 'heading_font', 'default' => 'Open Sans' ],
            '--et_global_body_font'    => [ 'label' => 'Body', 'option_name' => 'body_font', 'default' => 'Open Sans' ],
        ];

        /** @param array<string,array<string,array<string,mixed>>> $stored the groups as stored (type => id => entry) */
        public static function resetVariablesForTests( array $stored = [] ): void
        {
            $GLOBALS['__divi_gv'] = [
                'stored' => $stored, 'cache' => null, 'gets' => 0, 'set_calls' => [],
                'throw_get' => false, 'throw_set' => false, 'mutate' => null, 'on_get' => null,
            ];
        }

        /** Divi's read: seven groups as objects, allowedActions on every entry, the customizer fonts merged into `fonts`; cached until a save. */
        public static function get_global_variables(): array
        {
            if ( ! isset( $GLOBALS['__divi_gv'] ) ) {
                self::resetVariablesForTests();
            }
            $s = &$GLOBALS['__divi_gv'];
            ++$s['gets'];
            if ( is_callable( $s['on_get'] ) ) {
                ( $s['on_get'] )( $s['gets'] );
            }
            if ( $s['throw_get'] ) {
                throw new \RuntimeException( 'Divi could not read the variables' );
            }
            if ( null === $s['cache'] ) {
                $all   = array_merge( [ 'numbers' => [], 'strings' => [], 'images' => [], 'links' => [], 'colors' => [], 'fonts' => [], 'gradients' => [] ], $s['stored'] );
                $fonts = is_array( $all['fonts'] ) ? $all['fonts'] : [];
                $own   = [];
                foreach ( self::$customizer_fonts as $fid => $def ) {
                    unset( $fonts[ $fid ] );
                    $own[ $fid ] = [ 'id' => $fid, 'label' => $def['label'], 'value' => $def['default'], 'status' => 'active', 'order' => '--et_global_heading_font' === $fid ? 1 : 2 ];
                }
                $all['fonts'] = array_merge( $own, $fonts );
                foreach ( $all as $type => $items ) {
                    if ( ! is_array( $items ) ) {
                        $all[ $type ] = [];
                        continue;
                    }
                    foreach ( $items as $id => $item ) {
                        $all[ $type ][ $id ] = array_merge( $item, [ 'allowedActions' => [ 'editLabel' => true, 'editValue' => true, 'reorder' => true, 'remove' => true ] ] );
                    }
                }
                $s['cache'] = array_map( static fn ( $items ) => (object) $items, $all );
            }

            return $s['cache'];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,array<string,array<string,mixed>>>
         */
        public static function sanitize_global_variables_data( array $data ): array
        {
            $out = [];
            foreach ( $data as $type => $items ) {
                if ( ! in_array( $type, [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ], true ) || empty( $items ) ) {
                    continue;
                }
                foreach ( $items as $id => $item ) {
                    if ( 'undefined' === $id || empty( $item ) || empty( $item['label'] ) || 'gvid-' !== substr( (string) $id, 0, 5 ) ) {
                        continue;
                    }
                    $gid = sanitize_text_field( (string) $id );
                    foreach ( $item as $k => $v ) {
                        if ( 'allowedActions' === $k ) {
                            continue;
                        }
                        $out[ $type ][ $gid ][ sanitize_text_field( (string) $k ) ] = in_array( $type, [ 'strings', 'gradients' ], true ) && 'value' === $k
                            ? ( is_scalar( $v ) ? (string) $v : '' )
                            : sanitize_text_field( $v );
                    }
                }
            }

            return $out;
        }

        /** @param array<string,mixed> $data */
        public static function set_global_variables( array $data ): void
        {
            if ( ! isset( $GLOBALS['__divi_gv'] ) ) {
                self::resetVariablesForTests();
            }
            $s = &$GLOBALS['__divi_gv'];
            $s['set_calls'][] = $data;
            if ( $s['throw_set'] ) {
                throw new \RuntimeException( 'Divi refused the save' );
            }
            if ( ! current_user_can( 'edit_theme_options' ) || ! et_pb_is_allowed( 'variables_manager' ) ) {
                return; // Divi's own SILENT no-op without the permission
            }
            $s['cache'] = null;
            $data       = self::sanitize_global_variables_data( $data );
            if ( is_callable( $s['mutate'] ) ) {
                $data = ( $s['mutate'] )( $data );
            }
            $s['stored'] = $data; // replace all
            \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
        }
```

and in the final `namespace { ... }` block (next to the other `function_exists` guards) add:

```php
    if ( ! function_exists( 'et_pb_is_allowed' ) ) {
        function et_pb_is_allowed( $capabilities, $role = '' ) { return $GLOBALS['__divi_role_allowed'] ?? true; }
    }
```

Also extend the header comment of the file with one line: `Variables (get_global_variables / set_global_variables) follow the same source file; state lives in $GLOBALS['__divi_gv'].`

- [ ] **Step 2: Write the fixture**

Create `pro-addon/tests/GlobalVariablesFixture.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalData;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Stored variables as Divi stores them (every value a string), plus the usual WordPress shim state. */
final class GlobalVariablesFixture
{
    public const N1  = 'gvid-nnnnnnnn-0000-4000-8000-000000000001';
    public const N2  = 'gvid-nnnnnnnn-0000-4000-8000-000000000002';
    public const S1  = 'gvid-ssssssss-0000-4000-8000-000000000001';
    public const IMG = 'gvid-iiiiiiii-0000-4000-8000-000000000001';

    /** @return array<string,array<string,array<string,string>>> */
    public static function stored(): array
    {
        return [
            'numbers' => [
                self::N1 => [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ],
                self::N2 => [ 'label' => 'Gap', 'value' => '1.5rem', 'order' => '2', 'status' => 'archived' ],
            ],
            'strings' => [
                self::S1 => [ 'label' => 'Phone', 'value' => '555 0100', 'order' => '1', 'status' => 'active' ],
            ],
            'images'  => [
                self::IMG => [ 'label' => 'Logo', 'value' => 'https://example.com/logo.png', 'order' => '1', 'status' => 'active' ],
            ],
        ];
    }

    /** @param array<string,mixed> $extraStored merged over the stored groups (a whole group replaces it) */
    public static function reset( array $extraStored = [] ): void
    {
        GlobalData::resetVariablesForTests( $extraStored + self::stored() );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__divi_role_allowed']  = true;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gv'], $GLOBALS['__divi_role_allowed'] );
    }
}
```

- [ ] **Step 3: Write the failing test**

Create `pro-addon/tests/GlobalVariablesTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariablesTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    public function testAvailableNeedsBothDiviFunctions(): void
    {
        $this->assertTrue( GlobalVariables::available() );
        $this->assertFalse( GlobalVariables::available( 'No\\Such\\Class' ) );
    }

    public function testPermittedNeedsTheCapabilityAndDivisVariablesManagerRole(): void
    {
        $this->assertTrue( GlobalVariables::permitted() );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( GlobalVariables::permitted() );
        GlobalVariablesFixture::reset();
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertFalse( GlobalVariables::permitted() );
    }

    public function testReadReturnsEveryGroupWithoutDivisExtras(): void
    {
        $all = GlobalVariables::read();
        $this->assertSame( [ 'numbers', 'strings', 'images', 'links', 'colors', 'fonts', 'gradients' ], array_keys( (array) $all ) );
        $this->assertSame( [ self::N1, GlobalVariablesFixture::N2 ], array_keys( $all['numbers'] ) );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ], $all['numbers'][ self::N1 ], 'allowedActions is stripped' );
        $this->assertSame( [], $all['fonts'], "Divi's built-in customizer fonts are never part of what is stored" );
        $this->assertSame( [], $all['colors'] );
    }

    public function testAStoredFontStaysButTheCustomizerFontsAreDropped(): void
    {
        $font = 'gvid-ffffffff-0000-4000-8000-000000000001';
        GlobalVariablesFixture::reset( [ 'fonts' => [ $font => [ 'label' => 'Display', 'value' => 'Lora', 'order' => '3', 'status' => 'active' ] ] ] );
        $this->assertSame( [ $font ], array_keys( GlobalVariables::read()['fonts'] ) );
    }

    public function testReadFailsClosed(): void
    {
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->assertNull( GlobalVariables::read() );
    }

    public function testStateKeepsLabelValueAndStatusAsStrings(): void
    {
        $this->assertNull( GlobalVariables::state( null ) );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ], GlobalVariables::state( GlobalVariablesFixture::stored()['numbers'][ self::N1 ] ) );
        $this->assertSame( [ 'label' => '5', 'value' => '', 'status' => 'active' ], GlobalVariables::state( [ 'label' => 5, 'value' => [ 'x' ] ] ), 'a non-scalar value becomes an empty string, a missing status is active' );
    }

    public function testFindLocatesAnIdInAnyGroup(): void
    {
        $all = (array) GlobalVariables::read();
        $this->assertSame( 'strings', GlobalVariables::find( $all, self::S1 )['type'] );
        $this->assertSame( 'images', GlobalVariables::find( $all, self::IMG )['type'] );
        $this->assertNull( GlobalVariables::find( $all, 'gvid-nothing-0000' ) );
    }

    public function testNextOrderIsOneMoreThanTheHighest(): void
    {
        $this->assertSame( 1, GlobalVariables::nextOrder( [] ) );
        $this->assertSame( 6, GlobalVariables::nextOrder( [ 'a' => [ 'order' => '1' ], 'b' => [ 'order' => '5' ], 'c' => [ 'label' => 'no order' ] ] ) );
        $this->assertSame( 3, GlobalVariables::nextOrder( [ 'a' => [ 'order' => 2 ] ] ) );
    }

    public function testWriteHandsDiviTheMapOnceAndReadsBackWhatIsStored(): void
    {
        $map                                = GlobalVariables::sendMap( (array) GlobalVariables::read() );
        $map['numbers']['gvid-new-0000']    = [ 'label' => 'New', 'value' => '2px', 'order' => '3', 'status' => 'active' ];
        $res                                = GlobalVariables::write( $map );
        $this->assertFalse( $res['threw'] );
        $this->assertCount( 1, $GLOBALS['__divi_gv']['set_calls'] );
        $this->assertSame( '2px', $res['after']['numbers']['gvid-new-0000']['value'] );
        $this->assertSame( [ 'label' => 'Logo', 'value' => 'https://example.com/logo.png', 'order' => '1', 'status' => 'active' ], $res['after']['images'][ self::IMG ], 'an unmanaged group comes back as it was' );
    }

    public function testWriteReportsASaveThatThrew(): void
    {
        $GLOBALS['__divi_gv']['throw_set'] = true;
        $res                               = GlobalVariables::write( [] );
        $this->assertTrue( $res['threw'] );
        $this->assertNotNull( $res['after'] );
    }

    public function testSendMapKeepsOnlyTheGroupsThatHoldEntries(): void
    {
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( GlobalVariables::sendMap( (array) GlobalVariables::read() ) ) );
    }

    /** @return array<string,array{array<string,mixed>,string,string}> map, expected id, expected reason */
    public static function unsafeMaps(): array
    {
        $ok = [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ];

        return [
            'empty label'          => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'label' => '' ] + $ok ] ], 'gvid-aaa-1', 'empty_label' ],
            'label zero'           => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'label' => '0' ] + $ok ] ], 'gvid-aaa-1', 'empty_label' ],
            'missing label'        => [ [ 'numbers' => [ 'gvid-aaa-1' => array_diff_key( $ok, [ 'label' => 1 ] ) ] ], 'gvid-aaa-1', 'empty_label' ],
            'id without gvid'      => [ [ 'numbers' => [ 'radius-1' => $ok ] ], 'radius-1', 'bad_id' ],
            'id undefined'         => [ [ 'strings' => [ 'undefined' => $ok ] ], 'undefined', 'bad_id' ],
            'array field'          => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'value' => [ 'x' ] ] + $ok ] ], 'gvid-aaa-1', 'non_scalar_field' ],
            'null field'           => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'status' => null ] + $ok ] ], 'gvid-aaa-1', 'non_scalar_field' ],
            'entry not an array'   => [ [ 'numbers' => [ 'gvid-aaa-1' => 'x' ] ], 'gvid-aaa-1', 'not_an_entry' ],
            'a colors group'       => [ [ 'colors' => [ 'gvid-ccc-1' => $ok ] ], 'colors', 'unsupported_type' ],
            'a group not an array' => [ [ 'numbers' => 'x' ], 'numbers', 'bad_group' ],
        ];
    }

    /** @param array<string,mixed> $map */
    #[DataProvider( 'unsafeMaps' )]
    public function testUnsafeEntriesNamesWhatDiviWouldDropOrDamage( array $map, string $id, string $reason ): void
    {
        $this->assertSame( [ [ 'id' => $id, 'reason' => $reason ] ], GlobalVariables::unsafeEntries( $map ) );
    }

    public function testAnEmptyColorsGroupAndTheStoredFixtureAreSafe(): void
    {
        $this->assertSame( [], GlobalVariables::unsafeEntries( GlobalVariablesFixture::stored() + [ 'colors' => [] ] ) );
        $this->assertSame( [], GlobalVariables::unsafeEntries( GlobalVariables::sendMap( (array) GlobalVariables::read() ) ) );
    }

    public function testValidId(): void
    {
        $this->assertTrue( GlobalVariables::validId( 'gvid-98eb727a-9088-4709-8ec8-2fee0213c5c3' ) );
        $this->assertTrue( GlobalVariables::validId( 'gvid-3cf7c9305a' ) );
        foreach ( [ 'gcid-abc', 'gvid-', 'gvid-ab', 'gvid-a b c', "gvid-abc\n", 'GVID-abc', 'gvid-' . str_repeat( 'a', 65 ), 'gvid-abc;', '--et_global_heading_font' ] as $bad ) {
            $this->assertFalse( GlobalVariables::validId( $bad ), $bad );
        }
    }
}
```

- [ ] **Step 4: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablesTest.php`
Expected: errors `Class "AiEditorDivi5\Pro\Settings\GlobalVariables" not found`.

- [ ] **Step 5: Implement the accessor (without the value guards, Task 3 adds them)**

Create `pro-addon/src/Settings/GlobalVariables.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's global variables, and only through Divi's own class (never its option name).
 * Divi's save REPLACES the whole map and keeps only six groups, so a write always carries the complete map; every
 * read goes through `GlobalData::get_global_variables()`, minus what Divi itself adds on read (`allowedActions` and the
 * built-in customizer fonts, which must never be stored). Anything missing, throwing or unreadable fails closed.
 * Entries are arrays of strings: Divi's sanitiser turns every value (and `order`) into a string.
 */
final class GlobalVariables
{
    public const DATA_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The six groups Divi's save keeps; anything else (a stored `colors` group) is erased by it. */
    public const TYPES = [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ];

    /** The two groups this add-on writes. */
    public const MANAGED = [ 'numbers', 'strings' ];

    public const STATUSES = [ 'active', 'archived' ];

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::DATA_CLASS;

        return is_callable( [ $class, 'get_global_variables' ] ) && is_callable( [ $class, 'set_global_variables' ] );
    }

    /** Divi's own bar: set_global_variables() silently writes nothing without both. */
    public static function permitted(): bool
    {
        return current_user_can( 'edit_theme_options' ) && function_exists( 'et_pb_is_allowed' ) && (bool) et_pb_is_allowed( 'variables_manager' );
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>|null type => id => entry as stored; null when unreadable
     */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $all = call_user_func( [ self::DATA_CLASS, 'get_global_variables' ] );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $all ) ) {
            return null;
        }
        $skip = self::customizerFontIds();
        $out  = [];
        foreach ( $all as $type => $group ) {
            if ( ! is_string( $type ) ) {
                return null;
            }
            if ( is_object( $group ) ) {
                $group = (array) $group;
            }
            if ( ! is_array( $group ) ) {
                return null;
            }
            $entries = [];
            foreach ( $group as $id => $entry ) {
                $id = (string) $id;
                if ( 'fonts' === $type && in_array( $id, $skip, true ) ) {
                    continue;
                }
                if ( ! is_array( $entry ) ) {
                    return null; // Divi's replace-all save could not give this entry back faithfully
                }
                unset( $entry['allowedActions'] );
                $entries[ $id ] = $entry;
            }
            $out[ $type ] = $entries;
        }

        return $out;
    }

    /** What counts as "this variable changed": never `order`. */
    public static function state( ?array $entry ): ?array
    {
        if ( null === $entry ) {
            return null;
        }

        return [
            'label'  => self::str( $entry['label'] ?? '' ),
            'value'  => self::str( $entry['value'] ?? '' ),
            'status' => self::str( $entry['status'] ?? 'active' ),
        ];
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $all
     * @return array{type:string,entry:array<string,mixed>}|null
     */
    public static function find( array $all, string $id ): ?array
    {
        foreach ( $all as $type => $group ) {
            if ( is_array( $group ) && isset( $group[ $id ] ) && is_array( $group[ $id ] ) ) {
                return [ 'type' => (string) $type, 'entry' => $group[ $id ] ];
            }
        }

        return null;
    }

    /** @param array<string,array<string,mixed>> $group one group's entries */
    public static function nextOrder( array $group ): int
    {
        $max = 0;
        foreach ( $group as $entry ) {
            if ( is_array( $entry ) && isset( $entry['order'] ) && is_scalar( $entry['order'] ) ) {
                $max = max( $max, (int) $entry['order'] );
            }
        }

        return $max + 1;
    }

    /**
     * The groups that hold entries: what is handed to Divi (an empty group adds nothing, and a non-empty `colors` group stays in
     * so that unsafeEntries() can refuse it).
     *
     * @param array<string,mixed> $all
     * @return array<string,mixed>
     */
    public static function sendMap( array $all ): array
    {
        $out = [];
        foreach ( $all as $type => $group ) {
            if ( is_array( $group ) && [] !== $group ) {
                $out[ $type ] = $group;
            }
        }

        return $out;
    }

    /**
     * Hands Divi the COMPLETE map once (it replaces everything; sanitising stays on) and reads back what is stored now.
     *
     * @param array<string,mixed> $map
     * @return array{threw:bool, after:?array<string,array<string,array<string,mixed>>>}
     */
    public static function write( array $map ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::DATA_CLASS, 'set_global_variables' ], $map );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    /**
     * What Divi's own save would delete or flatten if it were handed this map: any group that is not one of the six (a stored
     * `colors` group), an entry with an empty label (PHP `empty()`, so also "0"), an id that is `undefined` or does not start with
     * `gvid-`, and any non-scalar value (the sanitiser flattens it to ''). Refusing beforehand turns silent loss, which no batch
     * could undo, into a clear message.
     *
     * @param array<mixed> $map type => id => entry (what sendMap() returns)
     * @return list<array{id:string,reason:string}>
     */
    public static function unsafeEntries( array $map ): array
    {
        $out = [];
        foreach ( $map as $type => $group ) {
            if ( ! in_array( $type, self::TYPES, true ) ) {
                if ( ! is_array( $group ) || [] !== $group ) {
                    $out[] = [ 'id' => (string) $type, 'reason' => 'unsupported_type' ];
                }
                continue;
            }
            if ( ! is_array( $group ) ) {
                $out[] = [ 'id' => (string) $type, 'reason' => 'bad_group' ];
                continue;
            }
            foreach ( $group as $id => $entry ) {
                $reason = self::unsafeReason( $id, $entry );
                if ( null !== $reason ) {
                    $out[] = [ 'id' => (string) $id, 'reason' => $reason ];
                }
            }
        }

        return $out;
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^gvid-[A-Za-z0-9-]{3,64}$/D', $id );
    }

    private static function unsafeReason( mixed $id, mixed $entry ): ?string
    {
        if ( ! is_string( $id ) || 'undefined' === $id || ! str_starts_with( $id, 'gvid-' ) ) {
            return 'bad_id';
        }
        if ( ! is_array( $entry ) ) {
            return 'not_an_entry';
        }
        if ( empty( $entry['label'] ) ) {
            return 'empty_label';
        }
        foreach ( $entry as $key => $value ) {
            if ( 'allowedActions' !== $key && ! is_scalar( $value ) ) {
                return 'non_scalar_field';
            }
        }

        return null;
    }

    /** @return list<string> the ids of Divi's built-in font variables (empty when this Divi does not expose them) */
    private static function customizerFontIds(): array
    {
        $class = self::DATA_CLASS;
        if ( ! property_exists( $class, 'customizer_fonts' ) ) {
            return [];
        }
        $fonts = $class::$customizer_fonts;

        return is_array( $fonts ) ? array_map( 'strval', array_keys( $fonts ) ) : [];
    }

    private static function str( mixed $value ): string
    {
        return is_scalar( $value ) ? (string) $value : '';
    }
}
```

In `pro-addon/src/autoload.php`, directly after `require_once __DIR__ . '/Kinds/GlobalColorKind.php';` add:

```php
require_once __DIR__ . '/Settings/GlobalVariables.php';
```

- [ ] **Step 6: Run the test and the whole suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablesTest.php`
Expected: all pass.
Run: `make test-pro`
Expected: green (the shim additions must not break the colour or Theme Builder tests).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Settings/GlobalVariables.php pro-addon/src/autoload.php pro-addon/tests/DiviGlobalDataShims.php pro-addon/tests/GlobalVariablesFixture.php pro-addon/tests/GlobalVariablesTest.php
git commit -m "feat(pro): GlobalVariables accessor over Divi's variables API (read, state, write, pre-flight)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Value guards (numbers grammar, strings)

**Files:**
- Modify: `pro-addon/src/Settings/GlobalVariables.php` (add `MAX_NUMBER`, `MAX_STRING`, `validNumber`, `validString`, `valueFits`)
- Test: `pro-addon/tests/GlobalVariableValuesTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `GlobalVariables::validNumber( string ): bool`, `::validString( string ): bool`, `::valueFits( string $type, string $value ): bool` (`numbers` or `strings`; any other type false), constants `MAX_NUMBER = 100`, `MAX_STRING = 200`.

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/GlobalVariableValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';

/** The values reach the site's generated CSS: a strict whitelist per type. */
class GlobalVariableValuesTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function goodNumbers(): array
    {
        $cases = [ '12px', '1.5rem', '0', '-4px', '.5em', '100%', '1.5', 'clamp(1rem, 2vw + 1rem, 3rem)', 'calc(100% - 2rem)', 'min(10px,5vw)', 'max(1px, calc(2px * 3))', 'calc((1px + 2px) * 3)', 'calc(calc(calc(calc(1px))))', '10svh', '2ch' ];

        return array_combine( $cases, array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    /** @return array<string,array{string}> */
    public static function badNumbers(): array
    {
        $cases = [ '', ' 12px', '12px ', '12 px', '12px;', 'calc(1px; x)', 'url(x)', 'var(--x)', '12px !important', '12pt', '1e3px', 'calc(1px', 'calc(1px))', 'clamp(1px, 2px)', 'clamp(1px,2px,3px,4px)', 'min()', '(1px)', 'calc(calc(calc(calc(calc(1px)))))', str_repeat( '1', 101 ), "12px\n", 'red', 'calc(1px + var(--a))', '{12px}', "12px\0", 'calc(1px, 2px)', '1px 2px', 'calc()', 'calc(1px +)', '--1px', '+1px', '12px"', "12px'", '12px\\' ];

        return array_combine( array_map( static fn ( string $c ): string => '' === $c ? '(empty)' : (string) json_encode( $c, JSON_INVALID_UTF8_SUBSTITUTE ), $cases ), array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    #[DataProvider( 'goodNumbers' )]
    public function testANumberValueIsAccepted( string $value ): void
    {
        $this->assertTrue( GlobalVariables::validNumber( $value ), $value );
        $this->assertTrue( GlobalVariables::valueFits( 'numbers', $value ) );
    }

    #[DataProvider( 'badNumbers' )]
    public function testANumberValueIsRefused( string $value ): void
    {
        $this->assertFalse( GlobalVariables::validNumber( $value ) );
        $this->assertFalse( GlobalVariables::valueFits( 'numbers', $value ) );
    }

    /** @return array<string,array{string}> */
    public static function goodStrings(): array
    {
        $cases = [ 'Call us today', '555 0100', 'Built with care. Since 1999!', "Caf\u{E9} \u{2615} na\u{EF}ve", 'a', '$99 off', '50% off (this week)', 'Tom & Jerry' ];

        return array_combine( $cases, array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    /** @return array<string,array{string}> */
    public static function badStrings(): array
    {
        $cases = [ '', ' x', 'x ', 'a{b}', 'a;b', 'a\\b', 'a<b>', 'a"b', "a'b", 'a`b', "a\nb", "a\0b", 'url(x)', 'URL (x)', '@import x', 'Expression(1)', '$variable({})$', '$VARIABLE({})$', str_repeat( 'a', 201 ), "bad \xC3\x28 utf8", "tab\there" ];

        return array_combine( array_map( static fn ( string $c ): string => '' === $c ? '(empty)' : (string) json_encode( $c, JSON_INVALID_UTF8_SUBSTITUTE ), $cases ), array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    #[DataProvider( 'goodStrings' )]
    public function testAStringValueIsAccepted( string $value ): void
    {
        $this->assertTrue( GlobalVariables::validString( $value ), $value );
        $this->assertTrue( GlobalVariables::valueFits( 'strings', $value ) );
    }

    #[DataProvider( 'badStrings' )]
    public function testAStringValueIsRefused( string $value ): void
    {
        $this->assertFalse( GlobalVariables::validString( $value ) );
        $this->assertFalse( GlobalVariables::valueFits( 'strings', $value ) );
    }

    public function testTheLimitsAreExactlyTheSpecs(): void
    {
        $this->assertTrue( GlobalVariables::validString( str_repeat( 'a', 200 ) ) );
        $this->assertTrue( GlobalVariables::validString( str_repeat( "\u{E9}", 200 ) ), '200 characters, not 200 bytes' );
        $this->assertTrue( GlobalVariables::validNumber( '1' . str_repeat( '0', 99 ) ) );
    }

    public function testOnlyNumbersAndStringsHaveARule(): void
    {
        foreach ( [ 'images', 'links', 'fonts', 'gradients', 'colors', '' ] as $type ) {
            $this->assertFalse( GlobalVariables::valueFits( $type, '12px' ), $type );
        }
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariableValuesTest.php`
Expected: errors `Call to undefined method ...::validNumber()`.

- [ ] **Step 3: Implement the guards**

In `pro-addon/src/Settings/GlobalVariables.php`: add the constants next to `STATUSES`:

```php
    public const MAX_NUMBER = 100;
    public const MAX_STRING = 200;

    private const NUMBER_RE = '/\G-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|%|vh|vw|vmin|vmax|ch|svh|dvh|lvh)?/';
    private const MAX_DEPTH = 4;
```

and add these methods before `validId()`:

```php
    /** Whether a value is acceptable for a managed type (numbers or strings); any other type has no rule and is refused. */
    public static function valueFits( string $type, string $value ): bool
    {
        return match ( $type ) {
            'numbers' => self::validNumber( $value ),
            'strings' => self::validString( $value ),
            default   => false,
        };
    }

    /**
     * A number with an optional unit, or calc()/clamp()/min()/max() of such numbers (nested at most four deep). The value ends up in the
     * site's CSS, so this is a whitelist: no var(), url(), braces, semicolons, quotes, other functions or `!important`.
     */
    public static function validNumber( string $value ): bool
    {
        if ( '' === $value || strlen( $value ) > self::MAX_NUMBER || $value !== trim( $value ) || '(' === $value[0] ) {
            return false;
        }
        $pos = 0;

        return self::operand( $value, $pos, 0 ) && strlen( $value ) === $pos;
    }

    /**
     * Plain text of 1 to 200 characters. It may reach the site's CSS, so: valid UTF-8, no control characters, none of { } ; \ < > " ' `,
     * and nothing that starts a CSS or Divi expression (url(, @import, expression(, $variable().
     */
    public static function validString( string $value ): bool
    {
        if ( '' === $value || $value !== trim( $value ) || 1 !== preg_match( '//u', $value ) || mb_strlen( $value ) > self::MAX_STRING ) {
            return false;
        }
        if ( 1 === preg_match( '/[\x00-\x1F\x7F{};<>\\\\"\'`]/', $value ) ) {
            return false;
        }

        return 1 !== preg_match( '/url\s*\(|@import|expression\s*\(|\$variable\(/i', $value );
    }

    private static function operand( string $s, int &$p, int $depth ): bool
    {
        if ( $depth > self::MAX_DEPTH ) {
            return false;
        }
        if ( 1 === preg_match( '/\G(calc|clamp|min|max)\(/', $s, $m, 0, $p ) ) {
            $p += strlen( $m[0] );
            if ( ! self::expr( $s, $p, $depth + 1 ) ) {
                return false;
            }
            $args = 1;
            if ( 'calc' !== $m[1] ) {
                while ( $p < strlen( $s ) && ',' === $s[ $p ] ) {
                    ++$p;
                    if ( ! self::expr( $s, $p, $depth + 1 ) ) {
                        return false;
                    }
                    ++$args;
                }
                if ( 'clamp' === $m[1] && 3 !== $args ) {
                    return false;
                }
            }

            return $p < strlen( $s ) && ')' === $s[ $p++ ];
        }
        if ( $p < strlen( $s ) && '(' === $s[ $p ] ) {
            ++$p;
            if ( ! self::expr( $s, $p, $depth + 1 ) ) {
                return false;
            }

            return $p < strlen( $s ) && ')' === $s[ $p++ ];
        }
        if ( 1 === preg_match( self::NUMBER_RE, $s, $m, 0, $p ) ) {
            $p += strlen( $m[0] );

            return true;
        }

        return false;
    }

    /** Operands joined by + - * / with optional spaces around the operators and before a comma or a closing parenthesis. */
    private static function expr( string $s, int &$p, int $depth ): bool
    {
        self::skipSpaces( $s, $p );
        if ( ! self::operand( $s, $p, $depth ) ) {
            return false;
        }
        while ( true ) {
            $q = $p;
            self::skipSpaces( $s, $q );
            if ( $q < strlen( $s ) && str_contains( '+-*/', $s[ $q ] ) ) {
                ++$q;
                self::skipSpaces( $s, $q );
                $p = $q;
                if ( ! self::operand( $s, $p, $depth ) ) {
                    return false;
                }
                continue;
            }
            $p = $q;

            return true;
        }
    }

    private static function skipSpaces( string $s, int &$p ): void
    {
        while ( $p < strlen( $s ) && ' ' === $s[ $p ] ) {
            ++$p;
        }
    }
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariableValuesTest.php`
Expected: all pass (the number grammar was proven against this table before the plan was written).

- [ ] **Step 5: Apply the evidence decision**

Read `## Decisions for the guards` in `docs/global-variables-evidence-5.14.md`. If it says "string guard stays as the spec", do nothing. If it names an extra form to refuse, add that exact pattern to the final `preg_match` of `validString`, add the form to `badStrings()` in the test, and rerun the file.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Settings/GlobalVariables.php pro-addon/tests/GlobalVariableValuesTest.php
git commit -m "feat(pro): number and string value guards for global variables

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `GlobalVariablePlanner`

**Files:**
- Create: `pro-addon/src/Settings/GlobalVariablePlanner.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/GlobalVariablePlannerTest.php`

**Interfaces:**
- Consumes: `GlobalVariables::{MANAGED, STATUSES, validId, valueFits, find, state, nextOrder}`, `GlobalColors::cleanLabel`, `GlobalColors::MAX_LABEL`.
- Produces: `GlobalVariablePlanner::MAX_ENTRIES` (50);
  `normalize( array $entries ): list<array{id:?string,type:?string,label:?string,value:?string,status:?string}>` (throws `\InvalidArgumentException` naming every bad entry);
  `plan( array $state, array $entries, \Closure $newId ): array{changes:list<array>, unchanged:list<string>}` where each change is `['index'=>int,'id'=>string,'type'=>string,'action'=>'create'|'update'|'archive'|'restore','fields'=>list<string>,'before'=>?array,'after'=>array,'write'=>array]` (`before`/`after` are `GlobalVariables::state()` shapes, `write` is the complete entry to store).

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/GlobalVariablePlannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariablePlanner;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariablePlannerTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->seq = 0;
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    /** @param list<mixed> $entries */
    private function plan( array $entries ): array
    {
        return GlobalVariablePlanner::plan( (array) GlobalVariables::read(), GlobalVariablePlanner::normalize( $entries ), function (): string {
            return 'gvid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        } );
    }

    private function refused( array $entries, string $needle ): void
    {
        try {
            $this->plan( $entries );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was changed', $e->getMessage() );
        }
    }

    public function testNormalizeKeepsOnlyTheKnownFieldsAndTrimsValues(): void
    {
        $rows = GlobalVariablePlanner::normalize( [ [ 'type' => 'numbers', 'label' => ' Card radius ', 'value' => ' 12px ' ], [ 'id' => self::N1, 'status' => 'archived' ] ] );
        $this->assertSame( [ 'id' => null, 'type' => 'numbers', 'label' => 'Card radius', 'value' => '12px', 'status' => null ], $rows[0] );
        $this->assertSame( [ 'id' => self::N1, 'type' => null, 'label' => null, 'value' => null, 'status' => 'archived' ], $rows[1] );
    }

    /** @return array<string,array{list<mixed>,string}> */
    public static function badEntries(): array
    {
        return [
            'not an object'                 => [ [ 'x' ], 'must be an object' ],
            'a list'                        => [ [ [ 'a', 'b' ] ], 'must be an object' ],
            'unknown field'                 => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => '1px', 'order' => '5' ] ], 'unknown field "order"' ],
            'a non-string field'            => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => 12 ] ], '"value" must be a string' ],
            'create without type'           => [ [ [ 'label' => 'A', 'value' => '1px' ] ], 'creating a variable needs' ],
            'create without label'          => [ [ [ 'type' => 'numbers', 'value' => '1px' ] ], 'creating a variable needs' ],
            'create without value'          => [ [ [ 'type' => 'numbers', 'label' => 'A' ] ], 'creating a variable needs' ],
            'a type that is not managed'    => [ [ [ 'type' => 'images', 'label' => 'A', 'value' => 'x' ] ], '"type" must be numbers or strings' ],
            'a bad number'                  => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => 'calc(1px; x)' ] ], 'numbers variable' ],
            'a bad string'                  => [ [ [ 'type' => 'strings', 'label' => 'A', 'value' => 'a{b}' ] ], 'strings variable' ],
            'a bad label'                   => [ [ [ 'type' => 'numbers', 'label' => '<b>', 'value' => '1px' ] ], '"label" must be 1 to 60' ],
            'a label of zero'               => [ [ [ 'type' => 'numbers', 'label' => '0', 'value' => '1px' ] ], '"label" must be 1 to 60' ],
            'a bad id'                      => [ [ [ 'id' => 'gcid-abc', 'status' => 'archived' ] ], '"id" must look like gvid-' ],
            'a bad status'                  => [ [ [ 'id' => self::N1, 'status' => 'inactive' ] ], '"status" must be active or archived' ],
            'type on an update'             => [ [ [ 'id' => self::N1, 'type' => 'numbers', 'value' => '2px' ] ], 'never changes type' ],
            'an update that changes nothing' => [ [ [ 'id' => self::N1 ] ], 'at least one of label, value, status' ],
        ];
    }

    /** @param list<mixed> $entries */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'badEntries' )]
    public function testNormalizeRefusesTheWholeRequestNamingTheEntry( array $entries, string $needle ): void
    {
        $this->refused( $entries, $needle );
        $this->refused( $entries, 'variables[0]' );
    }

    public function testASeveralProblemRequestNamesEveryBadEntry(): void
    {
        $this->refused( [ [ 'label' => 'A' ], [ 'type' => 'numbers', 'label' => 'B', 'value' => 'red' ] ], 'variables[1]' );
    }

    public function testCreateAssignsIdOrderAndStatusPerType(): void
    {
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Built with care', 'status' => 'archived' ], [ 'type' => 'numbers', 'label' => 'Pad two', 'value' => '16px' ] ] );
        $this->assertSame( [ 'create', 'create', 'create' ], array_column( $p['changes'], 'action' ) );
        $this->assertSame( [ 'gvid-new-00000000-0000-4000-8000-000000000001', 'gvid-new-00000000-0000-4000-8000-000000000002', 'gvid-new-00000000-0000-4000-8000-000000000003' ], array_column( $p['changes'], 'id' ) );
        $this->assertSame( [ 'label' => 'Pad', 'value' => '8px', 'order' => '3', 'status' => 'active' ], $p['changes'][0]['write'], 'numbers had orders 1 and 2' );
        $this->assertSame( [ 'label' => 'Tagline', 'value' => 'Built with care', 'order' => '2', 'status' => 'archived' ], $p['changes'][1]['write'], 'strings had order 1' );
        $this->assertSame( '4', $p['changes'][2]['write']['order'], 'two creates in one call do not share an order' );
        $this->assertNull( $p['changes'][0]['before'] );
        $this->assertSame( [ 'label' => 'Pad', 'value' => '8px', 'status' => 'active' ], $p['changes'][0]['after'] );
    }

    public function testTheFirstVariableOfATypeGetsOrderOne(): void
    {
        GlobalVariablesFixture::reset( [ 'strings' => [] ] );
        $p = $this->plan( [ [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hi' ] ] );
        $this->assertSame( '1', $p['changes'][0]['write']['order'] );
    }

    public function testStoredOrdersThatAreMissingOrOddAreHandled(): void
    {
        GlobalVariablesFixture::reset( [ 'numbers' => [ self::N1 => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ] ] ] );
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] );
        $this->assertSame( '1', $p['changes'][0]['write']['order'] );
    }

    public function testUpdateChangesOnlyTheGivenFieldsAndKeepsTheRestOfTheEntry(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'value' => '16px' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( [ 'value' ], $c['fields'] );
        $this->assertSame( 'numbers', $c['type'] );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '16px', 'order' => '1', 'status' => 'active' ], $c['write'] );
        $this->assertSame( '12px', $c['before']['value'] );
        $this->assertSame( '16px', $c['after']['value'] );
    }

    public function testAStatusOnlyChangeIsArchiveOrRestore(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'status' => 'archived' ], [ 'id' => self::N2, 'status' => 'active' ] ] );
        $this->assertSame( [ 'archive', 'restore' ], array_column( $p['changes'], 'action' ) );
    }

    public function testAnEntryThatAlreadyIsLikeThatIsUnchanged(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'value' => '12px', 'status' => 'active' ] ] );
        $this->assertSame( [], $p['changes'] );
        $this->assertSame( [ self::N1 ], $p['unchanged'] );
    }

    public function testUpdateValuesAreCheckedAgainstTheTypeOfTheStoredVariable(): void
    {
        $this->refused( [ [ 'id' => self::N1, 'value' => 'Call us' ] ], 'numbers variable' );
        $this->refused( [ [ 'id' => self::S1, 'value' => '12px;' ] ], 'strings variable' );
        $this->assertSame( 'update', $this->plan( [ [ 'id' => self::S1, 'value' => '12px' ] ] )['changes'][0]['action'] );
    }

    public function testAnUnknownIdAnIdOfAnotherGroupAndADuplicateRefuseTheWholeCall(): void
    {
        $this->refused( [ [ 'type' => 'numbers', 'label' => 'Ok', 'value' => '1px' ], [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ] ], 'no variable with the id gvid-doesnotexist-0000' );
        $this->refused( [ [ 'id' => self::IMG, 'label' => 'Renamed' ] ], 'only numbers and strings variables can be changed' );
        $this->refused( [ [ 'id' => self::N1, 'value' => '1px' ], [ 'id' => self::N1, 'value' => '2px' ] ], 'already in this call' );
        $this->refused( [ [ 'id' => '--et_global_heading_font', 'value' => '1px' ] ], 'must look like gvid-' );
    }

    public function testTwoCreatesWithTheSameTypeAndLabelAreRefusedButOtherTypesMayShareALabel(): void
    {
        $this->refused( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '1px' ], [ 'type' => 'numbers', 'label' => 'PAD', 'value' => '2px' ] ], 'already named' );
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '1px' ], [ 'type' => 'strings', 'label' => 'Pad', 'value' => 'x' ] ] );
        $this->assertCount( 2, $p['changes'] );
    }

    public function testMultiByteLabelsAndValuesPass(): void
    {
        $p = $this->plan( [ [ 'type' => 'strings', 'label' => "Caf\u{E9} \u{2615}", 'value' => "Na\u{EF}ve caf\u{E9}" ] ] );
        $this->assertSame( "Caf\u{E9} \u{2615}", $p['changes'][0]['write']['label'] );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablePlannerTest.php`
Expected: errors (class not found).

- [ ] **Step 3: Implement the planner**

Create `pro-addon/src/Settings/GlobalVariablePlanner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_variables request into a change list, or refuses the WHOLE request (variables are applied
 * atomically or not at all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the
 * caller passes the current state (GlobalVariables::read()), so it can be tested without Divi.
 */
final class GlobalVariablePlanner
{
    public const MAX_ENTRIES = 50;

    private const KEYS = [ 'id', 'type', 'label', 'value', 'status' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,type:?string,label:?string,value:?string,status:?string}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "variables[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"type\":\"numbers\",\"label\":\"Card radius\",\"value\":\"12px\"}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, type, label, value, status).";
                }
            }
            $row = [ 'id' => null, 'type' => null, 'label' => null, 'value' => null, 'status' => null ];
            foreach ( self::KEYS as $k ) {
                if ( ! array_key_exists( $k, $e ) || null === $e[ $k ] ) {
                    continue;
                }
                if ( ! is_string( $e[ $k ] ) ) {
                    $errors[] = "{$where}: \"{$k}\" must be a string.";
                    continue;
                }
                $row[ $k ] = $e[ $k ];
            }
            if ( null !== $row['id'] && ! GlobalVariables::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must look like gvid-... (letters, digits and hyphens, as list_global_styles shows it).";
            }
            if ( null !== $row['type'] && ! in_array( $row['type'], GlobalVariables::MANAGED, true ) ) {
                $errors[] = "{$where}: \"type\" must be numbers or strings (the other kinds of variable cannot be written yet).";
            }
            if ( null !== $row['label'] ) {
                $clean = GlobalColors::cleanLabel( $row['label'] );
                if ( null === $clean || empty( $clean ) ) { // Divi drops a variable whose label is empty() in PHP's sense, so "0" too
                    $errors[] = "{$where}: \"label\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, not "0", without angle brackets or control characters.';
                } else {
                    $row['label'] = $clean;
                }
            }
            if ( null !== $row['value'] ) {
                $row['value'] = trim( $row['value'] );
            }
            if ( null !== $row['status'] && ! in_array( $row['status'], GlobalVariables::STATUSES, true ) ) {
                $errors[] = "{$where}: \"status\" must be active or archived.";
            }
            if ( null === $row['id'] ) {
                if ( null === $row['type'] || null === $row['label'] || null === $row['value'] ) {
                    $errors[] = "{$where}: creating a variable needs \"type\", \"label\" and \"value\" (or give \"id\" to change an existing one).";
                } elseif ( in_array( $row['type'], GlobalVariables::MANAGED, true ) && ! GlobalVariables::valueFits( $row['type'], $row['value'] ) ) {
                    $errors[] = "{$where}: " . self::valueRule( $row['type'] );
                }
            } else {
                if ( null !== $row['type'] ) {
                    $errors[] = "{$where}: a variable never changes type; leave \"type\" out when you give an \"id\".";
                }
                if ( null === $row['label'] && null === $row['value'] && null === $row['status'] ) {
                    $errors[] = "{$where}: changing a variable needs at least one of label, value, status.";
                }
            }
            $out[] = $row;
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return $out;
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $state GlobalVariables::read()
     * @param list<array{id:?string,type:?string,label:?string,value:?string,status:?string}> $entries normalize() output
     * @param \Closure():string $newId makes the id of a created variable
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>}
     */
    public static function plan( array $state, array $entries, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $labels    = [];
        $next      = [];
        foreach ( $entries as $i => $e ) {
            $where = "variables[{$i}]";
            if ( null === $e['id'] ) {
                $type = (string) $e['type'];
                $key  = $type . '|' . mb_strtolower( (string) $e['label'] );
                if ( isset( $labels[ $key ] ) ) {
                    $errors[] = "{$where}: another new {$type} variable in this call is already named \"{$e['label']}\".";
                    continue;
                }
                $labels[ $key ] = true;
                $next[ $type ]  ??= GlobalVariables::nextOrder( $state[ $type ] ?? [] );
                $entry          = [ 'label' => (string) $e['label'], 'value' => (string) $e['value'], 'order' => (string) $next[ $type ]++, 'status' => $e['status'] ?? 'active' ];
                $changes[]      = [ 'index' => $i, 'id' => (string) $newId(), 'type' => $type, 'action' => 'create', 'fields' => [ 'label', 'value', 'status' ], 'before' => null, 'after' => GlobalVariables::state( $entry ), 'write' => $entry ];
                continue;
            }
            $id = $e['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            $found       = GlobalVariables::find( $state, $id );
            if ( null === $found ) {
                $errors[] = "{$where}: no variable with the id {$id} (list_global_styles shows the ids; to add a variable leave \"id\" out).";
                continue;
            }
            if ( ! in_array( $found['type'], GlobalVariables::MANAGED, true ) ) {
                $errors[] = "{$where}: {$id} is a {$found['type']} variable; only numbers and strings variables can be changed here yet.";
                continue;
            }
            if ( null !== $e['value'] && ! GlobalVariables::valueFits( $found['type'], $e['value'] ) ) {
                $errors[] = "{$where}: " . self::valueRule( $found['type'] );
                continue;
            }
            $before = (array) GlobalVariables::state( $found['entry'] );
            $after  = $before;
            $fields = [];
            foreach ( [ 'label', 'value', 'status' ] as $f ) {
                if ( null !== $e[ $f ] && $e[ $f ] !== $before[ $f ] ) {
                    $after[ $f ] = $e[ $f ];
                    $fields[]    = $f;
                }
            }
            if ( [] === $fields ) {
                $unchanged[] = $id;
                continue;
            }
            $write = $found['entry'];
            foreach ( $fields as $f ) {
                $write[ $f ] = $after[ $f ];
            }
            $action    = [ 'status' ] === $fields ? ( 'archived' === $after['status'] ? 'archive' : 'restore' ) : 'update';
            $changes[] = [ 'index' => $i, 'id' => $id, 'type' => $found['type'], 'action' => $action, 'fields' => $fields, 'before' => $before, 'after' => $after, 'write' => $write ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged ];
    }

    private static function valueRule( string $type ): string
    {
        return 'numbers' === $type
            ? '"value" of a numbers variable must be a number with an optional unit (px, rem, em, %, vh, vw, vmin, vmax, ch, svh, dvh, lvh) or calc(), clamp(), min() or max() of those; var(), url(), !important and anything else are refused.'
            : '"value" of a strings variable must be 1 to ' . GlobalVariables::MAX_STRING . ' characters of plain text without braces, semicolons, backslashes, angle brackets, quotes, url(, @import, expression( or $variable(.';
    }
}
```

In `pro-addon/src/autoload.php`, after the `Settings/GlobalVariables.php` line add:

```php
require_once __DIR__ . '/Settings/GlobalVariablePlanner.php';
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablePlannerTest.php`
Expected: all pass. The `--et_global_heading_font` id case is refused by `normalize` ("must look like gvid-"), which is the intended behaviour: a customizer font is never addressable.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Settings/GlobalVariablePlanner.php pro-addon/src/autoload.php pro-addon/tests/GlobalVariablePlannerTest.php
git commit -m "feat(pro): GlobalVariablePlanner (atomic change plan for numbers and strings variables)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Lock and undo kind

**Files:**
- Create: `pro-addon/src/Settings/GlobalVariablesLock.php`
- Create: `pro-addon/src/Kinds/GlobalVariableKind.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php` (kind list only)
- Test: `pro-addon/tests/GlobalVariablesLockTest.php`, `pro-addon/tests/GlobalVariableKindTest.php`

**Interfaces:**
- Consumes: `SiteLockingKind` (`lockSite(): bool`, `unlockSite(): void`, plus `ItemKind::{name,current,allowed,restore}`), `Item::make`, `Canon::hash`, `BatchLog`, `GlobalVariables::*`.
- Produces:
  - `GlobalVariablesLock` with `const PREFIX = 'aied_pro_buildlock_gvars_'`, `__construct( int $waitMs = 5000, int $expirySeconds = 30 )`, `acquire(): bool`, `release(): void`.
  - `GlobalVariableKind` (`NAME = 'global_variable'`), `__construct( ?GlobalVariablesLock $lock = null )`, `static itemFor( string $id, string $type, ?array $before, ?array $after, ?string $order = null ): array`. Item `ref` = the `gvid`; `before` = null (a create) or the previous `state` plus `_fields` (changed fields), `_type` and, for an item that removes a variable, `_order`.
  - `restore()` runs UNDER the lock `BatchUndo` holds and never locks itself; reasons: `divi_unavailable`, `not_found`, `changed_since`, `unsafe_to_save`, `divi_refused`.

- [ ] **Step 1: Write the lock test**

Create `pro-addon/tests/GlobalVariablesLockTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/OptionsStubWpdb.php';

/** One global-variables write at a time on the whole site (Divi's save replaces the whole map). */
class GlobalVariablesLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['wpdb']         = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function testItIsHeldUntilReleased(): void
    {
        $a = new GlobalVariablesLock( 0 );
        $b = new GlobalVariablesLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire(), 'a second request cannot take it' );
        $a->release();
        $this->assertTrue( $b->acquire() );
        $b->release();
        $this->assertSame( [], $GLOBALS['__wp_options'], 'release leaves no row behind' );
    }

    public function testItIsOneSiteWideLockWhoseRowUninstallRemoves(): void
    {
        ( new GlobalVariablesLock( 0 ) )->acquire();
        $this->assertCount( 1, $GLOBALS['__wp_options'] );
        $this->assertStringStartsWith( 'aied_pro_buildlock_gvars_', (string) array_key_first( $GLOBALS['__wp_options'] ), 'the Pro uninstall deletes every option starting aied_pro_buildlock_' );
    }

    public function testItIsIndependentOfTheColorsLock(): void
    {
        $colors = new GlobalColorsLock( 0 );
        $vars   = new GlobalVariablesLock( 0 );
        $this->assertTrue( $colors->acquire() );
        $this->assertTrue( $vars->acquire(), 'variables and colors live in different options' );
        $colors->release();
        $vars->release();
    }

    public function testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry(): void
    {
        $lock = new GlobalVariablesLock( 0, 30 );
        $lock->acquire();
        $key = (string) array_key_first( $GLOBALS['__wp_options'] );
        $lock->release();
        $GLOBALS['__wp_options'][ $key ] = ( time() - 40 ) . ':dead';
        $this->assertTrue( ( new GlobalVariablesLock( 0, 30 ) )->acquire(), 'older than 30 seconds: taken over' );
        $GLOBALS['__wp_options'][ $key ] = ( time() - 5 ) . ':live';
        $this->assertFalse( ( new GlobalVariablesLock( 0, 30 ) )->acquire(), 'a fresh lock is respected' );
    }

    public function testOnARealDatabaseItIsTheAtomicInsertIgnore(): void
    {
        $GLOBALS['wpdb'] = new OptionsStubWpdb();
        $a = new GlobalVariablesLock( 0 );
        $b = new GlobalVariablesLock( 0 );
        $this->assertTrue( $a->acquire() );
        $this->assertFalse( $b->acquire() );
        $this->assertStringStartsWith( 'INSERT IGNORE INTO wp_options', $GLOBALS['wpdb']->statements[0] );
    }

    public function testReleasingALockThatIsNotHeldIsHarmless(): void
    {
        ( new GlobalVariablesLock( 0 ) )->release();
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }
}
```

- [ ] **Step 2: Write the kind test**

Create `pro-addon/tests/GlobalVariableKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariableKindTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalVariableKind::NAME => new GlobalVariableKind( new GlobalVariablesLock( 0 ) ) ], $this->log );
    }

    /** Change fields of one variable the way the tool does (full map, one write) and record the batch. @param array<string,string> $fields */
    private function change( string $id, string $type, array $fields ): int
    {
        $all    = (array) GlobalVariables::read();
        $found  = GlobalVariables::find( $all, $id );
        $before = GlobalVariables::state( $found['entry'] ?? null );
        $entry  = $found['entry'] ?? [ 'label' => 'x', 'value' => '1px', 'order' => (string) GlobalVariables::nextOrder( $all[ $type ] ?? [] ), 'status' => 'active' ];
        $map    = GlobalVariables::sendMap( $all );
        $map[ $type ][ $id ] = array_merge( $entry, $fields );
        $res    = GlobalVariables::write( $map );
        $after  = GlobalVariables::state( $res['after'][ $type ][ $id ] ?? null );
        $batch  = $this->log->record( 'manage_global_variables', 'test', [ GlobalVariableKind::itemFor( $id, $type, $before, $after ) ] );

        return (int) $batch['id'];
    }

    public function testItIsASiteLockingKind(): void
    {
        $this->assertInstanceOf( SiteLockingKind::class, new GlobalVariableKind() );
        $this->assertSame( 'global_variable', ( new GlobalVariableKind() )->name() );
    }

    public function testItemForRecordsTheChangedFieldsTheTypeAndHashesTheAfterState(): void
    {
        $before = [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ];
        $after  = [ 'label' => 'Radius', 'value' => '16px', 'status' => 'archived' ];
        $item   = GlobalVariableKind::itemFor( self::N1, 'numbers', $before, $after );
        $this->assertSame( 'global_variable', $item['kind'] );
        $this->assertSame( self::N1, $item['ref'] );
        $this->assertSame( $before + [ '_fields' => [ 'value', 'status' ], '_type' => 'numbers' ], $item['before'] );
        $this->assertSame( Canon::hash( $after ), $item['after_hash'] );
        $this->assertTrue( $item['undo']['available'] );

        $create = GlobalVariableKind::itemFor( 'gvid-new-0001', 'strings', null, $after );
        $this->assertNull( $create['before'] );
        $this->assertStringContainsString( 'gvid-new-0001', $create['label'] );
        $this->assertStringContainsString( 'strings', $create['label'] );
    }

    public function testCurrentIsTheStateOrNull(): void
    {
        $kind = new GlobalVariableKind();
        $this->assertSame( [ 'label' => 'Phone', 'value' => '555 0100', 'status' => 'active' ], $kind->current( [ 'ref' => self::S1 ] ) );
        $this->assertNull( $kind->current( [ 'ref' => 'gvid-nothing-0000' ] ) );
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::S1 ] );
    }

    public function testAllowedNeedsTheCapabilityAndTheVariablesManagerRole(): void
    {
        $kind = new GlobalVariableKind();
        $this->assertTrue( $kind->allowed( [ 'ref' => self::N1 ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::N1 ] ) );
        GlobalVariablesFixture::reset();
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertFalse( $kind->allowed( [ 'ref' => self::N1 ] ) );
    }

    public function testUndoOfAnEditPutsTheValueBackAndLeavesEverythingElseIdentical(): void
    {
        $startingPoint = GlobalVariables::read();
        $id            = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $r             = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( $startingPoint, GlobalVariables::read(), 'every group, including the unmanaged images, is identical to before' );
        $this->assertCount( 2, $GLOBALS['__divi_gv']['set_calls'], 'the change and its undo: one complete write each' );
        $this->assertArrayNotHasKey( 'colors', $GLOBALS['__divi_gv']['set_calls'][1], 'no empty group and no customizer font is handed to Divi' );
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( $GLOBALS['__divi_gv']['set_calls'][1] ) );
    }

    public function testAVariableChangedSinceIsNotOverwrittenUnlessForcedAndForceOnlyRevertsWhatTheBatchChanged(): void
    {
        $id = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $this->change( self::N1, 'numbers', [ 'label' => 'Renamed later' ] );

        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );

        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $now = GlobalVariables::read()['numbers'][ self::N1 ];
        $this->assertSame( '12px', $now['value'], 'the value the batch changed is back' );
        $this->assertSame( 'Renamed later', $now['label'], 'the later rename is kept' );
    }

    public function testUndoOfAnArchiveRestoresTheStatus(): void
    {
        $id = $this->change( self::S1, 'strings', [ 'status' => 'archived' ] );
        $this->assertSame( 'archived', GlobalVariables::read()['strings'][ self::S1 ]['status'] );
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( 'active', GlobalVariables::read()['strings'][ self::S1 ]['status'] );
    }

    public function testUndoOfACreateRemovesTheEntryAndTheUndoIsItselfUndoable(): void
    {
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $this->assertArrayHasKey( 'gvid-new-0001', GlobalVariables::read()['numbers'] );

        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( [ self::N1, self::N2 ], array_keys( GlobalVariables::read()['numbers'] ) );

        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'], 'undoing the undo brings the variable back' );
        $back = GlobalVariables::read()['numbers']['gvid-new-0001'];
        $this->assertSame( [ 'Seven', '7px', 'active' ], [ $back['label'], $back['value'], $back['status'] ] );
        $this->assertSame( '3', $back['order'], 'it comes back in its old place' );
    }

    public function testUndoOfACreateRefusesWhenTheEntryChangedSinceEvenWhenForced(): void
    {
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '8px' ] );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( 'gvid-new-0001', GlobalVariables::read()['numbers'], 'a variable someone changed after our write is never removed' );
        }
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                             = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_role_allowed'] = false;
        $r                              = $this->undo()->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );
    }

    public function testWhenDiviCannotBeReadTheItemFailsAndNothingIsClaimed(): void
    {
        $id                                = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $r                                 = $this->undo()->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
    }

    public function testAnUndoHoldsTheSiteLockFromBeforeItsFirstReadAndReleasesIt(): void
    {
        $id   = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $seen = [];
        $GLOBALS['__divi_gv']['gets']   = 0;
        $GLOBALS['__divi_gv']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( [ 1 ], array_values( array_unique( $seen ) ), 'the lock row exists at every read of the undo, the first included' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'released at the end' );
    }

    public function testAnUndoWhileAnotherVariableChangeIsInProgressIsBusyAndLeavesEverything(): void
    {
        $id    = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $other = new GlobalVariablesLock( 0 );
        $this->assertTrue( $other->acquire() );
        $writes = count( $GLOBALS['__divi_gv']['set_calls'] );

        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'busy', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gv']['set_calls'], 'nothing was written' );

        $other->release();
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'], 'the same undo works once the other change is done' );
    }

    public function testAnUndoThatWouldMakeDiviEraseAColorsGroupIsRefusedAndWritesNothing(): void
    {
        $id = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['stored']['colors'] = [ 'gvid-cccccccc-0000-4000-8000-000000000001' => [ 'label' => 'Brand', 'value' => '#fff', 'order' => '1', 'status' => 'active' ] ];
        $GLOBALS['__divi_gv']['cache']            = null;
        $writes                                   = count( $GLOBALS['__divi_gv']['set_calls'] );

        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'unsafe_to_save', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gv']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertArrayHasKey( 'gvid-cccccccc-0000-4000-8000-000000000001', GlobalVariables::read()['colors'], 'the colors group is still there' );
    }

    public function testADiviThatKeepsADifferentStateThanRequestedIsReportedAsRefused(): void
    {
        $id                             = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['mutate'] = static function ( array $data ): array {
            $data['numbers'][ self::N1 ]['value'] = 'something else';

            return $data;
        };
        $r = $this->undo()->undo( $id );
        $this->assertSame( 'divi_refused', $r['items'][0]['reason'] );
    }
}
```

- [ ] **Step 3: Run both to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablesLockTest.php pro-addon/tests/GlobalVariableKindTest.php`
Expected: errors (classes not found).

- [ ] **Step 4: Implement the lock**

Create `pro-addon/src/Settings/GlobalVariablesLock.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\Pro\Site\BuildLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One global-variables write at a time on the whole site. Divi's save REPLACES the complete variable map, so two requests that
 * each read the map and then write their own copy would silently erase each other's change (and Divi caches its read for the
 * rest of the request). This lock covers the whole read, write and read-back of every Pro variable change and undo: take it
 * BEFORE the request's first read. It is the build lock's atomic database row under a fixed name that starts with the build-lock
 * prefix, so uninstall.php already removes it. A separate lock from the colors one: the data lives in a different option. It
 * cannot stop a save made in the Divi builder itself, which knows nothing of this lock.
 */
final class GlobalVariablesLock
{
    public const PREFIX = 'aied_pro_buildlock_gvars_';

    private BuildLock $lock;

    public function __construct( int $waitMs = 5000, int $expirySeconds = 30 )
    {
        $this->lock = new BuildLock( $waitMs, $expirySeconds, self::PREFIX );
    }

    public function acquire(): bool
    {
        return $this->lock->acquire( 0 );
    }

    public function release(): void
    {
        $this->lock->release( 0 );
    }
}
```

- [ ] **Step 5: Implement the kind**

Create `pro-addon/src/Kinds/GlobalVariableKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi global variable. The item stores the previous state (null = the variable did not exist, so undo removes it) and its
 * type; undo writes the complete map back through GlobalVariables, puts back only the fields the batch changed, and only while
 * the variable still looks the way the batch left it. A variable the batch created is never removed once it has changed since,
 * not even with force. restore() runs under the site lock BatchUndo holds for the whole batch (see SiteLockingKind).
 */
final class GlobalVariableKind implements SiteLockingKind
{
    public const NAME = 'global_variable';

    /** The only fields a change ever touches. */
    private const FIELDS = [ 'label', 'value', 'status' ];

    private GlobalVariablesLock $lock;

    public function __construct( ?GlobalVariablesLock $lock = null )
    {
        $this->lock = $lock ?? new GlobalVariablesLock();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,string>|null $before GlobalVariables::state() before the change, or null when the variable did not exist
     * @param array<string,string>|null $after GlobalVariables::state() as Divi stores it now, or null when it no longer exists
     * @param string|null $order the stored order of a variable this item removes, so that undoing the removal puts it back in place
     * @return array<string,mixed>
     */
    public static function itemFor( string $id, string $type, ?array $before, ?array $after, ?string $order = null ): array
    {
        $fields = [];
        foreach ( self::FIELDS as $field ) {
            if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
                $fields[] = $field;
            }
        }
        $name  = (string) ( $after['label'] ?? $before['label'] ?? '' );
        $label = 'Global variable' . ( '' !== $name ? " \"{$name}\"" : '' ) . " ({$type}, {$id})";
        $keep  = [ '_fields' => $fields, '_type' => $type ] + ( null === $order ? [] : [ '_order' => $order ] );

        return Item::make( self::NAME, $id, $label, null === $before ? null : $before + $keep, $after );
    }

    public function current( array $item ): mixed
    {
        $all = GlobalVariables::read();
        if ( null === $all ) {
            throw new \RuntimeException( "Divi's global variables cannot be read." );
        }
        $found = GlobalVariables::find( $all, (string) $item['ref'] );

        return GlobalVariables::state( $found['entry'] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return GlobalVariables::permitted() && GlobalVariables::available();
    }

    public function lockSite(): bool
    {
        return $this->lock->acquire();
    }

    public function unlockSite(): void
    {
        $this->lock->release();
    }

    /** Runs under the site lock BatchUndo holds: Divi's save replaces the whole map, so two writers must not interleave. */
    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : null;
        $all    = GlobalVariables::read();
        if ( null === $all ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        $found    = GlobalVariables::find( $all, $id );
        $nowState = GlobalVariables::state( $found['entry'] ?? null );

        if ( null === $before ) {
            if ( null === $found ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            if ( Canon::hash( $nowState ) !== (string) ( $item['after_hash'] ?? '' ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a variable someone changed after us
            }
            $type     = $found['type'];
            $order    = (string) ( $found['entry']['order'] ?? '1' );
            unset( $all[ $type ][ $id ] );
            $expected = null;
        } else {
            $type = (string) ( $found['type'] ?? ( $before['_type'] ?? '' ) );
            if ( ! in_array( $type, GlobalVariables::MANAGED, true ) ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            $order  = null;
            $fields = is_array( $before['_fields'] ?? null ) ? $before['_fields'] : self::FIELDS;
            $entry  = $found['entry'] ?? [ 'label' => (string) $before['label'], 'value' => (string) $before['value'], 'order' => (string) ( $before['_order'] ?? '1' ), 'status' => (string) $before['status'] ];
            foreach ( $fields as $field ) {
                if ( in_array( $field, self::FIELDS, true ) && array_key_exists( $field, $before ) ) {
                    $entry[ $field ] = $before[ $field ];
                }
            }
            $all[ $type ][ $id ] = $entry;
            $expected            = GlobalVariables::state( $entry );
        }

        $map = GlobalVariables::sendMap( $all );
        if ( [] !== GlobalVariables::unsafeEntries( $map ) ) {
            return [ 'ok' => false, 'reason' => 'unsafe_to_save' ]; // Divi would delete or damage an entry of the map we must send back
        }

        $res = GlobalVariables::write( $map );
        if ( null === $res['after'] ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }
        $stored = GlobalVariables::state( $res['after'][ $type ][ $id ] ?? null );
        if ( $stored !== $expected ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $id, $type, $nowState, $stored, $order ) ];
    }
}
```

In `pro-addon/src/autoload.php`, after the `Settings/GlobalVariablePlanner.php` line add:

```php
require_once __DIR__ . '/Settings/GlobalVariablesLock.php';
require_once __DIR__ . '/Kinds/GlobalVariableKind.php';
```

In `pro-addon/src/Batch/Batches.php` add `use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;` next to the `GlobalColorKind` import and `new GlobalVariableKind(),` after `new GlobalColorKind(),` in `kinds()`.

In `pro-addon/tests/ToolsTest.php`, `testEveryKindARecordedItemUsesIsRegisteredForUndo`: append `'global_variable'` to the expected list (after `'global_color'`).

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalVariablesLockTest.php pro-addon/tests/GlobalVariableKindTest.php pro-addon/tests/ToolsTest.php`
Expected: all three files pass (the tool list assertions in ToolsTest change in Task 6, not here).

Details to check if a kind test fails: the "comes back in its old place" test relies on the inverse item carrying `_order` (set by `restore()` from the removed entry); `testAnUndoHoldsTheSiteLock…` relies on `BatchUndo` taking `lockSite()` before `check()` (merged earlier).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Settings/GlobalVariablesLock.php pro-addon/src/Kinds/GlobalVariableKind.php pro-addon/src/autoload.php pro-addon/src/Batch/Batches.php pro-addon/tests/GlobalVariablesLockTest.php pro-addon/tests/GlobalVariableKindTest.php pro-addon/tests/ToolsTest.php
git commit -m "feat(pro): GlobalVariablesLock and the global_variable undo kind (a SiteLockingKind)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The tool, registration and parity

**Files:**
- Create: `pro-addon/src/Tools/ManageGlobalVariables.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Modify tests: `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/ProParityTest.php` (only if its assertions name the single exception; they iterate `MCP_REST_ONLY`)
- Test: `pro-addon/tests/ManageGlobalVariablesTest.php`

**Interfaces:**
- Consumes: everything from Tasks 2 to 5, `Args::{listOf,bool,string}`, `PreviewToken::{make,open,spend,TTL}`, `BatchLog::{record,attach}`, `Canon::hash`, `ProTool` (`name()`, `capability()`, `definition()`, `run( array ): array`).
- Produces: `ManageGlobalVariables` (`NAME = 'manage_global_variables'`), `__construct( BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalVariablesLock $lock = null )`; `ProOpenApi::MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables' ]`.
- Result shapes (dry run): `changed:false, dry_run:true, changes:[{index,id|null,type,action,before,after}], preview_token, expires_in, message`. Real run: `changed:true, dry_run:false, changes:[{id,type,action,before,after}], skipped, note, batch` (plus `differs_from_request`, `not_stored`, `not_changed`, `unexpected_changes`, `warning` when they apply). No-op: `changed:false, unchanged:[ids]`.

- [ ] **Step 1: Write the failing tool test**

Create `pro-addon/tests/ManageGlobalVariablesTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use AiEditorDivi5\Pro\Tools\ManageGlobalVariables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class ManageGlobalVariablesTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalVariables $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $this->tool = new ManageGlobalVariables( $this->log, fn (): int => $this->now, function (): string {
            return 'gvid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        }, new GlobalVariablesLock( 0 ) );
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    /** Preview, then apply with the returned token. @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    /** @return array<string,mixed> what a refusal must leave alone */
    private function snapshot(): array
    {
        return [ $GLOBALS['__divi_gv']['stored'], count( $GLOBALS['__divi_gv']['set_calls'] ), count( $this->repo->rows ), $GLOBALS['__wp_options'] ];
    }

    private function refused( array $args, string $needle ): void
    {
        $before = $this->snapshot();
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
        $this->assertSame( $before, $this->snapshot(), 'a refusal changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalVariableKind::NAME => new GlobalVariableKind( new GlobalVariablesLock( 0 ) ) ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_variables', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'variables' ], $d['inputSchema']['required'] );
        foreach ( [ 'variables', 'dry_run', 'preview_token' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 50, $d['inputSchema']['properties']['variables']['maxItems'] );
        $this->assertSame( [ 'numbers', 'strings' ], $d['inputSchema']['properties']['variables']['items']['properties']['type']['enum'] );
        $this->assertSame( [ 'active', 'archived' ], $d['inputSchema']['properties']['variables']['items']['properties']['status']['enum'] );
    }

    public function testADryRunReportsTheChangesAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::S1, 'value' => 'Call now' ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertNotSame( '', $r['preview_token'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'], 'the id of a new variable is assigned when it is applied' );
        $this->assertSame( 'numbers', $r['changes'][0]['type'] );
        $this->assertSame( self::S1, $r['changes'][1]['id'] );
        $this->assertSame( '555 0100', $r['changes'][1]['before']['value'] );
        $this->assertSame( 'Call now', $r['changes'][1]['after']['value'] );
        $this->assertSame( [], $GLOBALS['__divi_gv']['set_calls'], 'a preview writes nothing' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'and takes no lock' );
    }

    public function testARealRunCreatesBothTypesInOneCompleteWriteAndRecordsAnUndoableBatch(): void
    {
        $startImages = GlobalVariables::read()['images'];
        $r           = $this->apply( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Built with care' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'create', 'create' ], array_column( $r['changes'], 'action' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $all = GlobalVariables::read();
        $this->assertSame( [ 'label' => 'Pad', 'value' => '8px', 'order' => '3', 'status' => 'active' ], $all['numbers'][ $r['changes'][0]['id'] ] );
        $this->assertSame( [ 'label' => 'Tagline', 'value' => 'Built with care', 'order' => '2', 'status' => 'active' ], $all['strings'][ $r['changes'][1]['id'] ] );
        $this->assertSame( $startImages, $all['images'], 'the unmanaged group is untouched' );
        $this->assertCount( 1, $GLOBALS['__divi_gv']['set_calls'], 'exactly one complete write' );
        $sent = $GLOBALS['__divi_gv']['set_calls'][0];
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( $sent ), 'no empty group, no colors, no customizer fonts' );
        $this->assertArrayNotHasKey( 'allowedActions', $sent['numbers'][ self::N1 ] );
        $this->assertSame( GlobalVariablesFixture::stored()['numbers'][ self::N1 ], $sent['numbers'][ self::N1 ], 'an untouched entry goes back exactly as stored' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released' );
        $this->assertStringContainsString( 'gvid id', $r['note'] );
    }

    public function testEditArchiveAndRestoreAreNamedForWhatTheyDo(): void
    {
        $r = $this->apply( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ], [ 'id' => self::S1, 'status' => 'archived' ], [ 'id' => self::N2, 'status' => 'active' ] ] ] );
        $this->assertSame( [ 'update', 'archive', 'restore' ], array_column( $r['changes'], 'action' ) );
        $all = GlobalVariables::read();
        $this->assertSame( '16px', $all['numbers'][ self::N1 ]['value'] );
        $this->assertSame( 'archived', $all['strings'][ self::S1 ]['status'] );
        $this->assertSame( 'active', $all['numbers'][ self::N2 ]['status'] );
    }

    public function testAVariableThatAlreadyIsLikeThatChangesNothing(): void
    {
        $r = $this->tool->run( [ 'variables' => [ [ 'id' => self::N1, 'value' => '12px' ] ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ self::N1 ], $r['unchanged'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunNeedsTheTokenAndItWorksOnce(): void
    {
        $args = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] ];
        $this->refused( $args, 'preview_token' );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testATokenForOtherArgumentsAnotherUserOrAnExpiredOneIsRefused(): void
    {
        $args = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '9px' ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAVariableChangedSinceThePreviewIsSkippedAndReportedNeverOverwritten(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['stored']['numbers'][ self::N1 ]['value'] = '99px';
        $GLOBALS['__divi_gv']['cache']                                  = null;
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'changed_since_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );
        $this->assertSame( [], $GLOBALS['__divi_gv']['set_calls'] );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function refusals(): array
    {
        $many = array_fill( 0, 51, [ 'type' => 'numbers', 'label' => 'x', 'value' => '1px' ] );

        return [
            'no variables'         => [ [], 'variables' ],
            'an empty list'        => [ [ 'variables' => [] ], 'variables' ],
            'more than 50'         => [ [ 'variables' => $many ], 'variables' ],
            'variables not a list' => [ [ 'variables' => 'x' ], 'variables' ],
            'dry_run not a bool'   => [ [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'x', 'value' => '1px' ] ], 'dry_run' => 'yes' ], 'dry_run' ],
            'unknown id'           => [ [ 'variables' => [ [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ] ], 'dry_run' => true ], 'no variable with the id' ],
            'an images id'         => [ [ 'variables' => [ [ 'id' => self::IMG, 'label' => 'x' ] ], 'dry_run' => true ], 'only numbers and strings' ],
            'a bad number'         => [ [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'x', 'value' => 'calc(1px; x)' ] ], 'dry_run' => true ], 'numbers variable' ],
            'a bad string'         => [ [ 'variables' => [ [ 'type' => 'strings', 'label' => 'x', 'value' => '}body{' ] ], 'dry_run' => true ], 'strings variable' ],
            'type on an update'    => [ [ 'variables' => [ [ 'id' => self::N1, 'type' => 'strings', 'value' => 'x' ] ], 'dry_run' => true ], 'never changes type' ],
        ];
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testRefusalsChangeNothing( array $args, string $needle ): void
    {
        $this->refused( $args, $needle );
    }

    public function testWithoutDivisVariablesManagerPermissionEvenAPreviewIsRefusedUpFront(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'Variables Manager' );
        $GLOBALS['__divi_role_allowed'] = true;
        $GLOBALS['__wp_caps']           = [ 'edit_theme_options' => false ];
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'Variables Manager' );
    }

    public function testItFailsClosedWhenDiviCannotBeRead(): void
    {
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testASaveThatThrowsAndStoresNothingIsAnErrorNotASuccess(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['throw_set'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'did not save', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released after a failure' );
    }

    public function testASaveDiviIgnoredIsReportedNeverClaimed(): void
    {
        $args                           = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p                              = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['mutate'] = static fn ( array $data ): array => GlobalVariablesFixture::stored(); // Divi keeps what was there
        $r                              = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows, 'nothing recorded, nothing to undo' );
        $this->assertSame( [ 'Pad' ], array_column( $r['not_stored'], 'label' ) );
        $this->assertSame( [ self::N1 ], $r['not_changed'] );
        $this->assertStringContainsString( 'Variables Manager', $r['message'] );
    }

    public function testAnEntryDiviDropsWhileSavingIsReportedAsUnexpected(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['mutate'] = static function ( array $data ): array {
            unset( $data['strings'][ self::S1 ] );

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => self::S1, 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testAStoredColorsGroupIsRefusedBeforeAnythingIsWritten(): void
    {
        GlobalVariablesFixture::reset( [ 'colors' => [ 'gvid-cccccccc-0000-4000-8000-000000000001' => [ 'label' => 'Brand', 'value' => '#fff', 'order' => '1', 'status' => 'active' ] ] ] );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'would delete or damage' );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'colors' );
    }

    public function testAStoredEntryDiviWouldDropIsRefusedNamingItsId(): void
    {
        GlobalVariablesFixture::reset( [ 'strings' => [ self::S1 => [ 'label' => '', 'value' => 'x', 'order' => '1', 'status' => 'active' ] ] ] );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], self::S1 );
    }

    public function testADryRunTakesNoLockAndARealRunHoldsItFromBeforeItsFirstReadUntilTheEnd(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $seen = [];
        $GLOBALS['__divi_gv']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $GLOBALS['__divi_gv']['gets'] = 0;
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ 1 => 0 ], $seen, 'a dry run writes nothing, so it takes no lock' );

        $seen                         = [];
        $GLOBALS['__divi_gv']['gets'] = 0;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->assertSame( 1, $seen[1], 'the lock is already held at the very first read of the request' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'it is released at the end' );
    }

    public function testARealRunWhileAnotherChangeIsInProgressIsRefusedWithoutSpendingTheToken(): void
    {
        $args  = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $other = new GlobalVariablesLock( 0 );
        $this->assertTrue( $other->acquire() );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] ); // a preview needs no lock
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'in progress' );
        $this->assertCount( 1, $GLOBALS['__wp_options'], 'the other request still holds its lock' );
        $other->release();
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the token was not spent by the refused attempt' );
    }

    public function testUndoPutsEverythingBackAndEveryOtherEntryIsByteIdentical(): void
    {
        $start = GlobalVariables::read();
        $r     = $this->apply( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::S1, 'status' => 'archived' ], [ 'id' => self::N1, 'value' => '16px' ] ] ] );
        $this->assertNotSame( $start, GlobalVariables::read() );
        $u = $this->undo()->undo( (int) $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( $start, GlobalVariables::read() );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/ManageGlobalVariablesTest.php`
Expected: errors (class not found).

- [ ] **Step 3: Implement the tool**

Create `pro-addon/src/Tools/ManageGlobalVariables.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalVariablePlanner;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create, edit or archive the site's Divi global variables of type numbers and strings. It never deletes one. Every real run
 * needs the token from a dry run of the same arguments; it writes Divi's COMPLETE variable map once, reads back what Divi
 * really stored and records THAT as one undoable batch. MCP and REST only: the ChatGPT spec has no room.
 *
 * Safeguards, because Divi's save replaces the whole map: a real run holds the site-wide GlobalVariablesLock from before its
 * first read until the write is read back (Divi builder saves are NOT covered); a pre-flight refuses any map Divi would
 * silently delete or flatten entries from, already in the dry run; and Divi's own silent no-op for a user without the
 * Variables Manager permission is checked up front and again by the read-back.
 */
final class ManageGlobalVariables implements ProTool
{
    public const NAME = 'manage_global_variables';

    private const UNAVAILABLE = "Divi's global variable functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private \Closure $clock;
    private \Closure $newId;
    private GlobalVariablesLock $lock;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created variable
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalVariablesLock $lock = null )
    {
        $this->lock  = $lock ?? new GlobalVariablesLock();
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => 'gvid-' . wp_generate_uuid4();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Create, edit or archive the site\'s Divi global variables of type numbers (12px, clamp()) and strings (never deletes). Affects every page using a variable: call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'variables'     => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalVariablePlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a variable (type, label and value needed). With an id (from list_global_styles) = change that numbers or strings variable: any of label, value, status.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'gvid-... of an existing variable; leave out to create one' ],
                                'type'   => [ 'type' => 'string', 'enum' => [ 'numbers', 'strings' ], 'description' => 'Needed to create; never changes afterwards' ],
                                'label'  => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'value'  => [ 'type' => 'string', 'description' => 'numbers: a number with an optional unit or calc()/clamp()/min()/max() of those. strings: plain text up to 200 characters' ],
                                'status' => [ 'type' => 'string', 'enum' => [ 'active', 'archived' ], 'description' => 'archived retires a variable without deleting it' ],
                            ],
                        ],
                    ],
                    'dry_run'       => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token' => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'variables' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $entries = GlobalVariablePlanner::normalize( Args::listOf( $arguments, 'variables', GlobalVariablePlanner::MAX_ENTRIES ) );
        $dry     = Args::bool( $arguments, 'dry_run' );
        $token   = Args::string( $arguments, 'preview_token', false, 8000 );

        // set_global_variables() silently writes nothing without these, so say so before promising anything (even a preview).
        if ( ! GlobalVariables::permitted() ) {
            throw new \InvalidArgumentException( "Divi does not let your user manage global variables (it needs edit_theme_options and the Variables Manager permission in Divi's Role Editor), so nothing was changed." );
        }
        if ( $dry ) {
            return $this->execute( $entries, true, $token ); // a preview writes nothing, so it needs no lock
        }
        // Before the request's first read: Divi caches that read for the rest of the request, so a lock taken after it
        // would protect a snapshot another request may already have outdated.
        if ( ! $this->lock->acquire() ) {
            throw new \InvalidArgumentException( 'Another variable change is in progress on this site; nothing was changed. Try again in a moment.' );
        }
        try {
            return $this->execute( $entries, false, $token );
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function execute( array $entries, bool $dry, ?string $token ): array
    {
        $before = GlobalVariables::read();
        if ( null === $before ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        $plan = GlobalVariablePlanner::plan( $before, $entries, $this->newId );
        if ( [] === $plan['changes'] ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every variable already is exactly like that; nothing changed.' ];
        }

        $now      = ( $this->clock )();
        $argsHash = Canon::hash( [ 'variables' => $entries ] );
        $userId   = (int) get_current_user_id();
        if ( $dry ) {
            self::refuseUnsafe( GlobalVariables::sendMap( self::withChanges( $before, $plan['changes'] ) ) );
            $items = [];
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
            }

            return [
                'changed'       => false,
                'dry_run'       => true,
                'changes'       => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'type' => $c['type'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'] ], $plan['changes'] ),
                'preview_token' => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'    => PreviewToken::TTL,
                'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it.',
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the preview_token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $token );

        $kept    = [];
        $skipped = [];
        foreach ( $plan['changes'] as $c ) {
            if ( isset( $map[ $c['index'] ] ) && hash_equals( $map[ $c['index'] ], Canon::hash( $c['before'] ) ) ) {
                $kept[] = $c;
            } else {
                $skipped[] = [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'reason' => isset( $map[ $c['index'] ] ) ? 'changed_since_preview' : 'not_in_preview' ];
            }
        }
        if ( [] === $kept ) {
            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every variable changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        // Divi caches its read for the rest of the request, so within one request this can only notice a change made by code
        // that resets that cache; protection against another REQUEST is the lock taken in run(), which Divi builder saves ignore.
        $fresh = GlobalVariables::read();
        if ( null === $fresh || self::fingerprint( $fresh ) !== self::fingerprint( $before ) ) {
            throw new \InvalidArgumentException( "The site's global variables changed while this call was running; nothing was written. Call again with dry_run=true." );
        }

        $send = GlobalVariables::sendMap( self::withChanges( $before, $kept ) );
        self::refuseUnsafe( $send );
        $res   = GlobalVariables::write( $send );
        $after = $res['after'];
        if ( null === $after ) {
            throw new \RuntimeException( "Divi's global variables could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
        }

        $recorded   = [];
        $applied    = [];
        $notStored  = [];
        $notChanged = [];
        $differs    = [];
        foreach ( $kept as $c ) {
            $stored = GlobalVariables::state( $after[ $c['type'] ][ $c['id'] ] ?? null );
            if ( 'create' === $c['action'] && null === $stored ) {
                $notStored[] = [ 'index' => $c['index'], 'label' => $c['after']['label'] ];
                continue;
            }
            if ( 'create' !== $c['action'] && $stored === $c['before'] ) {
                $notChanged[] = $c['id'];
                continue;
            }
            if ( $stored !== $c['after'] ) {
                $differs[] = [ 'id' => $c['id'], 'requested' => $c['after'], 'stored' => $stored ];
            }
            $recorded[] = GlobalVariableKind::itemFor( $c['id'], $c['type'], $c['before'], $stored );
            $applied[]  = [ 'id' => $c['id'], 'type' => $c['type'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $stored ];
        }
        $changedIds = array_column( $kept, 'id' );
        $unexpected = [];
        foreach ( $before as $type => $group ) {
            foreach ( $group as $id => $entry ) {
                if ( in_array( $id, $changedIds, true ) ) {
                    continue;
                }
                $now_ = $after[ $type ][ $id ] ?? null;
                if ( null === $now_ ) {
                    $unexpected[] = [ 'id' => $id, 'reason' => 'removed_by_divi' ];
                } elseif ( GlobalVariables::state( $now_ ) !== GlobalVariables::state( $entry ) ) {
                    $unexpected[] = [ 'id' => $id, 'reason' => 'changed' ];
                }
            }
        }

        if ( [] === $recorded ) {
            if ( $res['threw'] ) {
                throw new \RuntimeException( 'Divi did not save the variables, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the call, but none of the requested changes is stored (Divi silently ignores a save from a user without the Variables Manager permission); nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global variables: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed'  => true,
            'dry_run'  => false,
            'changes'  => $applied,
            'skipped'  => $skipped,
            'note'     => "A variable only shows on pages that cite its gvid id in a layout (list_global_styles shows the ids). Divi's caches were cleared.",
        ];
        foreach ( [ 'differs_from_request' => $differs, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected ] as $key => $list ) {
            if ( [] !== $list ) {
                $result[ $key ] = $list;
            }
        }
        if ( $res['threw'] ) {
            $result['warning'] = 'Divi reported an error while saving; what is stored now is what was recorded, and undo_batch can put the previous state back.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $all
     * @param list<array<string,mixed>> $changes
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function withChanges( array $all, array $changes ): array
    {
        foreach ( $changes as $c ) {
            $all[ $c['type'] ][ $c['id'] ] = $c['write'];
        }

        return $all;
    }

    /** @param array<string,mixed> $map the exact map that would be sent to Divi */
    private static function refuseUnsafe( array $map ): void
    {
        $unsafe = GlobalVariables::unsafeEntries( $map );
        if ( [] === $unsafe ) {
            return;
        }
        $list = implode( ', ', array_map( static fn ( array $u ): string => $u['id'] . ' (' . $u['reason'] . ')', $unsafe ) );
        throw new \InvalidArgumentException( "Divi would delete or damage these existing variables when it saves the list, and the change could not bring them back: {$list}. Nothing was changed. Fix or remove them in Divi's builder (Variables Manager) first." );
    }

    /** @param array<string,array<string,array<string,mixed>>> $all */
    private static function fingerprint( array $all ): string
    {
        $norm = [];
        foreach ( $all as $type => $group ) {
            foreach ( $group as $id => $entry ) {
                $norm[ $type ][ $id ] = GlobalVariables::state( $entry );
            }
        }

        return Canon::hash( $norm );
    }
}
```

In `pro-addon/src/autoload.php`, after the `Tools/ManageGlobalColors.php` line add `require_once __DIR__ . '/Tools/ManageGlobalVariables.php';`.

In `pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\ManageGlobalVariables;` after the `ManageGlobalColors` import and `new ManageGlobalVariables( $log ),` after `new ManageGlobalColors( $log ),`.

In `pro-addon/src/Rest/ProOpenApi.php`: change the constant and its comment to

```php
    /** Tools that exist over MCP and REST only: the 16 free + 14 Pro operations already use all 30 the ChatGPT importer accepts. */
    public const MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables' ];
```

and update the class docblock sentence "the first 14 Pro tools use the rest" if it names only one exception (keep it true: the first 14 Pro tools use the rest).

- [ ] **Step 4: Update the parity tests**

`pro-addon/tests/ToolsTest.php`:
- `testThisReleaseShipsExactlyTheseTools`: append `'manage_global_variables'` after `'manage_global_colors'`.
- `testTheChatGptOperationBudgetIsRespected`: change the comment ("The 15th and 16th Pro tools, manage_global_colors and manage_global_variables, are MCP + REST only ... (16 free + 14 Pro = 30, exactly)") and the last assertion to `$this->assertSame( [ 'manage_global_colors', 'manage_global_variables' ], ProOpenApi::MCP_REST_ONLY, '...' );`.

`pro-addon/tests/ProOpenApiTest.php` (`testEveryOperationDocumentsTheStatusesAClientCanGet`): keep `assertCount( 14, $paths )`, and add `$this->assertArrayNotHasKey( '/pro/manage_global_variables', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );` after the colours line.

`pro-addon/tests/ProParityTest.php` needs no edit if it only iterates `ProOpenApi::MCP_REST_ONLY` (it does at lines 70 to 91, and its `assertCount( 14, ... )` stays 14). Run it to confirm.

- [ ] **Step 5: Run the tool test and the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/ManageGlobalVariablesTest.php`
Expected: all pass.
Run: `make test-pro`
Expected: green (1136 + the new tests). If an unrelated suite (for example a test that counts registered tools, REST routes or the Pro admin tab's tool list) fails because there are now 16 tools, fix that test's expected number; do not weaken any assertion about the 30-operation budget.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Tools/ManageGlobalVariables.php pro-addon/src/autoload.php pro-addon/src/Tools.php pro-addon/src/Rest/ProOpenApi.php pro-addon/tests/ManageGlobalVariablesTest.php pro-addon/tests/ToolsTest.php pro-addon/tests/ProOpenApiTest.php
git commit -m "feat(pro): manage_global_variables (16th Pro tool, MCP + REST only)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Live gate `make verify-pro-global-variables`

**Files:**
- Create: `scripts/verify-pro-global-variables.php`
- Modify: `Makefile` (`.PHONY` list and the target)

**Interfaces:**
- Consumes: the Docker dev site with the free plugin and the Pro add-on active; `ToolRegistry`, `Tools::all()`, `GlobalVariables`, `BatchTable`, `HistoryTable`, `scripts/dev-guard.php`.
- Produces: a self-cleaning script printing `PASS`/`FAIL` lines and `Success: ...`.

- [ ] **Step 1: Write the script**

Create `scripts/verify-pro-global-variables.php`:

```php
<?php
/**
 * Real-site check of the Pro manage_global_variables tool on Divi's own global variables: the evidence gate for that tool.
 * Through the real ToolRegistry it proves: a dry run changes nothing; a create of one numbers and one strings variable changes
 * exactly those two entries and every other stored variable (all groups, with their order) is untouched; an edit and an archive
 * each change exactly one entry; forbidden values and unknown ids are refused and change nothing; the token is required and single
 * use; undo walks it all back and the stored variables are IDENTICAL to the start, and a created variable that changed since its
 * batch is not removed by undoing the create.
 * Not covered live: Divi's refusal for a user without the Variables Manager permission (the dev admin always has it); the unit
 * tests cover that path against a shim that follows Divi's source.
 *
 * Run: make verify-pro-global-variables   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 * Dev sites only. WARNING: it records about 5 batches and deletes them at the end; the batch store keeps only the newest 20, so on a
 * site that already has more than 15 batches the run evicts the oldest real ones (it warns, and the final count check expects it).
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}

global $wpdb;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$batchIds = [];
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
$call     = static function ( string $name, array $args ) use ( $registry, &$batchIds ): array {
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        return [ 'error' => [ 'code' => 0, 'message' => 'not claimed' ], 'result' => null ];
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'result' => null ];
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return [ 'error' => null, 'result' => $result ];
};
$batchCount = static fn (): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ); // phpcs:ignore
$errHas     = static fn ( array $r, string $needle ): bool => null !== $r['error'] && false !== stripos( (string) $r['error']['message'], $needle );

/** Every stored variable of every group (label, value, status, order, every other key), optionally except some ids. @param list<string> $except */
$fingerprint = static function ( array $except = [] ): string {
    $norm = [];
    foreach ( (array) GlobalVariables::read() as $type => $group ) {
        foreach ( $group as $id => $entry ) {
            if ( ! in_array( $id, $except, true ) ) {
                $norm[ $type ][ $id ] = array_map( static fn ( $v ) => is_scalar( $v ) ? (string) $v : $v, $entry );
            }
        }
    }

    return md5( serialize( $norm ) );
};
$total = static function (): int {
    $n = 0;
    foreach ( (array) GlobalVariables::read() as $group ) {
        $n += count( $group );
    }

    return $n;
};

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 1. baseline --------------------------------------------------------------------------------------------------
$fpStart      = $fingerprint();
$countStart   = $total();
$batchesStart = $batchCount();
$check( GlobalVariables::available(), "baseline: Divi's variable functions are available" );
$check( GlobalVariables::permitted(), 'baseline: this user may manage variables (edit_theme_options and the Variables Manager permission)' );
WP_CLI::log( "baseline: {$countStart} stored variable(s), {$batchesStart} stored batch row(s)" );
if ( $batchesStart > 15 ) {
    WP_CLI::warning( "the batch store holds {$batchesStart} rows and keeps only 20: this run will evict the oldest real ones (dev site)" );
}
$ids = [];
try {
    // ---- 2. a dry run changes nothing ---------------------------------------------------------------------------------
    $new = [ [ 'type' => 'numbers', 'label' => 'AIED verify radius', 'value' => '12px' ], [ 'type' => 'strings', 'label' => 'AIED verify tagline', 'value' => 'Built with care' ] ];
    $d   = $call( 'manage_global_variables', [ 'variables' => $new, 'dry_run' => true ] );
    $check( null === $d['error'] && true === $d['result']['dry_run'] && false === $d['result']['changed'] && is_string( $d['result']['preview_token'] ?? null ) && null === $d['result']['changes'][0]['id'], 'dry run: a preview, a token, no id yet, changed=false' );
    $check( $fingerprint() === $fpStart, 'the dry run changed nothing' );
    $r = $call( 'manage_global_variables', [ 'variables' => $new ] );
    $check( $errHas( $r, 'preview_token' ), 'a real run without the token is refused' );
    $check( $fingerprint() === $fpStart, 'the refused run changed nothing' );

    // ---- 3. create one of each type ------------------------------------------------------------------------------------
    $c = $call( 'manage_global_variables', [ 'variables' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( null === $c['error'] && true === $c['result']['changed'] && 2 === count( $c['result']['changes'] ) && true === $c['result']['batch']['undoable'], 'create: applied and undoable' );
    $numId   = (string) ( $c['result']['changes'][0]['id'] ?? '' );
    $strId   = (string) ( $c['result']['changes'][1]['id'] ?? '' );
    $ids     = [ $numId, $strId ];
    $createB = (int) ( $c['result']['batch']['id'] ?? 0 );
    $stored  = (array) GlobalVariables::read();
    $check( str_starts_with( $numId, 'gvid-' ) && '12px' === ( $stored['numbers'][ $numId ]['value'] ?? '' ) && 'AIED verify radius' === ( $stored['numbers'][ $numId ]['label'] ?? '' ) && 'active' === ( $stored['numbers'][ $numId ]['status'] ?? '' ), "the number is stored through Divi: {$numId}" );
    $check( str_starts_with( $strId, 'gvid-' ) && 'Built with care' === ( $stored['strings'][ $strId ]['value'] ?? '' ), "the string is stored through Divi: {$strId}" );
    $check( is_string( $stored['numbers'][ $numId ]['order'] ?? null ) && ctype_digit( $stored['numbers'][ $numId ]['order'] ), 'Divi stores the order as a string of digits' );
    $check( $total() === $countStart + 2, 'exactly two entries were added' );
    $check( $fingerprint( $ids ) === $fpStart, 'create: every other stored variable, in every group, is untouched' );
    $reuse = $call( 'manage_global_variables', [ 'variables' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( $errHas( $reuse, 'preview_token' ), 'the token works once' );

    // ---- 4. refusals change nothing ---------------------------------------------------------------------------------------
    $fpNow = $fingerprint();
    foreach ( [
        'a number with a semicolon' => [ [ 'type' => 'numbers', 'label' => 'bad', 'value' => 'calc(1px; x)' ], 'numbers variable' ],
        'a string with braces'      => [ [ 'type' => 'strings', 'label' => 'bad', 'value' => '}body{' ], 'strings variable' ],
        'an unknown id'             => [ [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ], 'no variable with the id' ],
        'a type change'             => [ [ 'id' => $numId, 'type' => 'strings', 'value' => 'x' ], 'never changes type' ],
    ] as $what => [ $entry, $needle ] ) {
        $r = $call( 'manage_global_variables', [ 'variables' => [ $entry ], 'dry_run' => true ] );
        $check( $errHas( $r, $needle ), "refused: {$what}" );
    }
    $check( $fingerprint() === $fpNow, 'the refusals changed nothing' );

    // ---- 5. edit the number --------------------------------------------------------------------------------------------
    $edit = [ [ 'id' => $numId, 'value' => 'clamp(1rem, 2vw + 1rem, 3rem)' ] ];
    $de   = $call( 'manage_global_variables', [ 'variables' => $edit, 'dry_run' => true ] );
    $ed   = $call( 'manage_global_variables', [ 'variables' => $edit, 'preview_token' => (string) ( $de['result']['preview_token'] ?? '' ) ] );
    $editB = (int) ( $ed['result']['batch']['id'] ?? 0 );
    $check( null === $ed['error'] && 'update' === ( $ed['result']['changes'][0]['action'] ?? '' ) && 'clamp(1rem, 2vw + 1rem, 3rem)' === ( GlobalVariables::read()['numbers'][ $numId ]['value'] ?? '' ), 'edit: the value changed and is stored as sent' );
    $check( $fingerprint( $ids ) === $fpStart, 'edit: every other stored variable is untouched' );

    // ---- 6. archive the string -----------------------------------------------------------------------------------------
    $arch = [ [ 'id' => $strId, 'status' => 'archived' ] ];
    $da   = $call( 'manage_global_variables', [ 'variables' => $arch, 'dry_run' => true ] );
    $a    = $call( 'manage_global_variables', [ 'variables' => $arch, 'preview_token' => (string) ( $da['result']['preview_token'] ?? '' ) ] );
    $archB = (int) ( $a['result']['batch']['id'] ?? 0 );
    $check( null === $a['error'] && 'archive' === ( $a['result']['changes'][0]['action'] ?? '' ) && 'archived' === ( GlobalVariables::read()['strings'][ $strId ]['status'] ?? '' ), 'archive: the status is archived (nothing deleted)' );
    $check( $fingerprint( $ids ) === $fpStart, 'archive: every other stored variable is untouched' );

    // ---- 7. undo walks it back; undo of a create refuses once the entry changed since ----------------------------------
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( null === $u['error'] && false === ( $u['result']['fully_undone'] ?? true ) && isset( GlobalVariables::read()['numbers'][ $numId ] ), 'undoing the create while later batches changed the variable does not remove it' );
    foreach ( [ $archB, $editB ] as $b ) {
        $u = $call( 'undo_batch', [ 'batch_id' => $b ] );
        $check( true === ( $u['result']['fully_undone'] ?? false ), "undo of batch {$b}" );
    }
    $check( '12px' === ( GlobalVariables::read()['numbers'][ $numId ]['value'] ?? '' ) && 'active' === ( GlobalVariables::read()['strings'][ $strId ]['status'] ?? '' ), 'the variables are exactly as the create left them' );
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( true === ( $u['result']['fully_undone'] ?? false ) && ! isset( GlobalVariables::read()['numbers'][ $numId ] ) && ! isset( GlobalVariables::read()['strings'][ $strId ] ), 'now the undo of the create removes both variables' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    $all   = (array) GlobalVariables::read();
    $dirty = false;
    foreach ( array_filter( $ids ) as $id ) { // cleanup after an abort: remove any throwaway variable still stored
        foreach ( array_keys( $all ) as $type ) {
            if ( isset( $all[ $type ][ $id ] ) ) {
                unset( $all[ $type ][ $id ] );
                $dirty = true;
            }
        }
    }
    if ( $dirty ) {
        GlobalVariables::write( GlobalVariables::sendMap( $all ) );
    }
}

// ---- 8. the site is exactly as it was ---------------------------------------------------------------------------------------
$check( $fingerprint() === $fpStart, 'every stored variable (all groups, with their order) is identical to the start' );
$check( $total() === $countStart, 'the variable count equals the baseline' );
$batchIds = array_values( array_unique( $batchIds ) );
if ( [] !== $batchIds ) {
    $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
}
// The store keeps the newest 20: with N old rows and k new ones, the old rows left are min(N, 20 - k) (N when nothing was evicted).
$expectedBatches = min( $batchesStart, max( 0, 20 - count( $batchIds ) ) );
$batchesEnd      = $batchCount();
$check( $batchesEnd === $expectedBatches, "after deleting this run's own rows the batch table holds {$batchesEnd} row(s), expected {$expectedBatches} (baseline {$batchesStart}, this run recorded " . count( $batchIds ) . ', so no stray row)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro manage_global_variables verified on the real Divi global variables.' );
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile`: append `verify-pro-global-variables` to the `.PHONY:` list on line 1, and after the `verify-pro-global-colors` target add:

```make
# ---------------------------------------------------------------
# verify-pro-global-variables — real-site evidence gate for manage_global_variables (self-cleaning; free + Pro plugins must be active; dev site only)
# ---------------------------------------------------------------
verify-pro-global-variables:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-variables.php --user=1
```

- [ ] **Step 3: Run the gate on the Docker site**

The Pro add-on must be active on the dev site for the run. Run: `make verify-pro-global-variables`
Expected: every line `PASS`, then `Success: Pro manage_global_variables verified on the real Divi global variables.` Any `FAIL` is a real defect: fix the code (or, if the gate itself is wrong, the gate) and rerun until it is all PASS. Afterwards confirm the site is as before (the script asserts it).

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-pro-global-variables.php Makefile
git commit -m "test(pro): live gate verify-pro-global-variables (create, edit, archive, refusals, undo to identical)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Release prep, docs and the full verification

**Files:**
- Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` (header `Version:` and `AIED_PRO_VERSION` to `0.8.0`), `pro-addon/tests/bootstrap.php` (the `AIED_PRO_VERSION` define to `0.8.0`)
- Modify: `pro-addon/readme.txt` (`Stable tag: 0.8.0`; a `= 0.8.0 =` changelog entry above `= 0.7.0 =`)
- Modify: `pro-addon/README.md` (the tool counts and lists, a short variables paragraph, the ChatGPT budget paragraph)
- Modify: `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md` (an "Implementation notes" section)
- Modify: `docs/feature-requests-2026-10-04.md` (the status line of item 1)

**Interfaces:**
- Consumes: everything above.
- Produces: a releasable 0.8.0 branch state (not released, not pushed).

- [ ] **Step 1: Bump the version**

Edit `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` (line 5 `Version: 0.8.0`, line 22 `define( 'AIED_PRO_VERSION', '0.8.0' );`) and `pro-addon/tests/bootstrap.php` (`define( 'AIED_PRO_VERSION', '0.8.0' );`). Run `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/MainFileTest.php` (it reads the header and the define and checks they agree).

- [ ] **Step 2: Changelog and docs**

`pro-addon/readme.txt`: set `Stable tag:        0.8.0` and insert above `= 0.7.0 =`:

```
= 0.8.0 =
* New: `manage_global_variables` creates, edits and archives your site's Divi global variables of type numbers (for example `12px` or `clamp(1rem, 2vw + 1rem, 3rem)`) and strings (short plain text), so an assistant can set spacing, radius and size tokens before it builds pages. It never deletes a variable. Every real run needs the single-use preview token of a dry run of the same arguments; variables that changed since the preview are skipped and reported, never overwritten. Values are checked strictly (a number with an optional unit or calc, clamp, min, max of those; plain text without braces, semicolons, quotes or code). It needs Divi's Variables Manager permission as well as the theme options permission. One `undo_batch` puts it all back; undoing a created variable removes it, unless it changed since.
* Images, links, fonts and gradients variables are carried through untouched (they are not written yet). A site whose stored variables Divi's own save would damage (for example a stored colors group) is refused with the reason instead of being changed.
* Over MCP and REST only: this is the 16th Pro tool, and the ChatGPT importer's 30 operations are used up by the first 14 (16 free + 14 Pro).
* Fix (also in 0.7.0's code path): an `undo_batch` takes the site-wide lock before it first reads the global colors.
```

(Last bullet: only keep it if `readme.txt`'s 0.7.0 entry does not already mention the undo-lock fix; the fix merged after 0.7.0 was written but 0.7.0 was never released, so folding it into the 0.8.0 notes is accurate.)

`pro-addon/README.md`: change "the fifteen tools" to "the sixteen tools" in both places and append `manage_global_variables` to the `src/Tools/` list; add `src/Settings/GlobalVariables*.php` (the only code that talks to Divi's variables, the value guards, the planner, the lock) to the table row that describes `src/Settings/`; add a paragraph after the global-colors paragraph (line 42) saying: Divi's `set_global_variables()` replaces the whole map, so the tool sends the complete map once and records the read-back; only numbers and strings are written, every other group is passed back untouched; `colors` groups and entries Divi's sanitiser would drop make the call refuse; Divi silently ignores a save without the Variables Manager permission, so the tool checks it up front; created variables show nowhere until a layout cites them (citing is not taught yet, see the spec's phase B); spec and plan paths. Update the ChatGPT paragraph (line 54): "The 15th and 16th Pro tools, `manage_global_colors` and `manage_global_variables`, are MCP + REST only on purpose".

- [ ] **Step 3: Spec implementation notes**

Append to `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md`:

```markdown
## Implementation notes (2026-10-06, from the plan; all narrow the spec)

1. The strings guard also refuses `$variable(` (case-insensitive) and the backtick, and a value or label is trimmed before it is checked. Numbers nest at most four functions deep.
2. A label that is PHP `empty()` (so "0") is refused: Divi's sanitiser drops such an entry.
3. `GlobalVariables::read()` removes Divi's built-in customizer fonts by the ids in `GlobalData::$customizer_fonts` (`--et_global_heading_font`, `--et_global_body_font`; they do not start with `gvid-`); when that property is missing nothing is removed and the pre-flight refuses with `bad_id`, so a Divi that changes this fails closed.
4. An undo item stores the variable's type (`_type`) and, when it removes a variable, its `order` (`_order`), so undoing a removal puts the variable back in its place. An undo cannot recreate a variable that has since disappeared (`not_found`).
5. The live gate does not exercise the missing-Variables-Manager refusal (the dev admin always has the permission); the unit tests cover it against a shim that follows Divi's source (`set_global_variables` returns without writing). The gate asserts the permission is present and that the stored variables are identical after the undo chain.
6. Phase B (teaching the AI to cite variables) is NOT part of this plan; `docs/global-variables-evidence-5.14.md` holds the live evidence that phase starts from.
```

- [ ] **Step 4: Backlog status line**

In `docs/feature-requests-2026-10-04.md` item 1, extend the "Status 2026-10-05" sentence with: "Variables (numbers and strings) built as Pro 0.8.0 (manage_global_variables, MCP + REST only, 2026-10-06); presets remain open (own spec after a grounding spike)."

- [ ] **Step 5: Full verification**

Run, in this order, and confirm each:
- `make test-pro` (all green; report the count)
- `make test` (805 green; the free suite must be unaffected)
- `make verify-pro-global-variables` (all PASS)
- `make verify-pro-global-colors` (45 PASS, 0 FAIL: the colours gate must still pass)
- `make verify-pro-rest` (read-only; the live OpenAPI spec still has the same operations)
- `make build-pro` then `unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -i "GlobalVariable"` shows `GlobalVariables.php`, `GlobalVariablePlanner.php`, `GlobalVariablesLock.php`, `GlobalVariableKind.php`, `ManageGlobalVariables.php` and no `tests/` or `scripts/` files.
- `git status` shows only intended files; `CLAUDE.md` is not staged.

If `make verify-pro-rest` needs the Pro add-on active, activate it for the run and put the dev site back as found.

- [ ] **Step 6: Commit**

```bash
git add pro-addon docs
git commit -m "release(pro): 0.8.0, manage_global_variables (version, changelog, README, spec notes)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review (done while writing this plan)

- **Spec coverage:** tool, arguments and atomic refusals (Tasks 4, 6); value guards (3); pre-flight and Divi quirks, including permission and the colors group (2, 6); read normalisation and customizer fonts (2); write once and read-back, `not_stored`, `unexpected_changes` (6); lock from before the first read for runs and undo (5, 6); batch, undo, create-removal and `changed_since` (5); wiring, MCP + REST only, parity (6); version, docs (8); live gate (7); evidence spike (1); phase B deliberately out of scope (noted in Task 8 Step 3).
- **Spec deviations, all recorded in Task 8 Step 3:** `$variable(` and backtick added to the string guard; label `0` refused; no live low-permission probe.
- **Type consistency:** `GlobalVariables::{read,state,find,nextOrder,sendMap,write,unsafeEntries,valueFits,validId,permitted,available}`, `GlobalVariablePlanner::{normalize,plan}` (change keys `index,id,type,action,fields,before,after,write`), `GlobalVariableKind::itemFor( id, type, before, after, ?order )`, `GlobalVariablesLock::{acquire,release}`, `ManageGlobalVariables::NAME` are used with the same names and signatures in every task.
