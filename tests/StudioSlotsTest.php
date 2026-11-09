<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';

/**
 * Studio compiler, plan 1 task 3: the fill-in slots of the 75 recipes, derived from the markup itself. The coverage test
 * is the guard: a future recipe with copy in a place no slot reads fails here instead of shipping unfillable text.
 */
class StudioSlotsTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/studio-slots.json';

    /** @return list<string> */
    private static function names(): array
    {
        return SectionRecipes::names();
    }

    public function testSlotIdsAreUniqueWithinEachRecipe(): void
    {
        foreach ( self::names() as $name ) {
            $ids = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ), 'id' );
            $this->assertSame( $ids, array_values( array_unique( $ids ) ), "duplicate slot id in $name" );
        }
    }

    public function testHeroCtaSlots(): void
    {
        $slots = StudioSlots::extract( (string) SectionRecipes::rawMarkup( 'hero-cta' ) );
        $byRole = [];
        foreach ( $slots as $s ) {
            $byRole[ $s['role'] ][] = $s['id'];
        }
        $this->assertArrayHasKey( 'headline', $byRole );
        $this->assertArrayHasKey( 'button_label', $byRole );
        $this->assertArrayHasKey( 'link', $byRole );
        $ids = array_column( $slots, 'id' );
        $this->assertContains( 'section.0.background', $ids );
        $bg = $slots[ array_search( 'section.0.background', $ids, true ) ];
        $this->assertSame( 'picture', $bg['kind'] );
        $this->assertStringStartsWith( '{{aied:image:', $bg['sample'] );
    }

    public function testEverySlotHasAKnownKindAndRoleAndASample(): void
    {
        foreach ( self::names() as $name ) {
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) as $s ) {
                $this->assertContains( $s['kind'], [ 'text', 'link', 'picture', 'alt' ], "$name {$s['id']}" );
                $this->assertNotSame( '', $s['role'], "$name {$s['id']}" );
                $this->assertIsString( $s['sample'] );
                $this->assertIsBool( $s['rich'] );
                $this->assertStringStartsWith( 'divi/', $s['block'] );
            }
        }
    }

    /** Every string leaf under an `innerContent` key is the value of a slot or a named exemption; every image token is a picture slot. */
    public function testCoverage(): void
    {
        $uncovered = [];
        foreach ( self::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $slots = StudioSlots::extract( $raw );
            $index = [];
            foreach ( $slots as $s ) {
                $index[ $s['block'] . '#' . $s['index'] . '#' . $s['path'] ] = $s;
            }
            $counts = [];
            preg_match_all( BlockAttrs::BLOCK, $raw, $m, PREG_SET_ORDER );
            foreach ( $m as $block ) {
                $i       = $counts[ $block[2] ] = ( $counts[ $block[2] ] ?? -1 ) + 1;
                $attrs   = BlockAttrs::decode( $block[3] );
                $this->assertNotNull( $attrs, "$name {$block[2]}" );
                foreach ( self::leaves( $attrs, '' ) as $path => $value ) {
                    $isToken = str_contains( $value, '{{aied:image:' );
                    if ( ! $isToken && ! str_contains( $path, 'innerContent' ) ) {
                        continue;
                    }
                    $key = $block[2] . '#' . $i . '#' . $path;
                    if ( isset( $index[ $key ] ) ) {
                        continue;
                    }
                    if ( ! $isToken && ( self::exempt( $path ) || StudioSlots::isDynamic( $value ) ) ) {
                        continue;
                    }
                    if ( ! $isToken && '' === trim( html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" ) ) {
                        continue; // an empty or &nbsp;-only HTML field has no element to fill
                    }
                    $uncovered[] = "$name {$block[2]}#$i $path = " . substr( $value, 0, 50 );
                }
            }
        }
        $this->assertSame( [], $uncovered, "copy-bearing leaves no slot reads:\n" . implode( "\n", array_slice( $uncovered, 0, 20 ) ) );
    }

    public function testExemptionsCarryReasons(): void
    {
        foreach ( StudioSlots::EXEMPT_PATHS as $suffix => $reason ) {
            $this->assertNotSame( '', $suffix );
            $this->assertGreaterThan( 10, strlen( $reason ), $suffix );
        }
    }

    public function testGoldenTable(): void
    {
        $table = [];
        foreach ( self::names() as $name ) {
            $table[ $name ] = array_map(
                static fn ( array $s ): string => $s['id'] . '|' . $s['role'] . '|' . $s['kind'],
                StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) )
            );
        }
        $json = json_encode( $table, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        if ( false !== getenv( 'AIED_UPDATE_GOLDEN' ) ) {
            file_put_contents( self::GOLDEN, $json );
        }
        $this->assertFileExists( self::GOLDEN, 'regenerate with AIED_UPDATE_GOLDEN=1' );
        $this->assertSame( (string) file_get_contents( self::GOLDEN ), $json, 'slot table drifted; review the diff, then regenerate with AIED_UPDATE_GOLDEN=1' );
    }

    /** Found by the whole-library proof: filling a loop image's alt overwrote the loop's own dynamic token. */
    public function testNoSlotCarriesALoopsDynamicContent(): void
    {
        foreach ( self::names() as $name ) {
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) as $s ) {
                $this->assertStringNotContainsString( '$variable(', $s['sample'], "$name {$s['id']}" );
            }
        }
    }

    public function testRecipesWithSlot(): void
    {
        $all = StudioSlots::recipesWithSlot( 'heading.0' );
        $this->assertNotSame( [], $all );
        $this->assertSame( [], StudioSlots::recipesWithSlot( 'heading.9999' ) );
        $order = self::names();
        $this->assertSame( array_values( array_intersect( $order, $all ) ), $all, 'library order' );
        $this->assertContains( 'hero-cta', StudioSlots::recipesWithSlot( 'section.0.background' ) );
    }

    private static function exempt( string $path ): bool
    {
        foreach ( array_keys( StudioSlots::EXEMPT_PATHS ) as $suffix ) {
            if ( str_ends_with( $path, $suffix ) ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,string> dotted path => string leaf (list positions are written as their number) */
    private static function leaves( mixed $node, string $path ): array
    {
        $out = [];
        if ( $node instanceof \stdClass || is_array( $node ) ) {
            foreach ( (array) $node as $k => $v ) {
                $out += self::leaves( $v, '' === $path ? (string) $k : $path . '.' . $k );
            }
        } elseif ( is_string( $node ) ) {
            $out[ $path ] = $node;
        }

        return $out;
    }
}
