<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Dogfood\Mutate;
use Divi5Validator\Tests\Support\Dogfood\Prepare;
use Divi5Validator\Tests\Support\Dogfood\Scorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scorer against pages built by the real kit pipeline (dogfood plan 2026-10-22, Task 2): a conformance page (the edit base
 * plus tags, icon chips and dividers, so all seven categories are present) must score full marks in every category under every
 * kit, and a change to ONE attribute of ONE block must flip exactly the check that owns it. A cell that does not score full
 * marks is a finding to report, never something to exempt.
 */
class DogfoodScorerMutationTest extends TestCase
{
    /** The recipes of the conformance page: the edit base plus tags, icon chips and dividers. */
    private const EXTRA = [ 'portfolio-featured-grid', 'features-carousel-strip', 'list-icon-dividers' ];

    /** @var array<string,string> */
    private static array $pages = [];

    /** The conformance page of a kit under a language, built once. */
    private static function conformance( string $kit, string $language ): string
    {
        return self::$pages[ $kit . '|' . $language ] ??= Prepare::recipePage( array_merge( Prepare::EDIT_RECIPES, self::EXTRA ), $kit, $language );
    }

    public function testConformancePagesScoreFullMarksInEveryCategory(): void
    {
        $cells = 0;
        foreach ( StyleKits::ids() as $kit ) {
            foreach ( [ 'editorial', 'glass-dark' ] as $language ) {
                $report = ( new Scorer( $kit, $language ) )->score( self::conformance( $kit, $language ), "$kit/$language" );
                foreach ( Scorer::CATEGORIES as $category ) {
                    $c = $report['categories'][ $category ];
                    $this->assertGreaterThan( 0, $c['checked'], "$kit under $language: $category is present on the conformance page" );
                    $this->assertSame( $c['checked'], $c['passed'], "$kit under $language: every $category check passes" );
                }
                $this->assertSame( [], $report['failures'], "$kit under $language: no failure" );
                $this->assertSame( 100.0, $report['overall']['score'] );
                ++$cells;
            }
        }
        $this->assertSame( 80, $cells );
    }

    public function testTheConformanceUnclassifiedListIsPinned(): void
    {
        // The hero's fill-only column (a background panel) and the pricing [Most popular] badge (a filled pill with a radius,
        // no frame and only a text) are shape and brand decisions of the recipes, not kit roles; both are meant to be unclassified.
        $report = ( new Scorer( 'glass-edge', 'glass-dark' ) )->score( self::conformance( 'glass-edge', 'glass-dark' ) );
        $this->assertSame( [ 'divi/column#0', 'divi/group#10' ], array_column( $report['unclassified'], 'block' ) );
        $this->assertGreaterThan( 50, $report['ignored'], 'plain wrappers are only counted' );
    }

    public function testMutateTouchesOnlyTheAddressedBlocksAttributeJson(): void
    {
        $a    = '{"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"10px"}}}}}},"builderVersion":"5.8.0","empty":{}}';
        $b    = '{"x":{"y":"\u003cb\u003e\u0026\u003c/b\u003e"}}'; // as WordPress writes block attributes
        $page = "<!-- wp:divi/placeholder -->\n<!-- wp:divi/group $a -->\n  <!-- wp:divi/text /-->\n  <!-- wp:divi/text $b /-->\n<!-- /wp:divi/group -->\n<!-- /wp:divi/placeholder -->";

        $set = Mutate::set( $page, 'divi/text#1', 'x.y', 'new' );
        $this->assertSame( str_replace( $b, '{"x":{"y":"new"}}', $page ), $set, 'only the second text block changed (the first, without attributes, still counts in the numbering)' );
        $this->assertSame( 'new', Mutate::get( $set, 'divi/text#1', 'x.y' ) );
        $this->assertNull( Mutate::get( $page, 'divi/text#1', 'x.nope' ) );

        $made = Mutate::set( $page, 'divi/group#0', 'module.decoration.border.desktop.value.radius.topLeft', '7px' );
        $this->assertStringContainsString( '"empty":{}', $made, 'an empty object stays an object' );
        $this->assertStringContainsString( '"border":{"desktop":{"value":{"radius":{"topLeft":"7px"}}}}', $made, 'missing steps are created' );
        $this->assertStringContainsString( "\n  <!-- wp:divi/text /-->\n", $made, 'every other byte stays' );

        $gone = Mutate::remove( $page, 'divi/group#0', 'module.decoration.spacing' );
        $this->assertStringNotContainsString( 'spacing', $gone );
        $this->assertSame( $page, Mutate::set( $page, 'divi/text#1', 'x.y', Mutate::get( $page, 'divi/text#1', 'x.y' ) ), 'setting the same value re-encodes to the same bytes' );

        foreach ( [
            static fn () => Mutate::set( $page, 'divi/text#2', 'a', 1 ),
            static fn () => Mutate::set( $page, 'divi/text#0', 'a', 1 ),
            static fn () => Mutate::remove( $page, 'divi/group#0', 'module.nope' ),
            static fn () => Mutate::remove( $page, 'divi/group#0', 'nope.deeper' ),
            static fn () => Mutate::set( $page, 'bogus', 'a', 1 ),
        ] as $bad ) {
            try {
                $bad();
                $this->fail( 'a mutation of something that is not there is refused' );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertNotSame( '', $e->getMessage() );
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Mutations
    // ---------------------------------------------------------------------------------------------------------

    private const COMBOS = [
        'glass-edge'     => 'glass-dark',
        'underline-link' => 'editorial',
        'double-frame'   => 'editorial',
    ];

    private const BORDER = 'module.decoration.border.desktop.value';

    /**
     * mutation name => [the check that must flip, the check whose presence picks the block, the mutation].
     * A mutation is a closure (page, block key) => the mutated page, or null when this kit has no such subject.
     *
     * @return array<string,array{0:string,1:string,2:callable(string,string):?string}>
     */
    private static function mutations(): array
    {
        $plus7 = static function ( string $page, string $key, string $path ): ?string {
            $r = Mutate::get( $page, $key, $path );
            if ( ! is_array( $r ) ) {
                return null;
            }
            $out = [];
            foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $c ) {
                $out[ $c ] = ( (float) ( $r[ $c ] ?? 0 ) + 7 ) . 'px';
            }

            return Mutate::set( $page, $key, $path, $out );
        };
        /** The dot path of the style of the first visible side of a border value (`styles.<side>.style`, or `styles.all.style`). */
        $stylePath = static function ( string $page, string $key, string $border ): string {
            $styles = Mutate::get( $page, $key, $border . '.styles' );
            $styles = is_array( $styles ) ? $styles : [];
            $width  = static fn ( string $side ) => $styles[ $side ]['width'] ?? $styles['all']['width'] ?? '0px';
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( (float) $width( $side ) > 0 ) {
                    return $border . '.styles.' . ( isset( $styles[ $side ]['style'] ) ? $side : 'all' ) . '.style';
                }
            }

            return $border . '.styles.all.style';
        };
        $otherStyle = static function ( string $page, string $key, string $path ): string {
            $now = Mutate::get( $page, $key, $path );
            foreach ( StyleKits::BORDER_STYLES as $style ) {
                if ( $style !== $now ) {
                    return $style;
                }
            }
            return 'solid';
        };
        $restyle = static function ( string $border ) use ( $stylePath, $otherStyle ): callable {
            return static function ( string $page, string $key ) use ( $border, $stylePath, $otherStyle ): ?string {
                $path = $stylePath( $page, $key, $border );

                return Mutate::set( $page, $key, $path, $otherStyle( $page, $key, $path ) );
            };
        };
        $radius = static fn ( string $path ): callable => static fn ( string $page, string $key ): ?string => $plus7( $page, $key, $path );
        $set    = static fn ( string $path, mixed $value ): callable => static fn ( string $page, string $key ): ?string => Mutate::set( $page, $key, $path, $value );

        $divider = 'divider.advanced.line.desktop.value';

        return [
            'a button corner radius'            => [ 'button.radius', 'button.radius', $radius( 'button.decoration.border.desktop.value.radius' ) ],
            'a button visible-side style'       => [ 'button.edge-style', 'button.edge-style', $restyle( 'button.decoration.border.desktop.value' ) ],
            'a top-rule group top width'        => [ 'rule.width', 'rule.width@group', $set( self::BORDER . '.styles.top.width', '7px' ) ],
            'a divider weight'                  => [ 'rule.width', 'rule.width@divider', $set( $divider . '.weight', '7px' ) ],
            'a divider style'                   => [ 'rule.style', 'rule.style@divider', static fn ( string $p, string $k ): ?string => Mutate::set( $p, $k, $divider . '.style', $otherStyle( $p, $k, $divider . '.style' ) ) ],
            'a divider colour'                  => [ 'rule.colour', 'rule.colour@divider', $set( $divider . '.color', '#123456' ) ],
            'a tag radius'                      => [ 'chip.radius', 'chip.frame', $radius( self::BORDER . '.radius' ) ],
            'an icon chip radius'               => [ 'chip.radius', 'chip.fill-colour', $radius( self::BORDER . '.radius' ) ],
            'a framed card frame removed'       => [ 'framed.frame', 'framed.frame-style@round', static fn ( string $p, string $k ): ?string => Mutate::remove( $p, $k, self::BORDER . '.styles' ) ],
            'a framed card radius'              => [ 'framed.radius', 'framed.radius', $radius( self::BORDER . '.radius' ) ],
            'a framed card frame style'         => [ 'framed.frame-style', 'framed.frame-style', $restyle( self::BORDER ) ],
            'the accordion radius'              => [ 'item.radius', 'item.radius', $radius( self::BORDER . '.radius' ) ],
            'the accordion frame style'         => [ 'item.frame-style', 'item.frame-style', $restyle( self::BORDER ) ],
            'the form field radius'             => [ 'form.field-radius', 'form.field-radius', $radius( 'field.decoration.border.desktop.value.radius' ) ],
            'the form submit style'             => [ 'form.button-edge-style', 'form.button-edge-style', $restyle( 'button.decoration.border.desktop.value' ) ],
            'a media-radius image radius'       => [ 'image.radius', 'image.radius@radius', static fn ( string $p, string $k ): ?string => Mutate::set( $p, $k, 'image.decoration.border.desktop.value.radius', [ 'topLeft' => '7px', 'topRight' => '7px', 'bottomRight' => '7px', 'bottomLeft' => '7px' ] ) ],
        ];
    }

    /**
     * The first block of the page that the scorer checks with $needle ("check" or "check@kind"): kind `group` = a group or column,
     * `divider` = a divider, `round` = a framed block with a radius (so it stays classified without its frame), `radius` = an
     * image that carries a radius.
     *
     * @param list<array{check:string,block:string,role:string,band:string,pass:bool,expected:string,observed:string}> $details
     */
    private static function findBlock( string $page, array $details, string $needle ): ?string
    {
        [ $check, $kind ] = array_pad( explode( '@', $needle, 2 ), 2, '' );
        foreach ( $details as $row ) {
            if ( $row['check'] !== $check ) {
                continue;
            }
            $name = explode( '#', $row['block'] )[0];
            switch ( $kind ) {
                case 'group':
                    if ( 'divi/divider' === $name ) {
                        continue 2;
                    }
                    break;
                case 'divider':
                    if ( 'divi/divider' !== $name ) {
                        continue 2;
                    }
                    break;
                case 'round':
                    $r = Mutate::get( $page, $row['block'], self::BORDER . '.radius' );
                    if ( ! is_array( $r ) || (float) ( $r['topLeft'] ?? 0 ) <= 0 ) {
                        continue 2;
                    }
                    break;
                case 'radius':
                    if ( null === Mutate::get( $page, $row['block'], 'image.decoration.border.desktop.value.radius' ) ) {
                        continue 2;
                    }
                    break;
            }

            return $row['block'];
        }

        return null;
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function mutationRows(): array
    {
        $rows = [];
        foreach ( self::COMBOS as $kit => $language ) {
            $page    = self::conformance( $kit, $language );
            $details = ( new Scorer( $kit, $language ) )->details( $page );
            foreach ( self::mutations() as $name => [ , $needle ] ) {
                $key = self::findBlock( $page, $details, $needle );
                if ( null === $key ) {
                    continue; // this kit has no such subject
                }
                $rows[ "$kit/$language: $name" ] = [ $kit, $language, $name ];
            }
        }

        return $rows;
    }

    /**
     * label => [the check that judges the radius, the check that finds the block, the radius path, the kit radius that makes
     * the removal visible (null = a button: strict, an absent radius always differs)].
     */
    private const RADIUS_SUBJECTS = [
        'a button'        => [ 'button.radius', 'button.radius', 'button.decoration.border.desktop.value.radius', null ],
        'a framed card'   => [ 'framed.radius', 'framed.radius', self::BORDER . '.radius', 'card' ],
        'a tag'           => [ 'chip.radius', 'chip.frame', self::BORDER . '.radius', 'control' ],
        'an icon chip'    => [ 'chip.radius', 'chip.fill-colour', self::BORDER . '.radius', 'control' ],
        'the accordion'   => [ 'item.radius', 'item.radius', self::BORDER . '.radius', 'card' ],
        'the form field'  => [ 'form.field-radius', 'form.field-radius', 'field.decoration.border.desktop.value.radius', 'control' ],
        'the form submit' => [ 'form.button-radius', 'form.button-radius', 'button.decoration.border.desktop.value.radius', null ],
    ];

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function radiusRemovalRows(): array
    {
        $rows = [];
        foreach ( self::COMBOS as $kit => $language ) {
            foreach ( array_keys( self::RADIUS_SUBJECTS ) as $label ) {
                $rows[ "$kit/$language: $label" ] = [ $kit, $language, $label ];
            }
        }

        return $rows;
    }

    /**
     * An absent radius is Divi's square default (0px) except on a button: removing the radius flips the check only under a kit
     * whose radius for that role is not 0px, and always on a button. Nothing else changes either way.
     */
    #[DataProvider( 'radiusRemovalRows' )]
    public function testRemovingARadiusIsKitAware( string $kit, string $language, string $label ): void
    {
        [ $check, $needle, $path, $kitKey ] = self::RADIUS_SUBJECTS[ $label ];
        $page    = self::conformance( $kit, $language );
        $scorer  = new Scorer( $kit, $language );
        $before  = $scorer->details( $page );
        $key     = self::findBlock( $page, $before, $needle );
        $this->assertNotNull( $key, "$label exists on the conformance page" );
        $mutated = Mutate::remove( $page, $key, $path );
        $report  = $scorer->score( $mutated );
        $radius  = null === $kitKey ? null : (string) StyleKits::get( $kit )['radius'][ $kitKey ];
        $flips   = null === $kitKey || '0px' !== $radius;
        if ( $flips ) {
            $this->assertSame( [ $check ], array_column( $report['failures'], 'check' ), "$label without a radius under a " . ( $radius ?? 'strict' ) . ' kit' );
            $this->assertSame( $key, $report['failures'][0]['block'] );
        } else {
            $this->assertSame( [], $report['failures'], "$label without a radius under a 0px kit still passes" );
        }
        $this->assertSame( array_column( $before, 'check' ), array_column( $scorer->details( $mutated ), 'check' ), 'the same checks run' );
    }

    /** @return array<string,array{0:string,1:string,2:string,3:bool}> kit, language, block with the border removed, whether the 0px-kit expectation is violated */
    public static function bordersRemoved(): array
    {
        return [
            'quiet-solid: a hero button'     => [ 'quiet-solid', 'editorial', 'divi/button#0', true ],
            'quiet-solid: the form submit'   => [ 'quiet-solid', 'editorial', 'divi/contact-form#0', true ],
            'outline-2: the first control'   => [ 'outline-2', 'editorial', 'control', false ],
            'outline-2: the form submit'     => [ 'outline-2', 'editorial', 'divi/contact-form#0', false ],
        ];
    }

    /**
     * Divi draws `.et_pb_button{border:2px solid}` (the contact form's submit is one), so a button or submit with NO border
     * written is a 2px solid one. Under a 0px kit (the adapter writes `width: 0px`) that is a defect; under a 2px kit it is right.
     */
    #[DataProvider( 'bordersRemoved' )]
    public function testAButtonWithoutABorderIsDivisDefault( string $kit, string $language, string $block, bool $fails ): void
    {
        $page   = Prepare::editBase( $kit, $language );
        $scorer = new Scorer( $kit, $language );
        if ( 'control' === $block ) { // the first button the scorer judges as the kit's primary control
            foreach ( $scorer->details( $page ) as $row ) {
                if ( 'button.edge' === $row['check'] && 'control' === $row['role'] ) {
                    $block = $row['block'];
                    break;
                }
            }
            $this->assertStringStartsWith( 'divi/button#', $block );
        }
        $this->assertSame( [], $scorer->score( $page )['failures'], 'the base page is conformant' );
        $path    = 'button.decoration.border.desktop.value.styles';
        $mutated = Mutate::remove( $page, $block, $path );
        $report  = $scorer->score( $mutated );
        if ( $fails ) {
            $check = 'divi/contact-form#0' === $block ? 'form.button-edge' : 'button.edge';
            $this->assertSame( [ $check ], array_column( $report['failures'], 'check' ) );
            $this->assertSame( $block, $report['failures'][0]['block'] );
            $this->assertSame( '0px', $report['failures'][0]['expected'] );
            $this->assertSame( '2px', $report['failures'][0]['observed'] );
        } else {
            $this->assertSame( [], $report['failures'], 'a 2px kit: no border written is the same border' );
        }
    }

    public function testTheMutationRowsCoverEveryMutationOnGlassEdge(): void
    {
        $rows = self::mutationRows();
        foreach ( self::mutations() as $name => $_ ) {
            $this->assertArrayHasKey( "glass-edge/glass-dark: $name", $rows, "$name has a subject on the glass-edge conformance page" );
        }
        $this->assertGreaterThan( 40, count( $rows ) );
    }

    #[DataProvider( 'mutationRows' )]
    public function testEachMutationFlipsExactlyItsCheck( string $kit, string $language, string $name ): void
    {
        [ $flip, $needle, $mutate ] = self::mutations()[ $name ];
        $page   = self::conformance( $kit, $language );
        $scorer = new Scorer( $kit, $language );
        $before = $scorer->details( $page );
        $key    = self::findBlock( $page, $before, $needle );
        $this->assertNotNull( $key );
        $mutated = $mutate( $page, $key );
        $this->assertNotNull( $mutated );
        $this->assertNotSame( $page, $mutated, 'the mutation changed the page' );

        $index = static function ( array $rows ): array {
            $out = [];
            foreach ( $rows as $r ) {
                $out[ $r['block'] . '|' . $r['check'] ] = $r['pass'];
            }
            return $out;
        };
        $was = $index( $before );
        $now = $index( $scorer->details( $mutated ) );
        $this->assertSame( [], array_filter( $was, static fn ( bool $ok ): bool => ! $ok ), 'the conformance page had no failure' );

        $failed = array_keys( array_filter( $now, static fn ( bool $ok ): bool => ! $ok ) );
        $this->assertSame( [ "$key|$flip" ], $failed, "$name flips exactly $flip on $key" );
        $report = $scorer->score( $mutated );
        $this->assertCount( 1, $report['failures'] );
        $this->assertSame( $flip, $report['failures'][0]['check'] );
        $this->assertSame( $key, $report['failures'][0]['block'] );
        $this->assertNotSame( $report['failures'][0]['expected'], $report['failures'][0]['observed'] );

        // no check went from pass to fail elsewhere, and nothing new is checked; the checked count may only drop on the mutated block
        $this->assertSame( [], array_values( array_diff( array_keys( $now ), array_keys( $was ) ) ), 'no new check appears' );
        foreach ( array_diff( array_keys( $was ), array_keys( $now ) ) as $gone ) {
            $this->assertStringStartsWith( $key . '|', $gone, 'only the mutated block may stop being checked' );
        }
        $this->assertSame( $scorer->score( $page )['unclassified'], $report['unclassified'], 'the unclassified list is unchanged' );
    }
}
