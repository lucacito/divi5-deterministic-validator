<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $carrierBudgetClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $carrierBudgetClass . '.php';
}

/**
 * Size budgets of the carriers adapter (plan docs/superpowers/plans/2026-10-24-carriers-adapter.md, Task 0).
 *
 * Measured at feat/carriers-adapter f1a370a; every number equals the plan's table. Everything the carriers adapter does not
 * own must not move: the default guide, the topic description and every style-kit:<id> topic. Task 3 tightens kit-rules,
 * the catalog and controls to exact equalities once the generated wording exists; until then they only have an upper bound.
 */
final class CarrierBudgetTest extends TestCase
{
    /** Bytes of each style-kit:<id> topic at f1a370a (largest: neo-brutal, 9,599). */
    private const KIT_TOPIC_SIZES = [
        'quiet-solid'         => 8232,
        'glass-edge'          => 8230,
        'warm-pill'           => 8255,
        'hairline-mono'       => 8116,
        'chunky-pill'         => 8124,
        'steady-solid'        => 8236,
        'soft-rounded'        => 8136,
        'heavy-block'         => 8210,
        'raw-frame'           => 8109,
        'gilt-hairline'       => 8118,
        'soft-solid'          => 8191,
        'outline-2'           => 8145,
        'warm-pill-soft'      => 9259,
        'chunky-pill-offset'  => 9307,
        'hairline-dashed'     => 8124,
        'underline-link'      => 8779,
        'neo-brutal'          => 9599,
        'double-frame'        => 8206,
        'soft-lift'           => 9215,
        'ghost-swap'          => 8107,
        'dotted-pill'         => 8207,
        'hairline-frame-caps' => 8180,
        'offset-frame'        => 9320,
        'soft-hairline-caps'  => 8205,
        'thick-frame-caps'    => 8189,
        'thick-rounded-caps'  => 8219,
        'rimmed-solid'        => 8025,
        'flat-square'         => 8303,
        'block-caps'          => 8297,
        'pill-caps'           => 8345,
        'outline-pill'        => 8174,
        'outline-soft'        => 8153,
        'thick-frame'         => 8128,
        'raised-square'       => 9425,
        'offset-block-caps'   => 9590,
        'text-link'           => 8115,
        'text-link-caps'      => 8148,
        'underlined-label'    => 8892,
        'pressable'           => 9575,
        'hairline-halo'       => 9294,
    ];

    public function testTheDefaultGuideAndTheTopicDescriptionDoNotMove(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ), 'no topic name is added' );
    }

    public function testTheKitTopicsDoNotMove(): void
    {
        $this->assertCount( 40, self::KIT_TOPIC_SIZES );
        $this->assertSame( array_keys( self::KIT_TOPIC_SIZES ), StyleKits::ids(), 'the same 40 kits, in order' );
        $max = 0;
        foreach ( self::KIT_TOPIC_SIZES as $id => $size ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertSame( $size, $len, "$id: a kit topic does not move" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9599, $max, 'the largest kit topic (neo-brutal) is unchanged' );
    }

    public function testKitRulesStaysWithinItsPin(): void
    {
        $this->assertLessThanOrEqual( 9600, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules (Ruling 8: pin 9,000 -> 9,600; the real bound is 10,000)' );
    }

    public function testTheCatalogProjectionStaysWithinItsBound(): void
    {
        $property = new \ReflectionProperty( StyleKits::class, 'cache' );
        $real     = array_map( static fn ( string $id ): array => (array) StyleKits::get( $id ), StyleKits::ids() );
        try {
            $kits = $real;
            for ( $n = 1; count( $kits ) < 50; $n++ ) {
                $clone          = $real[ $n % count( $real ) ];
                $clone['id']    = 'extra-kit-' . $n;
                $clone['title'] = 'Extra kit ' . $n;
                $kits[]         = $clone;
            }
            $property->setValue( null, $kits );
            $this->assertCount( 50, StyleKits::ids() );
            $this->assertLessThanOrEqual( 9950, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the 50-kit catalog' );
        } finally {
            StyleKits::clearCache();
        }
    }

    public function testTheRecipeFileDoesNotGrow(): void
    {
        $this->assertLessThanOrEqual( 652430, filesize( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), 'section-recipes.json (the migration only shrinks it)' );
    }
}
