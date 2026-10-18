<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
foreach ( [ 'ImagePack', 'ImageTokens', 'SectionRecipes', 'StyleGuide' ] as $shapeRegistryClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $shapeRegistryClass . '.php';
}

/**
 * "Never invent shapes" as a test: every attribute path a recipe uses, and every path a guide teaches, must be in the
 * registry of shapes proven by a real builder export (or a live render), or - for the guide only - frozen as legacy.
 */
final class ShapeRegistryTest extends TestCase
{
    public function testPathNormalisation(): void
    {
        $attrs = [
            'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'gradient' => [ 'stops' => [ [ 'position' => 0 ] ] ] ] ] ] ] ],
            'builderVersion' => '5.8.0',
            'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'x' ] ] ],
        ];
        $this->assertSame(
            [ 'module.decoration.background.{bp}.value.gradient.stops.{n}.position', 'title.innerContent' ],
            ShapePaths::fromBlockAttrs( $attrs )
        );
    }

    public function testExemptKeysAndBreakpoints(): void
    {
        $attrs = [
            'modulePreset' => [ 'abc' ], 'syncNodeId' => 'x', 'slotId' => 's', 'globalModule' => '1', 'blockName' => 'b',
            'localAttrs' => [ 'a' => [ 'b' => 1 ] ], 'localAttrOverridePaths' => [ 'p' ],
            'a' => [ 'tablet' => [ 'value' => 1 ], 'phone' => [ 'value' => 2 ], 'tabletWide' => [ 'value' => 3 ], 'phoneWide' => [ 'value' => 4 ] ],
        ];
        $this->assertSame( [ 'a.{bp}.value' ], ShapePaths::fromBlockAttrs( $attrs ) );
    }

    public function testGuideTextPaths(): void
    {
        $md = "Use `module.decoration.background.desktop.value.color` or a.b.c.\nSee 5.14.0 and docs.example.com. `x.{bp}.y.z`.";
        $this->assertSame(
            [ 'module.decoration.background.{bp}.value.color', 'x.{bp}.y.z' ],
            ShapePaths::fromGuideText( $md )
        );
    }

    public function testEveryRecipePathIsRegistered(): void
    {
        $registry = ShapeRegistry::load();
        $this->assertNotEmpty( $registry->all(), 'the registry must be seeded (php scripts/seed-shape-registry.php)' );
        $pending = $this->lines( 'ungrounded-recipe-paths.txt' );
        $missing = [];
        $seen    = [];
        foreach ( SectionRecipes::names() as $name ) {
            $markup = SectionRecipes::rawMarkup( $name );
            $this->assertNotNull( $markup, "recipe $name should resolve" );
            foreach ( ShapePaths::fromMarkup( (string) $markup ) as $path ) {
                $seen[ $path ] = true;
                if ( ! $registry->covers( $path ) && ! isset( $pending[ $path ] ) ) {
                    $missing[ $path ][] = $name;
                }
            }
        }
        $lines = [];
        foreach ( $missing as $path => $names ) {
            $lines[] = $path . ' (' . implode( ', ', $names ) . ')';
        }
        $this->assertSame( [], $lines, "recipe paths with no export or render behind them:\n" . implode( "\n", $lines ) );

        // The frozen list of recipe paths still awaiting an export may only shrink: an entry that is now registered
        // (or no longer used by any recipe) must be deleted from it.
        $stale = [];
        foreach ( array_keys( $pending ) as $path ) {
            if ( ! isset( $seen[ $path ] ) || $registry->covers( $path ) ) {
                $stale[] = $path;
            }
        }
        $this->assertSame( [], $stale, "remove from ungrounded-recipe-paths.txt:\n" . implode( "\n", $stale ) );
    }

    public function testRegistryEntriesAreWellFormed(): void
    {
        $root = dirname( __DIR__ );
        $registry = ShapeRegistry::load();
        $this->assertNotEmpty( $registry->all() );
        foreach ( $registry->all() as $e ) {
            $this->assertStringNotContainsString( 'desktop', $e['path'], 'a literal breakpoint in ' . $e['path'] );
            $this->assertContains( $e['how'], [ 'export', 'probe' ], $e['path'] );
            $this->assertNotSame( '', $e['evidence'], $e['path'] );
            $file = explode( '#', $e['evidence'] )[0];
            $this->assertFileExists( $root . '/' . $file, 'evidence of ' . $e['path'] );
        }
    }

    public function testNewGuidePathsMustBeRegisteredOrFrozen(): void
    {
        $registry = ShapeRegistry::load();
        $frozen = $this->lines( 'legacy-guide-paths.txt' );
        $new = [];
        foreach ( ShapePaths::fromGuideText( StyleGuide::markdown() ) as $path ) {
            if ( ! $registry->covers( $path ) && ! isset( $frozen[ $path ] ) ) {
                $new[] = $path;
            }
        }
        $this->assertSame( [], $new, "guide paths neither proven nor frozen as legacy:\n" . implode( "\n", $new ) );
    }

    public function testProbeProvenShapesAreInAndDeadShapesAreOut(): void
    {
        $registry = ShapeRegistry::load();
        // Proven by a real builder export (the heading size) or by the effect probe (the image fit).
        $this->assertTrue( $registry->covers( 'title.decoration.font.font.{bp}.value.size' ) );
        $this->assertTrue( $registry->covers( 'image.decoration.fit.{bp}.value.objectFit' ) );
        $this->assertTrue( $registry->covers( 'module.advanced.sizing.{bp}.value.aspectRatio.width' ) );
        // Measured on the real Divi 5.15.1: these pass the validator but emit no CSS (docs/guide-shapes-evidence-5.14.md).
        $this->assertFalse( $registry->covers( 'image.decoration.fit.{bp}.value.type' ), 'a dead shape must never be registered' );
    }

    /** @return array<string,int> the non-empty lines of a file in fixtures/shapes, as a set */
    private function lines( string $file ): array
    {
        $lines = file( __DIR__ . '/../fixtures/shapes/' . $file, FILE_IGNORE_NEW_LINES );
        $this->assertIsArray( $lines, $file );
        return array_flip( array_filter( array_map( 'trim', $lines ), static fn ( string $l ): bool => $l !== '' ) );
    }
}
