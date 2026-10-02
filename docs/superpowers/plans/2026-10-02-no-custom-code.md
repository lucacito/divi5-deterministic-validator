# No Custom Code Through Layouts (WordPress.org review fix) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Answer the WordPress.org plugin review (ID `R jhmg-ai-editor-for-divi-5/lucaslopvet/1Oct26/T2 2Oct26/4.3`, issue "Attempting to process custom CSS/JS/PHP / Allowing arbitrary script insertion": the layout create/update interfaces accept arbitrary Divi block attributes, including the documented `css.freeForm`/`mainElement` fields): the free plugin must never save custom CSS, JavaScript or PHP through a layout, and must not teach the AI to.

**Architecture:** One new pure class `CodePolicy` finds custom code in layout content (custom-CSS fields, Divi Code modules, script/style/iframe/object/embed markup, `javascript:` URLs, event-handler attributes, PHP tags); a thin `LayoutGate` runs the deterministic validator and then the policy and returns the validator's own `ValidationResult`, so the ten existing `validateContent` call sites become one-line swaps. Code already present in the stored content of the page being edited (the "baseline", e.g. CSS the owner added in the Visual Builder) is preserved and never counted; only NEW code is refused. The validator itself (`src/`, `wp-plugin/validator/`) is NOT changed.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (`./vendor/bin/phpunit`), the `tests/` shims, Python 3 for scripted edits, Docker dev site for the live checks, Plugin Check.

**Spec:** the owner-approved design in conversation 2026-10-02 (scope: CSS + code modules + script/iframe/style/`javascript:`/`on…=`; existing page code is preserved, new code refused). Project rules in `CLAUDE.md` (WordPress.org compliance rule).

## Global Constraints

- Local commits only. NEVER push, upload or send anything (the owner uploads the zip, tests it on Playground, and sends the reply email). Work on a new branch `fix/no-custom-code` cut from `main`; ask the owner before merging.
- Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- The validator (`src/` and `wp-plugin/validator/`) is NOT touched. No Divi schema from memory: the plan removes custom CSS, it adds no new Divi attribute shape.
- Never weaken `tests/WpOrgComplianceTest.php`; new guards go in a new file.
- Plugin version stays **4.4.0** (never uploaded); add a changelog line, do not bump.
- Nothing licence-gated, remote-fetching or custom-code-saving enters `wp-plugin/`.
- Violation codes (exact): `CUSTOM_CSS_NOT_ALLOWED`, `CODE_MODULE_NOT_ALLOWED`, `SCRIPT_CONTENT_NOT_ALLOWED`; code modules refused: `divi/code`, `divi/fullwidth-code`; custom-CSS fields: any string anywhere under a `css` key, and any string under a `freeForm` or `mainElement` key.
- Restore (`HistoryService::restore`) is unchanged: it brings back the person's own earlier content and bypasses the gate by design (documented).
- Never run `wp plugin delete` (it runs uninstall.php against the shared dev DB).

## Review Focus

1. A page the owner built with custom CSS must still be editable by the AI: an edit that leaves that CSS untouched is saved; an edit that adds or changes code is refused. (Task 2)
2. Code hidden by JSON escaping (`<script>` in a block attribute) and code in text between blocks is found; ordinary prose and ordinary HTML (`<p>`, `<a>`, "join online = fun") is NOT a false positive, and no real export in `fixtures/valid/` is flagged except the ones that genuinely contain code. (Task 1)
3. `validate_layout`, `dry_run`, `update_page_layout`, `edit_page_content`, `create_page` and the REST equivalents all agree: the same content gives the same violations everywhere. (Task 2)
4. Nothing the plugin teaches the AI (guides, recipes, tool descriptions, readme) tells it to use custom CSS/JS/PHP, and a test fails if that comes back. (Task 3)
5. Whole-plugin sweep for the same class: no save path, setting or tool writes CSS, JS or PHP; Plugin Check clean; plugin runs with `WP_DEBUG` on without a notice or warning. (Task 4)

---

## File structure

| File | Change |
|---|---|
| `wp-plugin/src/CodePolicy.php`, `wp-plugin/src/LayoutGate.php` | Create |
| `wp-plugin/src/autoload.php` | Require the two new files |
| `wp-plugin/src/McpHandler.php`, `RestController.php`, `DryRun.php` | Swap `Validator::validateContent` for `LayoutGate::check` (baseline = the page's stored content) |
| `wp-plugin/src/StyleGuide.php`, `docs/STYLE.md` | Remove the custom-CSS teaching |
| `wp-plugin/data/section-recipes.json` | Remove `freeForm` CSS from 3 recipes |
| `wp-plugin/readme.txt` | Changelog line + one FAQ answer |
| `tests/CodePolicyTest.php`, `tests/LayoutGateHandlersTest.php`, `tests/NoCustomCodeTest.php` | Create |
| 5 existing tests that load the handlers | Add the two new requires |
| `docs/wporg-review-reply-2-draft.md`, `CLAUDE.md`, `jhmg-ai-editor-for-divi-5.zip` | Reply draft (never sent), docs, rebuilt zip |

---

### Task 1: `CodePolicy` and `LayoutGate`

**Files:**
- Create: `wp-plugin/src/CodePolicy.php`, `wp-plugin/src/LayoutGate.php`, `tests/CodePolicyTest.php`
- Modify: `wp-plugin/src/autoload.php`

**Interfaces:**
- Consumes: the validator's `Divi5Validator\Validator`, `BlockParser`, `Block`, `Violation`, `ValidationResult`.
- Produces:
  - `CodePolicy::violations( string $content, ?string $baseline = null ): list<Violation>` and the constants `E_CUSTOM_CSS`, `E_CODE_MODULE`, `E_SCRIPT`.
  - `LayoutGate::check( string $content, ?string $baseline = null ): ValidationResult` (validator violations first, then policy violations) and `LayoutGate::checkJson( string $json ): ValidationResult` (for a full layout envelope).

- [ ] **Step 1: Write the failing tests**

Create `tests/CodePolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\LayoutGate;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';

/** The plugin never saves custom CSS, JavaScript or PHP through a layout. */
class CodePolicyTest extends TestCase
{
    private const CSS = ',"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}';

    /** A real-shaped, validator-clean layout; %ATTRS% is spliced into the heading block's attributes. */
    private static function layout( string $headingExtra = '', string $between = '' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello"}}},"builderVersion":"5.14.0"' . $headingExtra . '} /-->'
            . $between
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** @return list<string> */
    private static function codes( string $content, ?string $baseline = null ): array
    {
        return array_map( static fn ( $v ) => $v->code(), CodePolicy::violations( $content, $baseline ) );
    }

    public function testACleanLayoutHasNoViolationsAndTheValidatorAcceptsIt(): void
    {
        $this->assertTrue( ( new Validator() )->validateContent( self::layout() )->isValid() );
        $this->assertSame( [], self::codes( self::layout() ) );
    }

    public function testCustomCssIsRefusedEvenThoughTheValidatorAcceptsIt(): void
    {
        $content = self::layout( self::CSS );
        $this->assertTrue( ( new Validator() )->validateContent( $content )->isValid(), 'the structure validator alone would accept it' );
        $v = CodePolicy::violations( $content );
        $this->assertCount( 1, $v );
        $this->assertSame( CodePolicy::E_CUSTOM_CSS, $v[0]->code() );
        $this->assertStringContainsString( 'css.desktop.value.freeForm', $v[0]->path() );
    }

    public function testEveryCssFieldAndBreakpointIsFound(): void
    {
        $css = ',"css":{"desktop":{"value":{"mainElement":"a:b"}},"phone":{"value":{"freeForm":"x"}},"tablet":{"hover":{"after":"y"}}}';
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS, CodePolicy::E_CUSTOM_CSS, CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( $css ) ) );
    }

    public function testAFreeFormOrMainElementFieldIsRefusedWhereverItSits(): void
    {
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( ',"module":{"decoration":{"freeForm":"a{}"}}' ) ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( ',"mainElement":"a{}"' ) ) );
    }

    public function testEmptyCssStringsAreNotCode(): void
    {
        $this->assertSame( [], self::codes( self::layout( ',"css":{"desktop":{"value":{"freeForm":"  "}}}' ) ) );
    }

    public function testDiviCodeModulesAreRefused(): void
    {
        foreach ( [ 'divi/code', 'divi/fullwidth-code' ] as $module ) {
            $content = self::layout( '', '<!-- wp:' . $module . ' {"builderVersion":"5.14.0"} /-->' );
            $this->assertContains( CodePolicy::E_CODE_MODULE, self::codes( $content ), $module );
        }
    }

    public function testScriptMarkupHiddenByJsonEscapingIsFound(): void
    {
        $text = ',"content":{"innerContent":{"desktop":{"value":"<script>alert(1)</script>"}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $text ) ) );
        foreach ( [ '<style>a{}</style>', '<iframe src=x></iframe>', '<object data=x>', '<embed src=x>' ] as $evil ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( ',"content":{"innerContent":{"desktop":{"value":"' . $evil . '"}}}' ) ), $evil );
        }
    }

    public function testAJavascriptUrlIsFound(): void
    {
        $btn = ',"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"javascript:alert(1)"}}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $btn ) ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( str_replace( 'javascript:', 'JaVaScRiPt :', $btn ) ) ) );
    }

    public function testAnEventHandlerAttributeIsFoundInATagAndAsACustomAttributeName(): void
    {
        $img = ',"content":{"innerContent":{"desktop":{"value":"<img src=x onerror=alert(1)>"}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $img ) ) );
        $attr = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":"onclick","value":"x()"}]}}}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $attr ) ) );
    }

    public function testScriptAndPhpBetweenTheBlocksIsFound(): void
    {
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', '<script>alert(1)</script>' ) ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', '<?php echo 1; ?>' ) ) );
    }

    public function testOrdinaryHtmlAndProseAreNotFalsePositives(): void
    {
        $ok = ',"content":{"innerContent":{"desktop":{"value":"<p>Join online = fun, on Monday=best. <a href="/about" class="x">More</a></p>"}}}';
        $this->assertSame( [], self::codes( self::layout( $ok ) ) );
        $attrs = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":"class","value":"hero"},{"id":"a2","name":"title","value":"Hi"}]}}}}}';
        $this->assertSame( [], self::codes( self::layout( $attrs ) ) );
    }

    public function testGarbageContentDoesNotThrow(): void
    {
        $this->assertSame( [], self::codes( 'not a layout at all' ) );
        $this->assertSame( [], self::codes( '' ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( '<script>x</script> broken <!-- wp:divi/section {' ) );
    }

    // ---- baseline: code that is already on the page is preserved, never counted ---------------

    public function testCodeAlreadyOnThePageIsAllowedWhenItIsLeftUntouched(): void
    {
        $page = self::layout( self::CSS );
        $this->assertSame( [], self::codes( $page, $page ) );
        $edited = str_replace( '"Hello"', '"Hello again"', $page );
        $this->assertSame( [], self::codes( $edited, $page ), 'editing other text keeps the existing CSS' );
    }

    public function testChangedOrAdditionalCodeIsRefusedEvenWhenThePageAlreadyHasSome(): void
    {
        $page = self::layout( self::CSS );
        $changed = str_replace( 'color:red', 'color:blue', $page );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $changed, $page ) );
        $twice = self::layout( self::CSS, '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"B"}}},"builderVersion":"5.14.0"' . self::CSS . '} /-->' );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $twice, $page ), 'a second identical copy is new code' );
    }

    public function testNoBaselineMeansNothingIsAllowed(): void
    {
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), null ) );
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), '' ) );
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), self::layout() ) );
    }

    // ---- the gate ------------------------------------------------------------------------------

    public function testTheGateAddsPolicyViolationsToTheValidatorsAndKeepsTheValidatorFirst(): void
    {
        $r = LayoutGate::check( self::layout( self::CSS ) );
        $this->assertFalse( $r->isValid() );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], array_map( static fn ( $v ) => $v->code(), $r->violations() ) );

        $broken = LayoutGate::check( '<!-- wp:divi/section {"css":{"desktop":{"value":{"freeForm":"x"}}}} /-->' );
        $codes  = array_map( static fn ( $v ) => $v->code(), $broken->violations() );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, $codes, 'policy findings are reported even when the structure is also invalid' );
        $this->assertGreaterThan( 1, count( $codes ) );
        $this->assertNotSame( CodePolicy::E_CUSTOM_CSS, $codes[0], 'validator violations come first' );
    }

    public function testACleanLayoutPassesTheGateUnchanged(): void
    {
        $this->assertTrue( LayoutGate::check( self::layout() )->isValid() );
        $this->assertTrue( LayoutGate::check( self::layout( self::CSS ), self::layout( self::CSS ) )->isValid() );
    }

    public function testTheJsonEnvelopeFormIsGatedToo(): void
    {
        $json = (string) json_encode( [ 'post_content' => self::layout( self::CSS ) ] );
        $r    = LayoutGate::checkJson( $json );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, array_map( static fn ( $v ) => $v->code(), $r->violations() ) );
        $this->assertTrue( LayoutGate::checkJson( (string) json_encode( [ 'post_content' => self::layout() ] ) )->isValid() );
        $this->assertFalse( LayoutGate::checkJson( 'not json' )->isValid() );
    }

    // ---- no false positives on real exports ----------------------------------------------------

    public function testRealExportsAreOnlyFlaggedWhenTheyGenuinelyContainCode(): void
    {
        $flagged = [];
        foreach ( glob( __DIR__ . '/../fixtures/valid/*.json' ) ?: [] as $file ) {
            $j = json_decode( (string) file_get_contents( $file ), true );
            if ( ! isset( $j['post_content'] ) || ! is_string( $j['post_content'] ) ) {
                continue;
            }
            if ( [] !== CodePolicy::violations( $j['post_content'] ) ) {
                $flagged[] = basename( $file );
            }
        }
        sort( $flagged );
        $this->assertSame( self::EXPECTED_FLAGGED_FIXTURES, $flagged );
    }

    /** Filled in from the real run in Step 4 (the fixtures that really contain custom CSS or Code modules). */
    private const EXPECTED_FLAGGED_FIXTURES = [];
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/CodePolicyTest.php 2>&1 | tail -6`

Expected: fatal error: `Failed opening required '.../wp-plugin/src/CodePolicy.php'`.

- [ ] **Step 3: Write the code**

Create `wp-plugin/src/CodePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;
use Divi5Validator\Violation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The plugin never saves custom code through a layout: no custom CSS fields, no Divi Code modules, no
 * script/style/iframe/object/embed markup, no `javascript:` URLs, no event-handler attributes, no PHP tags.
 * Pure and deterministic like the validator: it only reports violations, it never changes content.
 *
 * Code that is ALREADY in the stored content of the page being edited (for example custom CSS the owner added
 * in Divi's Visual Builder) is the baseline: it is preserved untouched and never counted. Only new or changed
 * code is refused.
 */
final class CodePolicy
{
    public const E_CUSTOM_CSS  = 'CUSTOM_CSS_NOT_ALLOWED';
    public const E_CODE_MODULE = 'CODE_MODULE_NOT_ALLOWED';
    public const E_SCRIPT      = 'SCRIPT_CONTENT_NOT_ALLOWED';

    private const CODE_MODULES   = [ 'divi/code', 'divi/fullwidth-code' ];
    private const CSS_FIELDS     = [ 'freeForm', 'mainElement' ];
    private const MARKUP         = [
        '/<\s*(?:script|style|iframe|object|embed)\b/i',
        '/<\?(?:php|=)/i',
        '/\bjavascript\s*:/i',
        '/<[a-z][^>]*\son[a-z]+\s*=/i',
    ];
    private const EVENT_ATTR_NAME = '/^on[a-z]+$/i';

    /**
     * @return list<Violation>
     */
    public static function violations( string $content, ?string $baseline = null ): array
    {
        $found = self::findings( $content );
        if ( [] === $found ) {
            return [];
        }

        $allowed = [];
        if ( null !== $baseline && '' !== $baseline ) {
            foreach ( self::findings( $baseline ) as $f ) {
                $allowed[ $f['fingerprint'] ] = ( $allowed[ $f['fingerprint'] ] ?? 0 ) + 1;
            }
        }

        $out = [];
        foreach ( $found as $f ) {
            if ( ( $allowed[ $f['fingerprint'] ] ?? 0 ) > 0 ) {
                --$allowed[ $f['fingerprint'] ];
                continue;
            }
            $out[] = new Violation( $f['code'], $f['message'], $f['path'] );
        }

        return $out;
    }

    /**
     * @return list<array{code:string, fingerprint:string, path:string, message:string}>
     */
    private static function findings( string $content ): array
    {
        $out    = [];
        $parsed = ( new BlockParser() )->parse( $content );
        $root   = $parsed->root();
        if ( null !== $root ) {
            $root->walk( static function ( Block $block, string $path ) use ( &$out ): void {
                if ( in_array( $block->name(), self::CODE_MODULES, true ) ) {
                    $out[] = self::finding(
                        self::E_CODE_MODULE,
                        $block->name() . '|' . md5( (string) json_encode( $block->attrs() ) ),
                        $path,
                        sprintf( 'The %s module (raw HTML or JavaScript) is not allowed: this plugin never saves custom code.', $block->name() )
                    );
                }
                self::scan( $block->attrs(), $path, false, $out );
            } );
        }

        // Text between the block comments (the comments carry the JSON attributes, scanned above).
        $outside = (string) preg_replace( '/<!--.*?-->/s', '', $content );
        foreach ( self::markupHits( $outside ) as $hit ) {
            $out[] = self::finding( self::E_SCRIPT, 'raw|' . md5( $hit ), 'post_content', self::scriptMessage( $hit ) );
        }

        return $out;
    }

    /**
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $out
     */
    private static function scan( mixed $value, string $path, bool $inCss, array &$out ): void
    {
        if ( is_string( $value ) ) {
            if ( $inCss ) {
                if ( '' !== trim( $value ) ) {
                    $out[] = self::finding(
                        self::E_CUSTOM_CSS,
                        'css|' . md5( $path . '|' . $value ),
                        $path,
                        'Custom CSS is not allowed: this plugin never saves custom CSS. Use the module\'s own style settings instead.'
                    );
                }

                return;
            }
            foreach ( self::markupHits( $value ) as $hit ) {
                $out[] = self::finding( self::E_SCRIPT, 'str|' . md5( $hit ), $path, self::scriptMessage( $hit ) );
            }

            return;
        }
        if ( ! is_array( $value ) ) {
            return;
        }

        // A custom HTML attribute entry such as {"name":"onclick","value":"..."}.
        if ( isset( $value['name'] ) && is_string( $value['name'] ) && array_key_exists( 'value', $value ) && 1 === preg_match( self::EVENT_ATTR_NAME, $value['name'] ) ) {
            $out[] = self::finding(
                self::E_SCRIPT,
                'attr|' . strtolower( $value['name'] ) . '|' . md5( (string) json_encode( $value['value'] ) ),
                $path,
                sprintf( 'The event-handler attribute "%s" is not allowed: this plugin never saves custom code.', substr( $value['name'], 0, 40 ) )
            );
        }

        foreach ( $value as $key => $child ) {
            $css = $inCss || 'css' === $key || ( is_string( $key ) && in_array( $key, self::CSS_FIELDS, true ) );
            self::scan( $child, $path . '.' . $key, $css, $out );
        }
    }

    /** @return list<string> */
    private static function markupHits( string $text ): array
    {
        $hits = [];
        foreach ( self::MARKUP as $pattern ) {
            if ( false !== preg_match_all( $pattern, $text, $m ) ) {
                foreach ( $m[0] as $hit ) {
                    $hits[] = substr( trim( $hit ), 0, 40 );
                }
            }
        }

        return $hits;
    }

    private static function scriptMessage( string $hit ): string
    {
        return sprintf( 'Script or embed content (%s) is not allowed: this plugin never saves custom code.', $hit );
    }

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function finding( string $code, string $fingerprint, string $path, string $message ): array
    {
        return [ 'code' => $code, 'fingerprint' => $code . '|' . $fingerprint, 'path' => $path, 'message' => $message ];
    }
}
```

Create `wp-plugin/src/LayoutGate.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\ValidationResult;
use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one gate every layout write and preview goes through: the deterministic validator first, then the
 * no-custom-code policy. Returns the validator's own result type so every existing caller keeps working.
 */
final class LayoutGate
{
    /** @param ?string $baseline the page's current stored content (code already there is preserved), or null for new content */
    public static function check( string $content, ?string $baseline = null ): ValidationResult
    {
        return self::merge( ( new Validator() )->validateContent( $content ), CodePolicy::violations( $content, $baseline ) );
    }

    /** A full layout envelope (JSON with a `post_content` string). */
    public static function checkJson( string $json ): ValidationResult
    {
        $result  = ( new Validator() )->validate( $json );
        $decoded = json_decode( $json, true );
        $content = is_array( $decoded ) && isset( $decoded['post_content'] ) && is_string( $decoded['post_content'] ) ? $decoded['post_content'] : null;

        return null === $content ? $result : self::merge( $result, CodePolicy::violations( $content ) );
    }

    /** @param list<\Divi5Validator\Violation> $policy */
    private static function merge( ValidationResult $result, array $policy ): ValidationResult
    {
        return [] === $policy ? $result : new ValidationResult( array_merge( $result->violations(), $policy ) );
    }
}
```

Wire the loader: in `wp-plugin/src/autoload.php`, add after the line `require_once __DIR__ . '/StyleReferences.php';`:

```php
require_once __DIR__ . '/CodePolicy.php';
require_once __DIR__ . '/LayoutGate.php';
```

- [ ] **Step 4: Run, then pin the real-export list**

Run: `./vendor/bin/phpunit tests/CodePolicyTest.php 2>&1 | tail -12`

Expected: everything passes EXCEPT `testRealExportsAreOnlyFlaggedWhenTheyGenuinelyContainCode`, which fails with an `assertSame` diff showing the real flagged fixture file names. That list must be exactly the real exports that genuinely contain custom CSS or Code modules; verify it by looking at the names (expected members include `themebuilder-footer-224970.json`, `themebuilder-header-224959.json` and `verified-fullwidth-code-column.json`, and any `page-*`/`verified-*` fixture with a Code module). If a fixture appears that does NOT contain custom CSS, a Code module, script markup, a `javascript:` URL or an event attribute, that is a FALSE POSITIVE in `CodePolicy`: fix the policy, do not just pin the name. When the list is right, replace `private const EXPECTED_FLAGGED_FIXTURES = [];` with the real sorted list and its comment, then:

Run: `./vendor/bin/phpunit tests/CodePolicyTest.php 2>&1 | tail -4`

Expected: `OK`.

- [ ] **Step 5: Run the whole suite and commit**

```bash
./vendor/bin/phpunit 2>&1 | tail -3
git add -A
git commit -m "feat: CodePolicy and LayoutGate (no custom CSS/JS/PHP through a layout)

Refuses custom-CSS fields, Divi Code modules, script/style/iframe/object/embed
markup, javascript: URLs, event-handler attributes and PHP tags, found even when
hidden by JSON escaping. Code already on the page being edited is preserved.
Not wired into the handlers yet.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Expected: `OK (...)`, the previous 548 tests plus the new ones.

---

### Task 2: Wire the gate into every write and preview path

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/DryRun.php`; the 5 tests that load the handlers (list in Step 1)
- Create: `tests/LayoutGateHandlersTest.php`

**Interfaces:**
- Consumes: Task 1's `LayoutGate::check( string, ?string )` and `checkJson( string )`.
- Produces: `validate_layout`, `update_page_layout`, `edit_page_content`, `create_page`, `dry_run` previews and their REST equivalents all return the policy violations (`CUSTOM_CSS_NOT_ALLOWED`, `CODE_MODULE_NOT_ALLOWED`, `SCRIPT_CONTENT_NOT_ALLOWED`) in the same `violations` array and refuse to save. For an existing page the baseline is its stored `post_content`.

- [ ] **Step 1: Write the failing handler test**

First give the 5 handler-loading tests the two new requires (they have no autoloader for the plugin classes):

```bash
python3 - <<'PY'
import glob, re
n = 0
for path in glob.glob('tests/*.php'):
    s = open(path, encoding='utf-8').read()
    m = re.search(r"^require_once __DIR__ \. '/\.\./wp-plugin/src/(?:DryRun|McpHandler|RestController)\.php';\n", s, flags=re.M)
    if not m or 'CodePolicy.php' in s:
        continue
    add = "require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';\nrequire_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';\n"
    s = s[:m.start()] + add + s[m.start():]
    open(path, 'w', encoding='utf-8').write(s)
    n += 1
    print('patched', path)
assert n == 5, n
PY
```

Expected: 5 `patched tests/...` lines (`DryRunTest`, `DryRunHandlersTest`, `GlobalStylesLockstepTest`, `StyleAdvisoryHandlersTest`, `RemovedThemeLayoutsToolTest`). Other tests that mention these files only read their source text and need nothing.

Create `tests/LayoutGateHandlersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Every write and preview path refuses new custom code and agrees with the others. */
class LayoutGateHandlersTest extends TestCase
{
    private const CSS = ',"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}';

    private static function layout( string $text = 'Hello', string $extra = '' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $text . '"}}},"builderVersion":"5.14.0"' . $extra . '} /-->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function page( string $content ): void
    {
        $p               = new \WP_Post();
        $p->ID           = 5;
        $p->post_type    = 'page';
        $p->post_status  = 'publish';
        $p->post_title   = 'Page 5';
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][5] = $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']        = [];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_can']          = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->page( self::layout() );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @return array{0:?int,1:array<string,mixed>} JSON-RPC error code (or null), decoded payload */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, array $body, array $params = [ 'id' => 5 ] ): array
    {
        $r = ( new RestController() )->$method( new \WP_REST_Request( $body, $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private static function codes( array $payload ): array
    {
        return array_map( static fn ( $v ) => (string) $v['code'], (array) ( $payload['violations'] ?? [] ) );
    }

    private function content(): string
    {
        return $GLOBALS['__wp_posts'][5]->post_content;
    }

    private function historyRows(): int
    {
        return count( HistoryStore::summaries( 5 ) );
    }

    public function testTheStructureValidatorAloneWouldAcceptTheCss(): void
    {
        $this->assertTrue( ( new Validator() )->validateContent( self::layout( 'Hello', self::CSS ) )->isValid(), 'proves the refusals below come from the code policy' );
    }

    public function testValidateLayoutReportsTheViolationOverMcpAndRest(): void
    {
        [ , $m ] = $this->mcp( 'validate_layout', [ 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'validate', [ 'post_content' => self::layout( 'Hello', self::CSS ) ], [] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );

        [ , $env ] = $this->rest( 'validate', [ 'post_content' => self::layout( 'Hello', self::CSS ), 'extra' => 'x' ], [] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $env ), 'the same content gives the same violations' );
    }

    public function testUpdateRefusesNewCssOverMcpAndRestAndSavesNothing(): void
    {
        $before = $this->content();
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'update_page', [ 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );

        $this->assertSame( $before, $this->content() );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows() );
    }

    public function testEditContentRefusesToInsertCss(): void
    {
        $find    = '"value":"Hello"}}},"builderVersion":"5.14.0"} /-->';
        $replace = '"value":"Hello"}}},"builderVersion":"5.14.0"' . self::CSS . '} /-->';
        [ , $m ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => $find, 'replace' => $replace ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'edit_page', [ 'find' => $find, 'replace' => $replace ] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testCreatePageRefusesCodeAndDoesNotCreate(): void
    {
        [ , $m ] = $this->mcp( 'create_page', [ 'title' => 'New', 'post_content' => self::layout( 'Hello', self::CSS ) ] );
        $this->assertFalse( $m['created'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ $status, $r ] = $this->rest( 'create_page', [ 'title' => 'New', 'post_content' => self::layout( 'Hello', self::CSS ) ], [] );
        $this->assertSame( 422, $status );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
    }

    public function testDryRunPreviewReportsTheViolationAndNeverWrites(): void
    {
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello', self::CSS ), 'dry_run' => true ] );
        $this->assertTrue( $m['dry_run'] );
        $this->assertFalse( $m['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );

        [ , $r ] = $this->rest( 'update_page', [ 'post_content' => self::layout( 'Hello', self::CSS ), 'dry_run' => true ] );
        $this->assertFalse( $r['valid'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $r ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testAScriptInTheTextIsRefused(): void
    {
        $text = '<script>alert(1)</script>';
        $bad  = self::layout( $text );
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $bad ] );
        $this->assertSame( [ 'SCRIPT_CONTENT_NOT_ALLOWED' ], self::codes( $m ) );
    }

    // ---- a page that already has custom CSS stays editable ------------------------------------

    public function testAPageThatAlreadyHasCssCanBeEditedWhenTheCssIsLeftUntouched(): void
    {
        $this->page( self::layout( 'Hello', self::CSS ) );
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Hello again', self::CSS ) ] );
        $this->assertTrue( $m['saved'], 'the owner\'s existing CSS is preserved, not counted' );
        $this->assertStringContainsString( 'Hello again', $this->content() );
        $this->assertStringContainsString( 'selector{color:red}', $this->content() );
    }

    public function testAnEditOfSuchAPageCannotChangeTheCss(): void
    {
        $this->page( self::layout( 'Hello', self::CSS ) );
        $changed = str_replace( 'color:red', 'color:blue', self::layout( 'Hello', self::CSS ) );
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $changed ] );
        $this->assertFalse( $m['saved'] );
        $this->assertSame( [ 'CUSTOM_CSS_NOT_ALLOWED' ], self::codes( $m ) );
        $this->assertStringContainsString( 'color:red', $this->content() );
    }

    public function testACleanSaveStillWorksAndIsSnapshotted(): void
    {
        [ , $m ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::layout( 'Brand new text' ) ] );
        $this->assertTrue( $m['saved'] );
        $this->assertStringContainsString( 'Brand new text', $this->content() );
        $this->assertSame( 1, $this->historyRows() );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/LayoutGateHandlersTest.php 2>&1 | tail -14`

Expected: FAIL: the refusal tests report `saved: true`/valid because the handlers still call the validator alone (`testTheStructureValidatorAloneWouldAcceptTheCss` and `testACleanSaveStillWorksAndIsSnapshotted` pass). If a test errors on a missing class or shim instead, add the one missing `require_once` copied from the top of `tests/DryRunHandlersTest.php`.

- [ ] **Step 3: Swap the ten call sites**

```bash
python3 - <<'PY'
def edit(path, pairs):
    s = open(path, encoding='utf-8').read()
    for old, new in pairs:
        assert s.count(old) == 1, (path, old[:80], s.count(old))
        s = s.replace(old, new)
    open(path, 'w', encoding='utf-8').write(s)

edit('wp-plugin/src/McpHandler.php', [
    # validate_layout: new content, no baseline
    ("        $result = (new Validator())->validateContent($content);\n        UsageTracker::log('validate'",
     "        $result = LayoutGate::check($content);\n        UsageTracker::log('validate'"),
    # update_page_layout: baseline = the page's stored content
    ("        $result = (new Validator())->validateContent($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('update_layout'",
     "        $result = LayoutGate::check($content, (string) $post->post_content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('update_layout'"),
    ("        $result = (new Validator())->validateContent($edit['content']);\n        if (!$result->isValid()) {\n            UsageTracker::log('edit_content'",
     "        $result = LayoutGate::check($edit['content'], (string) $post->post_content);\n        if (!$result->isValid()) {\n            UsageTracker::log('edit_content'"),
    ("        $result = (new Validator())->validateContent($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('create_page'",
     "        $result = LayoutGate::check($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('create_page'"),
])

edit('wp-plugin/src/RestController.php', [
    ("        $result = (new Validator())->validateContent($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('update_page'",
     "        $result = LayoutGate::check($content, (string) $post->post_content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('update_page'"),
    ("        $result = (new Validator())->validateContent($edit['content']);\n        if (!$result->isValid()) {\n            UsageTracker::log('edit_page'",
     "        $result = LayoutGate::check($edit['content'], (string) $post->post_content);\n        if (!$result->isValid()) {\n            UsageTracker::log('edit_page'"),
    ("        $result = (new Validator())->validateContent($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('create_page'",
     "        $result = LayoutGate::check($content);\n\n        if (!$result->isValid()) {\n            UsageTracker::log('create_page'"),
    ("            $result = (new Validator())->validateContent($content);\n        } else {\n            $result = (new Validator())->validate((string) json_encode($body));\n",
     "            $result = LayoutGate::check($content);\n        } else {\n            $result = LayoutGate::checkJson((string) json_encode($body));\n"),
])

edit('wp-plugin/src/DryRun.php', [
    ("        $verdict = ( new Validator() )->validateContent( $after );",
     "        $verdict = LayoutGate::check( $after, $before );"),
])
PY
php -l wp-plugin/src/McpHandler.php && php -l wp-plugin/src/RestController.php && php -l wp-plugin/src/DryRun.php
grep -n "new Validator" wp-plugin/src/*.php
```

Expected: three `No syntax errors detected`; the final grep prints only the `HistoryService.php` restore line (restore keeps the validator alone by design) and `LayoutGate.php`. If a `use Divi5Validator\Validator;` import is now unused in a handler file, remove only that import line.

- [ ] **Step 4: Run the handler test and the whole suite**

Run: `./vendor/bin/phpunit tests/LayoutGateHandlersTest.php 2>&1 | tail -6`

Expected: `OK`.

Run: `./vendor/bin/phpunit 2>&1 | tail -4`

Expected: `OK (...)`. If an existing test fails because a fixture layout it saves now contains custom code, that test was relying on the gate accepting code: read it and fix the TEST data (not the policy) only if the content is a synthetic layout; if it is a real export, report it.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: every layout write and preview goes through LayoutGate

validate_layout, update_page_layout, edit_page_content, create_page, dry_run and the
REST equivalents now refuse new custom CSS/JS/PHP with exact violation codes. An
existing page's own code is preserved (baseline = its stored content). Restore is
unchanged by design.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Stop teaching custom code; clean the recipes; readme

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `docs/STYLE.md`, `wp-plugin/data/section-recipes.json`, `wp-plugin/readme.txt`
- Create: `tests/NoCustomCodeTest.php`

**Interfaces:**
- Consumes: Task 1's `CodePolicy`.
- Produces: guides, recipes and docs with no custom-CSS teaching; every bundled recipe policy-clean; a guard test.

- [ ] **Step 1: Write the failing guard test**

Create `tests/NoCustomCodeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';

/**
 * WordPress.org review (2026-10): the plugin must not let users or an AI save custom CSS, JavaScript or PHP.
 * Nothing the plugin teaches the AI, and nothing it ships, may use or recommend custom code.
 */
class NoCustomCodeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    /** Fields and phrases that teach or use custom CSS in a layout (case-insensitive). */
    private const TEACHING = [ 'freeForm', 'mainElement', 'css.{bp}', 'css.desktop', 'backdrop-filter', '@keyframes' ];

    /** @return array<string,array{string}> */
    public static function shippedText(): array
    {
        $out = [];
        foreach ( glob( self::ROOT . '/src/*.php' ) ?: [] as $f ) {
            if ( in_array( basename( $f ), [ 'CodePolicy.php' ], true ) ) {
                continue; // the policy has to name the fields it refuses
            }
            $out[ 'src/' . basename( $f ) ] = [ 'src/' . basename( $f ) ];
        }
        foreach ( [ 'readme.txt', 'data/section-recipes.json', '../docs/STYLE.md' ] as $f ) {
            $out[ $f ] = [ $f ];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'shippedText' )]
    public function testNothingTeachesOrShipsCustomCss( string $rel ): void
    {
        $text = (string) file_get_contents( self::ROOT . '/' . $rel );
        foreach ( self::TEACHING as $needle ) {
            $this->assertFalse( stripos( $text, $needle ) !== false, "{$rel} mentions '{$needle}' (custom CSS must not be taught or shipped)" );
        }
    }

    public function testEveryBundledRecipeIsFreeOfCustomCode(): void
    {
        $recipes = json_decode( (string) file_get_contents( self::ROOT . '/data/section-recipes.json' ), true );
        $this->assertIsArray( $recipes );
        $this->assertNotEmpty( $recipes );
        foreach ( $recipes as $recipe ) {
            $this->assertSame( [], array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), CodePolicy::violations( (string) $recipe['markup'] ) ), (string) $recipe['name'] );
        }
    }

    public function testTheGuideTellsTheAiNotToWriteCustomCode(): void
    {
        $guide = (string) file_get_contents( self::ROOT . '/src/StyleGuide.php' );
        $this->assertStringContainsString( 'Never write CSS, JavaScript or PHP code', $guide );
        $this->assertStringContainsString( 'CUSTOM_CSS_NOT_ALLOWED', $guide );
    }

    public function testNoWritePathTouchesCssOrPhpFilesOrOptions(): void
    {
        $banned = '/\b(?:wp_update_custom_css_post|wp_get_custom_css_post|file_put_contents|fwrite|fputs|eval|assert|create_function|shell_exec|passthru|proc_open|popen)\s*\(|[\'"]custom_css[\'"]/i';
        foreach ( glob( self::ROOT . '/src/*.php' ) ?: [] as $f ) {
            $this->assertSame( 0, preg_match( $banned, (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents( $f ) ) ), basename( $f ) );
        }
    }

    public function testThePlantedViolationsAreCaughtByTheScans(): void
    {
        $this->assertTrue( stripos( 'css.desktop.value.freeForm = "x"', 'freeForm' ) !== false );
        $this->assertSame( 1, preg_match( '/\bfile_put_contents\s*\(/i', 'file_put_contents($a, $b);' ) );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/phpunit tests/NoCustomCodeTest.php 2>&1 | grep -E "^[0-9]+\) |still|mentions|^Tests:|OK" | head -12`

Expected: failures for `src/StyleGuide.php` (teaches `css.{bp}`/`freeForm`/`mainElement`/`backdrop-filter`), `data/section-recipes.json` (freeForm), `../docs/STYLE.md` (mainElement/backdrop-filter), the recipe policy test and the guide sentence test. Other cases pass. If another src file fails a TEACHING needle, read it: remove the custom-CSS mention if it is guidance; if it is an unrelated use of the word (for example a legitimate `@keyframes` is not expected anywhere), stop and report.

- [ ] **Step 3: Remove the teaching, clean the recipes**

```bash
python3 - <<'PY'
import json, re

def edit(path, fn):
    s = open(path, encoding='utf-8').read()
    s = fn(s)
    open(path, 'w', encoding='utf-8').write(s)

# --- StyleGuide.php: glass paragraph + the "Per-module styling" section ------------------------
def guide(s):
    old = s[s.index("True frosted-glass *backdrop-blur*"):s.index("glass look without any CSS.") + len("glass look without any CSS.")]
    assert 'backdrop-filter' in old and len(old) < 600
    s = s.replace(old, "True frosted-glass *backdrop-blur* (blurring what's behind the card) is NOT a\nstandard Divi attribute and needs CSS code, which this plugin never saves. Use the\nsemi-transparent + border recipe above for the glass look.")
    i = s.index("## Per-module styling (Divi's `css` attribute)")
    j = s.index("## Full-viewport height & background blend", i)
    section = s[i:j]
    assert 'freeForm' in section and len(section) < 2500
    new = """## No custom code
Never write CSS, JavaScript or PHP code, and never use a Divi Code module. This plugin refuses them:
a layout with a CSS field (anything under a module's `css` attribute), a `divi/code` or
`divi/fullwidth-code` module, `<script>`, `<style>`, `<iframe>`, `<object>` or `<embed>` markup, a
`javascript:` link, an `on…=` event attribute or a PHP tag is rejected with `CUSTOM_CSS_NOT_ALLOWED`,
`CODE_MODULE_NOT_ALLOWED` or `SCRIPT_CONTENT_NOT_ALLOWED` and the exact path. Style with the module's own
decoration attributes above instead. Code already on a page (the owner's own) is left untouched when
you edit that page; you cannot add or change it. If a design needs a reusable site-wide class, tell the
owner what to add in Appearance → Customize → Additional CSS themselves.

"""
    return s[:i] + new + s[j:]
edit('wp-plugin/src/StyleGuide.php', guide)

# --- docs/STYLE.md ------------------------------------------------------------------------------
def style_md(s):
    i = s.index("True frosted-glass **backdrop-blur**")
    j = s.index("## Aesthetic variety", i)
    return s[:i] + ("True frosted-glass **backdrop-blur** is NOT a native Divi attribute and appears in no export. It needs\n"
                    "CSS code, which the plugin never saves, so use the translucent surface + hairline border above.\n\n") + s[j:]
edit('docs/STYLE.md', style_md)

# --- recipes: remove the top-level "css" attribute from every block of every recipe --------------
STR = r'"(?:[^"\\]|\\.)*"'

def strip_css(attrs: str) -> str:
    """Remove the top-level "css":{...} member (string-aware) from one block-attributes JSON text."""
    m = re.search(r',?"css":\{', attrs)
    while m:
        start = m.start()
        i = m.end() - 1                      # at the opening brace
        depth = 0
        while True:
            c = attrs[i]
            if c == '"':
                i = re.compile(STR).match(attrs, i).end() - 1
            elif c == '{':
                depth += 1
            elif c == '}':
                depth -= 1
                if depth == 0:
                    break
            i += 1
        end = i + 1
        if attrs[start] != ',' and end < len(attrs) and attrs[end] == ',':
            end += 1                         # css was the first member: drop the comma after it
        attrs = attrs[:start] + attrs[end:]
        m = re.search(r',?"css":\{', attrs)
    return attrs

path = 'wp-plugin/data/section-recipes.json'
raw = open(path, encoding='utf-8').read()
recipes = json.loads(raw)
changed = []
for r in recipes:
    def fix(m):
        return m.group(1) + strip_css(m.group(2)) + m.group(3)
    new = re.sub(r'(<!-- wp:divi/[a-z0-9-]+ )(\{.*?\})( ?/?-->)', fix, r['markup'], flags=re.S)
    if new != r['markup']:
        changed.append(r['name'])
        r['markup'] = new
assert sorted(changed) == ['blog-feed', 'icon-values', 'stats-counter'], changed
open(path, 'w', encoding='utf-8').write(json.dumps(recipes, indent=4, ensure_ascii=False) + '\n')
print('recipes cleaned:', changed)
PY
git diff --stat | tail -5
php -l wp-plugin/src/StyleGuide.php
python3 - <<'PY'
# StyleGuideTest::testCssAttributeIsNotClaimedToBeScoped asserted the text of the section that was just removed
import re
p = 'tests/StyleGuideTest.php'
s = open(p, encoding='utf-8').read()
m = re.search(r"\n    public function testCssAttributeIsNotClaimedToBeScoped\(\): void\n    \{.*?\n    \}\n", s, flags=re.S)
assert m
open(p, 'w', encoding='utf-8').write(s[:m.start()] + s[m.end():])
PY
```

Expected: `recipes cleaned: ['blog-feed', 'icon-values', 'stats-counter']` (any order); the diff touches `StyleGuide.php`, `docs/STYLE.md` and `section-recipes.json`. The JSON file may be re-indented by `json.dumps`: check `git diff --stat wp-plugin/data/section-recipes.json` is limited to the three recipes' `markup` lines plus at most whitespace; if the whole file reflowed, restore it (`git checkout wp-plugin/data/section-recipes.json`) and instead apply the same `strip_css` to the three markup strings with a text-level edit that preserves the file's original formatting (replace each recipe's `"markup": "..."` value only), then re-run.

- [ ] **Step 4: Readme**

Add to `wp-plugin/readme.txt`:
1. Under `= 4.4.0 =` in the changelog, as a new first bullet: `* Safer: your AI assistant can no longer save custom CSS, JavaScript or PHP through a page layout. The checker refuses custom-CSS fields, Divi Code modules, script, style and iframe markup, javascript: links and event attributes, and says exactly where. Code already on a page, for example custom CSS you added in the Visual Builder, stays as it is.`
2. In the FAQ, directly after the "Does the AI get administrator access to my site?" answer, a new question and answer:

```
= Can the AI add custom CSS, JavaScript or PHP to my site? =

No. The checker refuses any layout that contains custom CSS, a Divi Code module, script, style or iframe markup, a javascript: link or an event attribute, and the plugin has no tool that saves CSS, JavaScript or PHP or changes site settings. Anything already on a page, for example custom CSS you added in the Visual Builder, is left exactly as you made it.
```

```bash
python3 - <<'PY'
p = 'wp-plugin/readme.txt'
s = open(p, encoding='utf-8').read()
h = "= 4.4.0 =\n"
assert s.count(h) >= 1
i = s.index(h, s.index("== Changelog =="))
s = s[:i + len(h)] + "* Safer: your AI assistant can no longer save custom CSS, JavaScript or PHP through a page layout. The checker refuses custom-CSS fields, Divi Code modules, script, style and iframe markup, javascript: links and event attributes, and says exactly where. Code already on a page, for example custom CSS you added in the Visual Builder, stays as it is.\n" + s[i + len(h):]
faq = "= Does the AI get administrator access to my site? =\n"
assert s.count(faq) == 1
j = s.index(faq)
k = s.index("\n= ", j + len(faq))
s = s[:k + 1] + "\n= Can the AI add custom CSS, JavaScript or PHP to my site? =\n\nNo. The checker refuses any layout that contains custom CSS, a Divi Code module, script, style or iframe markup, a javascript: link or an event attribute, and the plugin has no tool that saves CSS, JavaScript or PHP or changes site settings. Anything already on a page, for example custom CSS you added in the Visual Builder, is left exactly as you made it.\n" + s[k + 1:]
open(p, 'w', encoding='utf-8').write(s)
PY
grep -n "Can the AI add custom CSS" -B1 -A3 wp-plugin/readme.txt | cut -c1-160
```

- [ ] **Step 5: Run the guard and the whole suite**

Run: `./vendor/bin/phpunit tests/NoCustomCodeTest.php 2>&1 | tail -4`

Expected: `OK`.

Run: `./vendor/bin/phpunit 2>&1 | tail -4`

Expected: `OK (...)`. Existing tests that read the readme or guides may pin wording (for example the compliance test's readme claims or `SectionRecipesTest` id/uuid checks); the standing compliance rule that bans the phrase "Custom CSS" in guides is why the new guide wording says "CSS code": read any failure; fix only by adjusting a pin that asserted the REMOVED text, never by weakening a rule. Report each such adjustment.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "fix: stop teaching and shipping custom CSS (guides, recipes, readme)

StyleGuide and docs/STYLE.md no longer teach css.freeForm/mainElement or backdrop-filter and now
say plainly that custom code is refused; three recipes (stats-counter, blog-feed, icon-values) lose
their freeForm CSS; readme changelog + FAQ. NoCustomCodeTest guards all of it.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Sweep for the same class, test on a debug site, Plugin Check, zip, reply draft

**Files:**
- Modify: `jhmg-ai-editor-for-divi-5.zip` (rebuilt), `CLAUDE.md`
- Create: `docs/wporg-review-reply-2-draft.md`

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: evidence the whole plugin is clean of the reported class, a rebuilt 4.4.0 zip, and a reply draft for the owner (never sent).

- [ ] **Step 1: Sweep the whole free plugin for the same class of issue (read-only)**

```bash
echo "--- code that could save or run custom code"
grep -rIn -i -E "wp_update_custom_css|custom_css|file_put_contents|fwrite|eval\(|create_function|shell_exec|passthru|proc_open|popen|\bexec\(|\bsystem\(|unserialize|include\s*\(\s*\\$|require(_once)?\s*\(\s*\\$" wp-plugin --exclude-dir=validator --exclude-dir=assets | grep -v "^wp-plugin/src/CodePolicy.php" || echo "none"
echo "--- places that write post content or options"
grep -rIn -E "wp_update_post|wp_insert_post|update_option\(|add_option\(|update_post_meta\(|set_theme_mod\(|wp_set_" wp-plugin/src | cut -c1-150
echo "--- tool descriptions / guides mentioning css, script, code"
grep -rIn -i -E "\bcss\b|javascript|\bscript\b|PHP (code|snippet)|run PHP" wp-plugin/src/McpHandler.php wp-plugin/src/OpenApiSpec.php wp-plugin/src/LandingGuide.php wp-plugin/src/SiteGuide.php wp-plugin/src/ImageGuide.php wp-plugin/src/StyleGuide.php | cut -c1-170
```

Record the three outputs in the commit message body of Step 6. Expected: the first section prints only the `StyleGuide.php` line that quotes the refusal code `CUSTOM_CSS_NOT_ALLOWED` (the pattern matches `custom_css` case-insensitively), nothing else; the second lists only `HistoryService.php` (writes page content after the gate, `wp_slash`ed), `McpHandler.php`/`RestController.php` (`wp_insert_post` for `create_page` after the gate), `ApiKey.php`/`HistoryTable.php`/`UsageTracker.php` (the plugin's own options and API-key user); the third lists only the "never write custom code" guidance and ordinary prose. Anything else that can write CSS, JS or PHP, or an instruction to the AI to do so, is a finding: STOP and report it (do not guess a fix).

- [ ] **Step 2: Clean-install style test with `WP_DEBUG` on (dev site, no writes beyond a throwaway draft page)**

```bash
WPC="docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp"
$WPC --exec='error_reporting( E_ALL );' eval '
wp_set_current_user( 1 );
$call = static function ( string $method, string $route, array $body = [] ) {
    $r = new WP_REST_Request( $method, "/ai-editor-divi5/v1" . $route );
    if ( $body ) { $r->set_header( "content-type", "application/json" ); $r->set_body( wp_json_encode( $body ) ); }
    $res = rest_do_request( $r );
    return [ $res->get_status(), $res->get_data() ];
};
$layout = file_exists( "/fixtures/valid/page-7-home.json" ) ? json_decode( file_get_contents( "/fixtures/valid/page-7-home.json" ), true )["post_content"] : "";
echo "WP_DEBUG=", WP_DEBUG ? "on" : "off", "\n";
[ $s, $d ] = $call( "POST", "/validate", [ "post_content" => $layout ] );
echo "validate real home layout: ", $s, " valid=", var_export( $d["valid"] ?? null, true ), "\n";
$css = str_replace( "\"builderVersion\":\"5.8.0\"", "\"builderVersion\":\"5.8.0\",\"css\":{\"desktop\":{\"value\":{\"freeForm\":\"selector{color:red}\"}}}", $layout );
[ $s, $d ] = $call( "POST", "/validate", [ "post_content" => $css ] );
echo "validate with css: ", $s, " valid=", var_export( $d["valid"] ?? null, true ), " codes=", implode( ",", array_column( $d["violations"] ?? [], "code" ) ), "\n";
[ $s, $d ] = $call( "POST", "/pages", [ "title" => "gate-check (delete me)", "post_content" => $css ] );
echo "create_page with css: ", $s, " created=", var_export( $d["created"] ?? null, true ), "\n";
echo "done\n";' 2>&1 | tail -12
```

Expected: `WP_DEBUG=on` (the dev site's `wp-config.php` already sets it; if it prints `off`, add `define( "WP_DEBUG", true );` inside the `--exec` string guarded by `if ( ! defined( "WP_DEBUG" ) )`); the real home layout validates (`valid=true`); the css variant is `valid=false` with `CUSTOM_CSS_NOT_ALLOWED`; `create_page with css` is refused (`created=false`, status 422); and NO `Notice`, `Warning` or `Deprecated` lines anywhere in the output. If `/fixtures/valid/page-7-home.json` is missing in the container, use `ls /fixtures/valid` and pick another `page-*.json`; if the css replacement string does not match that fixture's `builderVersion`, adapt the replace to one `"builderVersion":"..."` occurrence that exists. Nothing is saved (the create is refused); if a draft page was created by mistake, delete it with `wp post delete <id> --force` and report it.

- [ ] **Step 3: Whole suite, then Plugin Check on the dev copy and the extracted zip, and rebuild the zip**

```bash
./vendor/bin/phpunit 2>&1 | tail -3
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5
bash scripts/build-plugin-zip.sh
rm -rf /tmp/aied-zipcheck && mkdir /tmp/aied-zipcheck && unzip -q jhmg-ai-editor-for-divi-5.zip -d /tmp/aied-zipcheck
docker compose cp /tmp/aied-zipcheck/jhmg-ai-editor-for-divi-5 wpcli:/var/www/html/wp-content/plugins/aied-zipcheck
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check aied-zipcheck --slug=jhmg-ai-editor-for-divi-5
docker compose exec -T -u root wpcli rm -rf /var/www/html/wp-content/plugins/aied-zipcheck
rm -rf /tmp/aied-zipcheck
unzip -l jhmg-ai-editor-for-divi-5.zip | grep -c -E "CodePolicy|LayoutGate"
```

Expected: `OK (...)`; `Success: Checks complete. No errors found.` twice; the build prints `Packaging jhmg-ai-editor-for-divi-5 version 4.4.0`; the last command prints `2`. NEVER run `wp plugin delete`.

- [ ] **Step 4: Write the reply draft (never sent)**

Create `docs/wporg-review-reply-2-draft.md`:

```markdown
# Reply to the WordPress.org review — DRAFT (the owner sends it; nothing here was sent)

Review ID: `R jhmg-ai-editor-for-divi-5/lucaslopvet/1Oct26/T2 2Oct26/4.3`

Before sending: upload the new `jhmg-ai-editor-for-divi-5.zip` (version 4.4.0) at "Add your plugin", and test it on Playground / a clean install with WP_DEBUG on.

---

Hello,

Thank you for the review. You are right: the layout tools accepted arbitrary Divi block attributes, including the `css.freeForm` / `css.mainElement` fields, and our own style guide documented them. That is fixed in the version I just uploaded (4.4.0).

What changed:

1. One extra check now runs after our structure validator on every path that saves or previews a layout (`update_page_layout`, `edit_page_content`, `create_page`, `validate_layout`, `dry_run` and the matching REST routes). It refuses: any custom-CSS field in a module's `css` attribute, the Divi Code modules (`divi/code`, `divi/fullwidth-code`), `<script>`, `<style>`, `<iframe>`, `<object>` and `<embed>` markup, `javascript:` URLs, event-handler attributes (`onclick`, `onerror`, …) and PHP tags. Code hidden by JSON escaping is found too. A refusal names the exact path, so the AI assistant corrects itself.
2. The AI guides no longer teach custom CSS, and the three bundled section recipes that carried `freeForm` CSS were rewritten without it.
3. The one exception is code already stored on a page, for example CSS the site owner added in the Visual Builder: an edit to that page keeps it untouched, and the AI still cannot add or change code. New pages and validation allow none.
4. The plugin has no tool that saves CSS, JavaScript or PHP, changes site settings or runs code (removed in 4.0.0). A test in our suite fails if a guide, recipe or write path ever allows custom code again.

We also went through the rest of the plugin for the same kind of issue (every value that can be saved: layout attributes, find-and-replace edits, new pages, history restore, which only brings back the owner's earlier content) and found no other path that saves custom CSS, JavaScript or PHP.

Tested: Plugin Check is clean on the code and on the zip, and the plugin runs with WP_DEBUG on without a notice or warning.

Thank you,
Lucas
```

- [ ] **Step 5: Update CLAUDE.md**

```bash
python3 - <<'PY'
p = 'CLAUDE.md'
s = open(p, encoding='utf-8').read()
anchor = "- **4.4.0 = global styles release PLUS the free re-cut**"
assert s.count(anchor) == 1
s = s.replace(anchor, """- **WordPress.org review answered 2026-10-02** (issue: custom CSS/JS/PHP through layouts, `css.freeForm`/`mainElement`):
  fixed on branch `fix/no-custom-code` (`CodePolicy` + `LayoutGate`, every write/preview path, guides/recipes cleaned,
  `NoCustomCodeTest`); the owner uploads the 4.4.0 zip, tests on Playground and sends `docs/wporg-review-reply-2-draft.md`.
  Rule: the free plugin never saves custom CSS, JavaScript or PHP, and any future layout write path (including every Pro
  write tool) must go through `LayoutGate::check()`.
""" + anchor)
open(p, 'w', encoding='utf-8').write(s)
PY
grep -n "WordPress.org review answered" CLAUDE.md | head -2
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "release: 4.4.0 answers the WordPress.org custom-code review (sweep, reply draft, rebuilt zip)

Sweep of the whole plugin for the same class found no other path that saves or runs CSS/JS/PHP
(outputs of the three greps in the plan's Step 1). WP_DEBUG-on check clean; Plugin Check clean on
the dev copy and the extracted zip; zip rebuilt. Reply draft is NOT sent.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-Review (run against the approved design)

| Requirement | Task |
|---|---|
| Refuse custom-CSS fields (`css` strings, `freeForm`, `mainElement`) | 1 |
| Refuse Divi Code modules | 1 |
| Refuse script/style/iframe/object/embed markup, `javascript:`, event handlers, PHP tags; found through JSON escaping and between blocks | 1 |
| Existing page code preserved, only new code refused; new pages and validation allow none | 1 (baseline), 2 (handlers) |
| One shared gate; validator untouched; same violation shape | 1 (`LayoutGate`), 2 (10 call sites) |
| `validate_layout`, dry_run, update, edit, create, REST all agree | 2 |
| Stop teaching `css.freeForm`/`mainElement`; recipes cleaned; docs | 3 |
| Readme + changelog; guard test; no write path for CSS/PHP | 3 |
| Whole-plugin sweep, WP_DEBUG-on check, Plugin Check x2, zip, reply draft, CLAUDE.md | 4 |
| Restore bypass kept and documented | Global Constraints |

**Placeholder scan:** none; the one value filled in from a real run (`EXPECTED_FLAGGED_FIXTURES`, Task 1 Step 4) has an explicit procedure and a false-positive check.

**Type consistency:** `CodePolicy::violations( string, ?string ): list<Violation>` and constants (Task 1) are what `LayoutGate` and `NoCustomCodeTest` use; `LayoutGate::check( string, ?string ): ValidationResult` / `checkJson( string )` (Task 1) are the names the Task 2 python edits and `LayoutGateHandlersTest` use; the codes `CUSTOM_CSS_NOT_ALLOWED`, `CODE_MODULE_NOT_ALLOWED`, `SCRIPT_CONTENT_NOT_ALLOWED` agree between the policy, the tests, the StyleGuide text and the reply draft.

**Honest limit (also in the handoff):** the Plugins Team decides approval. This plan removes the cited issue and its class, proves it with tests, and runs Plugin Check and a WP_DEBUG check; the owner still tests on Playground and replies.

**Replayed:** Tasks 1 to 3 (every file block and script, including the red runs) were replayed in a scratch git worktree starting from `main`: suite after each task 569, 579 and 617 tests (main was 548), all green; `CodePolicy` passes its 19 tests on the first run and flags exactly four real exports (`page-17-newpage`, the two Theme Builder exports, `verified-fullwidth-code-column`), each a genuine hit. The replay found and fixed: only 5 (not 8) tests load the handler classes; the handler test needs `WpInsertPostShims.php`; the REST `validate` answers 422 for an invalid layout; two existing pins asserted the removed guide text (`testCssAttributeIsNotClaimedToBeScoped` is deleted) and the standing compliance rule bans the phrase "Custom CSS" in guides (so the new guide text says "CSS code"); the dev site already runs with `WP_DEBUG` on (redefining it would itself emit a warning). Not replayed (they need Docker against the branch): Task 4 Steps 2 and 3 (WP_DEBUG check, Plugin Check, zip); the sweep greps (Step 1) and the CLAUDE.md edit were run and applied cleanly.

## Handoff (the controller's checklist after Task 4)

1. Report: test counts, the sweep output, the WP_DEBUG output, both Plugin Check results, the zip listing.
2. Tell the owner exactly what they must do: upload the rebuilt zip at "Add your plugin", test on Playground with WP_DEBUG, send the reply (`docs/wporg-review-reply-2-draft.md`). Nothing is uploaded or sent by us.
3. Ask before merging `fix/no-custom-code` into `main`.
4. Add to the Pro roadmap memory: every Pro layout write tool must go through `LayoutGate::check()`.
