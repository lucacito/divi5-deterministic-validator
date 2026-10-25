<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tests\Support\CarrierAudit;
use PHPUnit\Framework\TestCase;

/**
 * The audit of the carriers adapter (plan 2026-10-24, Task 2): the 11 library blocks the adapter takes, found by their
 * decoration (unmigrated) or by their marker (migrated) so the pinned table is the same before and after Task 5, and the
 * modules that stay unadapted with their measured counts.
 */
class CarrierAuditTest extends TestCase
{
    /** key => [role, band] of the 11 carrier blocks (sorted by key when compared: the file's recipe order is not the contract). */
    private const BLOCKS = [
        'blog-feed|divi/divider|0'               => [ 'rule-accent', 'light' ],
        'icon-values|divi/divider|0'             => [ 'rule-accent', 'light' ],
        'testimonial|divi/testimonial|0'         => [ 'panel', 'light' ],
        'blurb-grid|divi/blurb|0'                => [ 'chip', 'surface' ],
        'blurb-grid|divi/blurb|1'                => [ 'chip', 'surface' ],
        'blurb-grid|divi/blurb|2'                => [ 'chip', 'surface' ],
        'blurb-grid|divi/blurb|3'                => [ 'chip', 'surface' ],
        'stats-counter-band|divi/number-counter|0' => [ 'rule-strong', 'accent' ],
        'stats-counter-band|divi/number-counter|1' => [ 'rule-strong', 'accent' ],
        'stats-counter-band|divi/number-counter|2' => [ 'rule-strong', 'accent' ],
        'stats-counter-band|divi/number-counter|3' => [ 'rule-strong', 'accent' ],
    ];

    /** key => [role, band] of the four planted carriers (sorted by key). */
    private const PLANTED = [
        'planted|divi/blurb|0'          => [ 'chip', 'surface' ],
        'planted|divi/divider|0'        => [ 'rule-accent', 'surface' ],
        'planted|divi/number-counter|0' => [ 'rule-strong', 'surface' ],
        'planted|divi/testimonial|0'    => [ 'panel', 'surface' ],
    ];

    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $rows = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $rows );

        return $rows;
    }

    /** @return list<array{name:string,markup:string}> */
    private static function one( string $markup ): array
    {
        return [ [ 'name' => 'planted', 'markup' => $markup ] ];
    }

    /** @param array<string,mixed> $attrs */
    private static function block( string $name, array $attrs, string $inner = '' ): string
    {
        $json = json_encode( [] === $attrs ? new \stdClass() : $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return '' === $inner ? "<!-- wp:divi/{$name} {$json} /-->" : "<!-- wp:divi/{$name} {$json} -->{$inner}<!-- /wp:divi/{$name} -->";
    }

    /** @return array<string,mixed> */
    private static function fill( string $role ): array
    {
        return [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:' . $role . '}}' ] ] ] ];
    }

    /** @return array<string,mixed> */
    private static function dividerLine( string $role ): array
    {
        return [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:' . $role . '}}' ] ] ] ] ] ];
    }

    /** @return array<string,mixed> a counter border: `all` 0px and a `top` width. */
    private static function counterBorder( string $role, string $width = '1px' ): array
    {
        return [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [
            'all' => [ 'width' => '0px', 'color' => '{{aied:color:' . $role . '}}', 'style' => 'solid' ],
            'top' => [ 'width' => $width ],
        ] ] ] ] ] ] ];
    }

    /** @return array<string,array{0:string,1:string}> */
    private static function table( array $rows ): array
    {
        $t = [];
        foreach ( $rows as $r ) {
            $t[ CarrierAudit::key( $r ) ] = [ $r['role'], $r['on'] ];
        }
        ksort( $t );

        return $t;
    }

    public function testTheAuditFindsTheElevenCarrierBlocks(): void
    {
        $rows = CarrierAudit::blocks( self::library() );
        $expected = self::BLOCKS;
        ksort( $expected );
        $this->assertSame( $expected, self::table( $rows ) );
        $this->assertCount( 11, $rows );
        $this->assertCount( 5, array_unique( array_column( $rows, 'recipe' ) ) );
        foreach ( $rows as $r ) {
            // Carriers adapter Task 5 migrated the library (scripts/migrate-carrier-markers.php): the pinned table holds with marked=true.
            $this->assertTrue( $r['marked'], CarrierAudit::key( $r ) . ': the library is migrated since Task 5; every carrier block carries its marker' );
        }
    }

    public function testTheUnadaptedModulesAreCountedAndHaveAReason(): void
    {
        $this->assertSame( array_keys( CarrierAudit::UNADAPTED ), array_keys( CarrierAudit::UNADAPTED_COUNTS ) );
        $this->assertCount( 10, CarrierAudit::UNADAPTED );
        foreach ( CarrierAudit::UNADAPTED as $module => $reason ) {
            $this->assertNotSame( '', trim( $reason ), "$module has no reason" );
        }
        $this->assertSame( CarrierAudit::UNADAPTED_COUNTS, CarrierAudit::unadaptedSeen( self::library() ), 'blocks and recipes of every unadapted module, measured from the file' );
        $this->assertSame( [ 0, 0 ], CarrierAudit::UNADAPTED_COUNTS['divi/signup'], 'divi/signup: declined, nothing in the library' );
        // The slide count must not swallow the slider's blocks (prefix trap).
        $this->assertSame( [ 6, 2 ], CarrierAudit::UNADAPTED_COUNTS['divi/slide'] );
        $this->assertSame( [ 2, 2 ], CarrierAudit::UNADAPTED_COUNTS['divi/slider'] );
    }

    public function testTheAuditClassifiesPlantedBlocks(): void
    {
        $ground = static fn ( string $inner ): string => self::block( 'section', [ 'module' => [ 'decoration' => self::fill( 'surface' ) ] ], $inner );

        // Targets: an accent divider, a filled testimonial, a blurb with an icon fill, a counter with a top rule.
        $markup = $ground(
            self::block( 'divider', self::dividerLine( 'accent' ) )
            . self::block( 'testimonial', [ 'module' => [ 'decoration' => self::fill( 'bg' ) ] ] )
            . self::block( 'blurb', [ 'imageIcon' => [ 'decoration' => self::fill( 'accent' ) ] ] )
            . self::block( 'number-counter', self::counterBorder( 'accent-text' ) )
        );
        $this->assertSame( self::PLANTED, self::table( CarrierAudit::blocks( self::one( $markup ) ) ) );

        // Not targets: a border-coloured divider, a hidden accent divider, a counter with no rule (or a rule with a side
        // border), a testimonial without a fill, a blurb without an icon fill.
        $not = $ground(
            self::block( 'divider', self::dividerLine( 'border' ) )
            . self::block( 'divider', array_replace_recursive( self::dividerLine( 'accent' ), [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'show' => 'off' ] ] ] ] ] ] ) )
            . self::block( 'number-counter', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'x' ] ] ] ] )
            . self::block( 'number-counter', self::counterBorder( 'accent-text', '0px' ) )
            . self::block( 'testimonial', [ 'author' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'x' ] ] ] ] )
            . self::block( 'blurb', [ 'imageIcon' => [ 'advanced' => [ 'color' => [ 'desktop' => [ 'value' => '{{aied:color:accent-text}}' ] ] ] ] ] )
        );
        $this->assertSame( [], CarrierAudit::blocks( self::one( $not ) ) );

        // A counter that paints its own ground is a card, not a rule.
        $filled = self::counterBorder( 'accent-text' );
        $filled['module']['decoration'] += self::fill( 'surface' );
        $this->assertSame( [], CarrierAudit::blocks( self::one( self::block( 'number-counter', $filled ) ) ) );

        // A quiet counter rule (border or an alpha token) is `rule`; any other colour is `rule-strong`.
        foreach ( [ 'border' => 'rule', 'accent-text:40' => 'rule', 'text' => 'rule-strong', 'accent-text' => 'rule-strong' ] as $colour => $role ) {
            $rows = CarrierAudit::blocks( self::one( self::block( 'number-counter', self::counterBorder( $colour ) ) ) );
            $this->assertCount( 1, $rows, $colour );
            $this->assertSame( $role, $rows[0]['role'], $colour );
        }
    }

    public function testTheListIsTheSameWhenTheBlocksAreMarked(): void
    {
        $ground = static fn ( string $inner ): string => self::block( 'section', [ 'module' => [ 'decoration' => self::fill( 'surface' ) ] ], $inner );
        $marked = $ground(
            self::block( 'divider', [ 'aied' => [ 'kit' => 'rule-accent', 'on' => 'surface' ] ] )
            . self::block( 'testimonial', [ 'aied' => [ 'kit' => 'panel', 'on' => 'surface' ] ] )
            . self::block( 'blurb', [ 'aied' => [ 'kit' => 'chip', 'on' => 'surface' ] ] )
            . self::block( 'number-counter', [ 'aied' => [ 'kit' => 'rule-strong', 'on' => 'surface' ] ] )
        );
        $rows = CarrierAudit::blocks( self::one( $marked ) );
        $this->assertSame( self::PLANTED, self::table( $rows ) );
        foreach ( $rows as $r ) {
            $this->assertTrue( $r['marked'], CarrierAudit::key( $r ) );
        }
        // A marker that is not a role of that carrier is not a carrier (rule on a divider is the rules adapter's).
        $this->assertSame( [], CarrierAudit::blocks( self::one( self::block( 'divider', [ 'aied' => [ 'kit' => 'rule', 'on' => 'light' ] ] ) ) ) );
        $this->assertSame( [], CarrierAudit::blocks( self::one( self::block( 'testimonial', [ 'aied' => [ 'kit' => 'chip', 'on' => 'light' ] ] ) ) ) );
    }
}
