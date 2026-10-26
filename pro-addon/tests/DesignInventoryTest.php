<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 0: the facts every later task relies on, measured from the free plugin's own data and pinned.
 * A free-plugin change that moves one of these numbers fails here first (spec 2026-10-25-pro-design-switch-design.md,
 * section 3; plan Task 0). Nothing here writes or resolves anything: it only reads.
 */
class DesignInventoryTest extends TestCase
{
    /** Colour role => the key of the language's `colors` map (`surface-glass` counts only where `surface_glass` is set). */
    private const COLOR_KEYS = [
        'bg'            => 'bg',
        'surface'       => 'surface',
        'surface-glass' => 'surface_glass',
        'text'          => 'text',
        'muted'         => 'muted',
        'accent'        => 'accent',
        'accent-text'   => 'accent_text',
        'border'        => 'border',
        'inverse'       => 'inverse',
        'overlay'       => 'overlay',
    ];

    /** Spec section 3: [ colour roles sharing a value, font roles sharing a family, type triples that are equal ]. */
    private const COLLISIONS = [
        'editorial'       => [ 'surface=accent-text', 'body=eyebrow', '-' ],
        'glass-dark'      => [ 'bg=accent-text', 'display=body=eyebrow', '-' ],
        'organic-warm'    => [ '-', 'body=eyebrow', '-' ],
        'tech-mono'       => [ '-', 'body=eyebrow', '-' ],
        'playful-pastel'  => [ 'surface=inverse, text=accent-text', 'display=eyebrow', '-' ],
        'corporate-trust' => [ 'bg=accent-text', 'display=body=eyebrow', 'display=title' ],
        'soft-saas'       => [ 'surface=accent-text', 'body=eyebrow', '-' ],
        'bold-vibrant'    => [ 'surface=accent-text=inverse, text=border', 'body=eyebrow', '-' ],
        'brutalist-flat'  => [ 'surface=inverse, text=accent-text=border', '-', '-' ],
        'luxe'            => [ '-', 'body=eyebrow', '-' ],
    ];

    /** Plan Task 0 (f): the 14 recipes that between them hold every kit marker and the media token. */
    private const COVER_SET = [
        'pricing-three-tier', 'features-bento', 'pricing-two-plan-highlight', 'icon-values', 'list-icon-dividers',
        'portfolio-featured-grid', 'blurb-grid', 'contact-form', 'testimonial', 'features-accordion-image',
        'proof-press-quotes', 'stats-counter-band', 'faq-accordion', 'faq-toggle-list',
    ];

    /** @return list<string> the shipped recipe names */
    private static function recipes(): array
    {
        return SectionRecipes::names();
    }

    public function testTheCollisionTableIsTheMeasuredOne(): void
    {
        $this->assertSame( array_keys( self::COLLISIONS ), DesignLanguages::ids(), 'ten languages, in this order' );
        $this->assertCount( 10, self::COLLISIONS );

        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertIsArray( $lang, $id );

            $byColour = [];
            foreach ( self::COLOR_KEYS as $role => $key ) {
                $value = $lang['colors'][ $key ] ?? null;
                if ( is_string( $value ) && '' !== $value ) {
                    $byColour[ strtolower( $value ) ][] = $role;
                }
            }
            $colours = [];
            foreach ( $byColour as $roles ) {
                if ( count( $roles ) > 1 ) {
                    $colours[] = implode( '=', $roles );
                }
            }

            $byFont = [];
            foreach ( [ 'display', 'body', 'eyebrow' ] as $role ) {
                $byFont[ (string) $lang['fonts'][ $role ] ][] = $role;
            }
            $fonts = [];
            foreach ( $byFont as $roles ) {
                if ( count( $roles ) > 1 ) {
                    $fonts[] = implode( '=', $roles );
                }
            }

            $types = [];
            foreach ( [ 'display', 'title', 'eyebrow' ] as $a ) {
                foreach ( [ 'display', 'title', 'eyebrow' ] as $b ) {
                    if ( $a < $b && $lang['type'][ $a ] == $lang['type'][ $b ] ) {
                        $types[] = $a . '=' . $b;
                    }
                }
            }

            $this->assertSame(
                self::COLLISIONS[ $id ],
                [ implode( ', ', $colours ) ?: '-', implode( ', ', $fonts ) ?: '-', implode( ', ', $types ) ?: '-' ],
                $id
            );
        }
    }

    public function testTheRecipeTokenInventory(): void
    {
        $this->assertCount( 71, self::recipes() );

        $colour = 0;
        $colourAt = [];
        $alpha = 0;
        $font = 0;
        $fontAt = [];
        $media = 0;
        $markers = 0;
        $anyToken = 0;
        foreach ( self::recipes() as $name ) {
            $raw = SectionRecipes::rawMarkup( $name );
            $this->assertIsString( $raw, $name );
            preg_match_all( '/"([A-Za-z]+)":"\{\{aied:(color|font):([a-z-]+)(:\d+)?\}\}"/', $raw, $m, PREG_SET_ORDER );
            foreach ( $m as $x ) {
                if ( 'color' === $x[2] ) {
                    ++$colour;
                    $colourAt[ $x[1] ] = ( $colourAt[ $x[1] ] ?? 0 ) + 1;
                    if ( '' !== ( $x[4] ?? '' ) ) {
                        ++$alpha;
                    }
                } else {
                    ++$font;
                    $fontAt[ $x[1] ] = ( $fontAt[ $x[1] ] ?? 0 ) + 1;
                }
            }
            $anyToken += (int) preg_match_all( '/\{\{aied:(?:color|font):/', $raw );
            $media += substr_count( $raw, '{{aied:kit:radius-media}}' );
            $markers += (int) preg_match_all( '/"aied":\{/', $raw );
        }

        $this->assertSame( 860, $colour, 'colour tokens (whole values)' );
        $this->assertSame( [ 'color' => 781, 'value' => 79 ], $colourAt, 'at keys color and value only' );
        $this->assertSame( 0, $alpha, 'no alpha colour token in a recipe body' );
        $this->assertSame( 628, $font, 'font tokens' );
        $this->assertSame( [ 'family' => 628 ], $fontAt, 'all at key family' );
        $this->assertSame( 860 + 628, $anyToken, 'no colour or font token sits anywhere but a whole value at those keys' );
        $this->assertSame( 11, $media, '{{aied:kit:radius-media}} image tokens' );
        $this->assertSame( 405, $markers, 'aied markers (kit, type, rhythm)' );
    }

    /**
     * @return array{items:array<string,list<string>>, byRecipe:array<string,array<string,true>>}
     *         items: item => recipes holding it, where an item is "block|role|ground" or "media"
     */
    private static function kitItems(): array
    {
        $byRecipe = [];
        foreach ( self::recipes() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            preg_match_all( BlockAttrs::BLOCK, $raw, $m, PREG_SET_ORDER );
            foreach ( $m as $x ) {
                $attrs = json_decode( $x[3] ?? $x[2], true );
                if ( is_array( $attrs ) && isset( $attrs['aied']['kit'] ) ) {
                    $byRecipe[ $name ][ $x[2] . '|' . $attrs['aied']['kit'] . '|' . ( $attrs['aied']['ground'] ?? '-' ) ] = true;
                }
            }
            if ( str_contains( $raw, '{{aied:kit:radius-media}}' ) ) {
                $byRecipe[ $name ]['media'] = true;
            }
        }
        $items = [];
        foreach ( $byRecipe as $name => $set ) {
            foreach ( array_keys( $set ) as $item ) {
                $items[ $item ][] = $name;
            }
        }
        ksort( $items );

        return [ 'items' => $items, 'byRecipe' => $byRecipe ];
    }

    public function testTheCoverSetCoversEveryKitMarker(): void
    {
        $k = self::kitItems();

        // 24 distinct (block, role, ground) kit markers, plus the media token = 25 items to cover.
        $markers = array_filter( array_keys( $k['items'] ), static fn ( string $i ): bool => 'media' !== $i );
        $this->assertCount( 24, $markers, 'distinct (block, role, ground) kit markers in the library' );
        $this->assertArrayHasKey( 'media', $k['items'] );
        $this->assertCount( 25, $k['items'] );

        $this->assertCount( 14, self::COVER_SET );
        $have = [];
        foreach ( self::COVER_SET as $recipe ) {
            $this->assertArrayHasKey( $recipe, $k['byRecipe'], "{$recipe} holds a kit marker or the media token" );
            $have += $k['byRecipe'][ $recipe ];
        }
        $this->assertSame( [], array_values( array_diff( array_keys( $k['items'] ), array_keys( $have ) ) ), 'every item is held by a cover recipe' );

        // The set is minimal: the greedy cover picks exactly these 14, in this order, and none can be dropped.
        $remaining = array_keys( $k['items'] );
        $picked = [];
        while ( [] !== $remaining ) {
            $best = null;
            $bestCount = -1;
            foreach ( $k['byRecipe'] as $recipe => $set ) {
                $c = count( array_intersect( $remaining, array_keys( $set ) ) );
                if ( $c > $bestCount ) {
                    $bestCount = $c;
                    $best = $recipe;
                }
            }
            $picked[] = $best;
            $remaining = array_values( array_diff( $remaining, array_keys( $k['byRecipe'][ $best ] ) ) );
        }
        $this->assertSame( self::COVER_SET, $picked, 'the greedy cover of the library is the pinned set' );
    }

    public function testTheOpenApiBudgetIsUsedUp(): void
    {
        $free = 0;
        foreach ( OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '9.9.9' )['paths'] as $path => $methods ) {
            if ( ! str_starts_with( (string) $path, '/pro/' ) ) {
                $free += count( $methods );
            }
        }
        $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
        $pro      = count( ( new ProOpenApi( $registry ) )->paths( [], 'https://example.com' ) );

        $this->assertSame( 16, $free, 'free OpenAPI operations' );
        $this->assertSame( 14, $pro, 'Pro OpenAPI operations' );
        $this->assertSame( 30, $free + $pro, 'the ChatGPT importer\'s 30 operations are used up: a new Pro tool is MCP + REST only' );
    }

    public function testTheToolCountTodayAndTheHistoryCap(): void
    {
        // 21 Pro tools at Task 0 (check_page_render of 2026-10-25-pro-render-check.md has not merged); Task 8 moved every
        // count by exactly one when switch_design joined: 22 tools, the 8 in MCP_REST_ONLY are not in the spec.
        $this->assertCount( 22, Tools::all() );
        $this->assertCount( 8, ProOpenApi::MCP_REST_ONLY );
        $this->assertSame( 524288, PageHistory::MAX_BYTES, 'a snapshot, and so a restorable page, is at most 512 KB' );
    }
}
