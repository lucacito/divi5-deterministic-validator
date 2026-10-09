# set_custom_fields (Pro) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Pro tool `set_custom_fields` lets the AI set custom field values on one post it may edit: validated all-or-nothing, previewed with `dry_run`, written atomically, and undone in one step by `undo_batch`.

**Architecture:** Four small Pro units: `FieldValuePolicy` (what a value may contain), `AcfFields` (ACF's field definitions and type rules), `PostMetaStore` (the only code that reads and writes post meta, ACF-aware, read-back verified) and `PostMetaKind` (the undo kind). `SetCustomFields` composes them with three free-plugin helpers (`FieldTarget`, `CustomFieldReader::classify`, `CustomFields::lookup`). The free plugin is unchanged.

**Tech Stack:** PHP 8.1+ (the add-on supports 8.1: no standalone `null` return types), PHPUnit (`make test-pro`), WP shims in `tests/` and `pro-addon/tests/`, WP-CLI live gate (`make verify-pro-custom-fields`).

**Spec:** `docs/superpowers/specs/2026-10-09-set-custom-fields-design.md` (read it first; this plan implements it).

## Global Constraints

- Branch `feat/pro-set-custom-fields`. All new production code lives in `pro-addon/src/`. Nothing under `wp-plugin/` or `src/` (the validator) changes, except tests that pin the free API. The free plugin never gains a meta-write call (`tests/WpOrgComplianceTest.php` scans `wp-plugin/` and must stay green).
- Every PHP file under `pro-addon/src/` starts with `declare(strict_types=1);`, the right namespace and `if ( ! defined( 'ABSPATH' ) ) { exit; }`. New classes must be added to `pro-addon/src/autoload.php` by hand (it is an explicit `require_once` list) or the live plugin will fatal.
- `pro-addon/tests/ProSafetyTest.php` bans, in Pro source: `eval`, the exec family, `create_function`, `unserialize`, user-writing functions, plugin management, Divi preset storage names, and unlisted remote hosts. Stay inside it (no URLs, no `unserialize`).
- Limits (exact): at most 25 fields per call; a value is at most 10,000 characters (`mb_strlen`); strings only; no duplicate keys in one call.
- Tool: `NAME = 'set_custom_fields'`; registry capability `edit_posts`; MCP + REST only: add it to `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up: `ProOpenApiTest`/`ProParityTest` keep 14 Pro operations); the description is at most 300 characters (`ToolsTest` enforces it).
- Refuse, never sanitize: if any field is refused nothing is written and the exception message lists every refusal. `dry_run` runs every check and writes nothing and records no batch. No preview token.
- Key rules (per field): `CustomFieldReader::classify( $key ) === CustomFieldReader::SHOWN`; known (an ACF field, or `CustomFields::lookup()` says some post has it); `current_user_can( 'edit_post_meta', $postId, $key )`; stored at most once on the post; the existing value must not be a list or object. Target: `FieldTarget::resolve( $id )` (any public post type the user can edit); any error answers `Post N not found.` (no leak).
- Value rules: no custom code (`FieldValuePolicy`); `wp_kses_post( $value ) === $value`; ACF type rules (`AcfFields::refusalFor`); complex ACF types, ACF `password`, `checkbox`, multi-value `select`, and any key belonging to a group, repeater or flexible content are refused with "edit it in the ACF editor".
- Writes: pass `wp_slash( $value )` to `update_post_meta` (core unslashes); when ACF is active and the key is a top-level ACF field with a field key, write with `update_field( $fieldKey, $value, $postId )`. After every write read the raw value back; a mismatch fails the call and rolls back every field already written.
- Pro compatibility: add `FieldTarget`, `CustomFieldReader` and `CustomFields` to `Compat::REQUIRED_CLASSES` (the runtime guard) and pin their signatures in `FreeApiCompatTest`. Do NOT change `Compat::MIN_FREE` in this branch (the dev site still reports the pre-release free version, which would switch the Pro tools off); the owner raises it with the free release.
- Do NOT bump the Pro version, edit any changelog, rebuild any zip, or touch the licence server. Do not touch or stage `CLAUDE.md` except in Task 5 via the recipe given there (the owner has uncommitted edits in it).
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Test commands: `make test-pro` for the Pro suite (baseline 1515 pass), `vendor/bin/phpunit` for the free suite (baseline 967 pass), `vendor/bin/phpunit -c pro-addon/phpunit.xml --filter <Name>` for one Pro class (check the `test-pro` target in the `Makefile` for the exact command and use it if it differs).

## Review Focus

1. A refused field must leave EVERY field of the call unwritten, including fields listed before it (Task 3 `testOneRefusedFieldWritesNothingAtAll`).
2. A value containing backslashes, quotes or UTF-8 must round-trip byte-exactly through write, read-back and undo (Tasks 2 and 3 tests, and the live gate).
3. A key stored twice, a currently structured (array) value, an ACF group/repeater sub-key and a key that looks like an ACF group member must all be refused rather than overwritten (Task 3 refusal matrix).
4. Undo after someone else changed the value must refuse, and undo of a call that CREATED a key must delete it (Task 2 `PostMetaKind` tests).
5. A post the user cannot edit and a post of a non-public type must look exactly like a missing post (Task 3).

---

### Task 1: spec note + `FieldValuePolicy` + `AcfFields` (pure units)

**Files:**
- Modify: `docs/superpowers/specs/2026-10-09-set-custom-fields-design.md` (§5 wording about `MIN_FREE`)
- Create: `pro-addon/src/Fields/FieldValuePolicy.php`
- Create: `pro-addon/src/Fields/AcfFields.php`
- Create: `pro-addon/tests/FieldValuePolicyTest.php`
- Create: `pro-addon/tests/AcfFieldsTest.php`
- Modify: `pro-addon/src/autoload.php` (add both requires after the `Kinds/GlobalPresetKind.php` line)

**Interfaces:**
- Produces:
  - `AiEditorDivi5\Pro\Fields\FieldValuePolicy::refusal( string $value ): ?string` (null = allowed; otherwise a reason that completes "the value …"), constants `MAX_CHARS = 10000`.
  - `AiEditorDivi5\Pro\Fields\AcfFields::indexWith( ?callable $groups, ?callable $fields ): array{fields: array<string,array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool}>, prefixes: list<string>}`; `AcfFields::index(): array` (the same, read from ACF's own `acf_get_field_groups` / `acf_get_fields` behind `function_exists` guards); `AcfFields::refusalFor( array $def, string $value ): ?string`; constant `AcfFields::COMPLEX`.

- [ ] **Step 1: Amend the spec**

In `docs/superpowers/specs/2026-10-09-set-custom-fields-design.md` §5, replace the sentence that says `Compat::MIN_FREE` is raised to `4.8.0` by this work with: "`Compat::REQUIRED_CLASSES` (the runtime guard: the Pro tools stay off if a class is missing) gains the three classes. `Compat::MIN_FREE` is NOT changed in this branch (the dev site still reports the pre-release free version, which would switch the Pro tools off); the owner raises it, to the first free version that contains these classes, when versioning the free release." Also in §2 value rules replace "the same kinds of code the free `CodePolicy` refuses in layouts" with "the same kinds of code the free plugin refuses in layouts" if needed for accuracy (keep the list of patterns).

- [ ] **Step 2: Write the failing tests**

Create `pro-addon/tests/FieldValuePolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\FieldValuePolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class FieldValuePolicyTest extends TestCase
{
    /** @return list<array{string}> */
    public static function clean(): array
    {
        return [ [ '' ], [ 'Hello' ], [ 'Café – naïve ✓ 日本語' ], [ '<p>Hi <strong>there</strong></p>' ], [ 'a lesson on sections' ], [ '5 < 6 and 7 > 3' ], [ "Line one\nLine two" ], [ 'C:\\path "quoted" \'single\'' ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'clean' )]
    public function testOrdinaryTextIsAllowed( string $value ): void
    {
        $this->assertNull( FieldValuePolicy::refusal( $value ) );
    }

    /** @return list<array{string,string}> */
    public static function code(): array
    {
        return [
            [ '<script>alert(1)</script>', 'script' ],
            [ '<SCRIPT src=x>', 'script' ],
            [ '< script>', 'script' ],
            [ '<style>body{}</style>', 'style' ],
            [ '<iframe src="x"></iframe>', 'iframe' ],
            [ '<object data="x">', 'object' ],
            [ '<embed src="x">', 'embed' ],
            [ '<?php echo 1; ?>', 'PHP tag' ],
            [ '<a href="javascript:alert(1)">x</a>', 'javascript' ],
            [ '<a href="  JaVaScRiPt :alert(1)">x</a>', 'javascript' ],
            [ 'vbscript:msgbox', 'javascript' ],
            [ 'data:text/html;base64,AAAA', 'javascript' ],
            [ '<img src=x onerror=alert(1)>', 'event' ],
            [ '<p onclick="x()">hi</p>', 'event' ],
            [ "<div\nonmouseover='x'>", 'event' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'code' )]
    public function testCustomCodeIsRefusedWithAReason( string $value, string $needle ): void
    {
        $reason = FieldValuePolicy::refusal( $value );
        $this->assertNotNull( $reason, $value );
        $this->assertStringContainsString( $needle, $reason );
    }

    public function testTheLengthLimitIsInCharactersNotBytes(): void
    {
        $this->assertNull( FieldValuePolicy::refusal( str_repeat( 'é', FieldValuePolicy::MAX_CHARS ) ) );
        $this->assertNotNull( FieldValuePolicy::refusal( str_repeat( 'é', FieldValuePolicy::MAX_CHARS + 1 ) ) );
        $this->assertStringContainsString( '10,000', (string) FieldValuePolicy::refusal( str_repeat( 'a', FieldValuePolicy::MAX_CHARS + 1 ) ) );
    }

    public function testAnInvalidUtf8ValueIsRefused(): void
    {
        $this->assertNotNull( FieldValuePolicy::refusal( "bad \xB1\x31 bytes" ) );
    }
}
```

Create `pro-addon/tests/AcfFieldsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\AcfFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class AcfFieldsTest extends TestCase
{
    /** @return array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private function index(): array
    {
        return AcfFields::indexWith(
            static fn () => [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ], [ 'no' => 'key' ] ],
            static fn ( string $key ) => 'group_a' === $key ? [
                [ 'key' => 'field_headline', 'name' => 'headline', 'type' => 'text' ],
                [ 'key' => 'field_size', 'name' => 'size', 'type' => 'select', 'choices' => [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ] ],
                [ 'key' => 'field_many', 'name' => 'many', 'type' => 'select', 'multiple' => 1, 'choices' => [ 'a' => 'A' ] ],
                [ 'key' => 'field_addr', 'name' => 'address', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_city', 'name' => 'city', 'type' => 'text' ] ] ],
                [ 'key' => 'field_team', 'name' => 'team', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_member', 'name' => 'member', 'type' => 'text' ] ] ],
                [ 'name' => '', 'type' => 'tab' ],
            ] : false
        );
    }

    public function testTheIndexKeepsKeyTypeChoicesAndFlagsNestedGroupMembers(): void
    {
        $i = $this->index();
        $this->assertSame( [ 'headline', 'size', 'many', 'address', 'address_city', 'team' ], array_keys( $i['fields'] ) );
        $this->assertSame( 'field_headline', $i['fields']['headline']['field_key'] );
        $this->assertSame( [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ], $i['fields']['size']['choices'] );
        $this->assertTrue( $i['fields']['many']['multiple'] );
        $this->assertFalse( $i['fields']['address']['nested'] );
        $this->assertTrue( $i['fields']['address_city']['nested'] );
        $this->assertEqualsCanonicalizing( [ 'address_', 'team_' ], $i['prefixes'] );
    }

    public function testAMissingOrThrowingReaderGivesAnEmptyIndex(): void
    {
        $empty = [ 'fields' => [], 'prefixes' => [] ];
        $this->assertSame( $empty, AcfFields::indexWith( null, null ) );
        $this->assertSame( $empty, AcfFields::indexWith( static fn () => throw new \RuntimeException( 'x' ), static fn () => [] ) );
        $this->assertSame( $empty, AcfFields::indexWith( static fn () => 'nope', static fn () => [] ) );
    }

    /** @return list<array{string,string,?string}> type, value, expected reason substring (null = allowed) */
    public static function typeCases(): array
    {
        return [
            [ 'text', 'anything <b>goes</b>', null ], [ 'textarea', "multi\nline", null ], [ 'wysiwyg', '<p>x</p>', null ], [ 'url', 'https://example.test/', null ],
            [ 'email', 'a@b.test', null ], [ 'date_picker', '20261009', null ], [ 'color_picker', '#ff0000', null ],
            [ 'number', '42', null ], [ 'number', '4.5', null ], [ 'number', 'abc', 'number' ], [ 'range', '7', null ], [ 'range', 'x', 'number' ],
            [ 'true_false', '1', null ], [ 'true_false', '0', null ], [ 'true_false', 'yes', '0 or 1' ],
            [ 'password', 'x', 'ACF editor' ], [ 'checkbox', 'a', 'ACF editor' ], [ 'repeater', 'x', 'ACF editor' ], [ 'group', 'x', 'ACF editor' ],
            [ 'image', '12', 'ACF editor' ], [ 'relationship', '12', 'ACF editor' ], [ 'flexible_content', 'x', 'ACF editor' ], [ 'google_map', 'x', 'ACF editor' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'typeCases' )]
    public function testTheTypeRules( string $type, string $value, ?string $needle ): void
    {
        $def    = [ 'field_key' => 'f', 'type' => $type, 'choices' => [], 'multiple' => false, 'nested' => false ];
        $reason = AcfFields::refusalFor( $def, $value );
        if ( null === $needle ) {
            $this->assertNull( $reason, "{$type}: {$value}" );
        } else {
            $this->assertNotNull( $reason, "{$type}: {$value}" );
            $this->assertStringContainsString( $needle, $reason );
        }
    }

    public function testChoiceFieldsOnlyAcceptTheirChoicesAndMultiSelectIsRefused(): void
    {
        $choices = [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ];
        foreach ( [ 'select', 'radio', 'button_group' ] as $type ) {
            $def = [ 'field_key' => 'f', 'type' => $type, 'choices' => $choices, 'multiple' => false, 'nested' => false ];
            $this->assertNull( AcfFields::refusalFor( $def, 'l' ), $type );
            $this->assertNull( AcfFields::refusalFor( $def, '3' ), "{$type}: numeric choice key" );
            $this->assertStringContainsString( 'one of', (string) AcfFields::refusalFor( $def, 'xl' ) );
            $this->assertStringContainsString( 'one of', (string) AcfFields::refusalFor( $def, '' ) );
        }
        $multi = [ 'field_key' => 'f', 'type' => 'select', 'choices' => $choices, 'multiple' => true, 'nested' => false ];
        $this->assertStringContainsString( 'ACF editor', (string) AcfFields::refusalFor( $multi, 'l' ) );
    }
}
```

Run `make test-pro` (or the focused command): expected FAIL (classes missing).

- [ ] **Step 3: Implement `FieldValuePolicy`**

Create `pro-addon/src/Fields/FieldValuePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What a custom field value may contain. A value is plain text or the post-content HTML WordPress already allows; it is never
 * custom code. The same kinds of code the free plugin refuses in layouts are refused here, in plain text. This is a refusal
 * list in front of `wp_kses_post()` (the tool also refuses any value kses would change), not a sanitizer.
 */
final class FieldValuePolicy
{
    public const MAX_CHARS = 10000;

    private const PATTERNS = [
        'contains a script, style, iframe, object or embed tag' => '/<\s*(?:script|style|iframe|object|embed)\b/i',
        'contains a PHP tag'                                    => '/<\s*\?/',
        'contains a javascript:, vbscript: or data:text/html URL' => '/(?:java|vb)script\s*:|data\s*:\s*text\s*\/\s*html/i',
        'contains an HTML event-handler attribute'              => '/<[a-z][^>]*\son[a-z]{3,}\s*=/is',
    ];

    /** @return ?string a reason that completes "the value ...", or null when the value is allowed */
    public static function refusal( string $value ): ?string
    {
        if ( 1 !== preg_match( '//u', $value ) ) {
            return 'is not valid UTF-8';
        }
        if ( mb_strlen( $value ) > self::MAX_CHARS ) {
            return 'is longer than 10,000 characters';
        }
        foreach ( self::PATTERNS as $reason => $pattern ) {
            if ( 1 === preg_match( $pattern, $value ) ) {
                return $reason;
            }
        }

        return null;
    }
}
```
Check the test data against it: `'< script>'` matches the first pattern (`<\s*script`); `"<div\nonmouseover='x'>"` matches the event pattern (`s` flag, `\s` covers the newline); `'a lesson on sections'` and `'5 < 6 and 7 > 3'` must stay allowed (no tag name directly after `<`); `'<p onclick="x()">hi</p>'` matches the event pattern. Adjust only the regexes (never the tests) if a case disagrees, and say so in the report.

- [ ] **Step 4: Implement `AcfFields`**

Create `pro-addon/src/Fields/AcfFields.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ACF's field definitions as the writer needs them: stored key => field key, type, choices, whether a select is multi-value, and
 * whether the field is a member of a group. Read only through ACF's own public functions, each behind a function_exists guard;
 * a missing or throwing reader means an empty index (the writer then treats every key as a plain meta key). Nothing is written.
 */
final class AcfFields
{
    public const COMPLEX = 'is a complex ACF field type (a list, group, image, relationship and the like); edit it in the ACF editor';

    private const TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg', 'url', 'email', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker' ];
    private const PREFIXED   = [ 'repeater', 'flexible_content', 'group' ];

    /** @return array{fields: array<string,array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool}>, prefixes: list<string>} */
    public static function index(): array
    {
        return self::indexWith(
            function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null
        );
    }

    /** @return array{fields: array<string,array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool}>, prefixes: list<string>} */
    public static function indexWith( ?callable $groups, ?callable $fields ): array
    {
        $out = [ 'fields' => [], 'prefixes' => [] ];
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
                self::collect( $found, '', false, $out );
            }
        }
        $out['prefixes'] = array_values( array_unique( $out['prefixes'] ) );

        return $out;
    }

    /**
     * Why a value cannot be written to this ACF field, or null when it can. The reason completes "the field ...".
     *
     * @param array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool} $def
     */
    public static function refusalFor( array $def, string $value ): ?string
    {
        $type = $def['type'];
        if ( in_array( $type, self::TEXT_TYPES, true ) ) {
            return null;
        }
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            return is_numeric( $value ) ? null : 'must be a number';
        }
        if ( 'true_false' === $type ) {
            return in_array( $value, [ '0', '1' ], true ) ? null : 'must be 0 or 1';
        }
        if ( in_array( $type, [ 'select', 'radio', 'button_group' ], true ) ) {
            if ( 'select' === $type && $def['multiple'] ) {
                return self::COMPLEX;
            }

            return array_key_exists( $value, $def['choices'] )
                ? null
                : 'must be one of: ' . implode( ', ', array_slice( array_map( 'strval', array_keys( $def['choices'] ) ), 0, 20 ) );
        }

        return self::COMPLEX;
    }

    /**
     * @param array<mixed>                                                                        $fields
     * @param array{fields: array<string,array<string,mixed>>, prefixes: list<string>} $out
     */
    private static function collect( array $fields, string $prefix, bool $nested, array &$out ): void
    {
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
            if ( '' === $name ) {
                continue;
            }
            $stored  = $prefix . $name;
            $type    = is_string( $field['type'] ?? null ) ? $field['type'] : '';
            $choices = [];
            if ( is_array( $field['choices'] ?? null ) ) {
                foreach ( $field['choices'] as $value => $label ) {
                    $choices[ (string) $value ] = is_scalar( $label ) ? (string) $label : '';
                }
            }
            $out['fields'][ $stored ] = [
                'field_key' => is_string( $field['key'] ?? null ) ? $field['key'] : '',
                'type'      => $type,
                'choices'   => $choices,
                'multiple'  => ! empty( $field['multiple'] ),
                'nested'    => $nested,
            ];
            if ( in_array( $type, self::PREFIXED, true ) ) {
                $out['prefixes'][] = $stored . '_';
                if ( 'group' === $type && is_array( $field['sub_fields'] ?? null ) ) {
                    self::collect( $field['sub_fields'], $stored . '_', true, $out );
                }
            }
        }
    }
}
```

- [ ] **Step 5: Register in the loader**

In `pro-addon/src/autoload.php`, after `require_once __DIR__ . '/Kinds/GlobalPresetKind.php';` add:

```php
require_once __DIR__ . '/Fields/FieldValuePolicy.php';
require_once __DIR__ . '/Fields/AcfFields.php';
```

- [ ] **Step 6: Run the tests, then the Pro suite**

Run the focused command for `FieldValuePolicyTest|AcfFieldsTest` (PASS), then `make test-pro` (1515 + the new ones, all pass; `ProSafetyTest` stays green).

- [ ] **Step 7: Commit**

```bash
git add docs/superpowers/specs/2026-10-09-set-custom-fields-design.md pro-addon/src/Fields pro-addon/src/autoload.php pro-addon/tests/FieldValuePolicyTest.php pro-addon/tests/AcfFieldsTest.php
git commit -m "$(cat <<'EOF'
feat(pro): FieldValuePolicy and AcfFields, the pure rules for writing custom field values

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `PostMetaStore` and `PostMetaKind` (the write path and the undo kind)

**Files:**
- Create: `pro-addon/src/Fields/PostMetaStore.php`
- Create: `pro-addon/src/Kinds/PostMetaKind.php`
- Create: `pro-addon/tests/PostMetaStoreTest.php`
- Create: `pro-addon/tests/PostMetaKindTest.php`
- Modify: `pro-addon/src/autoload.php` (requires after the Task 1 lines)
- Modify: `pro-addon/src/Batch/Batches.php` (register the kind) and `pro-addon/tests/ToolsTest.php` (`testEveryKindARecordedItemUsesIsRegisteredForUndo`: add `'post_meta'`)

**Interfaces:**
- Consumes: `ValueKind`, `Item`, `Canon` (existing); the free `FieldTarget::resolve( int ): array` and the test shims in `tests/CustomFieldsReadShims.php` (`get_post_custom`, `get_post_type_object`, `is_protected_meta`) and `tests/bootstrap.php` (`get_post`, `get_post_meta`, `update_post_meta`, `delete_post_meta`, `wp_unslash`).
- Produces:
  - `PostMetaStore::__construct( ?callable $acfWriter = null, ?callable $acfDeleter = null )`; `PostMetaStore::wp(): self` (production: `update_field` / `delete_field` when they exist); `read( int $postId, string $key ): array{exists:bool, value:?string, rows:int, structured:bool}`; `write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool` (true only when the raw value read back equals `$value`); `delete( int $postId, string $key, ?string $acfSelector = null ): bool` (true when the key no longer exists).
  - `PostMetaKind` (`final class PostMetaKind extends ValueKind`, `NAME = 'post_meta'`): `ref` is `"{postId}:{key}"`; canonical value and `before` are `array{exists:bool, value:?string}`; an item may carry `meta.acf` (the ACF field key used to write).

- [ ] **Step 1: Write the failing tests**

First check which of these test shims exist (`grep -n "function wp_slash\|function metadata_exists" tests/*.php pro-addon/tests/*.php`); if `wp_slash` is missing from the shared shims, add a guarded `function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }` to `pro-addon/tests/bootstrap.php`, mirroring the existing guarded shims there.

Create `pro-addon/tests/PostMetaStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\PostMetaStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';

class PostMetaStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_postmeta'] = [ 5 => [ 'headline' => 'Old', 'list' => [ 1, 2 ] ], 6 => [] ];
    }

    public function testReadReportsExistenceValueAndStructure(): void
    {
        $s = new PostMetaStore();
        $this->assertSame( [ 'exists' => true, 'value' => 'Old', 'rows' => 1, 'structured' => false ], $s->read( 5, 'headline' ) );
        $this->assertSame( [ 'exists' => false, 'value' => null, 'rows' => 0, 'structured' => false ], $s->read( 5, 'missing' ) );
        $list = $s->read( 5, 'list' );
        $this->assertTrue( $list['exists'] );
        $this->assertTrue( $list['structured'] );
        $this->assertNull( $list['value'] );
    }

    public function testAnEmptyStringValueStillExists(): void
    {
        $GLOBALS['__wp_postmeta'][6]['blank'] = '';
        $this->assertSame( [ 'exists' => true, 'value' => '', 'rows' => 1, 'structured' => false ], ( new PostMetaStore() )->read( 6, 'blank' ) );
    }

    public function testWriteStoresTheValueByteExactlyIncludingBackslashesAndQuotes(): void
    {
        $s = new PostMetaStore();
        foreach ( [ 'New', 'C:\\path "quoted" \'single\'', "tab\tand\nnewline", 'Café ✓ 日本語', '' ] as $value ) {
            $this->assertTrue( $s->write( 5, 'headline', $value ), $value );
            $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['headline'] );
        }
    }

    public function testWriteCreatesAMissingKey(): void
    {
        $this->assertTrue( ( new PostMetaStore() )->write( 6, 'fresh', 'v' ) );
        $this->assertSame( 'v', $GLOBALS['__wp_postmeta'][6]['fresh'] );
    }

    public function testWriteReportsFailureWhenTheReadBackDiffers(): void
    {
        $GLOBALS['__wp_meta_fail'] = true; // the update_post_meta shim then refuses to store anything
        try {
            $this->assertFalse( ( new PostMetaStore() )->write( 5, 'headline', 'Changed' ) );
            $this->assertSame( 'Old', $GLOBALS['__wp_postmeta'][5]['headline'] );
        } finally {
            $GLOBALS['__wp_meta_fail'] = false;
        }
    }

    public function testDeleteRemovesTheKeyAndSucceedsWhenItIsAlreadyGone(): void
    {
        $s = new PostMetaStore();
        $this->assertTrue( $s->delete( 5, 'headline' ) );
        $this->assertArrayNotHasKey( 'headline', $GLOBALS['__wp_postmeta'][5] );
        $this->assertTrue( $s->delete( 5, 'headline' ) );
    }

    public function testAnAcfSelectorRoutesTheWriteAndTheDeleteThroughTheAcfCallables(): void
    {
        $calls = [];
        $s     = new PostMetaStore(
            static function ( string $selector, string $value, int $postId ) use ( &$calls ): void {
                $calls[] = [ 'write', $selector, $value, $postId ];
                $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] = $value;
            },
            static function ( string $selector, int $postId ) use ( &$calls ): void {
                $calls[] = [ 'delete', $selector, $postId ];
                unset( $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] );
            }
        );
        $this->assertTrue( $s->write( 5, 'acf_text', 'Hi', 'field_abc' ) );
        $this->assertTrue( $s->delete( 5, 'acf_text', 'field_abc' ) );
        $this->assertSame( [ [ 'write', 'field_abc', 'Hi', 5 ], [ 'delete', 'field_abc', 5 ] ], $calls );

        // without an ACF selector the plain path is used even if ACF callables exist
        $calls = [];
        $this->assertTrue( $s->write( 5, 'headline', 'Plain' ) );
        $this->assertSame( [], $calls );
    }

    public function testAnAcfWriteThatStoresSomethingElseIsReportedAsFailure(): void
    {
        $s = new PostMetaStore( static function ( string $selector, string $value, int $postId ): void {
            $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] = $value . ' (mangled)';
        } );
        $this->assertFalse( $s->write( 5, 'acf_text', 'Hi', 'field_abc' ) );
    }
}
```
(The `__wp_meta_fail` global is the existing `update_post_meta` shim switch in `tests/bootstrap.php`; if its name differs, use the one the shim reads.)

Create `pro-addon/tests/PostMetaKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Kinds\PostMetaKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';

class PostMetaKindTest extends TestCase
{
    private PostMetaKind $kind;

    protected function setUp(): void
    {
        $this->kind               = new PostMetaKind( new PostMetaStore() );
        $GLOBALS['__wp_postmeta'] = [ 5 => [ 'headline' => 'Old' ] ];
        $GLOBALS['__wp_posts']    = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => '' ] ];
        $GLOBALS['__cf_types']    = [ 'page' => true ];
        $GLOBALS['__wp_can']      = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function item( array $before, array $after, ?string $acf = null ): array
    {
        $item = Item::make( PostMetaKind::NAME, '5:headline', 'Custom field headline on #5', $before, $after );
        if ( null !== $acf ) {
            $item['meta'] = [ 'acf' => $acf ];
        }

        return $item;
    }

    public function testItIsNamedAndReadsTheCanonicalValue(): void
    {
        $this->assertSame( 'post_meta', $this->kind->name() );
        $this->assertSame( [ 'exists' => true, 'value' => 'Old' ], $this->kind->current( $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'Old' ] ) ) );
        unset( $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( [ 'exists' => false, 'value' => null ], $this->kind->current( $this->item( [], [] ) ) );
    }

    public function testRestoreBringsBackTheOldValueAndReturnsAnInverseThatUndoesTheUndo(): void
    {
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'New';
        $item = $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ] );
        $r    = $this->kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'Old', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( Canon::hash( [ 'exists' => true, 'value' => 'Old' ] ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'exists' => true, 'value' => 'New' ], $r['inverse']['before'] );

        $again = $this->kind->restore( $r['inverse'] );
        $this->assertTrue( $again['ok'] );
        $this->assertSame( 'New', $GLOBALS['__wp_postmeta'][5]['headline'] );
    }

    public function testRestoringAKeyTheCallCreatedDeletesIt(): void
    {
        $GLOBALS['__wp_postmeta'][5]['fresh'] = 'created';
        $item = Item::make( PostMetaKind::NAME, '5:fresh', 'Custom field fresh on #5', [ 'exists' => false, 'value' => null ], [ 'exists' => true, 'value' => 'created' ] );
        $r    = $this->kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertArrayNotHasKey( 'fresh', $GLOBALS['__wp_postmeta'][5] );
        $this->assertSame( [ 'exists' => true, 'value' => 'created' ], $r['inverse']['before'] );
    }

    public function testTheAcfFieldKeyIsKeptOnTheInverse(): void
    {
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'New';
        $r = $this->kind->restore( $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ], 'field_abc' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'acf' => 'field_abc' ], $r['inverse']['meta'] );
    }

    public function testAllowedNeedsAnEditablePostAndTheMetaCapability(): void
    {
        $item = $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ] );
        $this->assertTrue( $this->kind->allowed( $item ) );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post_meta:5' => false ];
        $this->assertFalse( $this->kind->allowed( $item ), 'no edit_post_meta' );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:5' => false, 'edit_post_meta' => true ];
        $this->assertFalse( $this->kind->allowed( $item ), 'no edit_post' );

        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_posts'] = [];
        $this->assertFalse( $this->kind->allowed( $item ), 'the post is gone' );
    }

    public function testARefOfTheWrongShapeIsNeverAllowed(): void
    {
        foreach ( [ 'nope', ':key', '5:', 'x:key', '0:key' ] as $ref ) {
            $item = Item::make( PostMetaKind::NAME, $ref, 'x', [], [] );
            $this->assertFalse( $this->kind->allowed( $item ), $ref );
        }
    }
}
```

Run the focused command: expected FAIL (classes missing).

- [ ] **Step 2: Implement `PostMetaStore`**

Create `pro-addon/src/Fields/PostMetaStore.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code in the add-on that reads and writes a post's custom fields. Writes go through ACF's own update_field() when an ACF
 * selector (the field key) is given and ACF is active, otherwise through update_post_meta(). WordPress unslashes meta values it is
 * given, so the plain path slashes first. Every write is verified by reading the raw value back.
 */
class PostMetaStore // not final: the tool tests extend it with doubles that fail a write or report a key stored twice
{
    /** @var ?callable(string,string,int):mixed */
    private $acfWriter;
    /** @var ?callable(string,int):mixed */
    private $acfDeleter;

    public function __construct( ?callable $acfWriter = null, ?callable $acfDeleter = null )
    {
        $this->acfWriter  = $acfWriter;
        $this->acfDeleter = $acfDeleter;
    }

    public static function wp(): self
    {
        return new self(
            function_exists( 'update_field' ) ? 'update_field' : null,
            function_exists( 'delete_field' ) ? 'delete_field' : null
        );
    }

    /** @return array{exists:bool, value:?string, rows:int, structured:bool} */
    public function read( int $postId, string $key ): array
    {
        $all  = get_post_custom( $postId );
        $rows = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? count( $all[ $key ] ) : 0;
        if ( 0 === $rows ) {
            return [ 'exists' => false, 'value' => null, 'rows' => 0, 'structured' => false ];
        }
        $value      = get_post_meta( $postId, $key, true );
        $structured = is_array( $value ) || is_object( $value );

        return [ 'exists' => true, 'value' => is_scalar( $value ) ? (string) $value : null, 'rows' => $rows, 'structured' => $structured ];
    }

    /** True only when the raw value read back equals $value. */
    public function write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool
    {
        if ( null !== $acfSelector && null !== $this->acfWriter ) {
            ( $this->acfWriter )( $acfSelector, $value, $postId );
        } else {
            update_post_meta( $postId, $key, wp_slash( $value ) ); // phpcs:ignore -- the Pro add-on may write meta; the free plugin never does
        }
        $now = $this->read( $postId, $key );

        return $now['exists'] && 1 === $now['rows'] && $now['value'] === $value;
    }

    /** True when the key no longer exists. */
    public function delete( int $postId, string $key, ?string $acfSelector = null ): bool
    {
        if ( null !== $acfSelector && null !== $this->acfDeleter ) {
            ( $this->acfDeleter )( $acfSelector, $postId );
        }
        delete_post_meta( $postId, $key ); // also clears a plain key; for an ACF key it removes any row ACF left behind

        return ! $this->read( $postId, $key )['exists'];
    }
}
```
If `ProSafetyTest` objects to anything here, fix the code, not the test. If the `phpcs:ignore` comment style is not used elsewhere in `pro-addon/src`, drop that comment.

- [ ] **Step 3: Implement `PostMetaKind`**

Create `pro-addon/src/Kinds/PostMetaKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\WP\FieldTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One custom field of one post. `ref` is "{postId}:{key}". The canonical value (and the stored `before`) is
 * `{ exists: bool, value: ?string }`, so undoing a call that created a key deletes it and undoing a change puts the old value back.
 * An item written through ACF carries `meta.acf` (the ACF field key) so the restore uses the same path.
 */
final class PostMetaKind extends ValueKind
{
    public const NAME = 'post_meta';

    private ?PostMetaStore $store;

    public function __construct( ?PostMetaStore $store = null )
    {
        $this->store = $store;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function current( array $item ): mixed
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 ) {
            return [ 'exists' => false, 'value' => null ];
        }
        $now = $this->store()->read( $postId, $key );

        return [ 'exists' => $now['exists'], 'value' => $now['value'] ];
    }

    public function allowed( array $item ): bool
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 || '' === $key ) {
            return false;
        }

        return null === FieldTarget::resolve( $postId )['error'] && current_user_can( 'edit_post_meta', $postId, $key );
    }

    public function restore( array $item ): array
    {
        $result = parent::restore( $item );
        if ( $result['ok'] && isset( $item['meta'] ) ) {
            $result['inverse']['meta'] = $item['meta']; // the undo of the undo must use the same ACF path
        }

        return $result;
    }

    protected function write( array $item, mixed $value ): bool
    {
        [ $postId, $key ] = self::parse( (string) ( $item['ref'] ?? '' ) );
        if ( $postId < 1 || '' === $key ) {
            return false;
        }
        $acf = isset( $item['meta']['acf'] ) && is_string( $item['meta']['acf'] ) && '' !== $item['meta']['acf'] ? $item['meta']['acf'] : null;
        if ( is_array( $value ) && ! empty( $value['exists'] ) ) {
            return $this->store()->write( $postId, $key, (string) ( $value['value'] ?? '' ), $acf );
        }

        return $this->store()->delete( $postId, $key, $acf );
    }

    /** @return array{0:int,1:string} post id (0 when the ref is malformed) and key */
    private static function parse( string $ref ): array
    {
        $pos = strpos( $ref, ':' );
        if ( false === $pos ) {
            return [ 0, '' ];
        }
        $id = substr( $ref, 0, $pos );

        return [ ctype_digit( $id ) ? (int) $id : 0, substr( $ref, $pos + 1 ) ];
    }

    private function store(): PostMetaStore
    {
        return $this->store ??= PostMetaStore::wp();
    }
}
```

- [ ] **Step 4: Register**

- `pro-addon/src/autoload.php`: after the Task 1 `Fields/AcfFields.php` line add `require_once __DIR__ . '/Fields/PostMetaStore.php';` and `require_once __DIR__ . '/Kinds/PostMetaKind.php';`.
- `pro-addon/src/Batch/Batches.php`: add `use AiEditorDivi5\Pro\Kinds\PostMetaKind;` and `new PostMetaKind(),` to the `$kinds` array after `new GlobalPresetKind(),`.
- `pro-addon/tests/ToolsTest.php` `testEveryKindARecordedItemUsesIsRegisteredForUndo`: add `'post_meta'` to the expected list.

- [ ] **Step 5: Run the tests**

Run the focused command for `PostMetaStoreTest|PostMetaKindTest|ToolsTest` (PASS), then `make test-pro` (all pass, `ProSafetyTest` included).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Fields/PostMetaStore.php pro-addon/src/Kinds/PostMetaKind.php pro-addon/src/autoload.php pro-addon/src/Batch/Batches.php pro-addon/tests/PostMetaStoreTest.php pro-addon/tests/PostMetaKindTest.php pro-addon/tests/ToolsTest.php
git commit -m "$(cat <<'EOF'
feat(pro): PostMetaStore and the post_meta undo kind

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```
(add `pro-addon/tests/bootstrap.php` if you added the `wp_slash` shim.)

---

### Task 3: the `set_custom_fields` tool, its registration and the compatibility pins

**Files:**
- Create: `pro-addon/src/Tools/SetCustomFields.php`
- Create: `pro-addon/tests/SetCustomFieldsTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`, `pro-addon/src/Compat.php`
- Modify: `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/FreeApiCompatTest.php`, `pro-addon/tests/CompatTest.php` (only if it asserts `REQUIRED_CLASSES`), `pro-addon/readme.txt` (so `ReadmeTest` finds the tool)

**Interfaces:**
- Consumes: Tasks 1-2 (`FieldValuePolicy::refusal`, `AcfFields::index/refusalFor`, `PostMetaStore`, `PostMetaKind::NAME`), `Args::int/bool/listOf`, `BatchLog::record/attach`, `Item::make`, the free `FieldTarget::resolve`, `CustomFieldReader::classify/SHOWN/HIDDEN/SECRET`, `CustomFields::lookup(): \Closure`.
- Produces: `SetCustomFields::__construct( BatchLog $log, ?PostMetaStore $store = null, ?\Closure $known = null, ?array $acfIndex = null )` (the last three exist so tests inject collaborators; production passes only the log); `run( array ): array` with the payload `{ changed, dry_run, post:{id,title,post_type}, fields:[{key,status,before,after}] }` (+ `batch` when written); `status` is `set`, `create` or `unchanged`.

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/SetCustomFieldsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Fields\AcfFields;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Tools\SetCustomFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

if ( ! function_exists( 'wp_kses_post' ) ) {
    // A stand-in for core's wp_kses_post(): keeps the usual post-content tags, drops others (the real one runs in the live gate).
    function wp_kses_post( $s ) { return (string) preg_replace( '#</?(?!(?:p|br|strong|em|b|i|a|ul|ol|li|blockquote|div|span|h[1-6])\b)[a-z][^>]*>#i', '', (string) $s ); }
}

class SetCustomFieldsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetCustomFields $tool;

    /** @var array<string,array<string,mixed>> */
    private array $acf;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->acf  = AcfFields::indexWith(
            static fn () => [ [ 'key' => 'group_a' ] ],
            static fn () => [
                [ 'key' => 'field_size', 'name' => 'size', 'type' => 'select', 'choices' => [ 's' => 'Small', 'l' => 'Large' ] ],
                [ 'key' => 'field_count', 'name' => 'count', 'type' => 'number' ],
                [ 'key' => 'field_acf_text', 'name' => 'acf_text', 'type' => 'text' ],
                [ 'key' => 'field_team', 'name' => 'team', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_m', 'name' => 'member', 'type' => 'text' ] ] ],
                [ 'key' => 'field_addr', 'name' => 'address', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_city', 'name' => 'city', 'type' => 'text' ] ] ],
                [ 'key' => 'field_pw', 'name' => 'acf_pw', 'type' => 'password' ],
            ]
        );
        $known      = static fn ( string $key ): bool => in_array( $key, [ 'headline', 'subtitle', 'seo_title', 'twice', 'listy' ], true );
        $this->tool = new SetCustomFields( $this->log, new PostMetaStore( static function ( string $selector, string $value, int $postId ): void {
            $GLOBALS['__acf_calls'][] = [ $selector, $value, $postId ];
            $name = [ 'field_acf_text' => 'acf_text', 'field_size' => 'size', 'field_count' => 'count' ][ $selector ] ?? 'unknown';
            $GLOBALS['__wp_postmeta'][ $postId ][ $name ] = $value;
        } ), $known, $this->acf );

        $GLOBALS['__acf_calls']   = [];
        $GLOBALS['__wp_postmeta'] = [
            5 => [ 'headline' => 'Old headline', 'subtitle' => 'Old sub', 'listy' => [ 1, 2 ], '_hidden' => 'h', 'api_token' => 't' ],
            6 => [],
            7 => [],
        ];
        $GLOBALS['__wp_posts'] = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'Proj', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Hdr', 'post_content' => '' ],
        ];
        $GLOBALS['__cf_types']  = [ 'page' => true, 'project' => true, 'et_header_layout' => false ];
        $GLOBALS['__cf_protected'] = [];
        $GLOBALS['__wp_can']    = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<array{key:string,value:string}> $fields */
    private function call( int $id, array $fields, bool $dry = false ): array
    {
        return $this->tool->run( [ 'id' => $id, 'fields' => $fields, 'dry_run' => $dry ] );
    }

    private function refused( array $args, string $message ): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'], 'nothing was written' );
        $this->assertSame( [], $this->repo->rows, 'no batch was recorded' );
    }

    private function field( string $key, string $value ): array
    {
        return [ 'key' => $key, 'value' => $value ];
    }

    // ---- definition ---------------------------------------------------------------------------

    public function testTheDefinitionIsGeminiSafeAndShortAndNeedsIdAndFields(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'set_custom_fields', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'id', 'fields' ], $d['inputSchema']['required'] );
        $this->assertSame( 'integer', $d['inputSchema']['properties']['id']['type'] );
        $this->assertSame( 'array', $d['inputSchema']['properties']['fields']['type'] );
        $this->assertSame( [ 'key', 'value' ], $d['inputSchema']['properties']['fields']['items']['required'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $json = (string) json_encode( $d['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf', 'anyOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }

    // ---- the happy path -----------------------------------------------------------------------

    public function testItSetsTheListedKeysOnlyAndRecordsOneUndoableBatch(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'New headline' ), $this->field( 'subtitle', 'Old sub' ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 'id' => 5, 'title' => 'Home', 'post_type' => 'page' ], $r['post'] );
        $this->assertSame( [
            [ 'key' => 'headline', 'status' => 'set', 'before' => 'Old headline', 'after' => 'New headline' ],
            [ 'key' => 'subtitle', 'status' => 'unchanged', 'before' => 'Old sub', 'after' => 'Old sub' ],
        ], $r['fields'] );
        $this->assertSame( 'New headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( 'h', $GLOBALS['__wp_postmeta'][5]['_hidden'], 'nothing else was touched' );
        $this->assertSame( 1, $r['batch']['id'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertCount( 1, $this->repo->rows );
        $this->assertCount( 1, $this->repo->rows[1]['items'], 'only the changed field is an item' );
        $this->assertSame( 'post_meta', $this->repo->rows[1]['items'][0]['kind'] );
    }

    public function testAKnownKeyThePostDoesNotHaveYetIsCreatedAndLabelledCreate(): void
    {
        $r = $this->call( 6, [ $this->field( 'seo_title', 'A title' ) ] );
        $this->assertSame( 'create', $r['fields'][0]['status'] );
        $this->assertNull( $r['fields'][0]['before'] );
        $this->assertSame( 'A title', $GLOBALS['__wp_postmeta'][6]['seo_title'] );
    }

    public function testADiviProjectAndAnyPublicTypeIsAccepted(): void
    {
        $this->assertTrue( $this->call( 6, [ $this->field( 'headline', 'On a project' ) ] )['changed'] );
    }

    public function testNothingToChangeIsReportedAndRecordsNoBatch(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'Old headline' ) ] );
        $this->assertFalse( $r['changed'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testDryRunPreviewsEverythingAndWritesNothing(): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        $r      = $this->call( 5, [ $this->field( 'headline', 'Preview' ) ], true );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'set', $r['fields'][0]['status'] );
        $this->assertSame( 'Preview', $r['fields'][0]['after'] );
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testBackslashesQuotesAndUnicodeSurviveTheWriteAndTheUndo(): void
    {
        $value = 'C:\\path "quoted" \'single\' Café ✓';
        $r     = $this->call( 5, [ $this->field( 'headline', $value ) ] );
        $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['headline'] );
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $u    = $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertTrue( $u['ok'] ?? $u['undone'] ?? true );
    }

    public function testAnAcfTopLevelFieldIsWrittenThroughAcfWithItsFieldKey(): void
    {
        $r = $this->call( 5, [ $this->field( 'acf_text', 'Via ACF' ), $this->field( 'size', 'l' ), $this->field( 'count', '12' ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'field_acf_text', 'Via ACF', 5 ], [ 'field_size', 'l', 5 ], [ 'field_count', '12', 5 ] ], $GLOBALS['__acf_calls'] );
        $items = $this->repo->rows[1]['items'];
        $this->assertSame( [ 'acf' => 'field_acf_text' ], $items[0]['meta'] );
    }

    // ---- argument checking --------------------------------------------------------------------

    public function testTheArgumentsAreTypeCheckedNeverCast(): void
    {
        $ok = [ $this->field( 'headline', 'x' ) ];
        $this->refused( [ 'fields' => $ok ], '"id" is required.' );
        $this->refused( [ 'id' => '5', 'fields' => $ok ], '"id" must be an integer.' );
        $this->refused( [ 'id' => 5 ], '"fields" is required.' );
        $this->refused( [ 'id' => 5, 'fields' => 'headline' ], '"fields" must be a list.' );
        $this->refused( [ 'id' => 5, 'fields' => [] ], '"fields" must not be empty.' );
        $this->refused( [ 'id' => 5, 'fields' => $ok, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'id' => 5, 'fields' => [ 'headline' ] ], 'item 1 must be an object' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 5, 'value' => 'x' ] ] ], 'item 1: "key" must be a non-empty string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => '', 'value' => 'x' ] ] ], 'item 1: "key" must be a non-empty string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 'headline', 'value' => 5 ] ] ], 'item 1: "value" must be a string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 'headline' ] ] ], 'item 1: "value" must be a string' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'a' ), $this->field( 'headline', 'b' ) ] ], 'appears more than once' );
    }

    public function testAtMost25FieldsPerCall(): void
    {
        $many = [];
        for ( $i = 0; $i < 26; $i++ ) {
            $many[] = $this->field( 'headline', (string) $i );
        }
        $this->refused( [ 'id' => 5, 'fields' => $many ], 'at most 25 items' );
    }

    // ---- the target ---------------------------------------------------------------------------

    public function testMissingNonPublicAndNotEditablePostsAllLookMissing(): void
    {
        $fields = [ $this->field( 'headline', 'x' ) ];
        $this->refused( [ 'id' => 999, 'fields' => $fields ], 'Post 999 not found.' );
        $this->refused( [ 'id' => 7, 'fields' => $fields ], 'Post 7 not found.' );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:5' => false, 'edit_post_meta' => true ];
        $this->refused( [ 'id' => 5, 'fields' => $fields ], 'Post 5 not found.' );
    }

    // ---- per-field refusals (all-or-nothing) --------------------------------------------------

    /** @return list<array{string,string,string}> key, value, reason substring */
    public static function refusals(): array
    {
        return [
            'underscore key'        => [ '_hidden', 'x', 'protected or private' ],
            'secret-looking key'    => [ 'api_token', 'x', 'password, token' ],
            'unknown key'           => [ 'never_seen', 'x', 'this site knows' ],
            'existing list value'   => [ 'listy', 'x', 'list or object' ],
            'script in the value'   => [ 'headline', '<script>alert(1)</script>', 'script' ],
            'event handler'         => [ 'headline', '<p onclick="x()">hi</p>', 'event-handler' ],
            'javascript url'        => [ 'headline', 'javascript:alert(1)', 'javascript' ],
            'markup kses changes'   => [ 'headline', '<form action="x">hi</form>', 'markup' ],
            'acf select not a choice' => [ 'size', 'xl', 'one of' ],
            'acf number not numeric'  => [ 'count', 'abc', 'number' ],
            'acf repeater'          => [ 'team', 'x', 'ACF editor' ],
            'acf group'             => [ 'address', 'x', 'ACF editor' ],
            'acf group member'      => [ 'address_city', 'x', 'ACF editor' ],
            'acf repeater row key'  => [ 'team_0_member', 'x', 'ACF editor' ],
            'acf password field'    => [ 'acf_pw', 'x', 'ACF editor' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testEachRefusalNamesTheKeyAndTheReason( string $key, string $value, string $needle ): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $this->tool->run( [ 'id' => 5, 'fields' => [ $this->field( $key, $value ) ] ] );
            $this->fail( "expected a refusal for {$key}" );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $key, $e->getMessage() );
            $this->assertStringContainsString( $needle, $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was written', $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__acf_calls'] );
    }

    public function testAValueOverTheLengthLimitIsRefused(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', str_repeat( 'a', 10001 ) ) ] ], '10,000' );
        $this->assertTrue( $this->call( 5, [ $this->field( 'headline', str_repeat( 'a', 10000 ) ) ] )['changed'], 'exactly 10,000 characters is allowed' );
    }

    public function testAKeyStoredTwiceOnThePostIsRefused(): void
    {
        $tool = new SetCustomFields( $this->log, new class() extends PostMetaStore {
            public function read( int $postId, string $key ): array
            {
                return 'twice' === $key ? [ 'exists' => true, 'value' => 'a', 'rows' => 2, 'structured' => false ] : parent::read( $postId, $key );
            }
        }, static fn ( string $k ): bool => true, $this->acf );
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'twice', 'x' ) ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'twice', $e->getMessage() );
            $this->assertStringContainsString( 'more than once', $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testWordPressCanRefuseAKeyForThisPost(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true, 'edit_post_meta:5' => false ];
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'x' ) ] ], 'not allowed by WordPress' );
    }

    public function testAProtectedKeyThatWordPressMarksProtectedIsRefused(): void
    {
        $GLOBALS['__cf_protected'] = [ 'subtitle' ];
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'subtitle', 'x' ) ] ], 'protected or private' );
    }

    public function testOneRefusedFieldWritesNothingAtAll(): void
    {
        $this->refused(
            [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'fine' ), $this->field( 'subtitle', 'also fine' ), $this->field( '_hidden', 'bad' ), $this->field( 'seo_title', 'ok' ) ] ],
            '_hidden'
        );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
    }

    public function testEveryRefusalIsReportedNotJustTheFirst(): void
    {
        try {
            $this->tool->run( [ 'id' => 5, 'fields' => [ $this->field( '_hidden', 'x' ), $this->field( 'never_seen', 'y' ), $this->field( 'headline', '<script>' ) ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            foreach ( [ '_hidden', 'never_seen', 'headline' ] as $key ) {
                $this->assertStringContainsString( $key, $e->getMessage() );
            }
            $this->assertStringContainsString( '3 field(s) refused', $e->getMessage() );
        }
    }

    public function testDryRunRefusesTheSameWayAndWritesNothing(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( '_hidden', 'x' ) ], 'dry_run' => true ], '_hidden' );
    }

    // ---- atomic writes ------------------------------------------------------------------------

    public function testAFailedWriteRollsBackEveryFieldAlreadyWritten(): void
    {
        $this->tool = new SetCustomFields( $this->log, new class() extends PostMetaStore {
            public function write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool
            {
                if ( 'subtitle' === $key && 'boom' === $value ) {
                    return false; // the second write fails
                }

                return parent::write( $postId, $key, $value, $acfSelector );
            }
        }, static fn ( string $k ): bool => true, $this->acf );

        try {
            $this->call( 5, [ $this->field( 'headline', 'First' ), $this->field( 'seo_title', 'Created' ), $this->field( 'subtitle', 'boom' ) ] );
            $this->fail( 'expected a failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'subtitle', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
        }
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'], 'the first write was rolled back' );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5], 'the created key was removed again' );
        $this->assertSame( 'Old sub', $GLOBALS['__wp_postmeta'][5]['subtitle'] );
        $this->assertSame( [], $this->repo->rows );
    }

    // ---- undo through the real batch machinery ------------------------------------------------

    public function testUndoRestoresAnEditedValueAndDeletesACreatedKey(): void
    {
        $r    = $this->call( 5, [ $this->field( 'headline', 'Changed' ), $this->field( 'seo_title', 'Created' ) ] );
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5] );
    }

    public function testUndoRefusesAValueSomeoneElseChangedSince(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'Changed' ) ] );
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'Edited by a person';
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 'Edited by a person', $GLOBALS['__wp_postmeta'][5]['headline'], 'the later edit is not overwritten' );
    }
}
```
Notes for the implementer: this test file was written against the Pro shims; the exact shape `BatchUndo::undo()` returns (keys like `ok`, `undone`, `skipped`) must be read from `pro-addon/src/Batch/BatchUndo.php` and `pro-addon/tests/BatchUndoTest.php`, and the two undo tests' assertions on the return value (`testBackslashesQuotesAndUnicodeSurviveTheWriteAndTheUndo`, `testUndoRefusesAValueSomeoneElseChangedSince`) must assert the REAL "restored" / "skipped, changed since" outcome keys (add those assertions; do not leave the loose `?? true` one). The `WP_Post`/stdClass fixtures follow `SetFrontPageTest`; `FieldTarget::resolve` reads `$post->post_type`.

Run the focused command: expected FAIL (class missing).

- [ ] **Step 2: Implement `SetCustomFields`**

Create `pro-addon/src/Tools/SetCustomFields.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Fields\AcfFields;
use AiEditorDivi5\Pro\Fields\FieldValuePolicy;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Kinds\PostMetaKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\WP\CustomFieldReader;
use AiEditorDivi5\WP\CustomFields;
use AiEditorDivi5\WP\FieldTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Set custom field values on ONE post. Every field is checked first; if any is refused nothing is written. A write is verified by
 * reading the raw value back and a failure rolls back every field already written. One undoable batch per call.
 */
final class SetCustomFields implements ProTool
{
    public const NAME       = 'set_custom_fields';
    public const MAX_FIELDS = 25;
    private const SHOW_CHARS = 200;

    private BatchLog $log;
    private ?PostMetaStore $store;
    private ?\Closure $known;
    /** @var ?array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private ?array $acfIndex;

    /**
     * @param ?array{fields: array<string,array<string,mixed>>, prefixes: list<string>} $acfIndex test injection
     */
    public function __construct( BatchLog $log, ?PostMetaStore $store = null, ?\Closure $known = null, ?array $acfIndex = null )
    {
        $this->log      = $log;
        $this->store    = $store;
        $this->known    = $known;
        $this->acfIndex = $acfIndex;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_posts';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Set custom field values on ONE page, Divi Project or other post you can edit. Only keys the site knows; never protected or secret keys, code, or ACF lists and images. All or nothing; one undoable batch. dry_run previews. Read fields first with get_custom_fields.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'      => [ 'type' => 'integer', 'description' => 'Post ID' ],
                    'fields'  => [
                        'type'        => 'array',
                        'description' => 'Up to 25 fields to set, each with a key and a text value',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'key'   => [ 'type' => 'string', 'description' => 'Custom field key' ],
                                'value' => [ 'type' => 'string', 'description' => 'New text value (at most 10,000 characters)' ],
                            ],
                            'required'   => [ 'key', 'value' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'id', 'fields' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $postId = (int) Args::int( $arguments, 'id' );
        $dry    = Args::bool( $arguments, 'dry_run' );
        $fields = $this->fieldsFrom( Args::listOf( $arguments, 'fields', self::MAX_FIELDS ) );

        $target = FieldTarget::resolve( $postId );
        if ( null !== $target['error'] || null === $target['post'] ) {
            throw new \InvalidArgumentException( "Post {$postId} not found." );
        }
        $post  = $target['post'];
        $store = $this->store();

        $plans    = [];
        $refusals = [];
        foreach ( $fields as $field ) {
            $plan = $this->plan( $postId, $field['key'], $field['value'], $store );
            if ( null !== $plan['refusal'] ) {
                $refusals[] = $field['key'] . ' ' . $plan['refusal'];
                continue;
            }
            $plans[] = $plan;
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was written. ' . count( $refusals ) . ' field(s) refused: ' . implode( '; ', $refusals ) . '.';
            throw new \InvalidArgumentException( mb_substr( $message, 0, 1500 ) );
        }

        $rows    = [];
        $changes = [];
        foreach ( $plans as $p ) {
            $unchanged = $p['before']['exists'] && $p['before']['value'] === $p['value'];
            $rows[]    = [
                'key'    => $p['key'],
                'status' => $unchanged ? 'unchanged' : ( $p['before']['exists'] ? 'set' : 'create' ),
                'before' => $p['before']['exists'] ? self::cut( (string) $p['before']['value'] ) : null,
                'after'  => self::cut( $p['value'] ),
            ];
            if ( ! $unchanged ) {
                $changes[] = $p;
            }
        }
        $page = [ 'id' => $postId, 'title' => (string) get_the_title( $post ), 'post_type' => (string) $post->post_type ];

        if ( [] === $changes || $dry ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'post' => $page, 'fields' => $rows ]
                + ( [] === $changes ? [ 'message' => 'Every value is already what you asked for; nothing changed.' ] : [] );
        }

        $written = [];
        foreach ( $changes as $c ) {
            if ( ! $store->write( $postId, $c['key'], $c['value'], $c['acf'] ) ) {
                $this->rollback( $postId, $written, $store );
                throw new \InvalidArgumentException( "Could not write \"{$c['key']}\"; nothing was changed." );
            }
            $written[] = $c;
        }

        $items = [];
        foreach ( $written as $c ) {
            $item = Item::make(
                PostMetaKind::NAME,
                "{$postId}:{$c['key']}",
                "Custom field {$c['key']} on #{$postId}",
                [ 'exists' => $c['before']['exists'], 'value' => $c['before']['value'] ],
                [ 'exists' => true, 'value' => $c['value'] ]
            );
            if ( null !== $c['acf'] ) {
                $item['meta'] = [ 'acf' => $c['acf'] ];
            }
            $items[] = $item;
        }
        $count = count( $items );
        $batch = $this->log->record( self::NAME, "Set {$count} custom field(s) on \"{$page['title']}\" (#{$postId})", $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'post' => $page, 'fields' => $rows ], $batch );
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{key:string,value:string}>
     */
    private function fieldsFrom( array $raw ): array
    {
        $out  = [];
        $seen = [];
        foreach ( $raw as $i => $item ) {
            $n = $i + 1;
            if ( ! is_array( $item ) || array_is_list( $item ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n} must be an object with \"key\" and \"value\"." );
            }
            $key = $item['key'] ?? null;
            if ( ! is_string( $key ) || '' === trim( $key ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n}: \"key\" must be a non-empty string." );
            }
            if ( ! isset( $item['value'] ) || ! is_string( $item['value'] ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n}: \"value\" must be a string." );
            }
            if ( isset( $seen[ $key ] ) ) {
                throw new \InvalidArgumentException( "\"fields\": the key \"{$key}\" appears more than once." );
            }
            $seen[ $key ] = true;
            $out[]        = [ 'key' => $key, 'value' => $item['value'] ];
        }

        return $out;
    }

    /**
     * @return array{key:string,value:string,before:array{exists:bool,value:?string},acf:?string,refusal:?string}
     */
    private function plan( int $postId, string $key, string $value, PostMetaStore $store ): array
    {
        $plan = [ 'key' => $key, 'value' => $value, 'before' => [ 'exists' => false, 'value' => null ], 'acf' => null, 'refusal' => null ];

        $class = CustomFieldReader::classify( $key );
        if ( CustomFieldReader::HIDDEN === $class ) {
            return [ 'refusal' => 'is a protected or private key and is never written' ] + $plan;
        }
        if ( CustomFieldReader::SECRET === $class ) {
            return [ 'refusal' => 'looks like a password, token or licence key and is never written' ] + $plan;
        }
        if ( ! current_user_can( 'edit_post_meta', $postId, $key ) ) {
            return [ 'refusal' => 'is not allowed by WordPress for this post' ] + $plan;
        }

        $acf = $this->acf();
        $def = $acf['fields'][ $key ] ?? null;
        if ( null !== $def && $def['nested'] ) {
            return [ 'refusal' => 'belongs to a group in ACF; edit it in the ACF editor' ] + $plan;
        }
        if ( null === $def ) {
            foreach ( $acf['prefixes'] as $prefix ) {
                if ( str_starts_with( $key, $prefix ) ) {
                    return [ 'refusal' => 'belongs to a complex ACF field (a group, repeater or flexible content); edit it in the ACF editor' ] + $plan;
                }
            }
            if ( ! ( $this->known() )( $key ) ) {
                return [ 'refusal' => 'is not a custom field this site knows (no ACF field and no post uses it)' ] + $plan;
            }
        }

        $now = $store->read( $postId, $key );
        if ( $now['rows'] > 1 ) {
            return [ 'refusal' => 'is stored more than once on this post' ] + $plan;
        }
        if ( $now['structured'] ) {
            return [ 'refusal' => 'currently holds a list or object; edit it in the ACF editor' ] + $plan;
        }
        $plan['before'] = [ 'exists' => $now['exists'], 'value' => $now['value'] ];

        $why = FieldValuePolicy::refusal( $value );
        if ( null !== $why ) {
            return [ 'refusal' => 'value ' . $why ] + $plan;
        }
        if ( wp_kses_post( $value ) !== $value ) {
            return [ 'refusal' => 'value contains markup WordPress does not allow in post content' ] + $plan;
        }
        if ( null !== $def ) {
            $why = AcfFields::refusalFor( $def, $value );
            if ( null !== $why ) {
                return [ 'refusal' => $why ] + $plan;
            }
            $plan['acf'] = '' !== $def['field_key'] ? $def['field_key'] : null;
        }

        return $plan;
    }

    /** @param list<array{key:string,value:string,before:array{exists:bool,value:?string},acf:?string,refusal:?string}> $written */
    private function rollback( int $postId, array $written, PostMetaStore $store ): void
    {
        foreach ( array_reverse( $written ) as $c ) {
            if ( $c['before']['exists'] ) {
                $store->write( $postId, $c['key'], (string) $c['before']['value'], $c['acf'] );
            } else {
                $store->delete( $postId, $c['key'], $c['acf'] );
            }
        }
    }

    private static function cut( string $text ): string
    {
        return mb_strlen( $text ) > self::SHOW_CHARS ? mb_substr( $text, 0, self::SHOW_CHARS ) . '...' : $text;
    }

    private function store(): PostMetaStore
    {
        return $this->store ??= PostMetaStore::wp();
    }

    private function known(): \Closure
    {
        return $this->known ??= CustomFields::lookup();
    }

    /** @return array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private function acf(): array
    {
        return $this->acfIndex ??= AcfFields::index();
    }
}
```
The refusal wording the tests match on: `"protected or private"`, `"password, token"`, `"this site knows"` (the unknown-key text is "is not a custom field this site knows (no ACF field and no post uses it)"), `"more than once"`, `"list or object"`, the `FieldValuePolicy` reasons (`script`, `event-handler`, `javascript`), `"markup"`, `"10,000"`, the ACF reasons (`one of`, `number`, `ACF editor`), `"not allowed by WordPress"`, `"Nothing was written"` and `"N field(s) refused"`. Each refusal message reads "{key} {reason}" (for a value problem: "headline value contains ..."). If you change a sentence, change the matching test needle in the same commit and say so in the report.

- [ ] **Step 3: Register the tool, the OpenAPI exception, the compat pins and the readme line**

- `pro-addon/src/autoload.php`: after the `Kinds/PostMetaKind.php` line add `require_once __DIR__ . '/Tools/SetCustomFields.php';`.
- `pro-addon/src/Tools.php`: `use AiEditorDivi5\Pro\Tools\SetCustomFields;` and append `new SetCustomFields( $log ),` after `new ManageGlobalPresets( $log ),`.
- `pro-addon/src/Rest/ProOpenApi.php`: `MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields' ]`.
- `pro-addon/src/Compat.php`: append `'AiEditorDivi5\\WP\\FieldTarget'`, `'AiEditorDivi5\\WP\\CustomFieldReader'`, `'AiEditorDivi5\\WP\\CustomFields'` to `REQUIRED_CLASSES`. Do NOT change `MIN_FREE`.
- `pro-addon/tests/FreeApiCompatTest.php`: add to `signatures()`:
  ```php
            [ $w . 'FieldTarget', 'resolve', [ 'id' => 'int' ], 'array' ],
            [ $w . 'CustomFieldReader', 'classify', [ 'key' => 'string' ], 'string' ],
            [ $w . 'CustomFields', 'lookup', [], 'Closure' ],
  ```
  and a test that `CustomFieldReader::SHOWN === 'shown'`, `HIDDEN === 'hidden'`, `SECRET === 'secret'`.
- `pro-addon/tests/CompatTest.php`: if it compares `REQUIRED_CLASSES` to a literal list, add the three classes there too.
- `pro-addon/tests/ToolsTest.php`: add `'set_custom_fields'` to the expected tool list in `testThisReleaseShipsExactlyTheseTools`.
- `pro-addon/tests/ProOpenApiTest.php`: next to the three existing `assertArrayNotHasKey( '/pro/manage_global_...' )` lines add the same for `'/pro/set_custom_fields'`; the `assertCount( 14, $paths )` stays 14. If `ProParityTest` asserts a literal list of MCP-only tools, add the new one.
- `pro-addon/readme.txt`: add under `= Tools =` (the section `ReadmeTest` parses) a bullet in the existing style: ``* `set_custom_fields` - set custom field values on one page, Divi Project or other post you can edit (only keys your site already uses; never protected or secret keys, code, or ACF lists and images; all or nothing; `dry_run` previews; `undo_batch` puts the values back as long as nothing changed them afterwards).`` and extend the "Every tool is also available over REST ..." sentence so the MCP-and-REST-only list includes `set_custom_fields`.

- [ ] **Step 4: Run everything**

Run the focused command for `SetCustomFieldsTest`, then `make test-pro` (all pass; `ProSafetyTest`, `ToolsTest`, `ProParityTest`, `ProOpenApiTest`, `ReadmeTest`, `FreeApiCompatTest` included) and `vendor/bin/phpunit` (967, untouched). Show at least these tests failing before the implementation (the missing-class error counts for the first run) and that the refusal tests would fail if the corresponding check were removed: pick two checks (for example the `_hidden` check and the all-or-nothing behavior), temporarily disable each, confirm the matching tests fail, restore, and record the evidence in the report.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -m "$(cat <<'EOF'
feat(pro): set_custom_fields, an undoable all-or-nothing tool for custom field values

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: live gate `make verify-pro-custom-fields`

**Files:**
- Create: `scripts/verify-pro-custom-fields.php`
- Modify: `Makefile` (`.PHONY` line and a new target after `verify-pro-settings`)

**Interfaces:** consumes the real tool, the real batch table and WordPress/ACF on the Docker dev site. No production changes.

Prerequisites: Docker dev site up with the free and Pro plugins active and `divi/Divi.zip` installed; dev site only. If the Pro plugin is not active, activate it with `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro` and deactivate it again afterwards if it was inactive before (report which). Never run `wp plugin delete`.

- [ ] **Step 1: Write the script**

Read `scripts/verify-pro-settings.php` first and reuse its structure: the `Plugin` class guard, `scripts/dev-guard.php` (`aied_require_dev_site()`), `$check`, `BatchTable::ensureReady( true )`, `Batches::log()` / `Batches::undo()`, the `$track` / `$trackUndo` helpers, and its `finally` cleanup of created posts and batch rows (the batch store keeps only the newest 20, so keep the script to about 8 batches and say so in the header). Create `scripts/verify-pro-custom-fields.php`:

```php
<?php
/**
 * Real-site check of the Pro tool set_custom_fields: the real tool, the real batch table and WordPress's own functions.
 * Self-cleaning: it deletes every post, batch row and ACF group it created. It records about 8 batches and the batch store keeps
 * only the newest 20, so on a site that already has batches it evicts the oldest real ones: dev sites only.
 *
 * ACF checks run only when the free ACF plugin is active (it is NOT part of the dev environment). To run them, install it for the
 * run, then remove it again exactly as described in the header of scripts/verify-acf-custom-fields.php (deactivate, delete the
 * folder, delete the acf_* options; never `wp plugin delete`). Without ACF those checks are SKIP lines.
 *
 * Run: make verify-pro-custom-fields   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed: the tool
 * is called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Tools\SetCustomFields;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
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
$skip = static function ( string $label ): void {
    WP_CLI::log( 'SKIP  ' . $label );
};

$posts    = [];
$batchIds = [];
$acfGroup = 0;
BatchTable::ensureReady( true );
$log  = Batches::log();
$undo = Batches::undo();
$tool = new SetCustomFields( $log );

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
$make = static function ( string $title, array $meta = [] ) use ( &$posts ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '', 'meta_input' => $meta ], true );
    if ( is_wp_error( $id ) ) {
        throw new \RuntimeException( 'Could not create a throwaway page: ' . $id->get_error_message() );
    }
    $posts[] = (int) $id;

    return (int) $id;
};
/** Run the tool; returns [ payload, error message or null ]. */
$run = static function ( array $args ) use ( $tool, $track ): array {
    try {
        return [ $track( $tool->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    }
};
$meta = static fn ( int $id, string $key ): mixed => get_post_meta( $id, $key, true );

try {
    // A key becomes "known" because ANOTHER post already has it.
    $seed = $make( 'AIED Pro cf seed', [ 'aied_pro_known' => 'seed value', 'aied_pro_other' => 'x' ] );
    $page = $make( 'AIED Pro cf target', [ 'aied_pro_known' => 'Original' ] );
    $fresh = $make( 'AIED Pro cf fresh' );

    // ---- dry run, write, undo ---------------------------------------------------------------
    [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'Preview' ] ], 'dry_run' => true ] );
    $check( null === $err && true === ( $r['dry_run'] ?? false ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'a dry run previews and leaves the value alone' );
    $check( 0 === (int) ( $log->recent( 20 )[0]['id'] ?? 0 ) || ! isset( $r['batch'] ), 'a dry run records no batch' );

    $tricky = 'C:\\path "quoted" \'single\' Café ✓ <b>bold</b>';
    [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => $tricky ] ] ] );
    $check( null === $err && true === ( $r['changed'] ?? false ), 'a valid call writes' );
    $check( $tricky === $meta( $page, 'aied_pro_known' ), 'backslashes, quotes, UTF-8 and allowed markup are stored byte-exactly' );
    $check( true === ( $r['batch']['undoable'] ?? false ), 'the call recorded one undoable batch' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'Original' === $meta( $page, 'aied_pro_known' ), 'undo puts the original value back' );

    // ---- a call that creates a key; undo deletes it -------------------------------------------
    [ $r, $err ] = $run( [ 'id' => $fresh, 'fields' => [ [ 'key' => 'aied_pro_other', 'value' => 'created here' ] ] ] );
    $check( null === $err && 'create' === ( $r['fields'][0]['status'] ?? '' ) && 'created here' === $meta( $fresh, 'aied_pro_other' ), 'a key the site knows is created on a post that did not have it' );
    $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( ! metadata_exists( 'post', $fresh, 'aied_pro_other' ), 'undo of a created key deletes it again' );

    // ---- undo refuses a value someone else changed ------------------------------------------
    [ $r ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'By the AI' ] ] ] );
    update_post_meta( $page, 'aied_pro_known', wp_slash( 'By a person' ) );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'By a person' === $meta( $page, 'aied_pro_known' ), 'undo does not overwrite a later edit' );
    $u2 = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );

    // ---- refusals change nothing --------------------------------------------------------------
    update_post_meta( $page, '_aied_pro_hidden', 'h' );
    update_post_meta( $page, 'aied_pro_api_token', 't' );
    $before = [ $meta( $page, 'aied_pro_known' ), $meta( $page, '_aied_pro_hidden' ), $meta( $page, 'aied_pro_api_token' ) ];
    $cases  = [
        'an underscore key'          => [ [ 'key' => '_aied_pro_hidden', 'value' => 'x' ], 'protected or private' ],
        'a secret-looking key'       => [ [ 'key' => 'aied_pro_api_token', 'value' => 'x' ], 'password' ],
        'an unknown key'             => [ [ 'key' => 'aied_pro_never_used_anywhere', 'value' => 'x' ], 'knows' ],
        'a script value'             => [ [ 'key' => 'aied_pro_known', 'value' => '<script>alert(1)</script>' ], 'script' ],
        'an event-handler value'     => [ [ 'key' => 'aied_pro_known', 'value' => '<p onclick="x()">hi</p>' ], 'event-handler' ],
        'a javascript: URL'          => [ [ 'key' => 'aied_pro_known', 'value' => '<a href="javascript:alert(1)">x</a>' ], 'javascript' ],
        'markup kses would change'   => [ [ 'key' => 'aied_pro_known', 'value' => '<form action="x">hi</form>' ], 'markup' ],
        'a value over 10,000 chars'  => [ [ 'key' => 'aied_pro_known', 'value' => str_repeat( 'a', 10001 ) ], '10,000' ],
    ];
    foreach ( $cases as $label => [ $field, $needle ] ) {
        [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'would be written' ], $field ] ] );
        $check( null !== $err && str_contains( $err, $needle ) && 'Original' === $meta( $page, 'aied_pro_known' ), "refused: {$label} (and the valid field beside it was not written)" );
    }
    $check( $before === [ $meta( $page, 'aied_pro_known' ), $meta( $page, '_aied_pro_hidden' ), $meta( $page, 'aied_pro_api_token' ) ], 'no refused call changed any value' );

    $tooMany = [];
    for ( $i = 0; $i < 26; $i++ ) {
        $tooMany[] = [ 'key' => 'aied_pro_known', 'value' => (string) $i ];
    }
    [ , $err ] = $run( [ 'id' => $page, 'fields' => $tooMany ] );
    $check( null !== $err && str_contains( $err, 'at most 25' ), 'refused: 26 fields' );
    [ , $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'a' ], [ 'key' => 'aied_pro_known', 'value' => 'b' ] ] ] );
    $check( null !== $err && str_contains( $err, 'more than once' ), 'refused: a duplicate key' );
    [ , $err ] = $run( [ 'id' => 99999999, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'x' ] ] ] );
    $check( null !== $err && str_contains( $err, 'not found' ), 'refused: a missing post' );

    // a user who cannot edit the post sees it as missing
    $contributor = wp_insert_user( [ 'user_login' => 'aied_pro_cf_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
    if ( ! is_wp_error( $contributor ) ) {
        wp_set_current_user( (int) $contributor );
        [ , $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'x' ] ] ] );
        wp_set_current_user( 1 );
        wp_delete_user( (int) $contributor );
        $check( null !== $err && str_contains( $err, 'not found' ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'a contributor who cannot edit the page gets "not found" and nothing changes' );
    }

    // the free read tool sees what the Pro tool wrote
    [ $r ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'Seen by get_custom_fields' ] ] ] );
    $read = \AiEditorDivi5\WP\CustomFieldReader::read( get_post( $page ), [ 'aied_pro_known' ] );
    $check( 'Seen by get_custom_fields' === ( $read['fields'][0]['value'] ?? '' ), 'get_custom_fields (free) reads the value the Pro tool wrote' );
    $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );

    // ---- ACF (only when ACF is active) --------------------------------------------------------
    if ( function_exists( 'acf_import_field_group' ) && function_exists( 'get_field' ) ) {
        acf_import_field_group( [
            'key' => 'group_aied_pro_cf', 'title' => 'AIED Pro cf group', 'active' => true,
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ],
            'fields' => [
                [ 'key' => 'field_aied_pro_text', 'label' => 'T', 'name' => 'aied_pro_acf_text', 'type' => 'text' ],
                [ 'key' => 'field_aied_pro_select', 'label' => 'S', 'name' => 'aied_pro_acf_select', 'type' => 'select', 'choices' => [ 'a' => 'A', 'b' => 'B' ] ],
                [ 'key' => 'field_aied_pro_rep', 'label' => 'R', 'name' => 'aied_pro_acf_rep', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_aied_pro_rep_item', 'label' => 'I', 'name' => 'item', 'type' => 'text' ] ] ],
            ],
        ] );
        $acfGroup = 1;
        $acfPage  = $make( 'AIED Pro cf acf' );
        $acfTricky = 'ACF C:\\path "q" Café';
        [ $r, $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_text', 'value' => $acfTricky ], [ 'key' => 'aied_pro_acf_select', 'value' => 'b' ] ] ] );
        $check( null === $err && true === ( $r['changed'] ?? false ), 'ACF: a text field and a select are written' );
        $check( $acfTricky === $meta( $acfPage, 'aied_pro_acf_text' ) && 'field_aied_pro_text' === $meta( $acfPage, '_aied_pro_acf_text' ), 'ACF: the raw value is exact (backslash, quote, UTF-8) and ACF\'s field-key reference row exists' );
        $check( $acfTricky === get_field( 'aied_pro_acf_text', $acfPage, false ), 'ACF: get_field returns the same value' );
        [ , $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_select', 'value' => 'zzz' ] ] ] );
        $check( null !== $err && str_contains( $err, 'one of' ) && 'b' === $meta( $acfPage, 'aied_pro_acf_select' ), 'ACF: a value that is not a choice is refused' );
        [ , $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_rep', 'value' => 'x' ] ] ] );
        $check( null !== $err && str_contains( $err, 'ACF editor' ), 'ACF: a repeater is refused' );
        $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
        $check( ! metadata_exists( 'post', $acfPage, 'aied_pro_acf_text' ), 'ACF: undo of a created ACF value removes it' );
    } else {
        $skip( 'ACF checks (the free ACF plugin is not active; see the header of this script)' );
    }
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    global $wpdb;
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    if ( $acfGroup ) {
        $group = get_posts( [ 'post_type' => 'acf-field-group', 'name' => 'group_aied_pro_cf', 'post_status' => 'any', 'numberposts' => 1 ] );
        foreach ( $group as $g ) {
            foreach ( get_posts( [ 'post_type' => 'acf-field', 'post_parent' => $g->ID, 'post_status' => 'any', 'numberposts' => -1 ] ) as $f ) {
                wp_delete_post( $f->ID, true );
            }
            wp_delete_post( $g->ID, true );
        }
    }
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
    }
    wp_set_current_user( 1 );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( 'set_custom_fields verified on the real site.' );
```
Adapt, never weaken: confirm the `$undo->undo()` return keys and `$log->recent()` row shape against `BatchUndo` / `BatchLog` and fix the two lines that read them; confirm the ACF group cleanup mirrors `scripts/verify-acf-custom-fields.php`'s `finally` (use its deletion code if it differs); remove the `$u`, `$u2` assignments that are not asserted if PHP complains. If a check cannot run as written, fix the SCRIPT; if it reveals a plugin defect, report it with the failing output.

- [ ] **Step 2: Makefile**

Append `verify-pro-custom-fields` to the `.PHONY` line and add after the `verify-pro-settings` target:

```make
# ---------------------------------------------------------------
# verify-pro-custom-fields — real-site check of set_custom_fields (self-cleaning; dev site only; free + Pro plugins active; ACF checks run when the free ACF plugin is active)
# ---------------------------------------------------------------
verify-pro-custom-fields:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-custom-fields.php --user=1
```

- [ ] **Step 3: Run the gate without ACF**

Run `make verify-pro-custom-fields`. Expected: every line `PASS`, one `SKIP` line for the ACF block, final `Success: set_custom_fields verified on the real site.` Then confirm no throwaway content or batch rows remain (`wp post list --post_type=any --post_status=any --s="AIED Pro cf" --fields=ID,post_title --user=1` is empty; contributor user `aied_pro_cf_contrib` is absent: `wp user list --search=aied_pro_cf* --user=1`). Use `php -d memory_limit=1G /usr/local/bin/wp` inside the wpcli container for these.

- [ ] **Step 4: Run the gate WITH ACF, once**

Install the free ACF plugin on the dev site only as documented in the header of `scripts/verify-acf-custom-fields.php` (`wp plugin install advanced-custom-fields --activate`), run `make verify-pro-custom-fields` again (the ACF block must run and PASS, including the exact backslash/quote value and the `_aied_pro_acf_text` field-key reference row), then remove ACF as that header says (deactivate, delete the folder with `rm -rf`, delete the `acf_*` options; never `wp plugin delete`) and re-run the leftovers check. If ACF cannot be installed (no network), report it and mark the ACF evidence as NOT obtained; do not fake it. If the ACF run reveals that `update_field` stores a different raw value than given (for example a slash problem), STOP and report it as a finding with the output: the unit design assumed `update_field` takes an unslashed value.

- [ ] **Step 5: Commit**

```bash
git add scripts/verify-pro-custom-fields.php Makefile
git commit -m "$(cat <<'EOF'
test: make verify-pro-custom-fields, a real-site gate for set_custom_fields

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: docs and final verification

**Files:**
- Modify: `pro-addon/README.md`, `CLAUDE.md`

- [ ] **Step 1: Pro README**

In `pro-addon/README.md`: update the `src/Tools/` row ("the seventeen tools" -> "the eighteen tools", add `set_custom_fields`); add `src/Fields/` (`FieldValuePolicy`, `AcfFields`, `PostMetaStore`) and `Kinds/PostMetaKind` to the file map where the other directories are described; add a short section "## Custom fields (`set_custom_fields`)" in the style of the presets section: what it does, the key rule (keys the site knows), the refusals, all-or-nothing, `dry_run`, undo and the changed-since rule, MCP + REST only, and the release note "raise `Compat::MIN_FREE` to the first free version that contains `FieldTarget`, `CustomFieldReader` and `CustomFields` when you version the free release (the runtime guard `REQUIRED_CLASSES` already switches the Pro tools off if they are missing)"; and update the sentence about the 30-operation budget to name `set_custom_fields` as the fourth MCP + REST only tool. Keep `ReadmeTest`/`NoPriceLiteralsTest` green.

- [ ] **Step 2: CLAUDE.md (owner has uncommitted edits: use this recipe)**

`CLAUDE.md` holds the OWNER's uncommitted edits; commit ONLY your hunks, no interactive tools:
a. `git show HEAD:CLAUDE.md > <scratchpad>/CLAUDE.head.md` (scratchpad: `/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/35406e12-9e03-4a9e-b84b-94900e6e1ef2/scratchpad`).
b. Make these edits to that scratch copy AND the same edits to the working `CLAUDE.md` with the Edit tool (adapt an anchor if the owner's edits changed it; keep the content identical): in the "Three parts, one repo" Pro add-on bullet and the "Pro add-on 0.5.0 ..." count language, mention the Pro tool `set_custom_fields` once (a short sentence under the Pro add-on description: "`set_custom_fields` (unreleased): sets custom field values on one post; keys the site knows only; all-or-nothing, `dry_run`, undoable; MCP + REST only (`ProOpenApi::MCP_REST_ONLY`); the free plugin stays read-only for fields (`get_custom_fields`)."); add an entry-points row: `` | `make verify-pro-custom-fields` | Real-site check of set_custom_fields (self-cleaning; dev site only; free + Pro active; ACF checks run when the free ACF plugin is active) | ``; add a "Current state" bullet: "**Unreleased (branch feat/pro-set-custom-fields): Pro `set_custom_fields`** (sub-project C of the Projects/custom-fields request). Spec/plan `docs/superpowers/specs|plans/2026-10-09-set-custom-fields*`. Pro version, changelog, zip and `Compat::MIN_FREE` are the owner's release step."
c. `blob=$(git hash-object -w <scratch copy>)`, `git update-index --cacheinfo 100644,$blob,CLAUDE.md`, confirm `git diff --cached --stat` shows ONLY `CLAUDE.md` with your hunks, commit it (`docs: CLAUDE.md for set_custom_fields`, with the trailer).
d. Afterwards `git diff CLAUDE.md` must show only the owner's original uncommitted edits (5 added lines). If the recipe fails, edit the working file only, leave it uncommitted and say so in the report.

- [ ] **Step 3: Final verification**

Run in order and read the real output: `vendor/bin/phpunit` (967), `make test-pro` (all pass), `make verify-pro-custom-fields` (all PASS, one ACF SKIP unless ACF is active), `make verify-custom-fields` (19 PASS), `make verify-project-layouts` (27 PASS), `git status --short` (only the owner's `CLAUDE.md`). Do NOT bump versions, edit changelogs, rebuild zips or run Plugin Check.

- [ ] **Step 4: Commit the README**

```bash
git add pro-addon/README.md
git commit -m "$(cat <<'EOF'
docs: Pro README for set_custom_fields

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review (done against the spec)

- **Spec coverage:** §1 tool (Task 3), §2 validation (Tasks 1 and 3: target, key rules, value rules, ACF types, limits), §3 write + atomicity + dry run (Tasks 2 and 3), §4 undo kind (Task 2), §5 compatibility (Task 3 pins, README note in Task 5; `MIN_FREE` deliberately not changed, spec amended in Task 1), Testing section (unit tests per task, live gate Task 4, final verification Task 5), Out of scope respected (no delete tool, no token, no OpenAPI).
- **Placeholders:** none; the places that depend on shapes only the code knows (`BatchUndo::undo()` return keys, `BatchLog::recent()` row shape, the ACF group cleanup in the live script, the `__wp_meta_fail` shim name) are called out with where to read them and what to do.
- **Type consistency:** `PostMetaStore::read/write/delete`, `PostMetaKind::NAME = 'post_meta'` with `ref` `"{postId}:{key}"` and canonical value `{exists,value}`, `FieldValuePolicy::refusal`, `AcfFields::index/indexWith/refusalFor`, `SetCustomFields::__construct( BatchLog, ?PostMetaStore, ?\Closure, ?array )` and the payload keys are used identically in every task.
