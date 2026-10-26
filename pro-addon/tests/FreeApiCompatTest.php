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
            [ $w . 'LayoutGate', 'check', [ 'content' => 'string', 'baseline' => '?string' ], 'Divi5Validator\\ValidationResult' ],
            [ $w . 'LayoutDiff', 'compare', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
            [ $w . 'DryRun', 'preview', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'attach', [ 'payload' => 'array', 'content' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'advise', [ 'content' => 'string', 'known' => 'array' ], '?array' ],
            [ $w . 'GlobalStyles', 'knownIds', [], 'array' ],
            [ $w . 'StyleReferences', 'knownIds', [], 'array' ],
            [ $w . 'FieldTarget', 'resolve', [ 'id' => 'int' ], 'array' ],
            [ $w . 'CustomFieldReader', 'classify', [ 'key' => 'string' ], 'string' ],
            [ $w . 'CustomFields', 'postHasMeta', [ 'key' => 'string' ], 'bool' ],
            // The design switch (Design\*) resolves markers through the free pipeline and builds nothing itself.
            [ $w . 'SectionRecipes', 'resolveMarkup', [ 'markup' => 'string', 'language' => 'array', 'kit' => 'array' ], '?string' ],
            [ $w . 'SectionRecipes', 'rawMarkup', [ 'name' => 'string' ], '?string' ],
            [ $w . 'SectionRecipes', 'names', [], 'array' ],
            [ $w . 'DesignLanguages', 'get', [ 'id' => 'string' ], '?array' ],
            [ $w . 'DesignLanguages', 'ids', [], 'array' ],
            [ $w . 'StyleKits', 'get', [ 'id' => 'string' ], '?array' ],
            [ $w . 'StyleKits', 'ids', [], 'array' ],
            [ $w . 'StyleKits', 'problems', [ 'kit' => 'array' ], 'array' ],
            [ $w . 'StyleKits', 'valueToken', [ 'kit' => 'array', 'name' => 'string' ], '?array' ],
            [ $w . 'RecipeTokens', 'encodeJson', [ 'value' => 'mixed' ], 'string' ],
            [ $w . 'RecipeTokens', 'resolve', [ 'markup' => 'string', 'language' => 'array' ], 'string' ],
            [ $w . 'BlockAttrs', 'decode', [ 'json' => 'string' ], '?stdClass' ],
            [ $w . 'KitResolver', 'leftovers', [ 'markup' => 'string' ], 'array' ],
            [ $w . 'LanguageResolver', 'leftovers', [ 'markup' => 'string' ], 'array' ],
        ];
    }

    /**
     * Methods that may have gained EXTRA TRAILING parameters, as long as each extra one is optional: the add-on keeps
     * calling them with the original arguments (HistoryService::write got `?string $newTitle = null`, DryRun::preview got
     * `?array $title = null` for the page-title feature, StyleReferences::attach got `?string $previous = null` for the content-loss
     * and component advisories). Every other row is exact.
     */
    private const MAY_GAIN_OPTIONAL_TRAILING = [
        'AiEditorDivi5\\WP\\HistoryService::write',
        'AiEditorDivi5\\WP\\DryRun::preview',
        'AiEditorDivi5\\WP\\StyleReferences::attach',
    ];

    /**
     * Compares the pinned parameters with a method's real ones. The pinned ones must come first, unchanged in name, type
     * and order. With $allowOptionalExtras, further parameters are accepted only when each is optional.
     *
     * @param array<string,string>                                      $expected name => type
     * @param list<array{name:string,type:string,optional:bool}>        $actual
     * @return string|null a description of the first mismatch, or null when the signature is compatible
     */
    public static function parameterMismatch( array $expected, array $actual, bool $allowOptionalExtras ): ?string
    {
        $i = 0;
        foreach ( $expected as $name => $type ) {
            if ( ! isset( $actual[ $i ] ) ) {
                return "parameter #{$i} {$name} ({$type}) is missing";
            }
            if ( $actual[ $i ]['name'] !== $name || $actual[ $i ]['type'] !== $type ) {
                return "parameter #{$i} is {$actual[$i]['type']} \${$actual[$i]['name']}, expected {$type} \${$name}";
            }
            ++$i;
        }
        for ( $n = count( $actual ); $i < $n; ++$i ) {
            if ( ! $allowOptionalExtras ) {
                return "unexpected extra parameter \${$actual[$i]['name']}";
            }
            if ( ! $actual[ $i ]['optional'] ) {
                return "extra parameter \${$actual[$i]['name']} is required (it must be optional)";
            }
        }

        return null;
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
            $actual[] = [ 'name' => $p->getName(), 'type' => (string) $p->getType(), 'optional' => $p->isOptional() ];
        }
        $mismatch = self::parameterMismatch( $params, $actual, in_array( $class . '::' . $method, self::MAY_GAIN_OPTIONAL_TRAILING, true ) );
        $this->assertNull( $mismatch, "{$class}::{$method} parameters changed: {$mismatch}" );
        $this->assertSame( $returns, (string) $m->getReturnType(), "{$class}::{$method} return type changed" );
    }

    /** The comparison itself: the original parameters are strict, extras pass only when optional and only where allowed. */
    public function testTheSignatureComparisonStaysStrict(): void
    {
        $expected = [ 'pageId' => 'int', 'newContent' => 'string' ];
        $base     = [
            [ 'name' => 'pageId', 'type' => 'int', 'optional' => false ],
            [ 'name' => 'newContent', 'type' => 'string', 'optional' => false ],
        ];
        $opt  = [ 'name' => 'newTitle', 'type' => '?string', 'optional' => true ];
        $req  = [ 'name' => 'newTitle', 'type' => '?string', 'optional' => false ];

        $this->assertNull( self::parameterMismatch( $expected, $base, false ) );
        $this->assertNull( self::parameterMismatch( $expected, [ ...$base, $opt ], true ) );
        $this->assertNotNull( self::parameterMismatch( $expected, [ ...$base, $opt ], false ), 'an extra parameter where none is allowed' );
        $this->assertNotNull( self::parameterMismatch( $expected, [ ...$base, $req ], true ), 'an extra REQUIRED parameter' );
        $this->assertNotNull( self::parameterMismatch( $expected, [ $base[0] ], true ), 'a missing parameter' );
        $this->assertNotNull( self::parameterMismatch( $expected, [ $base[1], $base[0] ], true ), 'reordered parameters' );
        $this->assertNotNull( self::parameterMismatch( $expected, [ $base[0], [ 'name' => 'newContent', 'type' => '?string', 'optional' => false ] ], true ), 'a changed type' );
        $this->assertNotNull( self::parameterMismatch( $expected, [ $base[0], [ 'name' => 'content', 'type' => 'string', 'optional' => false ] ], true ), 'a renamed parameter' );
        // The extras appear AFTER the originals only: a new optional parameter in the middle shifts an original and fails.
        $this->assertNotNull( self::parameterMismatch( $expected, [ $base[0], $opt, $base[1] ], true ), 'an optional parameter inserted before an original' );
    }

    public function testOnlyThreeNamedMethodsMayGainOptionalParameters(): void
    {
        $this->assertSame(
            [ 'AiEditorDivi5\\WP\\HistoryService::write', 'AiEditorDivi5\\WP\\DryRun::preview', 'AiEditorDivi5\\WP\\StyleReferences::attach' ],
            self::MAY_GAIN_OPTIONAL_TRAILING
        );
        foreach ( [ 'write' => 'HistoryService', 'preview' => 'DryRun' ] as $method => $class ) {
            $m = new \ReflectionMethod( 'AiEditorDivi5\\WP\\' . $class, $method );
            $this->assertSame( $method === 'write' ? 3 : 2, $m->getNumberOfRequiredParameters(), "{$class}::{$method}: the add-on's original call shape must still work" );
        }
    }

    /** The validator types LayoutWriter::refusal() reads off the verdict LayoutGate::check() returns. */
    public function testTheValidatorTypesTheAddOnReadsKeepTheirShape(): void
    {
        $isValid = new \ReflectionMethod( \Divi5Validator\ValidationResult::class, 'isValid' );
        $this->assertTrue( $isValid->isPublic() && ! $isValid->isStatic() );
        $this->assertSame( 0, $isValid->getNumberOfParameters() );
        $this->assertSame( 'bool', (string) $isValid->getReturnType() );

        $violations = new \ReflectionMethod( \Divi5Validator\ValidationResult::class, 'violations' );
        $this->assertTrue( $violations->isPublic() && ! $violations->isStatic() );
        $this->assertSame( 0, $violations->getNumberOfParameters() );
        $this->assertSame( 'array', (string) $violations->getReturnType() );

        $toArray = new \ReflectionMethod( \Divi5Validator\Violation::class, 'toArray' );
        $this->assertTrue( $toArray->isPublic() && ! $toArray->isStatic() );
        $this->assertSame( 0, $toArray->getNumberOfParameters() );
        $this->assertSame( 'array', (string) $toArray->getReturnType() );

        $this->assertSame( [ 'code', 'message', 'path' ], array_keys( ( new \Divi5Validator\Violation( 'c', 'm', 'p' ) )->toArray() ) );
        $this->assertTrue( ( new \Divi5Validator\ValidationResult( [] ) )->isValid() );
    }

    public function testTheRestAuthenticationMethodTheProRoutesUseKeepsItsShape(): void
    {
        $m = new \ReflectionMethod( \AiEditorDivi5\WP\RestController::class, 'require_edit_posts' );
        $this->assertTrue( $m->isPublic() && ! $m->isStatic(), 'RestController::require_edit_posts must stay a public instance method' );
        $this->assertSame( [], $m->getParameters() );
        $this->assertSame( 'WP_Error|bool', (string) $m->getReturnType() );
        $s = new \ReflectionMethod( \AiEditorDivi5\WP\OpenApiSpec::class, 'spec' );
        $this->assertTrue( $s->isStatic() && $s->isPublic() );
    }

    /** The constants and shapes the design switch (Design\*) reads from the free plugin, pinned as they are today. */
    public function testTheConstantsTheDesignSwitchReadsKeepTheirValues(): void
    {
        $this->assertIsString( \AiEditorDivi5\WP\BlockAttrs::BLOCK );
        $this->assertSame( 1, preg_match( \AiEditorDivi5\WP\BlockAttrs::BLOCK, '<!-- wp:divi/text {"a":1} /-->' ), 'BlockAttrs::BLOCK must still match an opening comment with attributes' );
        $this->assertSame(
            [
                'type'   => [
                    'divi/heading' => [ 'display', 'title', 'eyebrow' ],
                    'divi/text'    => [ 'eyebrow' ],
                ],
                'rhythm' => [
                    'divi/section' => [ 'section', 'open', 'tight' ],
                ],
            ],
            \AiEditorDivi5\WP\LanguageAdapters::APPLIED
        );
        $this->assertSame(
            [
                'display' => [ 'h1', 'h2' ],
                'title'   => [ 'h3' ],
                'eyebrow' => [ 'h4' ],
            ],
            \AiEditorDivi5\WP\LanguageAdapters::TYPE_HEADING_LEVELS
        );
        $this->assertSame( 524288, \AiEditorDivi5\WP\PageHistory::MAX_BYTES );
    }

    /** The five private KitResolver constants Design\MarkerSpace copies must still exist (their values are pinned by MarkerSpaceTest). */
    public function testTheKitResolverConstantsTheMarkerSpaceCopiesStillExist(): void
    {
        foreach ( [ 'ROLE_BLOCKS', 'ROLE_SURFACES', 'ROLE_GROUNDS', 'GROUND_SURFACES', 'GROUND_BLOCKS' ] as $name ) {
            $this->assertTrue( ( new \ReflectionClass( \AiEditorDivi5\WP\KitResolver::class ) )->hasConstant( $name ), "KitResolver::{$name} is gone" );
            $this->assertIsArray( ( new \ReflectionClassConstant( \AiEditorDivi5\WP\KitResolver::class, $name ) )->getValue(), $name );
        }
    }

    public function testTheEditTargetErrorConstantsStayTheSame(): void
    {
        $this->assertSame( 'not_found', \AiEditorDivi5\WP\EditTarget::NOT_FOUND );
        $this->assertSame( 'forbidden', \AiEditorDivi5\WP\EditTarget::FORBIDDEN );
    }

    public function testTheCustomFieldClassesTheWriterReadsKeepTheirClassifications(): void
    {
        $this->assertSame( 'shown', \AiEditorDivi5\WP\CustomFieldReader::SHOWN );
        $this->assertSame( 'hidden', \AiEditorDivi5\WP\CustomFieldReader::HIDDEN );
        $this->assertSame( 'secret', \AiEditorDivi5\WP\CustomFieldReader::SECRET );
    }

    public function testClassifyKeepsRefusingSecretLookingAndHiddenKeys(): void
    {
        foreach ( [ 'smtp_pass', 'db_password', 'licence_key', 'client_secret', 'my_credential', 'stripe_token' ] as $key ) {
            $this->assertSame( \AiEditorDivi5\WP\CustomFieldReader::SECRET, \AiEditorDivi5\WP\CustomFieldReader::classify( $key ), $key );
        }
        $this->assertSame( \AiEditorDivi5\WP\CustomFieldReader::HIDDEN, \AiEditorDivi5\WP\CustomFieldReader::classify( '_x' ) );
        $this->assertSame( \AiEditorDivi5\WP\CustomFieldReader::SHOWN, \AiEditorDivi5\WP\CustomFieldReader::classify( 'headline' ) );
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
