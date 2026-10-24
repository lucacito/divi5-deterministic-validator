<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

/**
 * The language step of the recipe pipeline (type adapter plan Task 3): a top-level `"aied":{"type":"<role>"}` on a block the
 * role belongs to is replaced by LanguageAdapters' patch and stripped; everything else is left byte-identical and the type
 * markers left are reported. It runs BEFORE the kit step, and the scanner it shares with KitResolver (BlockAttrs) changed no
 * byte of the kit step's output.
 */
class LanguageResolverTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private function languages(): array
    {
        $out = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $out[ $id ] = (array) DesignLanguages::get( $id );
        }
        $this->assertCount( 10, $out );
        return $out;
    }

    private static function heading( string $level, string $marker, string $extra = '' ): string
    {
        return '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A headline"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"' . $level . '","size":"44px","family":"{{aied:font:display}}","color":"{{aied:color:text}}"' . $extra . '}},"phone":{"value":{"size":"30px"}}}}}},"builderVersion":"5.15.1"' . ( '' === $marker ? '' : ',' . $marker ) . '} /-->';
    }

    private static function eyebrowText( string $marker, string $extra = '' ): string
    {
        return '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Label</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"size":"13px","family":"{{aied:font:eyebrow}}","color":"{{aied:color:muted}}"' . $extra . '}}}}}}},"builderVersion":"5.15.1"' . ( '' === $marker ? '' : ',' . $marker ) . '} /-->';
    }

    private static function button( string $marker ): string
    {
        return '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start"}}}},"builderVersion":"5.15.1"' . ( '' === $marker ? '' : ',' . $marker ) . '} /-->';
    }

    private static function type( string $role ): string
    {
        return '"aied":{"type":"' . $role . '"}';
    }

    /** @return array<string,mixed> the attributes of the first block named $block in $markup */
    private function attrs( string $markup, string $block ): array
    {
        $this->assertSame( 1, preg_match( '/<!-- wp:' . preg_quote( $block, '/' ) . ' (\{.*?\}) \/-->/s', $markup, $m ), "one $block" );
        $a = json_decode( $m[1], true );
        $this->assertIsArray( $a );
        return $a;
    }

    public function testEachRoleResolvesAndIsStrippedOnItsCarriers(): void
    {
        $literal = ',"weight":"300","letterSpacing":"9px","capitalization":"lowercase"';
        $cases   = [
            [ 'h1', 'display' ], [ 'h2', 'display' ], [ 'h3', 'title' ], [ 'h4', 'eyebrow' ],
        ];
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( $cases as [ $level, $role ] ) {
                foreach ( [ '', $literal ] as $extra ) {
                    $out = LanguageResolver::resolve( self::heading( $level, self::type( $role ), $extra ), $lang );
                    $this->assertStringNotContainsString( '"aied"', $out, "$id $level" );
                    $this->assertSame( [], LanguageResolver::leftovers( $out ) );
                    $a     = $this->attrs( $out, 'divi/heading' );
                    $this->assertArrayNotHasKey( 'aied', $a );
                    $value = $a['title']['decoration']['font']['font']['desktop']['value'];
                    $t     = $lang['type'][ $role ];
                    $this->assertSame( [ 'headingLevel', 'size', 'family', 'color' ], array_slice( array_keys( $value ), 0, 4 ), 'existing keys keep their place' );
                    $this->assertSame( $level, $value['headingLevel'] );
                    $this->assertSame( '44px', $value['size'] );
                    $this->assertSame( '{{aied:font:display}}', $value['family'] );
                    $this->assertSame( '{{aied:color:text}}', $value['color'] );
                    $this->assertSame( $t['weight'], $value['weight'], "$id $level: the language wins over a literal" );
                    $this->assertSame( $t['letterSpacing'], $value['letterSpacing'] );
                    if ( 'uppercase' === $t['capitalization'] ) {
                        $this->assertSame( 'uppercase', $value['capitalization'] );
                    } elseif ( '' === $extra ) {
                        $this->assertArrayNotHasKey( 'capitalization', $value, "$id $level: a none role writes no key" );
                    }
                    $this->assertSame( [ 'value' => [ 'size' => '30px' ] ], $a['title']['decoration']['font']['font']['phone'], 'other breakpoints untouched' );
                    $this->assertSame( '5.15.1', $a['builderVersion'] );
                }
            }
            $out   = LanguageResolver::resolve( self::eyebrowText( self::type( 'eyebrow' ), $literal ), $lang );
            $this->assertStringNotContainsString( '"aied"', $out );
            $value = $this->attrs( $out, 'divi/text' )['content']['decoration']['bodyFont']['body']['font']['desktop']['value'];
            $this->assertSame( [ 'size', 'family', 'color' ], array_slice( array_keys( $value ), 0, 3 ) );
            $this->assertSame( $lang['type']['eyebrow']['weight'], $value['weight'] );
            $this->assertSame( $lang['type']['eyebrow']['letterSpacing'], $value['letterSpacing'] );
            $this->assertSame( 'uppercase' === $lang['type']['eyebrow']['capitalization'] ? 'uppercase' : 'lowercase', $value['capitalization'] );
        }
    }

    public function testMarkersTheResolverDoesNotKnowAreLeftAndReported(): void
    {
        $lang = $this->languages()['editorial'];
        $left = [
            'display on a text'        => self::eyebrowText( self::type( 'display' ) ),
            'title on a text'          => self::eyebrowText( self::type( 'title' ) ),
            'eyebrow on a button'      => self::button( self::type( 'eyebrow' ) ),
            'an unknown role'          => self::heading( 'h2', self::type( 'headline' ) ),
            'an extra key'             => self::heading( 'h2', '"aied":{"type":"display","on":"light"}' ),
            'a kit key too'            => self::heading( 'h2', '"aied":{"type":"display","kit":"control"}' ),
            'a kit key too (button)'   => self::button( '"aied":{"kit":"control","type":"display"}' ),
            'a non-string role'        => self::heading( 'h2', '"aied":{"type":["display"]}' ),
            'a numeric role'           => self::heading( 'h2', '"aied":{"type":1}' ),
            'a list marker'            => self::heading( 'h2', '"aied":["type"]' ),
        ];
        foreach ( $left as $label => $markup ) {
            $this->assertSame( $markup, LanguageResolver::resolve( $markup, $lang ), "$label: byte-identical" );
            if ( 'a list marker' === $label ) {
                $this->assertSame( [], LanguageResolver::leftovers( $markup ), 'a list has no type key: the kit step reports it' );
                $this->assertNotSame( [], KitResolver::leftovers( $markup ) );
            } else {
                $this->assertSame( [ LanguageResolver::LEFT['type'] ], LanguageResolver::leftovers( $markup ), $label );
            }
        }
        // Below the top level, and malformed JSON: byte-identical; the kit step's catch-all reports them.
        $nested    = '<!-- wp:divi/heading {"title":{"aied":{"type":"display"}},"builderVersion":"5.15.1"} /-->';
        $malformed = '<!-- wp:divi/heading {"title":{"x":1},"aied":{"type":"display"},} /-->';
        foreach ( [ $nested, $malformed ] as $markup ) {
            $this->assertSame( $markup, LanguageResolver::resolve( $markup, $lang ) );
            $this->assertSame( [], LanguageResolver::leftovers( $markup ) );
            $this->assertNotSame( [], KitResolver::leftovers( $markup ) );
        }
        // Several left: one entry, in order of first appearance; nothing the content wrote is echoed.
        $many = self::button( self::type( 'eyebrow' ) ) . self::eyebrowText( self::type( 'display' ) );
        $this->assertSame( [ '{"aied":{"type":...}}' ], LanguageResolver::leftovers( $many ) );
        $this->assertSame( [], LanguageResolver::leftovers( '' ) );
        $this->assertSame( [], LanguageResolver::leftovers( self::heading( 'h2', '' ) ) );
    }

    public function testKitMarkersAreLeftForTheKitStep(): void
    {
        $lang = $this->languages()['editorial'];
        foreach ( [ self::button( '"aied":{"kit":"control"}' ), self::button( '"aied":{"kit":"control","on":"dark"}' ), '<!-- wp:divi/group {"builderVersion":"5.15.1","aied":{"kit":"card","on":"light"}} --><!-- /wp:divi/group -->' ] as $markup ) {
            $this->assertSame( $markup, LanguageResolver::resolve( $markup, $lang ) );
            $this->assertSame( [], LanguageResolver::leftovers( $markup ) );
        }
    }

    public function testUntouchedBlocksStayByteIdentical(): void
    {
        $lang  = $this->languages()['luxe'];
        // Odd but valid spacing and escapes in the unmarked blocks: a re-encode would change them.
        $plain = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>A \/ b<\/p>"}}},"builderVersion":"5.0.0"} /-->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"<b>x</b>"}}},  "builderVersion":"5.0.0"} /-->';
        $marked = self::heading( 'h3', self::type( 'title' ) );
        $out    = LanguageResolver::resolve( $plain . $marked . $plain, $lang );
        $this->assertStringStartsWith( $plain, $out );
        $this->assertStringEndsWith( $plain, $out );
        $this->assertNotSame( $plain . $marked . $plain, $out );
        $this->assertSame( $plain, LanguageResolver::resolve( $plain, $lang ) );
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( ! str_contains( $raw, '"aied":{"type"' ) ) {
                $this->assertSame( $raw, LanguageResolver::resolve( $raw, $lang ), "$name has no type marker: unchanged" );
            }
        }
    }

    public function testAnInvalidLanguageLeavesEveryMarker(): void
    {
        $markup = self::heading( 'h2', self::type( 'display' ) ) . self::eyebrowText( self::type( 'eyebrow' ) );
        $lang   = $this->languages()['editorial'];
        $bad    = $lang;
        $bad['type']['title']['letterSpacing'] = '-0.5px';
        $noType = $lang;
        unset( $noType['type'] );
        foreach ( [ $bad, $noType, [] ] as $language ) {
            $this->assertSame( $markup, LanguageResolver::resolve( $markup, $language ) );
            $this->assertSame( [ LanguageResolver::LEFT['type'] ], LanguageResolver::leftovers( $markup ) );
        }
    }

    public function testTheResolverOnlyReadsBlockComments(): void
    {
        $lang   = $this->languages()['editorial'];
        $inText = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>\"aied\":{\"type\":\"display\"}</p>"}}},"builderVersion":"5.15.1"} /-->';
        $loose  = '<p>"aied":{"type":"display"}</p><!-- not a block "aied":{"type":"display"} -->';
        foreach ( [ $inText, $loose, $inText . $loose ] as $markup ) {
            $this->assertSame( $markup, LanguageResolver::resolve( $markup, $lang ) );
            $this->assertSame( [], LanguageResolver::leftovers( $markup ) );
        }
    }

    public function testTheAdapterReceivesTheBlockKindRoleAndLanguage(): void
    {
        $lang  = $this->languages()['glass-dark'];
        $calls = [];
        $spy   = static function ( string $block, string $kind, string $role, array $language ) use ( &$calls ) {
            $calls[] = [ $block, $kind, $role, $language['id'] ];
            return 'divi/text' === $block ? null : [ 'title' => [ 'x' => '1' ], 'aied' => [ 'type' => 'display' ] ];
        };
        $markup = self::heading( 'h2', self::type( 'display' ) ) . self::eyebrowText( self::type( 'eyebrow' ) );
        $out    = LanguageResolver::resolve( $markup, $lang, $spy );
        $this->assertSame( [ [ 'divi/heading', 'type', 'display', 'glass-dark' ], [ 'divi/text', 'type', 'eyebrow', 'glass-dark' ] ], $calls );
        $this->assertSame( '1', $this->attrs( $out, 'divi/heading' )['title']['x'] );
        $this->assertArrayNotHasKey( 'aied', $this->attrs( $out, 'divi/heading' ), 'a patch can never put a marker back' );
        $this->assertStringEndsWith( self::eyebrowText( self::type( 'eyebrow' ) ), $out, 'a null patch leaves the block byte-identical' );
        $this->assertSame( [ LanguageResolver::LEFT['type'] ], LanguageResolver::leftovers( $out ) );
    }

    /** One marked heading of every role, an eyebrow text and two kit-marked buttons, as a recipe would carry them. */
    private static function mixedFixture(): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . self::eyebrowText( self::type( 'eyebrow' ) )
            . self::heading( 'h1', self::type( 'display' ) )
            . self::heading( 'h3', self::type( 'title' ) )
            . self::heading( 'h4', self::type( 'eyebrow' ) )
            . self::button( '"aied":{"kit":"control"}' )
            . self::button( '"aied":{"kit":"control-secondary"}' )
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    public function testATypeMarkerNeverReachesTheKitStep(): void
    {
        foreach ( $this->languages() as $id => $lang ) {
            $kit = (array) StyleKits::get( (string) $lang['kit'] );
            // The pipeline resolveMarkup() runs, spelled out with a spy on the kit step's adapter.
            $seen     = [];
            $spy      = static function ( string $block, ...$rest ) use ( &$seen ) {
                $seen[] = $block;
                return KitAdapters::patch( $block, ...$rest );
            };
            $language = LanguageResolver::resolve( self::mixedFixture(), $lang );
            $this->assertSame( [], LanguageResolver::leftovers( $language ) );
            $this->assertStringNotContainsString( '"aied":{"type"', $language, "$id: no type marker is left for the kit step" );
            $kitted   = KitResolver::resolve( $language, $kit, $spy );
            $this->assertSame( [ 'divi/button', 'divi/button' ], $seen, "$id: the kit step's adapter sees the buttons only" );
            $this->assertSame( RecipeTokens::resolve( $kitted, $lang ), SectionRecipes::resolveMarkup( self::mixedFixture(), $lang, $kit ), "$id: resolveMarkup() is that pipeline" );
        }

        // A marker the language step cannot resolve: null before the kit step (a combined marker would otherwise reach it).
        $lang = $this->languages()['editorial'];
        $kit  = (array) StyleKits::get( (string) $lang['kit'] );
        foreach ( [ self::button( self::type( 'eyebrow' ) ), self::eyebrowText( self::type( 'display' ) ), self::heading( 'h2', '"aied":{"type":"display","kit":"control"}' ), self::button( '"aied":{"kit":"control","type":"eyebrow"}' ) ] as $bad ) {
            $this->assertNotSame( [], LanguageResolver::leftovers( LanguageResolver::resolve( $bad, $lang ) ) );
            $this->assertNull( SectionRecipes::resolveMarkup( $bad, $lang, $kit ) );
        }
        $this->assertNull( SectionRecipes::resolveMarkup( self::button( '"aied":{"kit":"card"}' ), $lang, $kit ), 'a kit leftover is still refused after the kit step' );
    }

    public function testTheSharedScannerKeepsKitOutputByteIdentical(): void
    {
        $kits = [];
        foreach ( $this->languages() as $lang ) {
            $kits[ (string) $lang['kit'] ] = (array) StyleKits::get( (string) $lang['kit'] );
        }
        $this->assertCount( 10, $kits, 'the ten default kits' );
        $recipes = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( ! str_contains( $raw, '"aied":{"kit"' ) ) {
                continue;
            }
            ++$recipes;
            foreach ( $kits as $id => $kit ) {
                $this->assertSame( LegacyKitResolver::resolve( $raw, $kit ), KitResolver::resolve( $raw, $kit ), "$name / $id" );
            }
        }
        $this->assertSame( 45, $recipes, 'the 45 kit-marker recipes' );
        // The scanner edge cases of KitResolverTest, too.
        $kit = $kits['quiet-solid'] ?? reset( $kits );
        foreach ( [
            '<!-- wp:divi/button {"builderVersion":"5.15.1","aied":{"kit":"control"} -->',
            '<!-- wp:divi/button {"a":"x -- y","aied":{"kit":"control"}} /-->',
            '<!-- wp:divi/button {"a":"}","aied":{"kit":"control"}} /-->',
            '<!-- wp:divi/button {"a":"aied","radius":"{{aied:kit:radius-control}}"} /-->',
            '<!-- wp:divi/button {"builderVersion":"5.15.1",' . '"x":"' . str_repeat( 'a', 50000 ) . '","aied":{"kit":"control"}} /-->',
        ] as $edge ) {
            $this->assertSame( LegacyKitResolver::resolve( $edge, $kit ), KitResolver::resolve( $edge, $kit ) );
        }
    }
}

/**
 * KitResolver::resolve() exactly as it was before the scanner moved to BlockAttrs (main 77abc24), kept here so the move is
 * proven byte-identical. Do not edit.
 */
final class LegacyKitResolver
{
    private const ROLE_BLOCKS = [
        'control'           => [ 'divi/button' ],
        'control-secondary' => [ 'divi/button' ],
        'card'              => [ 'divi/group', 'divi/column' ],
        'panel'             => [ 'divi/group', 'divi/column' ],
        'rule'              => [ 'divi/group', 'divi/column', 'divi/divider' ],
        'rule-strong'       => [ 'divi/group', 'divi/column', 'divi/divider' ],
        'chip'              => [ 'divi/group', 'divi/icon' ],
        'item'              => [ 'divi/accordion', 'divi/toggle' ],
        'item-rule'         => [ 'divi/accordion', 'divi/toggle' ],
        'form'              => [ 'divi/contact-form' ],
    ];
    private const ROLE_SURFACES = [
        'control'           => [ 'light', 'accent', 'dark', 'panel' ],
        'control-secondary' => [ 'light', 'accent', 'dark', 'panel' ],
        'card'              => [ 'light', 'surface', 'accent', 'dark' ],
        'panel'             => [ 'light', 'surface', 'accent', 'dark' ],
        'item'              => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule'         => [ 'light', 'surface' ],
        'form'              => [ 'light', 'surface', 'accent', 'dark' ],
        'rule'              => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'rule-strong'       => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'chip'              => [ 'light', 'surface', 'accent', 'dark' ],
    ];
    private const ROLE_GROUNDS    = [ 'card' => [ 'inverted', 'accent' ], 'panel' => [ 'inverted', 'accent' ] ];
    private const GROUND_SURFACES = [ 'light', 'surface' ];
    private const BLOCK           = '/(<!--\s+wp:(divi\/[a-z0-9-]+)\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s';
    private const WHOLE_TOKEN     = '/^\{\{aied:kit:([a-z0-9-]+)\}\}\z/';
    private const DEPTH           = 64;

    /** @param array<string,mixed> $kit */
    public static function resolve( string $markup, array $kit ): string
    {
        if ( [] !== StyleKits::problems( $kit ) || ( ! str_contains( $markup, 'aied' ) && ! str_contains( $markup, '\\u' ) ) ) {
            return $markup;
        }
        $adapter = [ KitAdapters::class, 'patch' ];
        $out     = preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $kit, $adapter ): string {
                $attrs = json_decode( $m[3], false, self::DEPTH );
                if ( ! $attrs instanceof \stdClass ) {
                    return $m[0];
                }
                $changed = false;
                if ( property_exists( $attrs, 'aied' ) ) {
                    $marker = self::marker( $attrs->aied, $m[2] );
                    if ( null === $marker ) {
                        return $m[0];
                    }
                    $patch = null === $marker[2] ? $adapter( $m[2], $marker[0], $marker[1], $kit ) : $adapter( $m[2], $marker[0], $marker[1], $kit, $marker[2] );
                    if ( ! is_array( $patch ) ) {
                        return $m[0];
                    }
                    self::merge( $attrs, $patch );
                    unset( $attrs->aied );
                    $changed = true;
                }
                $attrs = self::tokens( $attrs, $kit, $changed );
                if ( ! $changed ) {
                    return $m[0];
                }
                $json = RecipeTokens::encodeJson( $attrs );

                return '' === $json ? $m[0] : $m[1] . $json . $m[4];
            },
            $markup
        );

        return is_string( $out ) ? $out : $markup;
    }

    /** @param mixed $marker @return ?array{0:string,1:string,2:?string} */
    private static function marker( $marker, string $block ): ?array
    {
        if ( ! $marker instanceof \stdClass ) {
            return null;
        }
        $vars = get_object_vars( $marker );
        $keys = array_keys( $vars );
        sort( $keys );
        if ( [ 'kit' ] !== $keys && [ 'kit', 'on' ] !== $keys && [ 'ground', 'kit' ] !== $keys && [ 'ground', 'kit', 'on' ] !== $keys ) {
            return null;
        }
        $role = $vars['kit'];
        $on   = array_key_exists( 'on', $vars ) ? $vars['on'] : 'light';
        if ( ! is_string( $role ) || ! is_string( $on ) || ! in_array( $on, self::ROLE_SURFACES[ $role ] ?? [], true ) ) {
            return null;
        }
        if ( ! in_array( $block, self::ROLE_BLOCKS[ $role ] ?? [], true ) ) {
            return null;
        }
        $ground = null;
        if ( array_key_exists( 'ground', $vars ) ) {
            $ground = $vars['ground'];
            if ( ! is_string( $ground ) || ! in_array( $ground, self::ROLE_GROUNDS[ $role ] ?? [], true ) || ! in_array( $on, self::GROUND_SURFACES, true ) ) {
                return null;
            }
        }

        return [ $role, $on, $ground ];
    }

    /** @param array<mixed> $patch */
    private static function merge( \stdClass $node, array $patch ): void
    {
        foreach ( $patch as $key => $value ) {
            $key = (string) $key;
            if ( is_array( $value ) && [] !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) && isset( $node->{$key} ) && $node->{$key} instanceof \stdClass ) {
                self::merge( $node->{$key}, $value );
            } else {
                $node->{$key} = $value;
            }
        }
    }

    /** @param mixed $node @param array<string,mixed> $kit @return mixed */
    private static function tokens( $node, array $kit, bool &$changed )
    {
        if ( is_string( $node ) ) {
            if ( 1 === preg_match( self::WHOLE_TOKEN, $node, $t ) ) {
                $value = StyleKits::valueToken( $kit, $t[1] );
                if ( null !== $value ) {
                    $changed = true;
                    return $value;
                }
            }
            return $node;
        }
        if ( $node instanceof \stdClass ) {
            foreach ( get_object_vars( $node ) as $key => $child ) {
                $node->{$key} = self::tokens( $child, $kit, $changed );
            }
            return $node;
        }
        if ( is_array( $node ) ) {
            foreach ( $node as $key => $child ) {
                $node[ $key ] = self::tokens( $child, $kit, $changed );
            }
        }
        return $node;
    }
}
