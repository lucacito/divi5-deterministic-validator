# get_custom_fields + custom field discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The AI can read one post's non-protected custom field values (`get_custom_fields`, MCP + REST) and see which custom field keys a site's posts use (`custom_fields` section of `get_site_structure`), read-only.

**Architecture:** One pure, testable class `CustomFieldReader` holds every rule (protected/secret filtering, caps, ACF join, discovery aggregation); its thin "real" wrappers call WordPress. `FieldTarget` is the one authorisation rule for "which post's fields may be read" (kept apart from the page-only `EditTarget`). `McpHandler` and `RestController` call the same two classes, exactly like `get_site_structure`.

**Tech Stack:** PHP 8.x, PHPUnit (`vendor/bin/phpunit`), WordPress shims in `tests/`, WP-CLI live gate in Docker (`make verify-custom-fields`).

**Spec:** `docs/superpowers/specs/2026-10-07-get-custom-fields-design.md` (read it first; this plan implements it).

## Global Constraints

- Branch `feat/get-custom-fields`. Never touch `src/` or `wp-plugin/validator/` (validator is out of scope; the two copies stay in sync by not changing).
- Every PHP file under `wp-plugin/src/` starts with `declare(strict_types=1);`, the `AiEditorDivi5\WP` namespace and `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Caps (exact): 100 fields per call; each value cut at 500 characters (`mb_substr`); discovery samples the newest 20 posts of each public post type except attachments; at most 100 keys per type; at most 100 entries in the `keys` argument.
- Never returned: keys starting with `_`, keys for which `is_protected_meta( $key, 'post' )` is true. Withheld and counted in `withheld`: keys matching `/(password|passwd|secret|token|api[_-]?key|licen[cs]e)/i`.
- MCP + REST only. NOT in `OpenApiSpec` (the ChatGPT importer's 30 operations are used up). Tool count 17 -> 18.
- Read-only: the free plugin gets no call to `update_post_meta`, `add_post_meta`, `update_field`, `update_metadata`, `add_metadata`; no remote requests; no raw SQL; no `unserialize`.
- A non-string or non-list argument is rejected (REST 400 / MCP -32602), never cast. `id` must be a positive integer.
- Authorisation: accepted API key whose user has `edit_pages` (`PageAccess::CAP`), then `edit_post` on the target post. Missing post or non-public post type = `not_found`; no `edit_post` = `forbidden`.
- `tests/WpOrgComplianceTest.php` is never weakened; only the justified edits named in Task 3.
- Do NOT bump the plugin version, edit the readme changelog or rebuild `jhmg-ai-editor-for-divi-5.zip`: release is the owner's step (4.7.1 is already waiting for upload).
- Code style: match `SiteStructure.php` (spaces inside parentheses in classes under `wp-plugin/src/` that use it; handlers in `McpHandler`/`RestController` use their own compact style).
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Test command: `vendor/bin/phpunit --filter <Name>` for one class, `make test` for the suite (must exit 0 at the end).

## Review Focus

1. A request for a protected or secret-looking key through `keys` must still return nothing for it (Task 1 test `testAnExplicitlyRequestedHiddenKeyIsStillNotReturned`).
2. Numeric-looking meta keys (`"123"`) become PHP int array keys; they must come back as strings and sort/filter correctly (Task 1 `testNumericLookingKeysStayStrings`).
3. Multibyte values (accents, emoji) must be cut on a character boundary, never mid-byte (Task 1 `testAMultibyteValueIsCutOnACharacterBoundary`).
4. A post with no custom fields at all is a normal answer, `fields: []`, not an error (Task 3 `testAPostWithNoFieldsIsANormalAnswer`).
5. Discovery must not leak keys from posts the user cannot edit, and a type whose sampled posts are all uneditable is omitted (Task 4 `testDiscoverySkipsPostsTheUserCannotEdit`).

---

### Task 1: `CustomFieldReader` pure core

**Files:**
- Create: `wp-plugin/src/CustomFieldReader.php`
- Create: `tests/CustomFieldsReadShims.php`
- Create: `tests/CustomFieldReaderTest.php`
- Modify: `wp-plugin/src/autoload.php` (add `require_once __DIR__ . '/CustomFieldReader.php';` after the `CustomFields.php` line)

**Interfaces:**
- Produces (all `public static`, class `AiEditorDivi5\WP\CustomFieldReader`, `final`):
  - `const SHOWN = 'shown'; const HIDDEN = 'hidden'; const SECRET = 'secret';`
  - `const MAX_FIELDS = 100; const MAX_VALUE = 500; const MAX_KEYS_ARG = 100; const SAMPLE_POSTS = 20; const MAX_KEYS_PER_TYPE = 100;`
  - `classify( string $key ): string` (one of SHOWN/HIDDEN/SECRET)
  - `parseKeys( mixed $value ): ?array` (`[]` = no filter, list of keys, or `null` = invalid)
  - `readWith( array $keys, array $only, callable $valueOf, array $acf ): array{fields: list<array{key:string,value:string,acf:?array{label:string,type:string}}>, withheld:int, truncated:bool}`
  - `acfInfoWith( ?callable $groups, ?callable $fields ): array<string,array{label:string,type:string}>`
  - `discoverWith( array $samples, array $acf ): array{post_types: list<array{post_type:string,sampled:int,keys:list<array{key:string,posts:int,acf:?array}>}>, truncated: bool}`

- [ ] **Step 1: Write the shims**

Create `tests/CustomFieldsReadShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * WordPress read functions driven by $GLOBALS, so the REAL CustomFieldReader / FieldTarget wrappers run without WordPress.
 * Required only by the custom-field tests. Each is guarded, so a richer shim from another test file wins.
 *   __cf_protected : list<string> keys is_protected_meta() answers true for (besides the leading-underscore rule WordPress has)
 *   __cf_types     : array<string,bool> post type => public?
 *   __wp_postmeta  : post id => key => value (from tests/bootstrap.php)
 */

if ( ! function_exists( 'is_protected_meta' ) ) {
    function is_protected_meta( $key, $type = '' ) { return in_array( $key, $GLOBALS['__cf_protected'] ?? [], true ); }
}
if ( ! function_exists( 'get_post_custom' ) ) {
    // Like core: key => list of values.
    function get_post_custom( $id = 0 ) { return array_map( static fn ( $v ) => [ $v ], $GLOBALS['__wp_postmeta'][ (int) $id ] ?? [] ); }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
    function get_post_type_object( $type ) {
        $types = $GLOBALS['__cf_types'] ?? [];

        return isset( $types[ $type ] ) ? (object) [ 'public' => (bool) $types[ $type ] ] : null;
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/CustomFieldReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CustomFieldReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';

class CustomFieldReaderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__cf_protected'] = [ 'wp_protected_by_filter' ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__cf_protected'] );
    }

    /** @param array<string,mixed> $meta */
    private function read( array $meta, array $only = [], array $acf = [] ): array
    {
        return CustomFieldReader::readWith( array_map( 'strval', array_keys( $meta ) ), $only, static fn ( string $k ): mixed => $meta[ $k ], $acf );
    }

    // ---- classify -----------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider( 'keyClasses' )]
    public function testClassify( string $key, string $expected ): void
    {
        $this->assertSame( $expected, CustomFieldReader::classify( $key ) );
    }

    /** @return list<array{string,string}> */
    public static function keyClasses(): array
    {
        return [
            [ 'headline', 'shown' ], [ 'project_url', 'shown' ], [ '123', 'shown' ],
            [ '_edit_lock', 'hidden' ], [ '_headline', 'hidden' ], [ '', 'hidden' ], [ 'wp_protected_by_filter', 'hidden' ],
            [ 'api_key', 'secret' ], [ 'my-api-key', 'secret' ], [ 'apikey', 'secret' ], [ 'Stripe_Token', 'secret' ],
            [ 'admin_password', 'secret' ], [ 'passwd', 'secret' ], [ 'client_secret', 'secret' ], [ 'license_code', 'secret' ], [ 'licence', 'secret' ],
        ];
    }

    // ---- parseKeys ----------------------------------------------------------------------------

    public function testParseKeysNoFilter(): void
    {
        foreach ( [ null, '', [], ' ', ' , ' ] as $none ) {
            $this->assertSame( [], CustomFieldReader::parseKeys( $none ) );
        }
    }

    public function testParseKeysAcceptsAListOrACommaStringTrimmedAndDeduplicated(): void
    {
        $this->assertSame( [ 'a', 'b' ], CustomFieldReader::parseKeys( [ 'a', ' b ', 'a' ] ) );
        $this->assertSame( [ 'a', 'b' ], CustomFieldReader::parseKeys( 'a, b' ) );
        $this->assertSame( [ '123' ], CustomFieldReader::parseKeys( '123' ), 'a numeric-looking key stays a string' );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badKeys' )]
    public function testParseKeysRejectsAnythingElse( mixed $bad ): void
    {
        $this->assertNull( CustomFieldReader::parseKeys( $bad ) );
    }

    /** @return list<array{mixed}> */
    public static function badKeys(): array
    {
        return [ [ 7 ], [ true ], [ [ 1 ] ], [ [ [ 'a' ] ] ], [ [ 'k' => 'a' ] ], [ array_map( 'strval', range( 1, 101 ) ) ] ];
    }

    // ---- readWith -----------------------------------------------------------------------------

    public function testReturnsShownKeysSortedAndWithholdsSecretsAndDropsProtectedOnes(): void
    {
        $r = $this->read( [ 'subtitle' => 'Sub', 'headline' => 'Hi', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE', 'stripe_token' => 'x', 'wp_protected_by_filter' => 'p' ] );
        $this->assertSame( [ 'headline', 'subtitle' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 'Hi', $r['fields'][0]['value'] );
        $this->assertSame( 2, $r['withheld'], 'only secret-looking keys are counted; protected keys are dropped silently' );
        $this->assertFalse( $r['truncated'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $r ) );
    }

    public function testAnExplicitlyRequestedHiddenKeyIsStillNotReturned(): void
    {
        $meta = [ 'headline' => 'Hi', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE' ];
        $r    = $this->read( $meta, [ '_edit_lock', 'api_key', 'headline', 'missing' ] );
        $this->assertSame( [ 'headline' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 1, $r['withheld'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $r ) );
    }

    public function testValuesAreOnlyLoadedForKeysThatAreReturned(): void
    {
        $asked = [];
        CustomFieldReader::readWith( [ 'headline', '_edit_lock', 'api_key' ], [], static function ( string $k ) use ( &$asked ): mixed {
            $asked[] = $k;

            return 'v';
        }, [] );
        $this->assertSame( [ 'headline' ], $asked );
    }

    public function testNumericLookingKeysStayStrings(): void
    {
        $r = $this->read( [ '10' => 'ten', '9' => 'nine' ], [ '10' ] );
        $this->assertSame( [ '10' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 'ten', $r['fields'][0]['value'] );
        $all = $this->read( [ '10' => 'ten', '9' => 'nine' ] );
        $this->assertSame( [ '10', '9' ], array_column( $all['fields'], 'key' ), 'string sort, deterministic' );
        $this->assertIsString( $all['fields'][0]['key'] );
    }

    public function testValuesAreAlwaysStrings(): void
    {
        $r = $this->read( [ 'arr' => [ 1, 2, 3 ], 'flag' => true, 'num' => 5, 'nothing' => null, 'obj' => [ 'a' => 'b' ] ] );
        $by = array_column( $r['fields'], 'value', 'key' );
        $this->assertSame( '[1,2,3]', $by['arr'] );
        $this->assertSame( '1', $by['flag'] );
        $this->assertSame( '5', $by['num'] );
        $this->assertSame( '', $by['nothing'] );
        $this->assertSame( '{"a":"b"}', $by['obj'] );
    }

    public function testAValueIsCutAtTheCapAndTheAnswerSaysSo(): void
    {
        $exact = $this->read( [ 'k' => str_repeat( 'a', CustomFieldReader::MAX_VALUE ) ] );
        $this->assertFalse( $exact['truncated'] );
        $over = $this->read( [ 'k' => str_repeat( 'a', CustomFieldReader::MAX_VALUE + 1 ) ] );
        $this->assertTrue( $over['truncated'] );
        $this->assertSame( CustomFieldReader::MAX_VALUE, mb_strlen( $over['fields'][0]['value'] ) );
    }

    public function testAMultibyteValueIsCutOnACharacterBoundary(): void
    {
        $r = $this->read( [ 'k' => str_repeat( 'é', 600 ) . '😀' ] );
        $this->assertSame( CustomFieldReader::MAX_VALUE, mb_strlen( $r['fields'][0]['value'] ) );
        $this->assertSame( 1, preg_match( '//u', $r['fields'][0]['value'] ), 'still valid UTF-8' );
    }

    public function testTheFieldCapTruncatesButStillCountsLaterSecrets(): void
    {
        $meta = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_FIELDS + 5; $i++ ) {
            $meta[ sprintf( 'k%03d', $i ) ] = 'v';
        }
        $meta['zz_token'] = 'x';
        $meta['zz_password'] = 'y';
        $r = $this->read( $meta );
        $this->assertCount( CustomFieldReader::MAX_FIELDS, $r['fields'] );
        $this->assertTrue( $r['truncated'] );
        $this->assertSame( 2, $r['withheld'] );
        $this->assertSame( 'k000', $r['fields'][0]['key'] );
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $meta = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_FIELDS; $i++ ) {
            $meta[ sprintf( 'k%03d', $i ) ] = 'v';
        }
        $r = $this->read( $meta );
        $this->assertCount( CustomFieldReader::MAX_FIELDS, $r['fields'] );
        $this->assertFalse( $r['truncated'] );
    }

    public function testAcfLabelAndTypeAreJoinedWhenKnownAndNullOtherwise(): void
    {
        $r = $this->read( [ 'headline' => 'Hi', 'plain' => 'x' ], [], [ 'headline' => [ 'label' => 'Headline', 'type' => 'text' ] ] );
        $this->assertSame( [ 'label' => 'Headline', 'type' => 'text' ], $r['fields'][0]['acf'] );
        $this->assertNull( $r['fields'][1]['acf'] );
    }

    public function testNoFieldsIsANormalAnswer(): void
    {
        $this->assertSame( [ 'fields' => [], 'withheld' => 0, 'truncated' => false ], $this->read( [] ) );
    }

    // ---- acfInfoWith --------------------------------------------------------------------------

    public function testAcfInfoCollectsTopLevelAndGroupFieldsWithTheirStoredKeys(): void
    {
        $info = CustomFieldReader::acfInfoWith(
            static fn () => [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ], [ 'no' => 'key' ] ],
            static fn ( string $key ) => 'group_a' === $key
                ? [ [ 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ], [ 'name' => 'address', 'label' => 'Address', 'type' => 'group', 'sub_fields' => [ [ 'name' => 'city', 'label' => 'City', 'type' => 'text' ] ] ], [ 'name' => '', 'type' => 'tab' ] ]
                : false
        );
        $this->assertSame( [ 'headline', 'address', 'address_city' ], array_keys( $info ) );
        $this->assertSame( [ 'label' => 'City', 'type' => 'text' ], $info['address_city'] );
    }

    public function testAcfInfoIsEmptyWhenAcfCannotBeReadOrThrows(): void
    {
        $this->assertSame( [], CustomFieldReader::acfInfoWith( null, null ) );
        $this->assertSame( [], CustomFieldReader::acfInfoWith( static fn () => throw new \RuntimeException( 'x' ), static fn () => [] ) );
        $this->assertSame( [], CustomFieldReader::acfInfoWith( static fn () => 'nope', static fn () => [] ) );
    }

    // ---- discoverWith -------------------------------------------------------------------------

    public function testDiscoveryCountsPostsPerKeyAndFiltersLikeReading(): void
    {
        $d = CustomFieldReader::discoverWith( [
            'book'    => [ [ 'isbn', '_edit_lock', 'api_key', 'isbn' ], [ 'isbn', 'format' ], [] ],
            'page'    => [],
            'project' => [ [ 'project_url' ] ],
        ], [ 'isbn' => [ 'label' => 'ISBN', 'type' => 'text' ] ] );
        $this->assertFalse( $d['truncated'] );
        $this->assertSame( [ 'book', 'project' ], array_column( $d['post_types'], 'post_type' ), 'a type with no sampled posts is omitted' );
        $book = $d['post_types'][0];
        $this->assertSame( 3, $book['sampled'] );
        $this->assertSame( [ [ 'key' => 'isbn', 'posts' => 2, 'acf' => [ 'label' => 'ISBN', 'type' => 'text' ] ], [ 'key' => 'format', 'posts' => 1, 'acf' => null ] ], $book['keys'] );
    }

    public function testDiscoveryCapsKeysPerTypeAndSaysSo(): void
    {
        $keys = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_KEYS_PER_TYPE + 3; $i++ ) {
            $keys[] = sprintf( 'k%03d', $i );
        }
        $d = CustomFieldReader::discoverWith( [ 'book' => [ $keys ] ], [] );
        $this->assertCount( CustomFieldReader::MAX_KEYS_PER_TYPE, $d['post_types'][0]['keys'] );
        $this->assertTrue( $d['truncated'] );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter CustomFieldReaderTest`
Expected: FAIL / error "Failed opening required .../CustomFieldReader.php".

- [ ] **Step 4: Implement the class**

Create `wp-plugin/src/CustomFieldReader.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * READ-ONLY view of a post's custom fields (post meta). Every rule lives in the `*With()` methods, which take plain data
 * and callables so they run without WordPress; the real wrappers (`read()`, `discover()`, `acfInfo()`, added in later
 * tasks) only fetch the data from WordPress and ACF's own public functions. Nothing here writes anything.
 *
 * Never returned: keys starting with an underscore or protected by WordPress. Withheld (and counted): keys that look like
 * secrets. The per-post `edit_post` check is the real protection; the secret filter is a heuristic on top of it.
 */
final class CustomFieldReader
{
    public const SHOWN  = 'shown';
    public const HIDDEN = 'hidden';
    public const SECRET = 'secret';

    public const MAX_FIELDS        = 100;
    public const MAX_VALUE         = 500;
    public const MAX_KEYS_ARG      = 100;
    public const SAMPLE_POSTS      = 20;
    public const MAX_KEYS_PER_TYPE = 100;

    private const SECRET_PATTERN = '/(password|passwd|secret|token|api[_-]?key|licen[cs]e)/i';
    private const MAX_LABEL      = 200;

    /** SHOWN, HIDDEN (never returned, not counted) or SECRET (never returned, counted as withheld). */
    public static function classify( string $key ): string
    {
        if ( '' === $key || str_starts_with( $key, '_' ) ) {
            return self::HIDDEN;
        }
        if ( function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' ) ) {
            return self::HIDDEN;
        }

        return 1 === preg_match( self::SECRET_PATTERN, $key ) ? self::SECRET : self::SHOWN;
    }

    /**
     * The `keys` argument: [] = no filter; a list of keys (a list or a comma separated string, trimmed, no duplicates);
     * null = invalid (a non-list, a non-string entry, an associative array or more than MAX_KEYS_ARG keys).
     *
     * @return ?list<string>
     */
    public static function parseKeys( mixed $value ): ?array
    {
        if ( null === $value || '' === $value || [] === $value ) {
            return [];
        }
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return null;
        }
        $keys = [];
        foreach ( $value as $key ) {
            if ( ! is_string( $key ) ) {
                return null;
            }
            $key = trim( $key );
            if ( '' !== $key ) {
                $keys[ $key ] = true;
            }
        }
        if ( count( $keys ) > self::MAX_KEYS_ARG ) {
            return null;
        }

        return array_map( 'strval', array_keys( $keys ) );
    }

    /**
     * @param list<string>                                  $keys    every meta key the post has
     * @param list<string>                                  $only    [] = all keys; otherwise only these
     * @param callable(string):mixed                        $valueOf loads one value; called ONLY for keys that are returned
     * @param array<string,array{label:string,type:string}> $acf     ACF info by stored key
     * @return array{fields: list<array{key:string,value:string,acf:?array{label:string,type:string}}>, withheld:int, truncated:bool}
     */
    public static function readWith( array $keys, array $only, callable $valueOf, array $acf ): array
    {
        $keys = array_map( 'strval', $keys );
        if ( [] !== $only ) {
            $keys = array_values( array_intersect( $keys, array_map( 'strval', $only ) ) );
        }
        sort( $keys, SORT_STRING );

        $fields    = [];
        $withheld  = 0;
        $truncated = false;
        foreach ( $keys as $key ) {
            $class = self::classify( $key );
            if ( self::HIDDEN === $class ) {
                continue;
            }
            if ( self::SECRET === $class ) {
                ++$withheld;
                continue;
            }
            if ( count( $fields ) >= self::MAX_FIELDS ) {
                $truncated = true;
                continue; // keep looping: later secret-looking keys are still counted
            }
            [ $text, $cut ] = self::valueText( $valueOf( $key ) );
            $truncated      = $truncated || $cut;
            $fields[]       = [ 'key' => $key, 'value' => $text, 'acf' => $acf[ $key ] ?? null ];
        }

        return [ 'fields' => $fields, 'withheld' => $withheld, 'truncated' => $truncated ];
    }

    /**
     * ACF label and type by the key ACF stores the value under (top-level names, group sub fields as group_sub). Empty
     * when ACF cannot be read.
     *
     * @return array<string,array{label:string,type:string}>
     */
    public static function acfInfoWith( ?callable $groups, ?callable $fields ): array
    {
        $out = [];
        if ( null === $groups || null === $fields ) {
            return $out;
        }
        try {
            $list = $groups();
        } catch ( \Throwable ) {
            return $out;
        }
        if ( ! is_array( $list ) ) {
            return $out;
        }
        foreach ( $list as $group ) {
            $groupKey = is_array( $group ) && is_string( $group['key'] ?? null ) ? $group['key'] : '';
            if ( '' === $groupKey ) {
                continue;
            }
            try {
                $found = $fields( $groupKey );
            } catch ( \Throwable ) {
                continue;
            }
            if ( is_array( $found ) ) {
                self::collectAcf( $found, '', $out );
            }
        }

        return $out;
    }

    /**
     * What a site's posts use: for each post type with sampled posts, its shown keys with how many sampled posts have each,
     * most used first, then by name. Keys only, never values.
     *
     * @param array<string,list<list<string>>>              $samples post type => for each sampled post, its meta keys
     * @param array<string,array{label:string,type:string}> $acf
     * @return array{post_types: list<array{post_type:string,sampled:int,keys:list<array{key:string,posts:int,acf:?array{label:string,type:string}}>}>, truncated:bool}
     */
    public static function discoverWith( array $samples, array $acf ): array
    {
        $types     = [];
        $truncated = false;
        foreach ( $samples as $type => $posts ) {
            if ( ! is_array( $posts ) || [] === $posts ) {
                continue;
            }
            $counts = [];
            foreach ( $posts as $postKeys ) {
                foreach ( array_unique( array_map( 'strval', (array) $postKeys ) ) as $key ) {
                    if ( self::SHOWN !== self::classify( $key ) ) {
                        continue;
                    }
                    $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
                }
            }
            $keys = array_map( 'strval', array_keys( $counts ) );
            usort( $keys, static fn ( string $a, string $b ): int => [ $counts[ $b ], $a ] <=> [ $counts[ $a ], $b ] );
            if ( count( $keys ) > self::MAX_KEYS_PER_TYPE ) {
                $truncated = true;
                $keys      = array_slice( $keys, 0, self::MAX_KEYS_PER_TYPE );
            }
            $types[] = [
                'post_type' => (string) $type,
                'sampled'   => count( $posts ),
                'keys'      => array_map( static fn ( string $k ): array => [ 'key' => $k, 'posts' => (int) $counts[ $k ], 'acf' => $acf[ $k ] ?? null ], $keys ),
            ];
        }

        return [ 'post_types' => $types, 'truncated' => $truncated ];
    }

    /** @return array{0:string,1:bool} the value as text, cut at MAX_VALUE characters; whether it was cut */
    private static function valueText( mixed $value ): array
    {
        if ( is_array( $value ) || is_object( $value ) ) {
            $text = (string) wp_json_encode( $value );
        } elseif ( is_bool( $value ) ) {
            $text = $value ? '1' : '';
        } elseif ( is_scalar( $value ) ) {
            $text = (string) $value;
        } else {
            $text = '';
        }
        if ( mb_strlen( $text ) > self::MAX_VALUE ) {
            return [ mb_substr( $text, 0, self::MAX_VALUE ), true ];
        }

        return [ $text, false ];
    }

    /**
     * @param array<mixed>                                  $fields
     * @param array<string,array{label:string,type:string}> $out
     */
    private static function collectAcf( array $fields, string $prefix, array &$out ): void
    {
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
            if ( '' === $name ) {
                continue;
            }
            $stored = $prefix . $name;
            $type   = is_string( $field['type'] ?? null ) ? $field['type'] : '';
            $label  = is_scalar( $field['label'] ?? null ) ? (string) $field['label'] : '';

            $out[ $stored ] = [ 'label' => mb_substr( $label, 0, self::MAX_LABEL ), 'type' => mb_substr( $type, 0, self::MAX_LABEL ) ];
            if ( 'group' === $type && is_array( $field['sub_fields'] ?? null ) ) {
                self::collectAcf( $field['sub_fields'], $stored . '_', $out );
            }
        }
    }
}
```

- [ ] **Step 5: Register the class in the plugin's loader**

In `wp-plugin/src/autoload.php`, directly after `require_once __DIR__ . '/CustomFields.php';` add:

```php
require_once __DIR__ . '/CustomFieldReader.php';
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter CustomFieldReaderTest`
Expected: PASS (all tests green).

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src/CustomFieldReader.php wp-plugin/src/autoload.php tests/CustomFieldsReadShims.php tests/CustomFieldReaderTest.php
git commit -m "$(cat <<'EOF'
feat: CustomFieldReader, the pure rules for reading a post's custom fields

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `FieldTarget` authorisation rule

**Files:**
- Create: `wp-plugin/src/FieldTarget.php`
- Create: `tests/FieldTargetTest.php`
- Modify: `wp-plugin/src/autoload.php` (add `require_once __DIR__ . '/FieldTarget.php';` after the `CustomFieldReader.php` line)

**Interfaces:**
- Produces: `AiEditorDivi5\WP\FieldTarget` (`final`): `const NOT_FOUND = 'not_found'; const FORBIDDEN = 'forbidden';` and `public static function resolve( int $id ): array{post: ?\WP_Post, error: ?string}`. A post of a public post type that the user can `edit_post` gives `['post' => $post, 'error' => null]`; a missing post or a non-public type gives NOT_FOUND (checked first, so nothing leaks about non-public types); a public post without `edit_post` gives FORBIDDEN.

- [ ] **Step 1: Write the failing test**

Create `tests/FieldTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\FieldTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';

/** Which post's custom fields may be read: any public post type the user can edit. Everything else looks missing. */
class FieldTargetTest extends TestCase
{
    private function post( int $id, string $type ): void
    {
        $p             = new \WP_Post();
        $p->ID         = $id;
        $p->post_type  = $type;
        $GLOBALS['__wp_posts'][ $id ] = $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true ];
        $GLOBALS['__cf_types'] = [ 'page' => true, 'post' => true, 'project' => true, 'et_header_layout' => false ];
        $this->post( 5, 'page' );
        $this->post( 6, 'project' );
        $this->post( 7, 'et_header_layout' );
        $this->post( 8, 'ghost' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__cf_types'] );
        $GLOBALS['__wp_can']   = true;
        $GLOBALS['__wp_posts'] = [];
    }

    public function testAPageAndADiviProjectCanBeRead(): void
    {
        foreach ( [ 5, 6 ] as $id ) {
            $r = FieldTarget::resolve( $id );
            $this->assertNull( $r['error'] );
            $this->assertSame( $id, $r['post']->ID );
        }
    }

    public function testMissingNonPublicAndUnknownTypesAreNotFound(): void
    {
        foreach ( [ 0, -3, 999, 7, 8 ] as $id ) {
            $r = FieldTarget::resolve( $id );
            $this->assertSame( FieldTarget::NOT_FOUND, $r['error'], "post {$id}" );
            $this->assertNull( $r['post'] );
        }
    }

    public function testAPublicPostTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:6' => false ];
        $this->assertNull( FieldTarget::resolve( 5 )['error'] );
        $r = FieldTarget::resolve( 6 );
        $this->assertSame( FieldTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );
    }

    public function testANonPublicTypeIsNotFoundEvenWhenTheUserCannotEditIt(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( FieldTarget::NOT_FOUND, FieldTarget::resolve( 7 )['error'], 'nothing leaks about non-public posts' );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter FieldTargetTest`
Expected: FAIL / error "Failed opening required .../FieldTarget.php".

- [ ] **Step 3: Implement**

Create `wp-plugin/src/FieldTarget.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which post's custom fields the plugin may READ: a post of a public post type (a page, a Divi Project, a blog post...) that
 * the current user can edit. Anything else is reported like a missing post, so nothing leaks about non-public posts such as
 * Theme Builder layouts. Separate from EditTarget on purpose: that one decides which posts may be EDITED and stays page-only.
 */
final class FieldTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{post: ?\WP_Post, error: ?string}
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return [ 'post' => null, 'error' => self::NOT_FOUND ];
        }
        $type = get_post_type_object( (string) $post->post_type );
        if ( ! $type || empty( $type->public ) ) {
            return [ 'post' => null, 'error' => self::NOT_FOUND ];
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return [ 'post' => null, 'error' => self::FORBIDDEN ];
        }

        return [ 'post' => $post, 'error' => null ];
    }
}
```

- [ ] **Step 4: Register in the loader**

In `wp-plugin/src/autoload.php`, after the `CustomFieldReader.php` line add:

```php
require_once __DIR__ . '/FieldTarget.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter FieldTargetTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add wp-plugin/src/FieldTarget.php wp-plugin/src/autoload.php tests/FieldTargetTest.php
git commit -m "$(cat <<'EOF'
feat: FieldTarget, which post's custom fields may be read

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: the `get_custom_fields` tool (MCP + REST) and the compliance updates

**Files:**
- Modify: `wp-plugin/src/CustomFieldReader.php` (add the real wrappers `acfInfo()` and `read()`)
- Modify: `wp-plugin/src/McpHandler.php` (tool declaration after `get_site_structure` at ~line 123; dispatch line ~291; new `toolGetCustomFields` after `toolSiteStructure` ~line 687)
- Modify: `wp-plugin/src/RestController.php` (route after the `/site-structure` route ~line 53; handler after `site_structure()` ~line 637)
- Modify: `tests/WpOrgComplianceTest.php` (tool list + count at ~lines 375-382, the `(17 tools)` assertion at ~547, new meta-write scan)
- Modify: `wp-plugin/readme.txt` (lines 46, 53, 131, 171) and `wp-plugin/src/AdminPage.php` (card text at line 622)
- Create: `tests/CustomFieldsLockstepTest.php`

**Interfaces:**
- Consumes: `CustomFieldReader::parseKeys/readWith/acfInfoWith`, `FieldTarget::resolve` (Tasks 1-2), `PageAccess::CAP`, `UsageTracker::log( string $endpoint, ?int $pageId, string $result )`.
- Produces: `CustomFieldReader::acfInfo(): array` and `CustomFieldReader::read( object $post, array $only ): array{id:int, post_type:string, fields:list, withheld:int, truncated:bool}`; MCP tool `get_custom_fields` (args `id` integer required, `keys` list of strings optional); REST `GET /posts/{id}/custom-fields` (`keys` query param); `RestController::custom_fields( WP_REST_Request ): WP_REST_Response|WP_Error`; usage endpoint name `custom_fields`.

- [ ] **Step 1: Write the failing lockstep test**

Create `tests/CustomFieldsLockstepTest.php` (it models `SiteStructureLockstepTest`):

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** get_custom_fields: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class CustomFieldsLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__cf_protected']   = [];
        $GLOBALS['__cf_types']       = [ 'page' => true, 'project' => true, 'et_header_layout' => false ];
        $GLOBALS['__ss_acf_groups']  = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']  = [ 'group_1' => [ [ 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ] ] ];
        $GLOBALS['__wp_caps']        = [ 'edit_pages' => true, 'edit_post' => true ];
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $this->post( 5, 'page', [ 'headline' => 'Hello', 'subtitle' => 'Sub', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE' ] );
        $this->post( 6, 'project', [ 'project_url' => 'https://example.test/p' ] );
        $this->post( 7, 'et_header_layout', [ 'anything' => 'x' ] );
        $this->post( 8, 'page', [] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__cf_types'], $GLOBALS['__cf_protected'], $GLOBALS['__ss_acf_groups'], $GLOBALS['__ss_acf_fields'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function post( int $id, string $type, array $meta ): void
    {
        $p            = new \WP_Post();
        $p->ID        = $id;
        $p->post_type = $type;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array<string,mixed> the JSON-RPC response */
    private function mcpCall( array $args ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_custom_fields', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    private function rest( array $params )
    {
        return ( new RestController() )->custom_fields( new \WP_REST_Request( [], $params ) );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'get_custom_fields'", $mcp );
        $this->assertMatchesRegularExpression( "/'get_custom_fields'\s*=>\s*\\\$this->toolGetCustomFields/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $this->assertMatchesRegularExpression( '#/posts/\(\?P<id>\\\\d\+\)/custom-fields\',\s*\[\s*\'methods\'\s*=>\s*WP_REST_Server::READABLE#', $this->src( 'RestController.php' ) );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertStringNotContainsString( 'custom-fields', json_encode( $spec ) );
        $this->assertStringNotContainsString( 'get_custom_fields', json_encode( $spec ) );
    }

    public function testRestAndMcpReturnTheSameAnswerForAPageAndAProject(): void
    {
        foreach ( [ 5, 6 ] as $id ) {
            $rest = $this->rest( [ 'id' => (string) $id ] );
            $this->assertSame( 200, $rest->get_status() );
            $mcp = $this->mcpCall( [ 'id' => $id ] );
            $this->assertArrayNotHasKey( 'error', $mcp );
            $this->assertSame( (array) $rest->get_data(), json_decode( $mcp['result']['content'][0]['text'], true ) );
        }
        $page = (array) $this->rest( [ 'id' => '5' ] )->get_data();
        $this->assertSame( 'page', $page['post_type'] );
        $this->assertSame( [ 'headline', 'subtitle' ], array_column( $page['fields'], 'key' ) );
        $this->assertSame( [ 'label' => 'Headline', 'type' => 'text' ], $page['fields'][0]['acf'] );
        $this->assertSame( 1, $page['withheld'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $page ) );
        $this->assertSame( 'project', ( (array) $this->rest( [ 'id' => '6' ] )->get_data() )['post_type'] );
    }

    public function testAPostWithNoFieldsIsANormalAnswer(): void
    {
        $d = (array) $this->rest( [ 'id' => '8' ] )->get_data();
        $this->assertSame( [], $d['fields'] );
        $this->assertSame( 0, $d['withheld'] );
    }

    public function testTheKeysFilterWorksOnBothSurfaces(): void
    {
        $rest = (array) $this->rest( [ 'id' => '5', 'keys' => 'subtitle, api_key, _edit_lock' ] )->get_data();
        $this->assertSame( [ 'subtitle' ], array_column( $rest['fields'], 'key' ) );
        $mcp = json_decode( $this->mcpCall( [ 'id' => 5, 'keys' => [ 'subtitle', 'api_key' ] ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'subtitle' ], array_column( $mcp['fields'], 'key' ) );
        $this->assertSame( 1, $mcp['withheld'] );
    }

    public function testBadInputIsRejectedNeverCast(): void
    {
        foreach ( [ [ 'id' => '5', 'keys' => 7 ], [ 'id' => '5', 'keys' => [ 1 ] ], [ 'id' => '5', 'keys' => true ] ] as $bad ) {
            $r = $this->rest( $bad );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'] );
            $e = $this->mcpCall( [ 'id' => 5, 'keys' => $bad['keys'] ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
        foreach ( [ '5', 5.5, true, [ 5 ], 0, -1, null ] as $badId ) {
            $e = $this->mcpCall( [ 'id' => $badId ] );
            $this->assertSame( -32602, $e['error']['code'], 'id ' . json_encode( $badId ) );
        }
    }

    public function testMissingAndNonPublicPostsAreNotFoundAndNoEditRightIsForbidden(): void
    {
        foreach ( [ '999', '7' ] as $id ) {
            $r = $this->rest( [ 'id' => $id ] );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 404, $r->data['status'] );
            $this->assertSame( -32602, $this->mcpCall( [ 'id' => (int) $id ] )['error']['code'] );
        }
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:6' => false ];
        $r = $this->rest( [ 'id' => '6' ] );
        $this->assertSame( 403, $r->data['status'] );
        $e = $this->mcpCall( [ 'id' => 6 ] );
        $this->assertStringContainsString( 'permission', $e['error']['message'] );
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $denied = $this->mcpCall( [ 'id' => 5 ] );
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = $this->rest( [ 'id' => '5' ] );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->rest( [ 'id' => '5' ] );
        $this->mcpCall( [ 'id' => 5 ] );
        $this->assertSame( $before, [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
    }

    public function testToolsListDeclaresAGeminiSafeSchema(): void
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tool = null;
        foreach ( $data['result']['tools'] as $t ) {
            if ( 'get_custom_fields' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool, 'get_custom_fields is listed' );
        $this->assertSame( 'integer', $tool['inputSchema']['properties']['id']['type'] );
        $this->assertSame( [ 'id' ], $tool['inputSchema']['required'] );
        $this->assertSame( 'array', $tool['inputSchema']['properties']['keys']['type'] );
        $this->assertSame( 'string', $tool['inputSchema']['properties']['keys']['items']['type'] );
        $json = (string) json_encode( $tool['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter CustomFieldsLockstepTest`
Expected: FAIL (the tool, the route and `custom_fields()` do not exist yet).

- [ ] **Step 3: Add the real wrappers to `CustomFieldReader`**

In `wp-plugin/src/CustomFieldReader.php`, add these two methods directly after `acfInfoWith()` (before `discoverWith()`):

```php
    /** ACF label and type by stored key, read through ACF's own public functions behind function_exists guards. @return array<string,array{label:string,type:string}> */
    public static function acfInfo(): array
    {
        return self::acfInfoWith(
            function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null
        );
    }

    /**
     * What `get_custom_fields` answers for one post the caller already resolved through FieldTarget. A key stored several
     * times returns its first value. WordPress unserializes values itself; nothing here calls unserialize.
     *
     * @param list<string> $only [] = all keys
     * @return array{id:int,post_type:string,fields:list<array<string,mixed>>,withheld:int,truncated:bool}
     */
    public static function read( object $post, array $only ): array
    {
        $postId = (int) $post->ID;
        $custom = get_post_custom( $postId );
        $keys   = is_array( $custom ) ? array_map( 'strval', array_keys( $custom ) ) : [];

        return [ 'id' => $postId, 'post_type' => (string) $post->post_type ]
            + self::readWith( $keys, $only, static fn ( string $key ): mixed => get_post_meta( $postId, $key, true ), self::acfInfo() );
    }
```

- [ ] **Step 4: Add the MCP tool**

In `wp-plugin/src/McpHandler.php`:

(a) In the tools list, directly after the closing `],` of the `get_site_structure` entry (before the `get_page_layout` entry), add:

```php
            [
                'name'        => 'get_custom_fields',
                'description' => 'Read the custom fields (post meta) of ONE page, Divi Project or other post you can edit (read-only): each key with its value and, when Advanced Custom Fields knows the key, its label and type. Keys that start with an underscore, are protected by WordPress or look like secrets (password, token, API key, licence) are never returned; the answer says how many were withheld. Values are cut at 500 characters and at most 100 fields are returned; a key stored several times returns its first value. Use it to write copy that fits the post or to find a key to bind a module to (see get_style_guide). Pass "keys" to read only some. get_site_structure lists the keys a site\'s posts use.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => ['type' => 'integer', 'description' => 'WordPress post ID (a page, a Divi Project or another public post type)'],
                        'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional. Only these keys (at most 100).'],
                    ],
                    'required'   => ['id'],
                ],
            ],
```

(b) In the `match ($name)` dispatch, after the `'get_site_structure' => ...` line add:

```php
            'get_custom_fields'  => $this->toolGetCustomFields($id, $arguments),
```

(c) After the `toolSiteStructure()` method add:

```php
    private function toolGetCustomFields(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('custom_fields', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read custom fields.');
        }
        $postId = $args['id'] ?? null;
        if (!is_int($postId) || $postId < 1) {
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }
        $only = CustomFieldReader::parseKeys($args['keys'] ?? null);
        if ($only === null) {
            return $this->rpcError($id, -32602, 'keys must be a list of at most ' . CustomFieldReader::MAX_KEYS_ARG . ' strings.');
        }
        $target = FieldTarget::resolve($postId);
        if ($target['error'] === FieldTarget::NOT_FOUND) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32602, "Post {$postId} not found.");
        }
        if ($target['error'] === FieldTarget::FORBIDDEN) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read post {$postId}.");
        }
        $result = CustomFieldReader::read($target['post'], $only);
        UsageTracker::log('custom_fields', $postId, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }
```

- [ ] **Step 5: Add the REST route and handler**

In `wp-plugin/src/RestController.php`:

(a) After the `/site-structure` route registration add:

```php
        // GET /posts/{id}/custom-fields — read-only custom field values of one post you can edit (any public post type)
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/custom-fields', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'custom_fields'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
```

(b) After the `site_structure()` method add:

```php
    public function custom_fields(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('custom_fields', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read custom fields.', ['status' => 403]);
        }
        $postId = (int) $request->get_param('id');
        $only   = CustomFieldReader::parseKeys($request->get_param('keys'));
        if ($only === null) {
            return new WP_Error('invalid_field', '"keys" must be a list of at most ' . CustomFieldReader::MAX_KEYS_ARG . ' strings.', ['status' => 400]);
        }
        $target = FieldTarget::resolve($postId);
        if ($target['error'] === FieldTarget::NOT_FOUND) {
            UsageTracker::log('custom_fields', $postId ?: null, 'error');
            return new WP_Error('not_found', "Post $postId not found.", ['status' => 404]);
        }
        if ($target['error'] === FieldTarget::FORBIDDEN) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return new WP_Error('forbidden', "You do not have permission to read post $postId.", ['status' => 403]);
        }
        $result = CustomFieldReader::read($target['post'], $only);
        UsageTracker::log('custom_fields', $postId, 'read');

        return new WP_REST_Response($result, 200);
    }
```

- [ ] **Step 6: Run the lockstep test**

Run: `vendor/bin/phpunit --filter CustomFieldsLockstepTest`
Expected: PASS. If a `keys => true` REST case fails because the shim `WP_REST_Request::get_param` returns `true`, `parseKeys( true )` must return `null` (it does: `true` is neither string nor array).

- [ ] **Step 7: Update the compliance test (justified edits only)**

In `tests/WpOrgComplianceTest.php`:

(a) In `testCreatePageIsFreeAndTheMcpToolListIsExactlyTheFreeTools`, add `'get_custom_fields'` to the expected sorted list directly after `'edit_page_content'`, change the count line and its comment to:

```php
        // 18 tools: 4.8.0 adds the READ-ONLY get_custom_fields (one post's non-protected custom field values). Nothing is gated and nothing writes a setting; no rule of this test is loosened.
        $this->assertCount(18, $tools);
```

(b) In `testTheStylesToolIsReadOnlyEverywhere`, change `'(17 tools)'` to `'(18 tools)'`.

(c) Add a scanner and its test next to the other scanners (follow the planted-violation pattern used by `diviStyleWriteHits`):

```php
    /** @param array<string,string> $files @return list<string> */
    private function metaWriteHits(array $files): array
    {
        $hits = [];
        foreach ($files as $rel => $src) {
            if (str_starts_with($rel, 'validator/')) {
                continue;
            }
            if (preg_match_all('/\b(update_post_meta|add_post_meta|update_field|update_metadata|add_metadata)\s*\(/', $src, $m)) {
                $hits[] = $rel . ' (' . implode(', ', array_unique($m[1])) . ')';
            }
        }
        return $hits;
    }

    public function testTheFreePluginNeverWritesPostMeta(): void
    {
        $planted = [
            'src/a.php' => "<?php update_post_meta(1, 'k', 'v');",
            'src/b.php' => "<?php add_post_meta (1, 'k', 'v');",
            'src/c.php' => "<?php update_field('f', 'v', 1);",
            'src/d.php' => "<?php add_metadata('post', 1, 'k', 'v');",
        ];
        $found = $this->metaWriteHits($planted);
        foreach (array_keys($planted) as $rel) {
            $this->assertNotEmpty(array_filter($found, fn($h) => str_starts_with($h, $rel)), "meta-write scan missed {$rel}");
        }
        $this->assertSame([], $this->metaWriteHits(['src/ok.php' => "<?php get_post_meta(1, 'k', true); delete_post_meta(1, 'legacy');"]));
        $this->assertSame([], $this->metaWriteHits($this->files()), 'the free plugin reads custom fields and never writes them');
    }
```

- [ ] **Step 8: Update the readme and the admin card text**

In `wp-plugin/readme.txt`:
- Line 46: `= What your AI assistant can do (17 tools) =` becomes `(18 tools)`.
- After the `get_site_structure` bullet (line 53) add the bullet, and in line 53 replace `(read-only: never field values or theme files; available over MCP and the REST API, not in the ChatGPT Action)` with `(read-only: field names and the keys your posts use, never values here or theme files; available over MCP and the REST API, not in the ChatGPT Action)`:

```
* `get_custom_fields`: read the custom fields of one page or Divi Project you can edit, with their values, so your assistant can write copy that fits and use the right field names (read-only: keys WordPress protects, or that look like passwords, tokens or licence keys, are never shown; available over MCP and the REST API, not in the ChatGPT Action).
```
- Line 131 (FAQ): after `read how your site is set up (post types, taxonomies, image sizes and ACF field definitions, read-only)` add `, read the custom fields of a page or Divi Project you can edit (read-only)`.
- Line 171: replace `It never reads field values, your theme files or `functions.php`.` with `It reads custom field values only when your assistant asks about one specific page or Project you can edit, never ones WordPress protects or that look like passwords, tokens or licence keys, and it never reads your theme files or `functions.php`.`

In `wp-plugin/src/AdminPage.php` line 622, replace the sentence `It never reads field values or your theme files.` with `It reads a page's or Project's custom field values only when it asks about that one post, and never your theme files.` (keep the card's third element `'get_site_structure'` unchanged).

- [ ] **Step 9: Run the whole suite**

Run: `make test`
Expected: exit 0. If a test fails because it counts tools or cards, or asserts the old "never reads field values" wording, fix THAT test's expectation to the new documented truth (do not loosen a compliance rule) and say what you changed in the commit message. Also run `make test-pro`; expected exit 0 (it asserts nothing about the free tool count; if it does, report it instead of editing Pro code).

- [ ] **Step 10: Commit**

```bash
git add wp-plugin/src/CustomFieldReader.php wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php wp-plugin/src/AdminPage.php wp-plugin/readme.txt tests/CustomFieldsLockstepTest.php tests/WpOrgComplianceTest.php
git commit -m "$(cat <<'EOF'
feat: get_custom_fields reads one post's non-protected custom fields (MCP + REST)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```
(Add any other test file you had to adjust in Step 9 to `git add`.)

---

### Task 4: custom field discovery in `get_site_structure`

**Files:**
- Modify: `wp-plugin/src/CustomFieldReader.php` (add the real wrapper `discover()`)
- Modify: `wp-plugin/src/SiteStructure.php` (SECTIONS, readers, catalogWith, class docblock, helper)
- Modify: `wp-plugin/src/McpHandler.php` (`get_site_structure` description and `include` enum, ~lines 116-119)
- Modify: `tests/SiteStructureTest.php`, `tests/SiteStructureLockstepTest.php`
- Modify: `tests/GetPostsShim.php` (the one shared `get_posts()` shim; extended, never duplicated)
- Create: `tests/CustomFieldDiscoveryTest.php`

**Interfaces:**
- Consumes: `CustomFieldReader::discoverWith`, `CustomFieldReader::acfInfo`, `CustomFieldReader::SAMPLE_POSTS` (Task 1/3).
- Produces: `CustomFieldReader::discover(): array{post_types: list, truncated: bool}`; `SiteStructure::SECTIONS` becomes `['post_types','taxonomies','image_sizes','acf','custom_fields']`; `readers()` gains key `custom_fields`; `catalogWith` output gains `custom_fields: {post_types: list, note: string}` placed after `acf`.

- [ ] **Step 1: Extend the shared `get_posts()` shim, then write the failing discovery-wrapper test**

PHPUnit loads every test file in one process, and `tests/GetPostsShim.php` already defines the only `get_posts()` shim (used by the `CustomFields::postHasMeta()` tests through `$GLOBALS['__wp_meta_keys']`). Do NOT define a second one: whichever file loads first would win and break the other's tests. Replace the whole body of `tests/GetPostsShim.php` after its header comment with a version that serves both callers:

```php
/**
 * Global stand-in for get_posts(), shared by two callers (no namespace, like the other shims):
 *  - CustomFields::postHasMeta() passes `meta_key`: a post "has" it when a test lists it in $GLOBALS['__wp_meta_keys'];
 *  - CustomFieldReader::discover() passes `post_type`: the newest post ids of that type come from $GLOBALS['__cfd_posts'][type].
 * The Pro suite has its own richer get_posts() shim.
 */
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        if ( isset( $args['meta_key'] ) ) {
            return ! empty( $GLOBALS['__wp_meta_keys'][ $args['meta_key'] ] ) ? [ 1 ] : [];
        }

        return array_slice( $GLOBALS['__cfd_posts'][ $args['post_type'] ?? '' ] ?? [], 0, (int) ( $args['posts_per_page'] ?? 5 ) );
    }
}
```

Run `vendor/bin/phpunit --filter CustomFields` first: the existing `CustomFieldsTest`/`StyleReferences` tests must still pass.

Create `tests/CustomFieldDiscoveryTest.php` (drives the REAL `discover()` through the global shims):

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CustomFieldReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';

if ( ! function_exists( 'update_meta_cache' ) ) {
    function update_meta_cache( $type, $ids ) { return true; }
}

/** The real discover(): which posts are sampled, and that posts the user cannot edit never leak their keys. */
class CustomFieldDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ss_post_types'] = [ 'page' => (object) [], 'book' => (object) [], 'attachment' => (object) [] ];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true ];
        $GLOBALS['__wp_postmeta']   = [
            1 => [ 'isbn' => '1', 'api_key' => 'x', '_edit_lock' => 'y' ],
            2 => [ 'isbn' => '2' ],
            3 => [ 'secret_note' => 'z', 'format' => 'hc' ],
            9 => [ 'media_only' => 'm' ],
        ];
        $GLOBALS['__cfd_posts'] = [ 'book' => [ 1, 2, 3 ], 'page' => [], 'attachment' => [ 9 ] ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__cfd_posts'], $GLOBALS['__ss_post_types'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    public function testItSamplesEachPublicTypeExceptAttachmentsAndFiltersKeys(): void
    {
        $d = CustomFieldReader::discover();
        $this->assertSame( [ 'book' ], array_column( $d['post_types'], 'post_type' ), 'empty types and attachments are omitted' );
        $this->assertSame( [ 'isbn', 'format' ], array_column( $d['post_types'][0]['keys'], 'key' ) );
        $this->assertSame( 2, $d['post_types'][0]['keys'][0]['posts'] );
        $this->assertStringNotContainsString( 'api_key', json_encode( $d ) );
    }

    public function testDiscoverySkipsPostsTheUserCannotEdit(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:1' => false, 'edit_post:2' => false ];
        $d = CustomFieldReader::discover();
        $this->assertSame( 1, $d['post_types'][0]['sampled'] );
        $this->assertSame( [ 'format' ], array_column( $d['post_types'][0]['keys'], 'key' ) );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( [], CustomFieldReader::discover()['post_types'], 'a type whose sampled posts are all uneditable is omitted' );
    }

    public function testItNeverReadsValuesIntoTheAnswer(): void
    {
        $this->assertStringNotContainsString( 'hc', json_encode( CustomFieldReader::discover() ) );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter CustomFieldDiscoveryTest`
Expected: FAIL ("Call to undefined method ...::discover()").

- [ ] **Step 3: Implement `discover()`**

In `wp-plugin/src/CustomFieldReader.php`, add after `read()`:

```php
    /**
     * What a site's posts use, read from WordPress: the newest SAMPLE_POSTS posts of each public post type except
     * attachments, only posts the current user can edit, keys only. No raw SQL: get_posts() plus the posts' own meta keys.
     *
     * @return array{post_types: list<array<string,mixed>>, truncated: bool}
     */
    public static function discover(): array
    {
        if ( ! function_exists( 'get_post_types' ) || ! function_exists( 'get_posts' ) ) {
            return [ 'post_types' => [], 'truncated' => false ];
        }
        $samples = [];
        foreach ( array_values( array_map( 'strval', array_keys( (array) get_post_types( [ 'public' => true ] ) ) ) ) as $type ) {
            if ( 'attachment' === $type ) {
                continue;
            }
            $ids = get_posts( [
                'post_type'      => $type,
                'post_status'    => 'any',
                'posts_per_page' => self::SAMPLE_POSTS,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
            $ids = array_values( array_filter( array_map( 'intval', (array) $ids ), static fn ( int $id ): bool => $id > 0 && current_user_can( 'edit_post', $id ) ) );
            if ( [] === $ids ) {
                continue;
            }
            if ( function_exists( 'update_meta_cache' ) ) {
                update_meta_cache( 'post', $ids ); // one query for the whole sample
            }
            foreach ( $ids as $id ) {
                $custom             = get_post_custom( $id );
                $samples[ $type ][] = is_array( $custom ) ? array_map( 'strval', array_keys( $custom ) ) : [];
            }
        }

        return self::discoverWith( $samples, self::acfInfo() );
    }
```

- [ ] **Step 4: Run the discovery test**

Run: `vendor/bin/phpunit --filter "CustomFieldDiscoveryTest|CustomFieldsTest|StyleReferences"`
Expected: PASS (the shared-shim change must not break the existing meta-lookup tests).

- [ ] **Step 5: Write the failing `SiteStructure` tests**

In `tests/SiteStructureTest.php`:
- In `readers()` add, after the `acf_fields` entry: `'custom_fields' => static fn () => [ 'post_types' => [], 'truncated' => false ],`.
- Change the key-order assertion (line ~78) to `[ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields', 'truncated', 'notes' ]`.
- Add these tests at the end of the class (before the final `}`):

```php
    // ---- custom_fields section ----------------------------------------------------------------

    public function testTheCustomFieldsSectionListsKeysOnlyAndIgnoresAnythingElseTheReaderReturns(): void
    {
        $r = $this->readers( [ 'custom_fields' => static fn () => [
            'post_types' => [ [ 'post_type' => 'book', 'sampled' => 3, 'keys' => [ [ 'key' => 'isbn', 'posts' => 2, 'acf' => [ 'label' => 'ISBN', 'type' => 'text' ], 'value' => 'SECRET-VALUE' ] ] ] ],
            'truncated'  => false,
        ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'custom_fields' ] );
        $this->assertSame( [ 'custom_fields', 'truncated', 'notes' ], array_keys( $d ) );
        $this->assertSame( [ [ 'key' => 'isbn', 'posts' => 2, 'acf' => [ 'label' => 'ISBN', 'type' => 'text' ] ] ], $d['custom_fields']['post_types'][0]['keys'] );
        $this->assertSame( 3, $d['custom_fields']['post_types'][0]['sampled'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $d ) );
        $this->assertStringContainsString( 'get_custom_fields', $d['custom_fields']['note'] );
    }

    public function testAMissingOrThrowingCustomFieldsReaderGivesAnEmptySectionAndANote(): void
    {
        foreach ( [ null, static fn () => throw new \RuntimeException( 'x' ), static fn () => 'nope' ] as $reader ) {
            $d = SiteStructure::catalogWith( $this->readers( [ 'custom_fields' => $reader ] ), [ 'custom_fields' ] );
            $this->assertSame( [], $d['custom_fields']['post_types'] );
            $this->assertNotEmpty( $d['notes'] );
        }
    }

    public function testACustomFieldsTruncationFlagReachesTheTopLevel(): void
    {
        $d = SiteStructure::catalogWith( $this->readers( [ 'custom_fields' => static fn () => [ 'post_types' => [], 'truncated' => true ] ] ), [ 'custom_fields' ] );
        $this->assertTrue( $d['truncated'] );
    }

    public function testParseIncludeKnowsTheNewSection(): void
    {
        $this->assertSame( [ 'custom_fields' ], SiteStructure::parseInclude( 'custom_fields' ) );
        $this->assertContains( 'custom_fields', SiteStructure::SECTIONS );
    }
```

In `tests/SiteStructureLockstepTest.php`:
- Add `require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';` next to the other `require_once` lines (before `SiteStructure.php`).
- In `testToolsListDeclaresAGeminiSafeSchema` change the expected enum to `[ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields' ]`.
- In `testIncludeWorksOnBothSurfaces` nothing changes.

- [ ] **Step 6: Run to verify the new tests fail**

Run: `vendor/bin/phpunit --filter "SiteStructure"`
Expected: FAIL on the new/changed assertions (section not implemented).

- [ ] **Step 7: Implement the section**

In `wp-plugin/src/SiteStructure.php`:

(a) Class docblock: after "its field groups and fields (names, labels, types, choices; never field values)" add: ` Also the custom field KEYS the site's recent posts use (a sample; keys only, never values).` (keep the rest of the docblock).

(b) `public const SECTIONS    = [ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields' ];`

(c) In `readers()` add after the `acf_fields` entry:
```php
            'custom_fields' => class_exists( CustomFieldReader::class ) ? [ CustomFieldReader::class, 'discover' ] : null,
```

(d) Docblock of `catalogWith`: `@param array<string, ?callable> $readers post_types, taxonomies, image_sizes, acf_groups, acf_fields, custom_fields`.

(e) In `catalogWith`, after the `if ( in_array( 'acf', ... ) ) { ... }` block add:

```php
        if ( in_array( 'custom_fields', $include, true ) ) {
            $raw = self::read( $readers['custom_fields'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Custom field data is not available on this site.';
            }
            $out['custom_fields'] = [
                'post_types' => self::customFieldTypes( $raw['post_types'] ?? [] ),
                'note'       => 'A sample: the keys seen on the most recently modified posts of each type that you can edit, not every key the site has. Values are never listed here; call get_custom_fields with a post id to read one post\'s values.',
            ];
            $truncated = $truncated || ! empty( $raw['truncated'] );
        }
```

(f) Add this private helper after `fields()`:

```php
    /**
     * Keys only: each entry is rebuilt from known fields, so nothing else a reader returns (such as a value) can pass through.
     *
     * @return list<array<string,mixed>>
     */
    private static function customFieldTypes( mixed $raw ): array
    {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $list = [];
        foreach ( $raw as $type ) {
            if ( ! is_array( $type ) || ! is_string( $type['post_type'] ?? null ) || ! is_array( $type['keys'] ?? null ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                break;
            }
            $keys = [];
            foreach ( $type['keys'] as $k ) {
                if ( ! is_array( $k ) || ! is_string( $k['key'] ?? null ) ) {
                    continue;
                }
                if ( count( $keys ) >= CustomFieldReader::MAX_KEYS_PER_TYPE ) {
                    break;
                }
                $acf    = $k['acf'] ?? null;
                $keys[] = [
                    'key'   => self::text( $k['key'] ),
                    'posts' => (int) ( $k['posts'] ?? 0 ),
                    'acf'   => is_array( $acf ) ? [ 'label' => self::text( $acf['label'] ?? '' ), 'type' => self::text( $acf['type'] ?? '' ) ] : null,
                ];
            }
            $list[] = [ 'post_type' => self::text( $type['post_type'] ), 'sampled' => (int) ( $type['sampled'] ?? 0 ), 'keys' => $keys ];
        }

        return $list;
    }
```

(g) `SiteStructure.php` must be able to see `CustomFieldReader::MAX_KEYS_PER_TYPE`: in `tests/SiteStructureTest.php` add `require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';` next to its other `require_once`. (In production both are loaded by `autoload.php`.)

(h) In `wp-plugin/src/McpHandler.php`, `get_site_structure` entry: change the description's last sentences to `... Call it before building a page that lists custom post types, uses a given image size or should refer to ACF fields or other custom fields. Pass "include" to get only some sections: post_types, taxonomies, image_sizes, acf, custom_fields. The custom_fields section lists the custom field KEYS your recent posts use (a sample, never values); use get_custom_fields to read one post's values.` and the enum to `['post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields']`.

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit --filter "SiteStructure|CustomField|FieldTarget"` then `make test`
Expected: all PASS, `make test` exits 0. Any other test that asserted the old default section list is updated to the new list (same documented change).

- [ ] **Step 9: Commit**

```bash
git add wp-plugin/src/CustomFieldReader.php wp-plugin/src/SiteStructure.php wp-plugin/src/McpHandler.php tests/SiteStructureTest.php tests/SiteStructureLockstepTest.php tests/GetPostsShim.php tests/CustomFieldDiscoveryTest.php
git commit -m "$(cat <<'EOF'
feat: get_site_structure lists the custom field keys a site's posts use (sampled, keys only)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: live gate on the real Divi site (`make verify-custom-fields`) and the render evidence

**Files:**
- Create: `scripts/verify-custom-fields.php`
- Modify: `Makefile` (`.PHONY` line 1 and a new target after `verify-acf-custom-fields`)
- Create: `docs/custom-field-plain-meta-evidence-5.14.md`

**Interfaces:** none produced for later tasks except the evidence verdict that gates Task 6's guide sentence.

Prerequisite: Docker dev site up (`make up`), the free plugin active on it. If it is not running, STOP and report; do not fake the evidence.

- [ ] **Step 1: Write the script**

Create `scripts/verify-custom-fields.php`:

```php
<?php
/**
 * Real-site check for get_custom_fields and custom field discovery: the REAL REST and MCP handlers on the Docker site, a plain
 * (non-ACF) post meta key, a protected key, a secret-looking key, a Divi Project when the site has the type, a contributor
 * who is refused, and a front-end render of a module bound to the plain key (the evidence the guide needs).
 *
 * Run: make verify-custom-fields   (needs `make up` and the plugin active on the dev site; ACF is NOT needed)
 * Creates throwaway pages / a draft Project / a contributor user and always deletes them. Dev sites only (scripts/dev-guard.php).
 * Set AIED_LOOPBACK_BASE if http://wordpress is not reachable from the wpcli container.
 */

use AiEditorDivi5\WP\CustomFieldReader;

if ( ! class_exists( CustomFieldReader::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with get_custom_fields) on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rest = static function ( string $method, string $route, array $params = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 );
    $data = (array) $res->get_data();
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => $data['error'] ?? null, 'text' => (string) $text, 'result' => (array) json_decode( (string) $text, true ) ];
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
// The legacy binding form (what a real builder export holds and what the style guide teaches).
$bind   = static fn ( string $field ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';
$layout = static fn ( string $field ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $bind( $field ) ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

$VALUE  = 'Plain value 7f3a';
$posts  = [];
$userId = 0;
try {
    $pid = wp_insert_post( [
        'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied custom fields verify',
        'post_content' => wp_slash( $layout( 'aied_plain_meta' ) ),
        'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on', 'aied_plain_meta' => $VALUE, 'aied_api_token' => 'SECRET-7f3a', '_aied_hidden' => 'HIDDEN-7f3a', 'aied_list' => [ 1, 2, 3 ] ],
    ], true );
    if ( is_wp_error( $pid ) ) {
        throw new RuntimeException( $pid->get_error_message() );
    }
    $posts[] = (int) $pid;
    $check( true, 'a throwaway published page exists' );
    $before = md5( serialize( get_post_meta( $pid ) ) );

    // 1. REST read
    $r = $rest( 'GET', "/posts/{$pid}/custom-fields" );
    $keys = array_column( (array) ( $r['data']['fields'] ?? [] ), 'value', 'key' );
    $check( 200 === $r['status'] && ( $keys['aied_plain_meta'] ?? null ) === $VALUE, 'REST: the plain (non-ACF) key and its value are returned' );
    $check( ( $keys['aied_list'] ?? null ) === '[1,2,3]', 'REST: an array value comes back as JSON' );
    $check( ! isset( $keys['aied_api_token'] ) && ! isset( $keys['_aied_hidden'] ) && ! isset( $keys['_et_pb_use_builder'] ), 'REST: the secret-looking, underscore and Divi protected keys are not returned' );
    $check( (int) ( $r['data']['withheld'] ?? 0 ) >= 1, 'REST: the secret-looking key is counted in withheld' );
    $check( ! str_contains( wp_json_encode( $r['data'] ), 'SECRET-7f3a' ) && ! str_contains( wp_json_encode( $r['data'] ), 'HIDDEN-7f3a' ), 'REST: neither hidden value appears anywhere in the answer' );

    // 2. MCP read, keys filter, bad input
    $m = $mcp( 'get_custom_fields', [ 'id' => $pid ] );
    $check( null === $m['error'] && $m['result']['fields'] === $r['data']['fields'], 'MCP: the same fields as REST' );
    $f = $mcp( 'get_custom_fields', [ 'id' => $pid, 'keys' => [ 'aied_plain_meta', 'aied_api_token' ] ] );
    $check( [ 'aied_plain_meta' ] === array_column( (array) ( $f['result']['fields'] ?? [] ), 'key' ), 'MCP: the keys filter returns only the requested shown key, even if a secret one is requested' );
    $check( -32602 === ( $mcp( 'get_custom_fields', [ 'id' => (string) $pid ] )['error']['code'] ?? null ), 'MCP: a non-integer id is rejected' );
    $check( 404 === $rest( 'GET', '/posts/99999999/custom-fields' )['status'], 'REST: a missing post is 404' );

    // 3. a non-public type looks missing; a Divi Project (when the type exists) is readable
    $hdr = wp_insert_post( [ 'post_type' => 'et_header_layout', 'post_status' => 'draft', 'post_title' => 'aied hdr', 'meta_input' => [ 'aied_x' => '1' ] ], true );
    if ( ! is_wp_error( $hdr ) ) {
        $posts[] = (int) $hdr;
        $check( 404 === $rest( 'GET', "/posts/{$hdr}/custom-fields" )['status'], 'REST: a Theme Builder layout (non-public type) looks missing' );
    }
    if ( post_type_exists( 'project' ) ) {
        $prj = wp_insert_post( [ 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'aied project', 'meta_input' => [ 'aied_project_meta' => 'proj-7f3a' ] ], true );
        if ( ! is_wp_error( $prj ) ) {
            $posts[] = (int) $prj;
            $pr = $rest( 'GET', "/posts/{$prj}/custom-fields" );
            $check( 200 === $pr['status'] && 'project' === ( $pr['data']['post_type'] ?? '' ) && 'proj-7f3a' === ( array_column( (array) $pr['data']['fields'], 'value', 'key' )['aied_project_meta'] ?? null ), 'REST: a Divi Project\'s custom field is readable' );
        }
    } else {
        WP_CLI::log( 'SKIP  this site has no "project" post type; the Project read is covered by the unit tests' );
    }

    // 4. a contributor cannot use it
    $userId = (int) wp_insert_user( [ 'user_login' => 'aied_cf_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
    wp_set_current_user( $userId );
    $denied = $rest( 'GET', "/posts/{$pid}/custom-fields" );
    wp_set_current_user( 1 );
    $check( in_array( $denied['status'], [ 401, 403 ], true ), 'REST: a contributor is refused (' . $denied['status'] . ')' );

    // 5. discovery
    $s  = $mcp( 'get_site_structure', [ 'include' => [ 'custom_fields' ] ] );
    $pg = null;
    foreach ( (array) ( $s['result']['custom_fields']['post_types'] ?? [] ) as $t ) {
        if ( 'page' === ( $t['post_type'] ?? '' ) ) {
            $pg = $t;
        }
    }
    $names = array_column( (array) ( $pg['keys'] ?? [] ), 'key' );
    $check( in_array( 'aied_plain_meta', $names, true ), 'get_site_structure custom_fields: the plain key is discovered on pages' );
    $check( ! in_array( 'aied_api_token', $names, true ) && ! in_array( '_aied_hidden', $names, true ), 'discovery: secret-looking and underscore keys are not listed' );
    $check( ! str_contains( $s['text'], $VALUE ), 'discovery: values are never listed' );

    // 6. nothing was written by any of the reads
    $check( md5( serialize( get_post_meta( $pid ) ) ) === $before, 'the reads left the post meta byte-identical' );

    // 7. EVIDENCE: Divi renders a module bound to a plain (non-ACF) meta key
    $html = $fetch( get_permalink( $pid ) );
    $check( '' !== $html && str_contains( $html, $VALUE ), 'RENDER: the front end shows the plain meta value through the custom_meta_ binding (Divi ' . wp_get_theme()->get( 'Version' ) . ')' );
    $check( ! str_contains( $html, '$variable(' ), 'RENDER: no $variable( token leaks into the HTML' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    if ( $userId > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $userId );
    }
    wp_set_current_user( 1 );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( 'get_custom_fields verified on the real site.' );
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile`: append `verify-custom-fields` to the `.PHONY` line, and after the `verify-acf-custom-fields` target add:

```make
# ---------------------------------------------------------------
# verify-custom-fields — real-site check of get_custom_fields, custom field discovery and the plain-meta render (Docker site; throwaway pages and a contributor user, always deleted)
# ---------------------------------------------------------------
verify-custom-fields:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-custom-fields.php --user=1
```

- [ ] **Step 3: Sync the dev copy of the plugin and run the gate**

The Docker site loads the plugin from the repo (as for the other `verify-*` targets). Run: `make verify-custom-fields`
Expected: every line `PASS` (a `SKIP` for the Project read is acceptable only if the site truly has no `project` type), final `Success: get_custom_fields verified on the real site.`
If a check FAILs: do not edit the script to make it pass. Diagnose (superpowers:systematic-debugging). If only the two RENDER lines fail while everything else passes, that is a REAL finding (Divi does not render the binding for a plain key, or the loopback fetch failed): try `AIED_LOOPBACK_BASE`, then if it genuinely does not render, record that in the evidence file and follow the "render failed" branch in Task 6.

- [ ] **Step 4: Write the evidence file**

Create `docs/custom-field-plain-meta-evidence-5.14.md` with the real results (fill the bracketed parts from the run, never invent):

```markdown
# Plain (non-ACF) post meta binding: render evidence, Divi [version from the run]

Date: [today]. Script: `scripts/verify-custom-fields.php` (`make verify-custom-fields`), real Docker site.

- Setup: a published page with a plain post meta key `aied_plain_meta` = `Plain value 7f3a` (no ACF installed) and a Text module whose content is the legacy binding string with `select_meta_key: custom_meta_aied_plain_meta`.
- Result: [PASS/FAIL] the front end showed the value; [PASS/FAIL] no `$variable(` token leaked.
- Verdict: [teach / do not teach] binding to a plain meta key in the style guide.
- Also verified live in the same run: get_custom_fields over REST and MCP (plain key, JSON array value, protected/secret keys withheld, keys filter, bad input), non-public type looks missing, contributor refused, discovery lists the key and no value, reads leave post meta byte-identical. [Project read: PASS / SKIPPED (no project type)].
```

- [ ] **Step 5: Commit**

```bash
git add scripts/verify-custom-fields.php Makefile docs/custom-field-plain-meta-evidence-5.14.md
git commit -m "$(cat <<'EOF'
test: make verify-custom-fields, a real-site gate with the plain-meta render evidence

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: teach it in the guide, update the project docs, final verification

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the "Show a custom field's value" section, after the sentence ending `Bind only fields whose group applies to the page you are editing.`, ~line 305)
- Modify: `tests/StyleGuideTest.php`
- Modify: `CLAUDE.md`

**Interfaces:** Consumes the Task 5 evidence verdict.

- [ ] **Step 1: Write the failing guide test**

In `tests/StyleGuideTest.php` add (inside the class):

```php
    public function testTheGuideSendsTheAiToGetCustomFieldsForValuesAndPlainKeys(): void
    {
        $g = StyleGuide::markdown();
        $this->assertStringContainsString( '`get_custom_fields`', $g );
        $this->assertStringContainsString( 'custom_fields', $g, 'names the get_site_structure section that lists the keys' );
    }
```
(Use the same import/namespace style as the other tests in that file.)

Run: `vendor/bin/phpunit --filter StyleGuideTest` — expected FAIL.

- [ ] **Step 2: Add the guide paragraph (branch on the evidence)**

In `wp-plugin/src/StyleGuide.php`, directly after the paragraph's last sentence `Bind only fields whose group applies to the page you are editing.` append a new paragraph. Use the variant that matches `docs/custom-field-plain-meta-evidence-5.14.md`:

If the render PASSED:

```
`get_site_structure` also lists, in its `custom_fields` section, the custom field keys this
site's posts really use, ACF or not (a sample of recent posts; keys only). To see what a
field holds on one page or Project, call `get_custom_fields` with its id: it returns each
key with its value (never protected or secret-looking keys), so you can write copy that
fits. A key with no ACF field behind it binds the same way: put its name in place of
`FIELD_NAME`.
```

If the render FAILED (or was not run):

```
`get_site_structure` also lists, in its `custom_fields` section, the custom field keys this
site's posts really use (a sample of recent posts; keys only). To see what a field holds on
one page or Project, call `get_custom_fields` with its id: it returns each key with its
value (never protected or secret-looking keys), so you can write copy that fits. Binding a
module is verified for ACF fields only: do not bind a key that is not an ACF field.
```

Mind the file's quoting: the guide is a PHP nowdoc/heredoc; keep the backticks and match the surrounding text's delimiters (check how line 282's text is quoted before editing).

- [ ] **Step 3: Run the guide tests, then the whole suite**

Run: `vendor/bin/phpunit --filter StyleGuideTest` (PASS), then `make test` (exit 0) and `make test-pro` (exit 0). If a guide-size or token test fails because the guide grew, report the numbers and the test; adjust that test's documented budget only if the owner-approved budget doc allows it, otherwise stop and report.

- [ ] **Step 4: Update `CLAUDE.md`**

First run `git diff CLAUDE.md`: it holds the owner's own uncommitted edits. Make only these edits, and stage ONLY your hunks with `git add -p CLAUDE.md`; if your hunks cannot be separated from the owner's, leave `CLAUDE.md` uncommitted and tell the owner exactly what to paste:
- "**17 MCP tools, all free:**" becomes "**18 MCP tools, all free:**"; add `get_custom_fields` (read-only: one post's non-protected custom field values; any public post type the user can edit, Divi Projects included; `CustomFieldReader` + `FieldTarget`; MCP and REST only) to the tool list, and add `custom_fields` to the `get_site_structure` description (keys only, sampled).
- The ChatGPT-exception sentence: "get_site_structure is MCP + REST only" becomes "get_site_structure and get_custom_fields are MCP + REST only"; mention `CustomFieldsLockstepTest` pins the absence too.
- WordPress.org compliance paragraph: "the 17-tool list" becomes "the 18-tool list"; add that the test also bans meta-write functions in `wp-plugin/`.
- Entry points table: add `make verify-custom-fields` (real-site check of `get_custom_fields`, discovery and the plain-meta render; self-cleaning; dev site only).
- "Current state": add a bullet: "**Unreleased (branch feat/get-custom-fields): `get_custom_fields` + `custom_fields` discovery** (sub-project A of the Projects/custom-fields request; B = edit Divi Project layouts and C = write field values are separate, later specs). Spec/plan `docs/superpowers/specs|plans/2026-10-07-get-custom-fields*`. Version, changelog and zip are the owner's release step (4.7.1 is already waiting for upload)."

- [ ] **Step 5: Final verification**

Run, in order, and read the real output:
1. `make test` -> exit 0.
2. `make test-pro` -> exit 0.
3. `make verify-custom-fields` -> all PASS.
4. `git status --short` -> only the owner's own `CLAUDE.md` edits (if you left them) may remain unstaged.
Do NOT rebuild the zip or run Plugin Check here; both belong to the release step.

- [ ] **Step 6: Commit**

```bash
git add wp-plugin/src/StyleGuide.php tests/StyleGuideTest.php
git commit -m "$(cat <<'EOF'
feat: teach get_custom_fields and the custom_fields discovery section in the style guide

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```
(Then the `CLAUDE.md` commit, if separable: `git commit -m "docs: CLAUDE.md for get_custom_fields" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"`.)

---

## Self-review (done against the spec)

- **Spec coverage:** §1 tool, input rules, target rule, output, REST route, OpenAPI exception -> Tasks 1-3; §2 safety rules (protected, secret, caps, no writes, first value) -> Task 1 + compliance scan in Task 3; §3 discovery (attachments excluded, edit_post-only, 20 sample, keys only, 100 cap, note) -> Tasks 1 + 4; §4 guide gated on evidence -> Tasks 5-6; §5 counts/docs -> Tasks 3 and 6; Testing section -> unit tests per task, lockstep, compliance, live gate. Spec "Out of scope" untouched.
- **Placeholders:** the only bracketed fill-ins are the evidence file's run results (Task 5 Step 4), which must come from the real run.
- **Type consistency:** `CustomFieldReader::{classify,parseKeys,readWith,acfInfoWith,acfInfo,read,discoverWith,discover}`, `FieldTarget::{resolve,NOT_FOUND,FORBIDDEN}`, endpoint name `custom_fields`, MCP tool `get_custom_fields`, REST `custom_fields()` are used identically in every task.
