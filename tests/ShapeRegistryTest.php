<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Tests\Support\DeadOnModule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/DeadOnModule.php';
foreach ( [ 'ImagePack', 'ImageTokens', 'SectionRecipes', 'StyleGuide', 'StyleKits', 'KitAdapters', 'KitResolver' ] as $shapeRegistryClass ) {
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
        $markups = [];
        foreach ( SectionRecipes::names() as $name ) {
            $markup = SectionRecipes::rawMarkup( $name );
            $this->assertNotNull( $markup, "recipe $name should resolve" );
            // The paths a recipe really uses are those it is SERVED with: every style kit's markers resolved (a marker
            // is not a Divi attribute), so the paths each kit's adapter writes are linted too.
            $served = [];
            foreach ( StyleKits::ids() as $kit ) {
                $served[] = KitResolver::resolve( (string) $markup, (array) StyleKits::get( $kit ) );
            }
            $markups[ $name ] = implode( "\n", $served );
        }
        $problems = $this->recipeProblems( $registry, $markups, $this->lines( 'ungrounded-recipe-paths.txt' ) );
        $lines = [];
        foreach ( $problems['missing'] as $path => $names ) {
            $lines[] = $path . ' (' . implode( ', ', $names ) . ')';
        }
        $this->assertSame( [], $lines, "recipe paths with no export or render behind them:\n" . implode( "\n", $lines ) );
        // The registry is path-only: a path proven on one module can be dead on another (fixtures/shapes/dead-on-module.txt).
        $dead = DeadOnModule::load();
        $killed = [];
        foreach ( $markups as $name => $served ) {
            foreach ( $dead->violations( $served ) as $v ) {
                $killed[] = $name . ': ' . $v;
            }
        }
        $this->assertSame( [], $killed, "served recipes use paths that are dead on their module:\n" . implode( "\n", $killed ) );
        // The frozen list of recipe paths still awaiting an export may only shrink: an entry that is now registered
        // (or no longer used by any recipe) must be deleted from it.
        $this->assertSame( [], $problems['stale'], "remove from ungrounded-recipe-paths.txt:\n" . implode( "\n", $problems['stale'] ) );
    }

    /**
     * A recipe uses leaves, so a recipe path counts only when it is a registered leaf (a registered prefix is not enough).
     *
     * @param array<string,string> $markups recipe name => markup
     * @param array<string,int>    $pending the frozen list of recipe paths awaiting an export
     * @return array{missing:array<string,list<string>>,stale:list<string>}
     */
    private function recipeProblems( ShapeRegistry $registry, array $markups, array $pending ): array
    {
        $missing = [];
        $seen    = [];
        foreach ( $markups as $name => $markup ) {
            foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                $seen[ $path ] = true;
                if ( ! $registry->coversLeaf( $path ) && ! isset( $pending[ $path ] ) ) {
                    $missing[ $path ][] = (string) $name;
                }
            }
        }
        $stale = [];
        foreach ( array_keys( $pending ) as $path ) {
            if ( ! isset( $seen[ $path ] ) || $registry->coversLeaf( $path ) ) {
                $stale[] = (string) $path;
            }
        }

        return [ 'missing' => $missing, 'stale' => $stale ];
    }

    /**
     * @param array<string,int> $frozen
     * @return list<string> guide paths neither proven nor frozen
     */
    private function newGuidePaths( ShapeRegistry $registry, string $guideText, array $frozen ): array
    {
        $new = [];
        foreach ( ShapePaths::fromGuideText( $guideText ) as $path ) {
            if ( ! $registry->covers( $path ) && ! isset( $frozen[ $path ] ) ) {
                $new[] = $path;
            }
        }

        return $new;
    }

    /** @param list<string> $paths */
    private function fakeRegistry( array $paths ): ShapeRegistry
    {
        $file = tempnam( sys_get_temp_dir(), 'aied-registry-' );
        $this->assertNotFalse( $file );
        $entries = array_map( static fn ( string $p ): array => [ 'path' => $p, 'how' => 'export', 'evidence' => 'fixtures/valid/page-17-newpage.json' ], $paths );
        file_put_contents( $file, json_encode( [ 'divi' => 'x', 'shapes' => $entries ] ) );
        $registry = ShapeRegistry::load( $file );
        unlink( $file );

        return $registry;
    }

    public function testCoversLeafRejectsAProperPrefixThatCoversAccepts(): void
    {
        $registry = $this->fakeRegistry( [ 'module.decoration.spacing.{bp}.value.padding.top' ] );
        $this->assertTrue( $registry->coversLeaf( 'module.decoration.spacing.{bp}.value.padding.top' ) );
        $this->assertFalse( $registry->coversLeaf( 'module.decoration.spacing.{bp}.value.padding' ), 'a container is not a leaf' );
        $this->assertFalse( $registry->coversLeaf( 'module.decoration' ) );
        $this->assertFalse( $registry->coversLeaf( 'module.decoration.spacing.{bp}.value.padding.top.extra' ) );
        $this->assertTrue( $registry->covers( 'module.decoration.spacing.{bp}.value.padding' ), 'covers() still accepts a container, for guide text' );
        // innerContent is not trimmed for leaves: only an exactly registered path counts.
        $inner = $this->fakeRegistry( [ 'title.innerContent' ] );
        $this->assertTrue( $inner->coversLeaf( 'title.innerContent' ) );
        $this->assertFalse( $inner->coversLeaf( 'title.innerContent.desktop' ) );
    }

    public function testAnUnregisteredRecipePathFailsTheLint(): void
    {
        $registry = $this->fakeRegistry( [ 'module.decoration.spacing.{bp}.value.padding.top' ] );
        $markup   = '<!-- wp:divi/heading {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"1px"}}}}}},"title":{"decoration":{"font":{"font":{"desktop":{"value":{"zzinvented":"x"}}}}}}} /-->';
        $found    = $this->recipeProblems( $registry, [ 'fake-recipe' => $markup ], [] );
        $this->assertSame( [ 'title.decoration.font.font.{bp}.value.zzinvented' => [ 'fake-recipe' ] ], $found['missing'] );
        // The same path frozen as ungrounded is tolerated, and then it is not stale while a recipe still uses it.
        $frozen = $this->recipeProblems( $registry, [ 'fake-recipe' => $markup ], [ 'title.decoration.font.font.{bp}.value.zzinvented' => 0 ] );
        $this->assertSame( [], $frozen['missing'] );
        $this->assertSame( [], $frozen['stale'] );
        // A frozen path that is now registered (or unused) is reported stale.
        $stale = $this->recipeProblems( $this->fakeRegistry( [ 'title.decoration.font.font.{bp}.value.zzinvented' ] ), [ 'fake-recipe' => $markup ], [ 'title.decoration.font.font.{bp}.value.zzinvented' => 0, 'unused.path' => 1 ] );
        $this->assertSame( [ 'title.decoration.font.font.{bp}.value.zzinvented', 'unused.path' ], $stale['stale'] );
    }

    public function testARecipePathThatIsOnlyAContainerOfARegisteredOneFails(): void
    {
        $registry = $this->fakeRegistry( [ 'module.decoration.spacing.{bp}.value.padding.top' ] );
        $markup   = '<!-- wp:divi/heading {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":"1px"}}}}}} /-->';
        $found    = $this->recipeProblems( $registry, [ 'prefix-recipe' => $markup ], [] );
        $this->assertSame( [ 'module.decoration.spacing.{bp}.value.padding' => [ 'prefix-recipe' ] ], $found['missing'] );
    }

    public function testANewUnregisteredGuidePathFailsTheRatchet(): void
    {
        $registry = $this->fakeRegistry( [ 'module.decoration.spacing.{bp}.value.padding.top' ] );
        $text     = 'Set `module.decoration.spacing.desktop.value.padding.top` and `module.decoration.zzinvented.desktop.value.shape`.';
        $this->assertSame( [ 'module.decoration.zzinvented.{bp}.value.shape' ], $this->newGuidePaths( $registry, $text, [] ) );
        $this->assertSame( [], $this->newGuidePaths( $registry, $text, [ 'module.decoration.zzinvented.{bp}.value.shape' => 0 ] ), 'frozen legacy paths are tolerated' );
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
        $new = $this->newGuidePaths( ShapeRegistry::load(), StyleGuide::markdown(), $this->lines( 'legacy-guide-paths.txt' ) );
        $this->assertSame( [], $new, "guide paths neither proven nor frozen as legacy:\n" . implode( "\n", $new ) );
    }

    public function testTopicPathsAreRegistered(): void
    {
        $registry = ShapeRegistry::load();
        $problems = [];
        foreach ( StyleGuide::topics() as $topic ) {
            $problems = array_merge( $problems, $this->topicProblems( $registry, $topic, StyleGuide::markdown( $topic ) ) );
        }
        $this->assertSame( [], $problems, "topic paths (4 or more segments) that are not registered:\n" . implode( "\n", $problems ) );
        $dead   = DeadOnModule::load();
        $killed = [];
        foreach ( array_merge( [ null ], StyleGuide::topics() ) as $topic ) {
            foreach ( $dead->violations( StyleGuide::markdown( $topic ) ) as $v ) {
                $killed[] = ( $topic ?? 'default guide' ) . ': ' . $v;
            }
        }
        $this->assertSame( [], $killed, "guide blocks that use paths dead on their module:\n" . implode( "\n", $killed ) );
    }

    public function testAnUnregisteredTopicPathFailsTheLint(): void
    {
        $registry = $this->fakeRegistry( [ 'module.decoration.background.{bp}.value.pattern.style' ] );
        $text     = 'Use `module.decoration.background.desktop.value.pattern.style` and `module.decoration.background.desktop.value.pattern.zzinvented`.';
        $this->assertSame(
            [ 'fake-topic: module.decoration.background.{bp}.value.pattern.zzinvented' ],
            $this->topicProblems( $registry, 'fake-topic', $text )
        );
    }

    /** @return list<string> "<topic>: <path>" for every dotted path of 4 or more segments in a topic's text that the registry does not cover */
    private function topicProblems( ShapeRegistry $registry, string $topic, string $text ): array
    {
        $out = [];
        foreach ( ShapePaths::fromGuideText( $text ) as $path ) {
            if ( ! $registry->covers( $path ) ) {
                $out[] = $topic . ': ' . $path;
            }
        }
        return $out;
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

    /** @return array<string,int> the non-empty lines of a file in fixtures/shapes that are not `#` comments, as a set */
    private function lines( string $file ): array
    {
        $lines = file( __DIR__ . '/../fixtures/shapes/' . $file, FILE_IGNORE_NEW_LINES );
        $this->assertIsArray( $lines, $file );
        return array_flip( array_filter( array_map( 'trim', $lines ), static fn ( string $l ): bool => $l !== '' && '#' !== $l[0] ) );
    }
}
