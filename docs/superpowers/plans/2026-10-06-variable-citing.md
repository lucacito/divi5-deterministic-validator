# Free plugin 4.7.0: cite global variables + unknown-variable advisory, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach the AI assistant to reuse the site's numbers and strings variables in the four verified places, and warn it (non-blocking) when a layout cites a `gvid-` id the site does not have.

**Architecture:** Three small, read-only changes in the free plugin: the `StyleGuide` text, the known-ids lookup plus the existing `StyleReferences` advisory (a new `unknown_variables` list, mirrored in the ChatGPT OpenAPI schema), and one discovery note in `list_global_styles`. Grounded by a real builder fixture. Released as free plugin 4.7.0.

**Tech Stack:** PHP 8 (strict types), PHPUnit (`make test`), WP-CLI + Docker for the real-site check.

**Spec:** `docs/superpowers/specs/2026-10-06-variable-citing-design.md` (read it first). Evidence: `docs/global-variables-citing-evidence-5.14.md`.

## Global Constraints

- Free plugin only (`wp-plugin/`) plus tests, scripts, docs. The validator (`src/`, `wp-plugin/validator/`) is NOT touched; the Pro add-on (`pro-addon/`) is NOT touched.
- Read-only: nothing in the free plugin writes variables, colors or presets; nothing licence-gated, remote-fetching or custom-code-saving enters `wp-plugin/`. `tests/WpOrgComplianceTest.php` is never weakened. The guide and readme must not mention any paid add-on (`StyleGuideTest` bans the words `premium`, `upgrade`, `license` and the old Pro tool names in the guide).
- Taught places, verbatim from the spec: a **numbers** variable as a **padding** value or a font **`size`**; a **strings** variable as a heading's **title text** (`title.innerContent`) or a text module's **content** (`content.innerContent`). Nothing else; fonts, images, links and gradients variables stay "listed for information only".
- Citation markup (quotes written `"` inside a block comment): `$variable({"type":"content","value":{"name":"gvid-XXXX","settings":{}}})$`.
- The advisory never changes `valid`, never blocks or alters a save, is attached exactly where `style_references` is today (`validate_layout`, `update_page_layout`, `edit_page_content`, `create_page`, every `dry_run`; MCP and REST), lists at most 20 ids (each cut to 100 characters), and counts ARCHIVED variables and the built-in customizer fonts as existing. When the variables reader is missing or throws, nothing is flagged for variables. When nothing is unknown the response is byte-identical to today's.
- MCP, REST and the ChatGPT OpenAPI spec stay in lockstep (`OpenApiSpec` `StyleAdvisory` gets `unknown_variables`); no new tool, no new operation.
- Version 4.7.0: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header `Version` and `AI_EDITOR_DIVI5_VERSION`), `wp-plugin/readme.txt` (`Stable tag`, `= 4.7.0 =` in Changelog and in Upgrade Notice), tracked `jhmg-ai-editor-for-divi-5.zip` rebuilt with `bash scripts/build-plugin-zip.sh`. Never push, upload or publish. Never stage `CLAUDE.md`.
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Work on branch `feat/variable-citing` (the spec, this plan and the fixture are already committed on it).
- Test commands: one file `./vendor/bin/phpunit -c phpunit.xml tests/<File>.php`; whole free suite `make test` (805 tests before this plan); Pro suite `make test-pro` must stay green too (1369).

## Review Focus

1. A layout that cites a `gvid-` id in the `content` form, as a stray raw `var(--gvid-…)`, or inside a nested attribute: flagged only when the site does not have it; an ARCHIVED variable, a customizer font id and a variable in any group count as existing (Task 2).
2. Divi's variables reader missing, throwing or returning a non-array: no variable advice and no error; presets and colors advice unchanged (Task 2).
3. Payload compatibility: with nothing unknown the response is byte-identical to before; with only a preset unknown the existing keys are unchanged (the new `unknown_variables` key is an empty list); old two-argument calls to `knownIdsWith` keep working (Task 2).
4. The guide teaches exactly the four verified places with markup that matches a real builder export, and warns about everything else; it names no paid add-on (Task 1).
5. A site with variables only of fonts/images/links/gradients kinds, or none, gets no "can be cited" note in `list_global_styles` (Task 3).

---

### Task 1: The guide text and its grounding

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the "Match the site (colors and presets)" section, lines ~284-303)
- Modify: `tests/StyleGuideTest.php` (replace `testSiteStylesSectionDoesNotTeachUngroundedVariableTypes`, add two tests)
- Fixture (already on the branch, do not regenerate): `fixtures/valid/page-17-variable-heading.json`

**Interfaces:**
- Consumes: the fixture's `post_content` (a builder-made heading whose font `size` cites `gvid-f7rcuxrcdb` in the `content` form; it passes `make validate`).
- Produces: `StyleGuide::markdown()` containing the new text; later tasks and the advisory message refer to `advisories.style_references.unknown_variables`.

- [ ] **Step 1: Write the failing tests**

In `tests/StyleGuideTest.php`, replace the whole method `testSiteStylesSectionDoesNotTeachUngroundedVariableTypes` (the last test in the class) with these three (keep the class's existing `use` lines; `\Divi5Validator\Validator` is referenced fully qualified):

```php
    public function testTeachesNumbersAndStringsVariablesInExactlyTheFourVerifiedPlaces(): void
    {
        $md = StyleGuide::markdown();
        $q  = '\\' . 'u0022';
        $this->assertStringContainsString('gvid-XXXX', $md);
        $this->assertStringContainsString('numbers', $md);
        $this->assertStringContainsString('strings', $md);
        foreach (['padding', '`size`', 'title.innerContent', 'content.innerContent'] as $place) {
            $this->assertStringContainsString($place, $md, "the guide must name the verified place: $place");
        }
        $this->assertStringContainsString(
            '$variable({' . $q . 'type' . $q . ':' . $q . 'content' . $q . ',' . $q . 'value' . $q . ':{' . $q . 'name' . $q . ':' . $q . 'gvid-XXXX' . $q . ',' . $q . 'settings' . $q . ':{}}})$',
            $md
        );
        $this->assertStringContainsString('unknown_variables', $md);
        $this->assertStringContainsString('Never invent an id', $md);
    }

    public function testStillForbidsTheVariableKindsThatAreNotVerified(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringContainsString('fonts, images, links or gradients', $md);
        $this->assertStringContainsString('listed for information only', $md);
        $this->assertStringContainsString('Do not cite a variable anywhere else', $md);
        $q = '\\' . 'u0022';
        $this->assertStringNotContainsString($q . 'type' . $q . ':' . $q . 'number', $md);
    }

    public function testTheVariableShapeTheGuideTeachesIsInARealBuilderExportThatPassesTheChecker(): void
    {
        $q     = '\\' . 'u0022';
        $shape = '$variable({' . $q . 'type' . $q . ':' . $q . 'content' . $q . ',' . $q . 'value' . $q . ':{' . $q . 'name' . $q . ':' . $q . 'gvid-';
        $found = null;
        foreach (glob(__DIR__ . '/../fixtures/valid/page-*.json') ?: [] as $file) {
            $export = json_decode((string) file_get_contents($file), true);
            if (is_array($export) && str_contains((string) ($export['post_content'] ?? ''), $shape)) {
                $found = $export;
                break;
            }
        }
        $this->assertNotNull($found, 'no real export in fixtures/valid cites a global variable in the content form the guide teaches');
        $this->assertStringContainsString($shape, StyleGuide::markdown());
        $this->assertTrue((new \Divi5Validator\Validator())->validateContent((string) $found['post_content'])->isValid(), 'the real export that grounds the guide must pass the checker');
    }
```

- [ ] **Step 2: Run to see it fail**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/StyleGuideTest.php`
Expected: the first two new tests FAIL (text not there yet); the third passes its fixture part but fails on `assertStringContainsString($shape, StyleGuide::markdown())`.

- [ ] **Step 3: Change the guide**

In `wp-plugin/src/StyleGuide.php`, in the nowdoc, replace the final paragraph of the "Match the site (colors and presets)" section, i.e. from `falls back to the default preset. Variables other than colors are listed for information` through `site does not have; replace those ids with the site's own.` (the text between the sentence ending `an unknown id silently` and the closing `MD;`), so that the section ends exactly like this (the first lines are the unchanged tail of the previous paragraph):

```
name and `controls` fit, as `"modulePreset":["<id from the tool>"]`; an unknown id silently
falls back to the default preset.

Numbers and strings variables (ids like `gvid-…`, listed by the same tool with their values)
can be reused too, but only in four places that are verified: a **numbers** variable as a
`padding` value or as a font `size`; a **strings** variable as a heading's title text
(`title.innerContent`) or as a text module's content (`content.innerContent`). Write the
value exactly like this (inside a block comment the quotes are written as "):
`"size":"$variable({"type":"content","value":{"name":"gvid-XXXX","settings":{}}})$"`
with a real id from the tool in place of `gvid-XXXX`. Do not cite a variable anywhere else,
and do not reference fonts, images, links or gradients variables: those are listed for
information only. Never invent an id: Divi silently drops a value (a padding, a size, a line
of text) that cites an id the site does not have, and the checker cannot see that.

The tool is read-only: presets, colors and variables are created in Divi's Visual Builder
(its Variables Manager). The save, create and preview tools add an
`advisories.style_references` note when a layout cites a preset, color or variable id the
site does not have (`unknown_presets`, `unknown_colors`, `unknown_variables`); replace those
ids with the site's own.
```

Keep the nowdoc terminator `MD;` and everything before the changed paragraph untouched. Do not use the words premium, upgrade, license.

- [ ] **Step 4: Run to see it pass**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/StyleGuideTest.php`
Expected: all pass (including the unchanged color-shape test and `testDoesNotReferenceRemovedOrPaidTools`).
Run: `make test` — Expected: green (a test that pins the guide text or its size elsewhere, if any, is updated narrowly; never loosened).

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/StyleGuide.php tests/StyleGuideTest.php
git commit -m "feat: the style guide teaches citing numbers and strings variables in four verified places

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Known variable ids, the `unknown_variables` advisory and the OpenAPI schema

**Files:**
- Modify: `wp-plugin/src/GlobalStyles.php` (`knownIds`, `knownIdsWith`)
- Modify: `wp-plugin/src/StyleReferences.php`
- Modify: `wp-plugin/src/OpenApiSpec.php` (`StyleAdvisory`)
- Modify tests: `tests/GlobalStylesTest.php`, `tests/StyleReferencesTest.php`, `tests/StyleAdvisoryHandlersTest.php`, and `tests/GlobalStylesLockstepTest.php` only if it fails

**Interfaces:**
- Consumes: Divi's variables reader already returned by `GlobalStyles::readers()[2]` (`GlobalData::get_global_variables`: groups => objects/arrays of `gvid-…` => entry).
- Produces:
  - `GlobalStyles::knownIds(): array{presets: ?array<string,true>, colors: ?array<string,true>, variables: ?array<string,true>}`;
  - `GlobalStyles::knownIdsWith( ?callable $presets, ?callable $colors, ?callable $variables = null )` (same return shape; `variables` is null when the third argument is null, missing, throws or is not an array);
  - `StyleReferences::advise( string $content, array $known )` where `$known['variables']` is optional (a missing key counts as null) and the result gains `unknown_variables: list<array{id:string,count:int}>`; the advisory exists when any of presets, colors or variables is unknown;
  - `StyleReferences::MESSAGE` extended.

- [ ] **Step 1: Write the failing tests**

`tests/GlobalStylesTest.php`:
1. In `testKnownIdsAreNullForAnUnavailableReader` change the expected value to `[ 'presets' => null, 'colors' => null, 'variables' => null ]` (the lookup now has a third part; this is the one existing assertion that must change).
2. Add these tests (use the file's existing `catalog()`/shim helpers; `knownIdsWith` is called directly):

```php
    public function testKnownIdsCoverEveryVariableInEveryGroupIncludingArchivedAndObjectGroups(): void
    {
        $vars = [
            'numbers' => [ 'gvid-active' => [ 'label' => 'R', 'value' => '12px', 'status' => 'active' ], 'gvid-archived' => [ 'label' => 'G', 'value' => '1rem', 'status' => 'archived' ] ],
            'strings' => (object) [ 'gvid-text' => [ 'label' => 'T', 'value' => 'Hi' ] ],
            'fonts'   => [ '--et_global_heading_font' => [ 'label' => 'Heading', 'value' => 'Abel' ] ],
        ];
        $known = GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors(), fn () => $vars );
        $this->assertSame( [ 'gvid-active', 'gvid-archived', 'gvid-text', '--et_global_heading_font' ], array_keys( $known['variables'] ) );
        $this->assertArrayNotHasKey( 'gvid-nope', $known['variables'] );
    }

    public function testKnownVariableIdsAreNullWhenTheReaderIsMissingThrowsOrReturnsNothingUseful(): void
    {
        $this->assertNull( GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors() )['variables'] );
        $this->assertNull( GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors(), null )['variables'] );
        $this->assertNull( GlobalStyles::knownIdsWith( null, null, function () { throw new \RuntimeException( 'x' ); } )['variables'] );
        $this->assertSame( [], GlobalStyles::knownIdsWith( null, null, fn () => [ 'numbers' => 'junk' ] )['variables'], 'a reader that works but holds no usable group means "no variables", not "cannot tell"' );
    }
```

3. In `testCatalogAndKnownIdsReadThroughTheDiviClasses` add one assertion before the `$GLOBALS['__divi_presets'] = null;` line: `$this->assertArrayHasKey( 'gvid-1', GlobalStyles::knownIds()['variables'] );`.

`tests/StyleReferencesTest.php`: add the constants `private const KNOWN_VAR = 'gvid-f7rcuxrcdb';` and `private const UNKNOWN_VAR = 'gvid-00000000-0000-4000-8000-000000000000';`, a helper

```php
    private function varAttr( string $id ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new \stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
    }
```

and these tests:

```php
    public function testAnUnknownVariableIsFoundCountedAndBoundedButKnownAndArchivedAreNot(): void
    {
        $known   = $this->known() + [ 'variables' => [ self::KNOWN_VAR => true, 'gvid-archived' => true ] ];
        $content = $this->layout(
            $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ),
            $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ),
            $this->heading( $this->varAttr( self::KNOWN_VAR ) ),
            $this->heading( $this->varAttr( 'gvid-archived' ) )
        );
        $advice = StyleReferences::advise( $content, $known );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_VAR, 'count' => 2 ] ], $advice['unknown_variables'] );
        $this->assertSame( [], $advice['unknown_presets'] );
        $this->assertSame( [], $advice['unknown_colors'] );
        $this->assertSame( StyleReferences::MESSAGE, $advice['message'] );
        $this->assertNull( StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( self::KNOWN_VAR ) ) ), $known ) );
    }

    public function testARawCssVariableReferenceIsCaughtToo(): void
    {
        $known   = $this->known() + [ 'variables' => [ self::KNOWN_VAR => true ] ];
        $content = $this->layout( $this->heading( [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => 'var(--' . self::UNKNOWN_VAR . ')' ] ] ] ] ] ] ] ) );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_VAR, 'count' => 1 ] ], StyleReferences::advise( $content, $known )['unknown_variables'] );
    }

    public function testWhenTheVariablesReaderCannotBeReadNothingIsFlaggedForVariablesButOtherAdviceStays(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] + $this->varAttr( self::UNKNOWN_VAR ) ) );
        foreach ( [ $this->known(), $this->known() + [ 'variables' => null ] ] as $known ) {
            $advice = StyleReferences::advise( $content, $known );
            $this->assertSame( [ self::UNKNOWN ], array_column( $advice['unknown_presets'], 'id' ) );
            $this->assertSame( [], $advice['unknown_variables'] );
        }
        $this->assertNull( StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ) ), [ 'presets' => null, 'colors' => null, 'variables' => null ] ) );
    }

    public function testMoreThanTwentyUnknownVariablesAreCappedAndLongIdsCut(): void
    {
        $blocks = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $blocks[] = $this->heading( $this->varAttr( 'gvid-missing-' . $i ) );
        }
        $blocks[] = $this->heading( $this->varAttr( 'gvid-' . str_repeat( 'a', 150 ) ) );
        $advice   = StyleReferences::advise( $this->layout( ...$blocks ), $this->known() + [ 'variables' => [] ] );
        $this->assertCount( StyleReferences::MAX_IDS, $advice['unknown_variables'] );
        $long = StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( 'gvid-' . str_repeat( 'a', 150 ) ) ) ), $this->known() + [ 'variables' => [] ] );
        $this->assertSame( 100, strlen( $long['unknown_variables'][0]['id'] ) );
    }
```

`tests/StyleAdvisoryHandlersTest.php`: add `private const VAR_KNOWN = 'gvid-f7rcuxrcdb'; private const VAR_NO = 'gvid-00000000-0000-4000-8000-000000000000';`; in `setUp` add `$GLOBALS['__divi_variables'] = [ 'numbers' => [ self::VAR_KNOWN => [ 'label' => '12px', 'value' => '12px', 'status' => 'active' ] ] ];` and in `tearDown` add `'__divi_variables'` to the `unset(...)` list; add:

```php
    public function testAnUnknownVariableIsReportedOverRestAndMcpAndNeverChangesTheVerdict(): void
    {
        $cite = static fn ( string $id ): array => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hello' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '$variable({"type":"content","value":{"name":"' . $id . '","settings":{}}})$' ] ] ] ] ] ] ];
        $bad  = $this->layout( 'Hello', $cite( self::VAR_NO ) + [ 'modulePreset' => [ self::KNOWN ] ] );
        $good = $this->layout( 'Hello', $cite( self::VAR_KNOWN ) + [ 'modulePreset' => [ self::KNOWN ] ] );

        [ $st, $rest ] = $this->rest( 'validate', [ 'post_content' => $bad ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['valid'] );
        $this->assertSame( self::VAR_NO, $rest['advisories']['style_references']['unknown_variables'][0]['id'] );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $bad ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertSame( self::VAR_NO, $mcp['advisories']['style_references']['unknown_variables'][0]['id'] );

        [ , $clean ] = $this->rest( 'validate', [ 'post_content' => $good ] );
        $this->assertArrayNotHasKey( 'advisories', $clean );
        $this->assertArrayNotHasKey( 'advisories', $this->mcp( 'validate_layout', [ 'post_content' => $good ] ) );
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/GlobalStylesTest.php tests/StyleReferencesTest.php tests/StyleAdvisoryHandlersTest.php`
Expected: the new tests and the updated `testKnownIdsAreNullForAnUnavailableReader` FAIL.

- [ ] **Step 3: Implement the known ids**

In `wp-plugin/src/GlobalStyles.php` replace `knownIds()` and change the head and tail of `knownIdsWith()`:

```php
    /**
     * Every preset id, global color id and global variable id that exists on this site, uncapped, for checking a layout's
     * references. A part is null when Divi cannot be read, so the caller never flags what it cannot know.
     *
     * @return array{presets: ?array<string,true>, colors: ?array<string,true>, variables: ?array<string,true>}
     */
    public static function knownIds(): array
    {
        [ $presets, $colors, $variables ] = self::readers();

        return self::knownIdsWith( $presets, $colors, $variables );
    }

    /** @return array{presets: ?array<string,true>, colors: ?array<string,true>, variables: ?array<string,true>} */
    public static function knownIdsWith( ?callable $presets, ?callable $colors, ?callable $variables = null ): array
    {
```

keep the existing body that builds `$presetIds` and `$colorIds`, and replace its last line (`return [ 'presets' => $presetIds, 'colors' => $colorIds ];`) with:

```php
        $variableData = self::read( $variables );
        $variableIds  = null;
        if ( null !== $variableData ) {
            $variableIds = [];
            foreach ( $variableData as $items ) {
                $items = is_object( $items ) ? get_object_vars( $items ) : $items;
                if ( ! is_array( $items ) ) {
                    continue;
                }
                foreach ( array_keys( $items ) as $id ) {
                    if ( is_string( $id ) && '' !== $id ) {
                        $variableIds[ $id ] = true;
                    }
                }
            }
        }

        return [ 'presets' => $presetIds, 'colors' => $colorIds, 'variables' => $variableIds ];
```

(The first docblock of `knownIds()` and the `return` annotation of `knownIdsWith()` replace the two old ones.)

- [ ] **Step 4: Implement the advisory**

In `wp-plugin/src/StyleReferences.php`:

1. Replace the class docblock first sentence to say "preset ids, global color ids and global variable ids" and replace `MESSAGE` with:

```php
    public const MESSAGE = "These ids do not exist on this site; Divi falls back to the module's default preset (presets), leaves the color undefined (colors), or silently drops the value that cites the variable (variables). Call list_global_styles and use this site's own ids.";
```

2. Add `private const VARIABLE_TOKEN = '/gvid-[A-Za-z0-9_-]+/';` next to `COLOR_TOKEN`.

3. Replace `advise()` with:

```php
    /**
     * @param array{presets: ?array<string,true>, colors: ?array<string,true>, variables?: ?array<string,true>} $known ids that exist on the site; null (or a missing key) = cannot tell
     * @return ?array{unknown_presets: list<array{id:string,count:int}>, unknown_colors: list<array{id:string,count:int}>, unknown_variables: list<array{id:string,count:int}>, message: string}
     */
    public static function advise( string $content, array $known ): ?array
    {
        $knownVariables = $known['variables'] ?? null;
        if ( null === $known['presets'] && null === $known['colors'] && null === $knownVariables ) {
            return null;
        }
        // Content too large or too deeply nested to walk safely gets no advisory (the policy refuses it anyway).
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }

        $presets   = [];
        $colors    = [];
        $variables = [];
        $parsed->root()->walk( static function ( Block $block ) use ( &$presets, &$colors, &$variables, $known, $knownVariables ): void {
            $attrs = $block->attrs();
            if ( null !== $known['presets'] ) {
                foreach ( (array) ( $attrs['modulePreset'] ?? [] ) as $id ) {
                    if ( is_string( $id ) && '' !== $id && 'default' !== $id && ! isset( $known['presets'][ $id ] ) ) {
                        $presets[ $id ] = ( $presets[ $id ] ?? 0 ) + 1;
                    }
                }
            }
            if ( null !== $known['colors'] ) {
                foreach ( self::tokens( $attrs, self::COLOR_TOKEN ) as $id ) {
                    if ( ! isset( $known['colors'][ $id ] ) ) {
                        $colors[ $id ] = ( $colors[ $id ] ?? 0 ) + 1;
                    }
                }
            }
            if ( null !== $knownVariables ) {
                foreach ( self::tokens( $attrs, self::VARIABLE_TOKEN ) as $id ) {
                    if ( ! isset( $knownVariables[ $id ] ) ) {
                        $variables[ $id ] = ( $variables[ $id ] ?? 0 ) + 1;
                    }
                }
            }
        } );

        if ( [] === $presets && [] === $colors && [] === $variables ) {
            return null;
        }

        return [
            'unknown_presets'   => self::rows( $presets ),
            'unknown_colors'    => self::rows( $colors ),
            'unknown_variables' => self::rows( $variables ),
            'message'           => self::MESSAGE,
        ];
    }
```

4. Replace the private `colorTokens()` with a generic `tokens()` (same body, with the pattern as a parameter) and update its recursive call:

```php
    /** Every token matching $pattern in any string value of the attributes, in order, repeats included. @param array<mixed> $node @return list<string> */
    private static function tokens( array $node, string $pattern ): array
    {
        $out = [];
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                array_push( $out, ...self::tokens( $value, $pattern ) );
            } elseif ( is_string( $value ) && preg_match_all( $pattern, $value, $m ) ) {
                array_push( $out, ...$m[0] );
            }
        }

        return $out;
    }
```

`attach()` is unchanged (it already passes `GlobalStyles::knownIds()`).

In `wp-plugin/src/OpenApiSpec.php`, inside `StyleAdvisory.properties` after `unknown_colors` add:

```php
                            'unknown_variables' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StyleRefCount'], 'description' => 'Global variable ids (gvid-...) that do not exist on this site (at most 20); Divi silently drops a value that cites one'],
```

- [ ] **Step 5: Run to see them pass**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/GlobalStylesTest.php tests/StyleReferencesTest.php tests/StyleAdvisoryHandlersTest.php tests/GlobalStylesLockstepTest.php`
Expected: all pass. Then `make test` (green) and `make test-pro` (green).
If an existing test pins the whole advisory array or the old `MESSAGE` text, update it narrowly to the new shape (the extra `unknown_variables` key, the new message); never loosen an assertion. Report each such edit.

- [ ] **Step 6: Commit**

```bash
git add wp-plugin/src/GlobalStyles.php wp-plugin/src/StyleReferences.php wp-plugin/src/OpenApiSpec.php tests
git commit -m "feat: warn when a layout cites a global variable the site does not have (advisories.style_references.unknown_variables)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The discovery note in `list_global_styles`

**Files:**
- Modify: `wp-plugin/src/GlobalStyles.php` (`catalogWith`)
- Test: `tests/GlobalStylesTest.php`

**Interfaces:**
- Consumes: the `$varLists` computed in `catalogWith` (type => list of `{id,label,value}` for ACTIVE variables).
- Produces: one extra string in `notes`, immediately before the final read-only note, only when `numbers` or `strings` has at least one listed variable.

- [ ] **Step 1: Write the failing tests**

In `tests/GlobalStylesTest.php` (use the existing `catalog( $presets, $colors, $variables )` helper):

```php
    public function testANoteSaysNumbersAndStringsVariablesCanBeUsedOnlyWhenThereAreSome(): void
    {
        $with = $this->catalog( $this->presets(), $this->colors(), [ 'numbers' => [ 'gvid-1' => [ 'label' => 'R', 'value' => '12px', 'status' => 'active' ] ] ] );
        $note = "numbers and strings variables can be used in a layout as described in get_style_guide; other kinds of variable are listed for information only.";
        $this->assertContains( $note, $with['notes'] );
        $this->assertSame( "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.", end( $with['notes'] ), 'the read-only note stays last' );

        foreach ( [ [], [ 'fonts' => [ 'gvid-f' => [ 'label' => 'F', 'value' => 'Abel', 'status' => 'active' ] ] ], [ 'numbers' => [ 'gvid-arch' => [ 'label' => 'A', 'value' => '1px', 'status' => 'archived' ] ] ] ] as $vars ) {
            $this->assertNotContains( $note, $this->catalog( $this->presets(), $this->colors(), $vars )['notes'], json_encode( $vars ) );
        }
    }
```

- [ ] **Step 2: Run to see it fail**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/GlobalStylesTest.php`
Expected: the new test FAILS (note missing).

- [ ] **Step 3: Implement**

In `GlobalStyles::catalogWith`, directly before the line `$notes[] = "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.";` add:

```php
        if ( [] !== ( $varLists['numbers'] ?? [] ) || [] !== ( $varLists['strings'] ?? [] ) ) {
            $notes[] = 'numbers and strings variables can be used in a layout as described in get_style_guide; other kinds of variable are listed for information only.';
        }
```

- [ ] **Step 4: Run to see it pass**

Run: `./vendor/bin/phpunit -c phpunit.xml tests/GlobalStylesTest.php` then `make test` (green). If a test counts `notes` exactly for a catalog that has numbers variables, update it narrowly and report it.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/GlobalStyles.php tests/GlobalStylesTest.php
git commit -m "feat: list_global_styles notes that numbers and strings variables can be used in a layout

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The real-site check

**Files:**
- Modify: `scripts/verify-global-styles.php`
- Modify: `Makefile` only if its comment for `verify-global-styles` needs one clause

**Interfaces:**
- Consumes: the free plugin's REST/MCP handlers through the script's existing `$rest(...)` / `$mcp(...)` helpers, `$layout( $text, $extra )`, the catalog `$d` (its `variables` map: type => list of `{id,label,value}`), `Validator`.
- Produces: new PASS/FAIL checks; the script stays read-only on Divi's data (it still proves the fingerprint is unchanged) and creates only its one throwaway draft page.

- [ ] **Step 1: Add the checks**

In `scripts/verify-global-styles.php`, after the line that defines `$dirty` (and its checker check `...the probe layout with unknown ids passes the checker...`), add:

```php
$varAttr = static fn ( string $id ): array => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
$knownVar = '';
foreach ( [ 'numbers', 'strings' ] as $kind ) {
    if ( [] !== ( $d['variables'][ $kind ] ?? [] ) ) {
        $knownVar = (string) $d['variables'][ $kind ][0]['id'];
        break;
    }
}
$unknownV  = 'gvid-00000000-0000-4000-8000-000000000000';
$dirtyVar  = $layout( 'Probe', $varAttr( $unknownV ) );
$cleanVar  = '' === $knownVar ? '' : $layout( 'Probe', $varAttr( $knownVar ) );
$check( ( new Validator() )->validateContent( $dirtyVar )->isValid(), 'a layout citing a variable the site does not have passes the checker (the advisory is not a violation)' );
if ( '' === $knownVar ) {
    WP_CLI::warning( 'this site has no numbers or strings variable: the "known variable gets no advisory" checks were skipped' );
}
```

and, inside the existing `try { ... }` block, after the check that follows `$dirty`'s REST validate (so `$rest`/`$mcp` are in scope), add:

```php
    $r = $rest( 'POST', '/validate', [ 'post_content' => $dirtyVar ] );
    $check( 200 === $r['status'] && true === $r['data']['valid'] && [ $unknownV ] === array_column( $r['data']['advisories']['style_references']['unknown_variables'] ?? [], 'id' ), 'REST /validate of a layout citing a missing variable: still valid, and the advisory names exactly that id' );
    $m = $mcp( 'validate_layout', [ 'post_content' => $dirtyVar ] );
    $check( ! $m['isError'] && true === $m['result']['valid'] && [ $unknownV ] === array_column( $m['result']['advisories']['style_references']['unknown_variables'] ?? [], 'id' ), 'MCP validate_layout reports the same unknown variable' );
    if ( '' !== $knownVar ) {
        $r = $rest( 'POST', '/validate', [ 'post_content' => $cleanVar ] );
        $check( 200 === $r['status'] && true === $r['data']['valid'] && ! array_key_exists( 'advisories', $r['data'] ), "REST /validate of a layout citing the real variable {$knownVar}: no advisories key" );
    }
```

(If the script's helper names differ slightly, use the ones the surrounding code uses; do not change what the existing checks assert.)

- [ ] **Step 2: Run it**

The free plugin and Divi are active on the Docker dev site; the plugin directory is bind-mounted from `wp-plugin/`, so no sync step is needed. Run: `make verify-global-styles`
Expected: every line PASS, ending with the script's existing Success line, 0 FAIL (the dev site has one real numbers variable, `gvid-f7rcuxrcdb`, so the known-variable check runs). The existing fingerprint check must still prove presets, global colors and variables are byte-identical afterwards.

- [ ] **Step 3: Commit**

```bash
git add scripts/verify-global-styles.php Makefile
git commit -m "test: the real-site check covers unknown and known variables in the style advisory

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Release 4.7.0 and the full verification

**Files:**
- Modify: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header `Version: 4.7.0`, `define('AI_EDITOR_DIVI5_VERSION', '4.7.0');`)
- Modify: `wp-plugin/readme.txt` (`Stable tag: 4.7.0`; a `= 4.7.0 =` entry at the top of Changelog and of Upgrade Notice)
- Modify: `tests/WpOrgNamingTest.php` (the pinned version string, line ~45)
- Rebuild (tracked): `jhmg-ai-editor-for-divi-5.zip`
- Modify: `docs/superpowers/specs/2026-10-06-variable-citing-design.md` (status line: implemented)

**Interfaces:**
- Consumes: Tasks 1 to 4.
- Produces: a releasable, unpublished 4.7.0 state.

- [ ] **Step 1: Bump the version and write the notes**

Edit the plugin header and constant to 4.7.0, `Stable tag: 4.7.0`, then add to `wp-plugin/readme.txt`, directly under `== Changelog ==` above `= 4.6.0 =`:

```
= 4.7.0 =
* New: your AI assistant can now use your Divi number and text variables in the pages it builds (for example your spacing and font-size variables, or a shared line of text), the same way it already reuses your global colors. The style guide it reads explains exactly where this works, and `list_global_styles` points it there.
* New: when a layout uses a variable your site does not have, the check now tells your assistant (Divi would silently drop that value). This is advice only and never blocks a save.
* As before, the plugin only reads: variables, colors and presets are created in Divi itself.
```

and under `== Upgrade Notice ==` above `= 4.6.0 =`:

```
= 4.7.0 =
Your AI assistant can reuse your Divi number and text variables and is warned about ones that do not exist. Nothing changes unless your assistant uses them.
```

Update the pinned version in `tests/WpOrgNamingTest.php` to `4.7.0`. Run `./vendor/bin/phpunit -c phpunit.xml tests/WpOrgNamingTest.php tests/WpOrgComplianceTest.php` (both green; the compliance test checks the readme's claims and is never weakened).

- [ ] **Step 2: Rebuild the zip and check it**

Run `bash scripts/build-plugin-zip.sh`. Then prove the zip equals `wp-plugin/`: `rm -rf /tmp/aied-zipcheck && mkdir /tmp/aied-zipcheck && unzip -q jhmg-ai-editor-for-divi-5.zip -d /tmp/aied-zipcheck && diff -r wp-plugin /tmp/aied-zipcheck/jhmg-ai-editor-for-divi-5` (no output = identical; ignore nothing).

- [ ] **Step 3: Full verification (report counts)**

- `make test` (green; 805 plus the new tests), `make test-pro` (1369 green).
- `make verify-global-styles` (all PASS).
- Plugin Check on the dev copy (the plugin dir in the container is the bind-mounted `wp-plugin/`): `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp --allow-root plugin check jhmg-ai-editor-for-divi-5` — expected `No errors found` (warnings that were already there are fine; report any new one). Never run `wp plugin delete` or uninstall anything.
- `git status` shows only intended files; `CLAUDE.md` and `dist/` are not staged.

- [ ] **Step 4: Spec status and commit**

Change the spec's first status line to "implemented on branch feat/variable-citing (free plugin 4.7.0, not uploaded)". Then:

```bash
git add wp-plugin tests jhmg-ai-editor-for-divi-5.zip docs/superpowers/specs/2026-10-06-variable-citing-design.md
git commit -m "release: free plugin 4.7.0 (cite global variables, unknown-variable advisory)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review (done while writing this plan)

- **Spec coverage:** guide text with four places, markup and warnings plus fixture-backed test (Task 1); known ids with variables, advisory field, message, OpenAPI schema, MCP and REST handlers (Task 2); discovery note (Task 3); real-site check (Task 4); version, readme, zip, Plugin Check, compliance (Task 5). Token numbers: `docs/token-usage.md` has no row quoting the style guide, so nothing to re-measure; the tool-definitions row is unchanged because no tool definition changed.
- **Placeholder scan:** none; every step has the code or exact text.
- **Type consistency:** `knownIdsWith( ?callable, ?callable, ?callable = null )` and the `variables` key are used identically in `GlobalStyles`, `StyleReferences::advise` (`$known['variables'] ?? null`) and the tests; `unknown_variables` rows share the `{id,count}` shape of `unknown_presets`/`unknown_colors`; `tokens()` replaces `colorTokens()` everywhere in `StyleReferences`.
