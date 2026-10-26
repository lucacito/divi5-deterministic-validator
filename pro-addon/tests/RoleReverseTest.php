<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Bands;
use AiEditorDivi5\Pro\Design\ContextTable;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\RoleReverse;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\RecipeTokens;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 4: a value of the source design goes back to its role exactly when one role has it; a collision is
 * decided by the library context (one attested role: `context`; one target value: `same-target`; a strict majority:
 * `assumed`; otherwise `ambiguous`), and every decision names its roles (plan Ruling 3, Review Focus 6).
 */
class RoleReverseTest extends TestCase
{
    private const PATH = '.a.color';
    private const FONT_PATH = '.a.family';

    private static function design( string $language ): Design
    {
        return Design::make( $language, (string) DesignLanguages::get( $language )['kit'] );
    }

    /**
     * Source colour roles (surface-glass only where the language sets it) => normalised key.
     *
     * @return array<string,string>
     */
    private static function sourceColours( array $language ): array
    {
        $out = [];
        foreach ( RoleReverse::COLOR_ROLES as $role => $key ) {
            $v = $language['colors'][ $key ] ?? null;
            if ( is_string( $v ) && '' !== $v ) {
                $out[ $role ] = (string) Bands::colourKey( $v );
            }
        }

        return $out;
    }

    /**
     * Groups of colliding roles of one kind in a language.
     *
     * @return list<list<string>>
     */
    private static function collisions( array $byRole ): array
    {
        $groups = [];
        foreach ( $byRole as $role => $v ) {
            $groups[ $v ][] = $role;
        }

        return array_values( array_filter( $groups, static fn ( array $g ): bool => count( $g ) > 1 ) );
    }

    /** @return array<string,string> */
    private static function sourceFonts( array $language ): array
    {
        $out = [];
        foreach ( RoleReverse::FONT_ROLES as $role => $key ) {
            $out[ $role ] = (string) $language['fonts'][ $key ];
        }

        return $out;
    }

    /** A planted library: one divi/text per entry, each carrying the token of that role at `.a.color` / `.a.family`. */
    private static function planted( string $kind, array $roles ): ContextTable
    {
        $markup = '';
        foreach ( $roles as $role ) {
            $leaf    = 'color' === $kind ? '"color":"{{aied:color:' . $role . '}}"' : '"family":"{{aied:font:' . $role . '}}"';
            $markup .= '<!-- wp:divi/text {"a":{' . $leaf . '}} /-->';
        }
        $t = ContextTable::build( [ 'planted' => $markup ] );
        self::assertSame( [], $t->skipped() );

        return $t;
    }

    /** A target language in which the given roles do NOT all have one value (null when none of the ten). */
    private static function differingTarget( string $kind, array $roles ): ?string
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $lang   = DesignLanguages::get( $id );
            $values = 'color' === $kind ? self::sourceColours( $lang ) : self::sourceFonts( $lang );
            $seen   = [];
            foreach ( $roles as $r ) {
                $seen[ $values[ $r ] ?? ( 'color' === $kind ? Bands::colourKey( (string) $lang['colors']['surface'] ) : '' ) ] = true;
            }
            if ( count( $seen ) === count( $roles ) ) {
                return $id;
            }
        }

        return null;
    }

    public function testTheRoleMapsEqualTheFreeOnes(): void
    {
        $this->assertSame( ( new \ReflectionClassConstant( RecipeTokens::class, 'COLOR_ROLES' ) )->getValue(), RoleReverse::COLOR_ROLES );
        $this->assertSame( ( new \ReflectionClassConstant( RecipeTokens::class, 'FONT_ROLES' ) )->getValue(), RoleReverse::FONT_ROLES );
    }

    public function testEveryValueOneRoleHasIsExact(): void
    {
        $empty = ContextTable::build( [] );
        $exact = 0;
        foreach ( DesignLanguages::ids() as $id ) {
            $from = self::design( $id );
            $rr   = new RoleReverse( $from, self::design( 'luxe' ), $empty );
            $colours = self::sourceColours( $from->language );
            $counts  = array_count_values( $colours );
            foreach ( $colours as $role => $key ) {
                $value = (string) $from->language['colors'][ RoleReverse::COLOR_ROLES[ $role ] ];
                $d     = $rr->colour( $value, 'divi/text', self::PATH, 'light' );
                if ( 1 === $counts[ $key ] ) {
                    $this->assertSame( [ 'token' => '{{aied:color:' . $role . '}}', 'status' => 'exact', 'roles' => [ $role ] ], $d, "{$id} {$role}" );
                    ++$exact;
                } else {
                    $this->assertNotSame( 'exact', $d['status'], "{$id} {$role}" );
                }
            }
            $fonts  = self::sourceFonts( $from->language );
            $counts = array_count_values( $fonts );
            foreach ( $fonts as $role => $family ) {
                $d = $rr->font( $family, 'divi/text', self::FONT_PATH, '-' );
                if ( 1 === $counts[ $family ] ) {
                    $this->assertSame( [ 'token' => '{{aied:font:' . $role . '}}', 'status' => 'exact', 'roles' => [ $role ] ], $d, "{$id} {$role}" );
                    ++$exact;
                } else {
                    $this->assertNotSame( 'exact', $d['status'], "{$id} {$role}" );
                }
            }
        }
        // 91 colour roles (9 per language, 10 in glass-dark, which sets surface_glass) minus the 22 roles in the 10 colour
        // collision groups of spec section 3, plus 30 font roles minus the 20 in the 9 font collision groups.
        $this->assertSame( 91 - 22 + 30 - 20, $exact, 'exact decisions over the ten palettes' );
    }

    public function testEveryCollisionFollowsThePlantedContext(): void
    {
        $groups = 0;
        foreach ( DesignLanguages::ids() as $id ) {
            $from = self::design( $id );
            foreach ( [ 'color' => self::sourceColours( $from->language ), 'font' => self::sourceFonts( $from->language ) ] as $kind => $byRole ) {
                foreach ( self::collisions( $byRole ) as $roles ) {
                    ++$groups;
                    $label  = "{$id} {$kind} " . implode( '=', $roles );
                    $value  = 'color' === $kind ? (string) $from->language['colors'][ RoleReverse::COLOR_ROLES[ $roles[0] ] ] : $byRole[ $roles[0] ];
                    $target = self::differingTarget( $kind, $roles );
                    $this->assertNotNull( $target, $label . ': some target tells the roles apart' );
                    $to   = self::design( (string) $target );
                    $ask  = static function ( ContextTable $t, ?Design $tgt = null ) use ( $from, $to, $kind, $value ): array {
                        $rr = new RoleReverse( $from, $tgt ?? $to, $t );

                        return 'color' === $kind ? $rr->colour( $value, 'divi/text', self::PATH, 'light' ) : $rr->font( $value, 'divi/text', self::FONT_PATH, '-' );
                    };
                    $other = 'color' === $kind ? ( in_array( 'muted', $roles, true ) ? 'overlay' : 'muted' ) : null;

                    // One attested role: context, whichever colliding role it is.
                    foreach ( $roles as $r ) {
                        $this->assertSame( [ 'token' => '{{aied:' . $kind . ':' . $r . '}}', 'status' => 'context', 'roles' => $roles ], $ask( self::planted( $kind, [ $r, $r ] ) ), $label . ' attested ' . $r );
                    }
                    // Several attested, a strict majority among them: assumed (the target tells them apart).
                    $d = $ask( self::planted( $kind, [ $roles[1], $roles[0], $roles[1] ] ) );
                    $this->assertSame( [ 'token' => '{{aied:' . $kind . ':' . $roles[1] . '}}', 'status' => 'assumed', 'roles' => $roles ], $d, $label . ' majority' );
                    // A tie for the top: ambiguous.
                    $this->assertSame( [ 'token' => null, 'status' => 'ambiguous', 'roles' => $roles ], $ask( self::planted( $kind, [ $roles[0], $roles[1] ] ) ), $label . ' tie' );
                    // None of the colliding roles attested: ambiguous (the majority is another role, or there is none).
                    $this->assertSame( [ 'token' => null, 'status' => 'ambiguous', 'roles' => $roles ], $ask( ContextTable::build( [] ) ), $label . ' empty context' );
                    if ( null !== $other ) {
                        $this->assertSame( 'ambiguous', $ask( self::planted( $kind, [ $other, $other, $other ] ) )['status'], $label . ' only another role' );
                    }
                    // The majority role is another role: ambiguous, even with the colliding roles attested.
                    if ( null !== $other ) {
                        $this->assertSame( 'ambiguous', $ask( self::planted( $kind, [ $roles[0], $roles[1], $other, $other, $other ] ) )['status'], $label . ' majority elsewhere' );
                    }
                    // Every colliding role gives one target value (here: the source itself): same-target, never left.
                    foreach ( [ [], [ $roles[0], $roles[1] ], [ $roles[1], $roles[0], $roles[1] ] ] as $planted ) {
                        $d = $ask( [] === $planted ? ContextTable::build( [] ) : self::planted( $kind, $planted ), $from );
                        $this->assertSame( 'same-target', $d['status'], $label . ' same target ' . implode( ',', $planted ) );
                        $this->assertSame( $roles, $d['roles'] );
                        $this->assertContains( substr( (string) $d['token'], strlen( '{{aied:' . $kind . ':' ), -2 ), $roles );
                    }
                }
            }
        }
        // Spec section 3: 10 colour collision groups and 9 font collision groups over the ten languages.
        $this->assertSame( 19, $groups );
    }

    public function testEveryNonExactDecisionIsReported(): void
    {
        $table    = ContextTable::current();
        $contexts = self::libraryContexts( $table );
        $outcomes = [ 'color' => [], 'font' => [] ];
        foreach ( DesignLanguages::ids() as $s ) {
            foreach ( DesignLanguages::ids() as $g ) {
                if ( $s === $g ) {
                    continue;
                }
                $from = self::design( $s );
                $rr   = new RoleReverse( $from, self::design( $g ), $table );
                foreach ( $contexts as [ $kind, $block, $path, $ctx, $roles ] ) {
                    foreach ( $roles as $role => $n ) {
                        $token = '{{aied:' . $kind . ':' . $role . '}}';
                        $value = RecipeTokens::resolve( $token, $from->language );
                        // Where the language does not set surface_glass the free resolver writes the surface colour:
                        // its role in the saved page is surface.
                        $role  = 'surface-glass' === $role && ! isset( $from->language['colors']['surface_glass'] ) ? 'surface' : $role;
                        $token = '{{aied:' . $kind . ':' . $role . '}}';
                        $d     = 'color' === $kind ? $rr->colour( $value, $block, $path, $ctx ) : $rr->font( $value, $block, $path, $ctx );
                        $this->assertNotSame( 'unknown', $d['status'], "{$s} {$token}" );
                        $this->assertNotSame( [], $d['roles'], 'a decision always names its roles' );
                        if ( 'exact' === $d['status'] ) {
                            $this->assertSame( $token, $d['token'] );
                            $this->assertCount( 1, $d['roles'] );
                            continue;
                        }
                        $this->assertGreaterThan( 1, count( $d['roles'] ), 'a non-exact decision names every colliding role' );
                        $this->assertContains( $role, $d['roles'] );
                        if ( null === $d['token'] ) {
                            $this->assertSame( 'ambiguous', $d['status'] );
                        } else {
                            $this->assertContains( substr( $d['token'], strlen( '{{aied:' . $kind . ':' ), -2 ), $d['roles'] );
                        }
                        if ( 'context' === $d['status'] ) {
                            $this->assertSame( $token, $d['token'], 'a unanimous context is never wrong on the library' );
                        }
                        $right = $d['token'] === $token ? 'right' : ( null === $d['token'] ? 'left' : 'other' );
                        $key   = $d['status'] . ':' . $right;
                        $outcomes[ $kind ][ $key ] = ( $outcomes[ $kind ][ $key ] ?? 0 ) + $n;
                    }
                }
            }
        }
        foreach ( $outcomes as &$o ) {
            ksort( $o );
        }
        unset( $o );
        // Measured: every library token occurrence whose source value collides, over the 90 ordered pairs of the ten
        // (language, default kit) designs. "other" under same-target writes another colliding role with the same target
        // value (harmless by definition); "assumed:other" is D1, "ambiguous:left" is D3 (colours) and D1 (fonts left).
        $this->assertSame(
            [
                'color' => [ 'ambiguous:left' => 252, 'context:right' => 16110, 'same-target:other' => 36, 'same-target:right' => 36 ],
                'font'  => [ 'ambiguous:left' => 452, 'assumed:other' => 1041, 'assumed:right' => 6671, 'context:right' => 16695, 'same-target:other' => 1084, 'same-target:right' => 10102 ],
            ],
            $outcomes
        );
    }

    /**
     * Every context of the table as [kind, block, path, band or type role, role => count].
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:array<string,int>}>
     */
    private static function libraryContexts( ContextTable $table ): array
    {
        $out = [];
        foreach ( [ 'colour' => 'color', 'font' => 'font' ] as $prop => $kind ) {
            $map = ( new \ReflectionProperty( ContextTable::class, $prop ) )->getValue( $table );
            foreach ( $map as $key => $roles ) {
                [ $block, $path, $ctx ] = explode( "\0", (string) $key );
                $out[] = [ $kind, $block, $path, $ctx, $roles ];
            }
        }

        return $out;
    }

    public function testColoursCompareAsNormalisedKeys(): void
    {
        $rr = new RoleReverse( self::design( 'corporate-trust' ), self::design( 'luxe' ), ContextTable::current() );
        $long = $rr->colour( '#ffffff', 'divi/text', self::PATH, 'light' );
        $this->assertSame( [ 'bg', 'accent-text' ], $long['roles'] );
        foreach ( [ '#FFF', '#fff', '#FFFFFF', ' #ffffff ', 'rgb(255,255,255)', 'rgb(255 255 255)', 'rgba(255, 255, 255, 1)' ] as $same ) {
            $this->assertSame( $long, $rr->colour( $same, 'divi/text', self::PATH, 'light' ), $same );
        }
        $this->assertSame( 'exact', $rr->colour( '#13233F', 'divi/text', self::PATH, 'light' )['status'], 'text, upper case' );
    }

    public function testAValueInNoPaletteIsUnknown(): void
    {
        $rr = new RoleReverse( self::design( 'editorial' ), self::design( 'luxe' ), ContextTable::current() );
        $unknown = [ 'token' => null, 'status' => 'unknown', 'roles' => [] ];
        foreach ( [ '#123456', 'rgba(255,255,255,0.5)', 'transparent', '', 'not a colour', '16px', '{{aied:color:text}}' ] as $v ) {
            $this->assertSame( $unknown, $rr->colour( $v, 'divi/text', self::PATH, 'light' ), $v );
        }
        foreach ( [ 'Comic Sans', 'playfair display', 'Playfair Display, serif', '' ] as $v ) {
            $this->assertSame( $unknown, $rr->font( $v, 'divi/text', self::FONT_PATH, '-' ), $v );
        }
        // surface-glass is not a role of a language that does not set it: the surface colour reads as surface.
        $this->assertSame( [ 'surface', 'accent-text' ], $rr->colour( '#ffffff', 'divi/text', self::PATH, 'light' )['roles'] );
        $glass = new RoleReverse( self::design( 'glass-dark' ), self::design( 'luxe' ), ContextTable::current() );
        $this->assertSame( [ 'token' => '{{aied:color:surface-glass}}', 'status' => 'exact', 'roles' => [ 'surface-glass' ] ], $glass->colour( 'rgba(255,255,255,0.06)', 'divi/group', self::PATH, 'dark' ) );
    }

    public function testAlphaRoundTrip(): void
    {
        $tokens = 0;
        $nulls  = 0;
        foreach ( DesignLanguages::ids() as $id ) {
            $from = self::design( $id );
            $rr   = new RoleReverse( $from, self::design( 'luxe' ), ContextTable::build( [] ) );
            $rgb  = array_map( static fn ( string $k ): string => implode( ',', array_slice( explode( ',', $k ), 0, 3 ) ), self::sourceColours( $from->language ) );
            $shared = array_count_values( $rgb );
            foreach ( array_keys( $rgb ) as $role ) {
                foreach ( [ 10, 40, 45, 70 ] as $nn ) {
                    $token = '{{aied:color:' . $role . ':' . $nn . '}}';
                    $value = RecipeTokens::resolve( $token, $from->language );
                    $this->assertStringNotContainsString( '{{', $value, $token );
                    $want = 1 === $shared[ $rgb[ $role ] ] ? $token : null;
                    $this->assertSame( $want, $rr->alpha( $value ), "{$id} {$token} = {$value}" );
                    $this->assertSame( $want, $rr->alpha( strtoupper( str_replace( ',', ', ', $value ) ) ), "{$id} {$token} spaced" );
                    null === $want ? ++$nulls : ++$tokens;
                }
            }
        }
        $this->assertSame( 4 * 91, $tokens + $nulls, 'every role x four percents in the ten languages' );
        $this->assertGreaterThan( 0, $nulls, 'some languages share channels between roles' );

        $rr = new RoleReverse( self::design( 'editorial' ), self::design( 'luxe' ), ContextTable::build( [] ) );
        foreach ( [ '#1c1a17', 'rgba(28,26,23,1)', 'rgba(28,26,23,0)', 'rgba(28,26,23,0.405)', 'rgba(1,2,3,0.4)', 'junk', '' ] as $v ) {
            $this->assertNull( $rr->alpha( $v ), $v );
        }
        $this->assertSame( '{{aied:color:text:40}}', $rr->alpha( 'rgba(28,26,23,0.4)' ) );
    }

    public function testTheSameInputGivesTheSameAnswer(): void
    {
        $a = new RoleReverse( self::design( 'glass-dark' ), self::design( 'luxe' ), ContextTable::current() );
        $b = new RoleReverse( self::design( 'glass-dark' ), self::design( 'luxe' ), ContextTable::build( ContextTable::installedRecipes() ) );
        $path = '.content.decoration.bodyFont.body.font.desktop.value.family';
        $this->assertSame( $a->font( 'Inter', 'divi/text', $path, '-' ), $b->font( 'Inter', 'divi/text', $path, '-' ) );
        $this->assertSame( [ 'token' => '{{aied:font:body}}', 'status' => 'assumed', 'roles' => [ 'display', 'body', 'eyebrow' ] ], $a->font( 'Inter', 'divi/text', $path, '-' ), 'D1: the library majority' );
        $this->assertSame( [ 'token' => '{{aied:font:eyebrow}}', 'status' => 'context', 'roles' => [ 'display', 'body', 'eyebrow' ] ], $a->font( 'Inter', 'divi/text', $path, 'eyebrow' ) );
    }
}
