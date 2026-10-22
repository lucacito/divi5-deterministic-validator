<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Dogfood\Expected;
use Divi5Validator\Tests\Support\Dogfood\Page;
use Divi5Validator\Tests\Support\Dogfood\Prepare;
use Divi5Validator\Tests\Support\Dogfood\Scorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The dogfood scorer core (plan 2026-10-22, Task 2): the expectations are the REAL adapters' resolved output, every check has a
 * planted pass and a planted fail, a block the scorer cannot place is neither passed nor failed, colours are role-set
 * membership, bands are inferred by a fixed priority. The planted blocks are tiny hand-built block comments whose values are
 * taken from Expected::attrs(), never typed. Dev tooling only: nothing here is shipped.
 */
class DogfoodScorerTest extends TestCase
{
    private const KIT  = 'glass-edge';
    private const LANG = 'glass-dark';

    // ---------------------------------------------------------------------------------------------------------
    // Builders
    // ---------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function language( string $id ): array
    {
        $l = DesignLanguages::get( $id );
        self::assertIsArray( $l );

        return $l;
    }

    private static function expected( string $kit = self::KIT, string $lang = self::LANG ): Expected
    {
        $k = StyleKits::get( $kit );
        self::assertIsArray( $k );

        return new Expected( $k, self::language( $lang ) );
    }

    /** @param array<string,mixed> $attrs */
    private static function leaf( string $name, array $attrs ): string
    {
        return '<!-- wp:' . $name . ' ' . ( [] === $attrs ? '{}' : RecipeTokens::encodeJson( $attrs ) ) . ' /-->';
    }

    /** @param array<string,mixed> $attrs */
    private static function block( string $name, array $attrs, string $inner ): string
    {
        return '<!-- wp:' . $name . ' ' . ( [] === $attrs ? '{}' : RecipeTokens::encodeJson( $attrs ) ) . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
    }

    /** @return array<string,mixed> */
    private static function bg( string $colour ): array
    {
        return [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => $colour ] ] ] ] ] ];
    }

    /** A page: one section painting $colour (the language's `bg` by default: the light band) around $inner. */
    private static function page( string $inner, ?string $colour = null, string $lang = self::LANG ): string
    {
        $colour ??= (string) self::language( $lang )['colors']['bg'];

        return '<!-- wp:divi/placeholder -->' . self::block( 'divi/section', self::bg( $colour ), $inner ) . '<!-- /wp:divi/placeholder -->';
    }

    /**
     * $attrs with the value at the dot path replaced (missing steps created).
     *
     * @param array<string,mixed> $attrs
     *
     * @return array<string,mixed>
     */
    private static function with( array $attrs, string $path, mixed $value ): array
    {
        $parts = explode( '.', $path );
        $node  = &$attrs;
        foreach ( $parts as $part ) {
            if ( ! is_array( $node ) ) {
                $node = [];
            }
            $node = &$node[ $part ];
        }
        $node = $value;

        return $attrs;
    }

    private static function corners( string $v ): array
    {
        return [ 'topLeft' => $v, 'topRight' => $v, 'bottomRight' => $v, 'bottomLeft' => $v ];
    }

    private static function report( string $page, string $kit = self::KIT, string $lang = self::LANG ): array
    {
        return ( new Scorer( $kit, $lang ) )->score( $page );
    }

    /** The details row of a check, or null when it was not checked. */
    private static function row( array $details, string $check ): ?array
    {
        foreach ( $details as $row ) {
            if ( $row['check'] === $check ) {
                return $row;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Expectations
    // ---------------------------------------------------------------------------------------------------------

    public function testExpectationsAreTheResolvedAdapterPatches(): void
    {
        $count = 0;
        foreach ( [ 'glass-edge', 'underline-link', 'double-frame' ] as $kitId ) {
            $kit  = StyleKits::get( $kitId );
            $lang = self::language( Scorer::defaultLanguage( $kitId ) );
            $this->assertIsArray( $kit );
            $expected = new Expected( $kit, $lang );
            foreach ( KitAdapters::APPLIED as $block => $roles ) {
                foreach ( $roles as $role ) {
                    $taken = $expected->bands( $block, $role );
                    $this->assertNotSame( [], $taken, "$kitId $block $role takes a band" );
                    foreach ( Expected::BANDS as $band ) {
                        $marker   = '<!-- wp:' . $block . ' {"aied":{"kit":"' . $role . '","on":"' . $band . '"}} /-->';
                        $resolved = RecipeTokens::resolve( KitResolver::resolve( $marker, $kit ), $lang );
                        $got      = $expected->attrs( $block, $role, $band );
                        if ( ! in_array( $band, $taken, true ) ) {
                            $this->assertNull( $got, "$kitId $block $role on $band is refused" );
                            $this->assertStringContainsString( '"aied"', $resolved, 'the resolver leaves the marker' );
                            continue;
                        }
                        $this->assertSame( 1, preg_match( '/^<!-- wp:[a-z0-9\/-]+ (\{.*\}) \/-->$/s', $resolved, $m ), "$kitId $block $role $band resolves" );
                        $this->assertSame( json_decode( $m[1], true ), $got, "$kitId $block $role on $band" );
                        $this->assertStringNotContainsString( '{{aied:', (string) json_encode( $got ), 'no token is left' );
                        ++$count;
                    }
                    if ( in_array( $role, [ 'card', 'panel' ], true ) ) {
                        foreach ( [ 'inverted', 'accent' ] as $ground ) {
                            foreach ( Expected::BANDS as $band ) {
                                $marker   = '<!-- wp:' . $block . ' {"aied":{"kit":"' . $role . '","on":"' . $band . '","ground":"' . $ground . '"}} /-->';
                                $resolved = RecipeTokens::resolve( KitResolver::resolve( $marker, $kit ), $lang );
                                $got      = $expected->attrs( $block, $role, $band, $ground );
                                if ( null === $got ) {
                                    $this->assertStringContainsString( '"aied"', $resolved, "$kitId $block $role $ground on $band is refused" );
                                    $this->assertContains( $band, [ 'accent', 'dark', 'panel' ], 'a ground sits on a light or surface band only' );
                                    continue;
                                }
                                $this->assertSame( 1, preg_match( '/^<!-- wp:[a-z0-9\/-]+ (\{.*\}) \/-->$/s', $resolved, $m ) );
                                $this->assertSame( json_decode( $m[1], true ), $got );
                                ++$count;
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan( 150, $count, 'a real number of (kit, block, role, band) cells was compared' );
    }

    // ---------------------------------------------------------------------------------------------------------
    // Planted passes and fails, one row per check
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}> check => [check, kit, language, passing page, failing page]
     */
    public static function plantedChecks(): array
    {
        $rows = [];
        $add  = static function ( string $check, string $kit, string $lang, string $pass, string $fail ) use ( &$rows ): void {
            $rows[ $check ] = [ $check, $kit, $lang, $pass, $fail ];
        };
        $E = static fn ( string $kit, string $lang ): Expected => self::expected( $kit, $lang );
        $heading = self::leaf( 'divi/heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Plan' ] ] ] ] );

        // button (glass-edge: a filled control; outline-2: an outlined control whose ghost secondary has no edge)
        $g       = $E( 'glass-edge', 'glass-dark' );
        $btn     = $g->attrs( 'divi/button', 'control', 'light' );
        $B       = 'button.decoration.border.desktop.value';
        $wrapBtn = static fn ( array $a ): string => self::page( self::leaf( 'divi/button', $a ) );
        $add( 'button.radius', 'glass-edge', 'glass-dark', $wrapBtn( $btn ), $wrapBtn( self::with( $btn, $B . '.radius', self::corners( '13px' ) ) ) );
        $add( 'button.edge', 'glass-edge', 'glass-dark', $wrapBtn( $btn ), $wrapBtn( self::with( $btn, $B . '.styles.all.width', '3px' ) ) );
        $add( 'button.edge-style', 'glass-edge', 'glass-dark', $wrapBtn( $btn ), $wrapBtn( self::with( $btn, $B . '.styles.all.style', 'dotted' ) ) );
        $o2     = $E( 'outline-2', 'editorial' )->attrs( 'divi/button', 'control', 'light' );
        $add( 'button.fill', 'outline-2', 'editorial', $wrapBtn( $o2 ), $wrapBtn( self::with( $o2, 'button.decoration.background.desktop.value.color', '#9a3b1f' ) ) );
        $add( 'button.fill-colour', 'glass-edge', 'glass-dark', $wrapBtn( $btn ), $wrapBtn( self::with( $btn, 'button.decoration.background.desktop.value.color', '#123456' ) ) );

        // rule (a divider)
        $div  = $g->attrs( 'divi/divider', 'rule', 'light' );
        $L    = 'divider.advanced.line.desktop.value';
        $wrapD = static fn ( array $a ): string => self::page( self::leaf( 'divi/divider', $a ) );
        $add( 'rule.width', 'glass-edge', 'glass-dark', $wrapD( $div ), $wrapD( self::with( $div, $L . '.weight', '7px' ) ) );
        $add( 'rule.style', 'glass-edge', 'glass-dark', $wrapD( $div ), $wrapD( self::with( $div, $L . '.style', 'dashed' ) ) );
        $add( 'rule.colour', 'glass-edge', 'glass-dark', $wrapD( $div ), $wrapD( self::with( $div, $L . '.color', '#123456' ) ) );

        // chip (a tag and an icon)
        $tag   = $g->attrs( 'divi/group', 'chip', 'light' );
        $M     = 'module.decoration.border.desktop.value';
        $text  = self::leaf( 'divi/text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>New</p>' ] ] ] ] );
        $wrapT = static fn ( array $a ): string => self::page( self::block( 'divi/group', $a, $text ) );
        $add( 'chip.radius', 'glass-edge', 'glass-dark', $wrapT( $tag ), $wrapT( self::with( $tag, $M . '.radius', self::corners( '13px' ) ) ) );
        $add( 'chip.frame', 'glass-edge', 'glass-dark', $wrapT( $tag ), $wrapT( self::with( $tag, $M . '.styles.all.width', '3px' ) ) );
        $add( 'chip.frame-style', 'glass-edge', 'glass-dark', $wrapT( $tag ), $wrapT( self::with( $tag, $M . '.styles.all.style', 'dotted' ) ) );
        $add( 'chip.frame-colour', 'glass-edge', 'glass-dark', $wrapT( $tag ), $wrapT( self::with( $tag, $M . '.styles.all.color', '#123456' ) ) );
        $icon  = $g->attrs( 'divi/icon', 'chip', 'light' );
        $wrapI = static fn ( array $a ): string => self::page( self::leaf( 'divi/icon', $a ) );
        $add( 'chip.fill-colour', 'glass-edge', 'glass-dark', $wrapI( $icon ), $wrapI( self::with( $icon, 'module.decoration.background.desktop.value.color', '#123456' ) ) );
        $add( 'chip.glyph-colour', 'glass-edge', 'glass-dark', $wrapI( $icon ), $wrapI( self::with( $icon, 'icon.advanced.color.desktop.value', '#123456' ) ) );

        // framed (a card with a heading inside)
        $card  = $g->attrs( 'divi/group', 'card', 'light' );
        $wrapC = static fn ( array $a ): string => self::page( self::block( 'divi/group', $a, $heading ) );
        $add( 'framed.radius', 'glass-edge', 'glass-dark', $wrapC( $card ), $wrapC( self::with( $card, $M . '.radius', self::corners( '13px' ) ) ) );
        $add( 'framed.frame', 'glass-edge', 'glass-dark', $wrapC( $card ), $wrapC( self::with( $card, $M . '.styles.all.width', '5px' ) ) );
        $add( 'framed.frame-style', 'glass-edge', 'glass-dark', $wrapC( $card ), $wrapC( self::with( $card, $M . '.styles.all.style', 'dotted' ) ) );
        $add( 'framed.frame-colour', 'glass-edge', 'glass-dark', $wrapC( $card ), $wrapC( self::with( $card, $M . '.styles.all.color', '#123456' ) ) );
        $add( 'framed.fill-colour', 'glass-edge', 'glass-dark', $wrapC( $card ), $wrapC( self::with( $card, 'module.decoration.background.desktop.value.color', '#123456' ) ) );
        $topRule = static fn ( string $w ): array => self::with( self::with( self::with( $card, $M . '.styles.top.width', $w ), $M . '.styles.top.style', 'solid' ), $M . '.styles.top.color', '#2a3558' );
        $add( 'framed.top-rule', 'glass-edge', 'glass-dark', $wrapC( $topRule( '2px' ) ), $wrapC( $topRule( '7px' ) ) );

        // item (an accordion)
        $acc  = $g->attrs( 'divi/accordion', 'item', 'light' );
        $wrapA = static fn ( array $a ): string => self::page( self::leaf( 'divi/accordion', $a ) );
        $add( 'item.radius', 'glass-edge', 'glass-dark', $wrapA( $acc ), $wrapA( self::with( $acc, $M . '.radius', self::corners( '13px' ) ) ) );
        $add( 'item.frame', 'glass-edge', 'glass-dark', $wrapA( $acc ), $wrapA( self::with( $acc, $M . '.styles.all.width', '5px' ) ) );
        $add( 'item.frame-style', 'glass-edge', 'glass-dark', $wrapA( $acc ), $wrapA( self::with( $acc, $M . '.styles.all.style', 'dotted' ) ) );
        $add( 'item.fill-colour', 'glass-edge', 'glass-dark', $wrapA( $acc ), $wrapA( self::with( $acc, 'openToggle.decoration.background.desktop.value.color', '#123456' ) ) );

        // form (a contact form)
        $form  = $g->attrs( 'divi/contact-form', 'form', 'light' );
        $wrapF = static fn ( array $a ): string => self::page( self::leaf( 'divi/contact-form', $a ) );
        $FB    = 'field.decoration.border.desktop.value';
        $add( 'form.field-radius', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $FB . '.radius', self::corners( '13px' ) ) ) );
        $add( 'form.field-frame', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $FB . '.styles.all.width', '5px' ) ) );
        $add( 'form.field-frame-style', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $FB . '.styles.all.style', 'dotted' ) ) );
        $add( 'form.field-fill-colour', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, 'field.decoration.background.desktop.value.color', '#123456' ) ) );
        $add( 'form.button-radius', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $B . '.radius', self::corners( '13px' ) ) ) );
        $add( 'form.button-edge', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $B . '.styles.all.width', '5px' ) ) );
        $add( 'form.button-edge-style', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, $B . '.styles.all.style', 'dotted' ) ) );
        $add( 'form.button-fill-colour', 'glass-edge', 'glass-dark', $wrapF( $form ), $wrapF( self::with( $form, 'button.decoration.background.desktop.value.color', '#123456' ) ) );

        // image (the media radius, or flush)
        $wrapM = static fn ( string $r ): string => self::page( self::leaf( 'divi/image', [ 'image' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => self::corners( $r ) ] ] ] ] ] ] ) );
        $add( 'image.radius', 'glass-edge', 'glass-dark', $wrapM( '12px' ), $wrapM( '7px' ) );

        return $rows;
    }

    public function testThePlantedProviderCoversEveryCheck(): void
    {
        $this->assertCount( 33, Scorer::CHECKS );
        $this->assertSame( Scorer::CHECKS, array_keys( self::plantedChecks() ) );
        $this->assertSame( Scorer::CHECKS, array_unique( Scorer::CHECKS ), 'no name twice' );
        foreach ( Scorer::CHECKS as $check ) {
            $this->assertContains( explode( '.', $check )[0], Scorer::CATEGORIES );
        }
    }

    #[DataProvider( 'plantedChecks' )]
    public function testEveryCheckHasAPlantedPassAndFail( string $check, string $kit, string $lang, string $pass, string $fail ): void
    {
        $scorer = new Scorer( $kit, $lang );

        $details = $scorer->details( $pass );
        $row     = self::row( $details, $check );
        $this->assertNotNull( $row, "$check is checked on the passing page" );
        $this->assertTrue( $row['pass'], "$check passes on the passing page: expected {$row['expected']}, observed {$row['observed']}" );
        $this->assertSame( [], $scorer->score( $pass )['failures'], 'a page built from the adapter output fails nothing' );

        $report = $scorer->score( $fail );
        $this->assertSame( [ $check ], array_column( $report['failures'], 'check' ), "exactly $check fails on the failing page" );
        $row = self::row( $scorer->details( $fail ), $check );
        $this->assertNotNull( $row );
        $this->assertFalse( $row['pass'] );
        $this->assertNotSame( $row['expected'], $row['observed'], 'a failure says what was expected and what was seen' );
    }

    // ---------------------------------------------------------------------------------------------------------
    // Classification
    // ---------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> a group's border value */
    private static function border( array $styles, ?string $radius = null ): array
    {
        $v = [ 'styles' => $styles ];
        if ( null !== $radius ) {
            $v['radius'] = self::corners( $radius );
        }

        return [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => $v ] ] ] ] ];
    }

    private static function fillOf( array $attrs, string $colour = '#151c33' ): array
    {
        return self::with( $attrs, 'module.decoration.background.desktop.value.color', $colour );
    }

    /** @return array<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function classifications(): array
    {
        $text    = self::leaf( 'divi/text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>Hi</p>' ] ] ] ] );
        $heading = self::leaf( 'divi/heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Plan' ] ] ] ] );
        $topOnly = self::border( [ 'all' => [ 'width' => '0px', 'style' => 'solid', 'color' => '#2a3558' ], 'top' => [ 'width' => '1px', 'style' => 'solid', 'color' => '#2a3558' ] ] );
        $full    = self::border( [ 'all' => [ 'width' => '1px', 'style' => 'solid', 'color' => '#2a3558' ] ], '12px' );
        $img     = static fn ( array $radius ): string => self::leaf( 'divi/image', [ 'image' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => $radius ] ] ] ] ] ] );

        return [
            'a top-only unfilled group is a rule'          => [ self::page( self::block( 'divi/group', $topOnly, $text ) ), 'rule', [ 'rule' => 3 ] ],
            'the same with a fill is not a rule'            => [ self::page( self::block( 'divi/group', self::fillOf( $topOnly ), $text ) ), 'framed', [ 'rule' => 0, 'framed' => 'some' ] ],
            'a full-frame unfilled group with a text is a tag' => [ self::page( self::block( 'divi/group', $full, $text ) ), 'tag', [ 'chip' => 4, 'framed' => 0 ] ],
            'with a heading inside it is framed'           => [ self::page( self::block( 'divi/group', $full, $heading ) ), 'framed', [ 'chip' => 0, 'framed' => 'some' ] ],
            'a filled group with a radius, no frame and a text is unclassified' => [ self::page( self::block( 'divi/group', self::fillOf( self::border( [], '12px' ) ), $text ) ), 'unclassified', [ 'unclassified' => 'divi/group#0' ] ],
            'a filled column with no frame, no radius and a heading is unclassified' => [ self::page( self::block( 'divi/column', self::fillOf( [] ), $heading ) ), 'unclassified', [ 'unclassified' => 'divi/column#0' ] ],
            'an undecorated group is ignored'              => [ self::page( self::block( 'divi/group', [], $text ) ), 'ignored', [ 'ignored' => 1 ] ],
            'an icon with a radius and no background is unclassified' => [ self::page( self::leaf( 'divi/icon', self::border( [], '12px' ) ) ), 'unclassified', [ 'unclassified' => 'divi/icon#0' ] ],
            'an image with 999px corners is a shaped image' => [ self::page( $img( self::corners( '999px' ) ) ), 'unclassified', [ 'unclassified' => 'divi/image#0', 'reason' => 'shaped image' ] ],
            'an image with mixed corners is a shaped image' => [ self::page( $img( [ 'topLeft' => '999px', 'topRight' => '999px', 'bottomRight' => '32px', 'bottomLeft' => '32px' ] ) ), 'unclassified', [ 'unclassified' => 'divi/image#0', 'reason' => 'shaped image' ] ],
            'a show:off divider is ignored'                => [ self::page( self::leaf( 'divi/divider', [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'show' => 'off' ] ] ] ] ] ] ) ), 'ignored', [ 'ignored' => 1 ] ],
        ];
    }

    #[DataProvider( 'classifications' )]
    public function testClassificationOfPlantedBlocks( string $page, string $kind, array $expect ): void
    {
        $r = self::report( $page );
        foreach ( $expect as $what => $value ) {
            switch ( $what ) {
                case 'rule':
                    $this->assertSame( $value, $r['categories']['rule']['checked'], 'rule checks' );
                    $this->assertSame( [], $r['unclassified'] );
                    break;
                case 'chip':
                case 'framed':
                    if ( 'some' === $value ) {
                        $this->assertGreaterThan( 0, $r['categories'][ $what ]['checked'], "$what is checked" );
                    } else {
                        $this->assertSame( $value, $r['categories'][ $what ]['checked'], "$what checks" );
                    }
                    break;
                case 'unclassified':
                    $this->assertSame( [ $value ], array_column( $r['unclassified'], 'block' ) );
                    $this->assertSame( 0, $r['overall']['checked'], 'nothing is checked' );
                    break;
                case 'reason':
                    $this->assertStringContainsString( $value, $r['unclassified'][0]['reason'] );
                    break;
                case 'ignored':
                    $this->assertSame( $value, $r['ignored'] );
                    $this->assertSame( [], $r['unclassified'] );
                    $this->assertSame( 0, $r['overall']['checked'] );
                    break;
            }
        }
        $this->assertNotSame( '', $kind );
    }

    public function testUnclassifiedBlocksAreNeitherPassedNorFailed(): void
    {
        $g      = self::expected();
        $button = self::leaf( 'divi/button', $g->attrs( 'divi/button', 'control', 'light' ) );
        $divider = self::leaf( 'divi/divider', self::with( $g->attrs( 'divi/divider', 'rule', 'light' ), 'divider.advanced.line.desktop.value.color', '#123456' ) );
        $base    = self::report( self::page( $button . $divider ) );
        $this->assertNotSame( [], $base['failures'], 'the base page has a failure to keep' );
        $this->assertSame( [], $base['unclassified'] );

        $odd    = self::block( 'divi/column', self::fillOf( [] ), self::leaf( 'divi/heading', [] ) );
        $after  = self::report( self::page( $button . $odd . $divider ) );
        $this->assertSame( $base['categories'], $after['categories'] );
        $this->assertSame( $base['overall'], $after['overall'] );
        $this->assertSame( $base['failures'], $after['failures'] );
        $this->assertCount( 1, $after['unclassified'] );
        $this->assertSame( 'divi/column#0', $after['unclassified'][0]['block'] );
        $this->assertNotSame( '', $after['unclassified'][0]['reason'] );
    }

    public function testPageKeysSkipTheRootAndNumberBlocksByName(): void
    {
        $inner = self::block( 'divi/section', [], self::block( 'divi/row', [], self::leaf( 'divi/text', [] ) . self::block( 'divi/group', [], self::leaf( 'divi/text', [] ) . self::leaf( 'divi/heading', [] ) ) ) );
        $keys  = static fn ( Page $p ): array => array_column( $p->blocks( self::language( self::LANG ) ), 'key' );
        $want  = [ 'divi/section#0', 'divi/row#0', 'divi/text#0', 'divi/group#0', 'divi/text#1', 'divi/heading#0' ];
        $wrapped = Page::parse( '<!-- wp:divi/placeholder -->' . $inner . '<!-- /wp:divi/placeholder -->' );
        $this->assertSame( $want, $keys( $wrapped ), 'a page that starts with the root placeholder' );
        $this->assertSame( $want, $keys( Page::parse( "  \n" . $inner ) ), 'a page without one is wrapped, with the same keys' );
        $this->assertSame( [ 'divi/text', 'divi/heading' ], $wrapped->descendants( 'divi/group#0' ) );
        $this->assertSame( [], $wrapped->descendants( 'divi/heading#0' ) );
        $contents = [];
        foreach ( $wrapped->blocks( self::language( self::LANG ) ) as $b ) {
            $contents[ $b['key'] ] = $b['hasContent'];
        }
        foreach ( $wrapped->blocks( self::language( self::LANG ) ) as $b ) {
            $this->assertSame( $b['band'], $b['parentBand'] );
        }
        $this->assertTrue( $contents['divi/group#0'] );
        $this->assertFalse( $contents['divi/text#0'] );
        $this->assertSame( [], Page::parse( '' )->blocks( self::language( self::LANG ) ), 'an empty page has no blocks' );
        $this->assertSame( 'n/a', self::report( '' )['overall']['score'] );
        $this->expectException( \InvalidArgumentException::class );
        Page::parse( '<!-- wp:divi/section {"a":1} -->' );
    }

    // ---------------------------------------------------------------------------------------------------------
    // Colours and bands
    // ---------------------------------------------------------------------------------------------------------

    private static function dividerPage( string $colour, string $weight, string $sectionColour, string $lang = self::LANG, string $kit = self::KIT ): string
    {
        $div = self::expected( $kit, $lang )->attrs( 'divi/divider', 'rule', 'light' );
        $div = self::with( self::with( $div, 'divider.advanced.line.desktop.value.color', $colour ), 'divider.advanced.line.desktop.value.weight', $weight );

        return self::page( self::leaf( 'divi/divider', $div ), $sectionColour, $lang );
    }

    public function testColourIsRoleSetMembership(): void
    {
        $scorer = new Scorer( self::KIT, self::LANG );
        $bg     = '#0b1020';
        foreach ( [ '#2a3558', '#2A3558', 'rgb(42,53,88)', 'rgba(42, 53, 88, 1)' ] as $c ) {
            $r = $scorer->score( self::dividerPage( $c, '1px', $bg ) );
            $this->assertSame( [], $r['failures'], "a quiet rule on light passes with $c" );
        }
        // a strong-width rule picks rule-strong and passes with the `text` colour
        $strong = $scorer->details( self::dividerPage( '#f2f4fa', '2px', $bg ) );
        $this->assertSame( 'rule-strong', $strong[0]['role'] );
        $this->assertSame( [], $scorer->score( self::dividerPage( '#f2f4fa', '2px', $bg ) )['failures'] );
        // a colour outside the palette fails rule.colour
        $r = $scorer->score( self::dividerPage( '#123456', '1px', $bg ) );
        $this->assertSame( [ 'rule.colour' ], array_column( $r['failures'], 'check' ) );
        // a 40% rule on a dark band, written two ways
        $dark = 'rgba(7,11,26,0.7)'; // the language's overlay: a dark band
        foreach ( [ 'rgba(244,246,253,0.4)', 'rgba(244, 246, 253, .40)' ] as $c ) {
            $d = $scorer->details( self::dividerPage( $c, '1px', $dark ) );
            $this->assertSame( 'dark', $d[0]['band'], 'the overlay colour is a dark band' );
            $this->assertSame( [], $scorer->score( self::dividerPage( $c, '1px', $dark ) )['failures'], "a 40% rule on dark passes with $c" );
        }
        // the light-band colour is not the dark band's: the same blocks, a different band
        $r = $scorer->score( self::dividerPage( '#2a3558', '1px', $dark ) );
        $this->assertSame( [ 'rule.colour' ], array_column( $r['failures'], 'check' ), 'role colours are per band' );
    }

    public function testBandsAreInferredByTheFixedRolePriority(): void
    {
        $inner = self::leaf( 'divi/text', [] );
        $bands = static function ( string $lang, string $sectionAttrs ) use ( $inner ): string {
            $page = Page::parse( '<!-- wp:divi/section ' . $sectionAttrs . ' -->' . $inner . '<!-- /wp:divi/section -->' );
            foreach ( $page->blocks( self::language( $lang ) ) as $b ) {
                if ( 'divi/text' === $b['name'] ) {
                    return $b['band'];
                }
            }
            return 'missing';
        };
        $this->assertSame( 'surface', $bands( 'playful-pastel', RecipeTokens::encodeJson( self::bg( '#ffffff' ) ) ), 'surface before inverse (both #ffffff)' );
        $this->assertSame( 'light', $bands( 'glass-dark', RecipeTokens::encodeJson( self::bg( '#0b1020' ) ) ), 'bg before accent_text (both #0b1020)' );
        $this->assertSame( 'dark', $bands( 'glass-dark', '{"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"https://example.com/a.jpg"}}}}}}}' ) );
        $this->assertSame( 'dark', $bands( 'glass-dark', '{"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"enabled":"on","stops":[{"color":"rgba(7,11,26,0.7)","position":"0%"},{"color":"#123456","position":"100%"}]}}}}}}}' ), 'a gradient with an overlay stop' );
        $this->assertSame( 'unknown', $bands( 'glass-dark', '{"module":{"decoration":{"background":{"desktop":{"value":{"gradient":{"enabled":"on","stops":[{"color":"#111111"},{"color":"#222222"}]}}}}}}}' ), 'any other gradient' );
        $this->assertSame( 'panel', $bands( 'glass-dark', RecipeTokens::encodeJson( self::bg( '#f2f4fa' ) ) ), 'the text colour makes a panel' );
        $this->assertSame( 'unknown', $bands( 'glass-dark', RecipeTokens::encodeJson( self::bg( '#123456' ) ) ), 'a non-palette colour' );
        $this->assertSame( 'light', $bands( 'glass-dark', '{}' ), 'under no painting ancestor' );
        $this->assertNull( Page::bandOf( self::bg( 'rgba(255,255,255,0)' ), self::language( 'glass-dark' ) ), 'a clear colour paints nothing' );
        $this->assertSame( 'light', Page::bandOf( self::bg( '#0B1020' ), self::language( 'glass-dark' ) ) );

        // a group filled with the `text` colour makes its children `panel`; the nearest painting ancestor wins over the section
        $page = Page::parse( self::block( 'divi/section', self::bg( '#0b1020' ), self::block( 'divi/group', self::bg( '#f2f4fa' ), self::block( 'divi/group', self::bg( '#151c33' ), $inner ) . $inner ) . $inner ) );
        $got  = [];
        foreach ( $page->blocks( self::language( 'glass-dark' ) ) as $b ) {
            $got[ $b['key'] ] = $b['band'];
        }
        $this->assertSame( [ 'divi/section#0' => 'light', 'divi/group#0' => 'light', 'divi/group#1' => 'panel', 'divi/text#0' => 'surface', 'divi/text#1' => 'panel', 'divi/text#2' => 'light' ], $got );

        // an unknown band judges colours against the union of the role's bands
        $scorer = new Scorer( self::KIT, self::LANG );
        foreach ( [ '#2a3558', 'rgba(244,246,253,0.4)', 'rgba(124,156,255,0.4)' ] as $quiet ) { // border, inverse:40, ... accent is not a rule colour
            $r = $scorer->score( self::dividerPage( $quiet, '1px', '#123456' ) );
            $expectOk = '#2a3558' === $quiet || 'rgba(244,246,253,0.4)' === $quiet;
            $this->assertSame( $expectOk, [] === $r['failures'], "a quiet rule in an unknown band, $quiet" );
        }
        $r = $scorer->score( self::dividerPage( '#123456', '1px', '#123456' ) );
        $this->assertSame( 'unknown', $r['failures'][0]['band'] );
        $this->assertSame( [ 'rule.colour' ], array_column( $r['failures'], 'check' ) );
    }

    public function testBestRoleTieGoesToTheFirstRole(): void
    {
        $r = new Scorer( self::KIT, self::LANG );
        // glass-edge: rule = 1px border, rule-strong = 2px text. Width of `rule`, colour of `rule-strong`.
        $page    = self::dividerPage( '#f2f4fa', '1px', '#0b1020' );
        $details = $r->details( $page );
        $this->assertSame( 'rule', $details[0]['role'], 'two checks pass for each role: the first role wins' );
        $report = $r->score( $page );
        $this->assertSame( [ 'rule.colour' ], array_column( $report['failures'], 'check' ) );
        $this->assertSame( 'rule', $report['failures'][0]['role'] );
    }

    // ---------------------------------------------------------------------------------------------------------
    // The report
    // ---------------------------------------------------------------------------------------------------------

    public function testTheReportShapeAndNa(): void
    {
        $g      = self::expected();
        $button = self::leaf( 'divi/button', $g->attrs( 'divi/button', 'control', 'light' ) );
        $bad    = self::leaf( 'divi/button', self::with( $g->attrs( 'divi/button', 'control', 'light' ), 'button.decoration.border.desktop.value.radius', self::corners( '13px' ) ) );
        $r      = ( new Scorer( self::KIT, self::LANG ) )->score( self::page( $button . $button . $bad ), 'some/page.html' );
        $this->assertSame( [ 'page', 'kit', 'language', 'categories', 'overall', 'failures', 'unclassified', 'ignored' ], array_keys( $r ) );
        $this->assertSame( 'some/page.html', $r['page'] );
        $this->assertSame( self::KIT, $r['kit'] );
        $this->assertSame( self::LANG, $r['language'] );
        $this->assertSame( Scorer::CATEGORIES, array_keys( $r['categories'] ) );
        foreach ( [ 'rule', 'chip', 'framed', 'item', 'form', 'image' ] as $cat ) {
            $this->assertSame( [ 'checked' => 0, 'passed' => 0, 'score' => 'n/a' ], $r['categories'][ $cat ], $cat );
        }
        $this->assertSame( [ 'checked', 'passed', 'score' ], array_keys( $r['categories']['button'] ) );
        $this->assertSame( 15, $r['categories']['button']['checked'], 'three buttons, five checks each' );
        $this->assertSame( 14, $r['categories']['button']['passed'] );
        $this->assertSame( 93.3, $r['categories']['button']['score'], 'one decimal' );
        $this->assertSame( $r['categories']['button'], $r['overall'] );
        $this->assertCount( 1, $r['failures'] );
        $this->assertSame( [ 'check', 'block', 'role', 'band', 'expected', 'observed' ], array_keys( $r['failures'][0] ) );
        $this->assertSame( [ 'button.radius', 'divi/button#2', 'control', 'light', '12px', '13px' ], array_values( $r['failures'][0] ) );

        $json = Scorer::json( $r );
        $this->assertStringEndsWith( "}\n", $json );
        $this->assertSame( $r, json_decode( $json, true ) );
        $this->assertStringContainsString( "\n    \"page\"", $json, 'pretty printed' );
        $this->assertStringNotContainsString( '\\/', $json, 'slashes unescaped' );

        $text = Scorer::text( $r );
        $this->assertStringStartsWith( "dogfood score some/page.html kit=glass-edge language=glass-dark\nbutton 14/15 93.3%\nrule 0/0 n/a\n", $text );
        $this->assertStringContainsString( "\noverall 14/15 93.3%\nFAIL button.radius divi/button#2 (control on light): expected 12px, observed 13px\n", $text );
        $this->assertStringEndsWith( "\n", $text );
    }

    public function testScoringIsDeterministic(): void
    {
        $page = Prepare::recipePage( array_merge( Prepare::EDIT_RECIPES, [ 'portfolio-featured-grid', 'list-icon-dividers' ] ), self::KIT, self::LANG );
        $a    = Scorer::json( ( new Scorer( self::KIT, self::LANG ) )->score( $page, 'x' ) );
        $b    = Scorer::json( ( new Scorer( self::KIT, self::LANG ) )->score( $page, 'x' ) );
        $this->assertSame( $a, $b );
        $this->assertGreaterThan( 10000, strlen( $page ), 'a corpus-sized page' );
    }

    public function testUnknownIdsAreRefusedWithTheValidList(): void
    {
        try {
            new Scorer( 'no-such-kit', 'editorial' );
            $this->fail( 'an unknown kit is refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'glass-edge', $e->getMessage() );
        }
        try {
            new Scorer( 'glass-edge', 'no-such-language' );
            $this->fail( 'an unknown language is refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'glass-dark', $e->getMessage() );
        }
        $this->expectException( \InvalidArgumentException::class );
        ( new Scorer( self::KIT, self::LANG ) )->score( '<!-- wp:divi/section {not json} -->' );
    }

    public function testDefaultLanguage(): void
    {
        $this->assertSame( 'glass-dark', Scorer::defaultLanguage( 'glass-edge' ) );
        $this->assertSame( 'editorial', Scorer::defaultLanguage( 'dotted-pill' ) );
        $this->assertSame( 'tech-mono', Scorer::defaultLanguage( 'hairline-dashed' ) );
    }

    // ---------------------------------------------------------------------------------------------------------
    // The script
    // ---------------------------------------------------------------------------------------------------------

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    private static function runScript( array $args ): array
    {
        $cmd  = array_merge( [ PHP_BINARY, '-d', 'memory_limit=-1', dirname( __DIR__ ) . '/scripts/dogfood-score.php' ], $args );
        $proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ) );
        self::assertIsResource( $proc );
        $out  = (string) stream_get_contents( $pipes[1] );
        $err  = (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        return [ proc_close( $proc ), $out, $err ];
    }

    public function testTheScoreScriptExitCodes(): void
    {
        $dir  = sys_get_temp_dir() . '/dogfood-score-' . bin2hex( random_bytes( 6 ) );
        mkdir( $dir );
        try {
            $g = self::expected();
            $good = $dir . '/page.html';
            file_put_contents( $good, self::page( self::leaf( 'divi/button', $g->attrs( 'divi/button', 'control', 'light' ) ) ) );
            $bad = $dir . '/broken.html';
            file_put_contents( $bad, '<!-- wp:divi/section {"oops" --><!-- /wp:divi/section -->' );

            [ $code, $out ] = self::runScript( [ $good, 'glass-edge' ] );
            $this->assertSame( 0, $code );
            $this->assertStringStartsWith( "dogfood score $good kit=glass-edge language=glass-dark\n", $out, 'text output, the language it used, the path as given' );

            [ $code, $out ] = self::runScript( [ $good, 'glass-edge', '--json' ] );
            $this->assertSame( 0, $code );
            $this->assertSame( Scorer::json( ( new Scorer( 'glass-edge', 'glass-dark' ) )->score( (string) file_get_contents( $good ), $good ) ), $out );

            [ $code, $out ] = self::runScript( [ $good, 'dotted-pill', '--language', 'glass-dark', '--json' ] );
            $this->assertSame( 0, $code, 'a page that scores low still exits 0' );
            $this->assertSame( 'dotted-pill', json_decode( $out, true )['kit'] );

            $this->assertSame( 1, self::runScript( [ $good, 'dotted-pill', '--language', 'glass-dark', '--min', '101' ] )[0], '--min above the score exits 1' );
            $this->assertSame( 1, self::runScript( [ $good, 'glass-edge', '--min', '101' ] )[0] );
            $this->assertSame( 0, self::runScript( [ $good, 'glass-edge', '--min', '100' ] )[0] );
            $this->assertSame( 0, self::runScript( [ $good, 'glass-edge', '--min', '0' ] )[0] );

            $this->assertSame( 2, self::runScript( [ $good, 'no-such-kit' ] )[0], 'unknown kit' );
            [ $code, , $err ] = self::runScript( [ $good, 'glass-edge', '--language', 'no-such' ] );
            $this->assertSame( 2, $code, 'unknown language' );
            $this->assertStringContainsString( 'glass-dark', $err );
            $this->assertSame( 2, self::runScript( [ $dir . '/missing.html', 'glass-edge' ] )[0], 'missing file' );
            $this->assertSame( 2, self::runScript( [ $bad, 'glass-edge' ] )[0], 'a page that does not parse' );
            $this->assertSame( 2, self::runScript( [ $good ] )[0], 'no kit' );
            $this->assertSame( 2, self::runScript( [ $good, 'glass-edge', '--bogus' ] )[0] );
            $this->assertSame( 2, self::runScript( [ $good, 'glass-edge', '--min', 'lots' ] )[0] );
        } finally {
            foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
                unlink( $f );
            }
            rmdir( $dir );
        }
    }
}
