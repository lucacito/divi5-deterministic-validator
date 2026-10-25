<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';

/**
 * The kit resolver: block-comment markers ("aied":{"kit":"<role>"}) get the adapter's patch merged in and lose the
 * marker; whole-value kit tokens become objects. Only block-comment attribute JSON is ever parsed; every block
 * without a marker or token stays byte-identical.
 */
class KitResolverTest extends TestCase
{
    private const BLOCK = '/<!--\s+wp:divi\/[a-z0-9-]+\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})\s+\/?-->/s';

    /** @return array<string,mixed> */
    private function kit(): array
    {
        $kit = StyleKits::get( StyleKits::ids()[0] );
        $this->assertIsArray( $kit );
        $this->assertSame( [], StyleKits::problems( $kit ) );
        return $kit;
    }

    /** A fake adapter: a fixed patch for divi/button + control/control-secondary, null otherwise. */
    private function adapter( ?array $patch = null ): callable
    {
        $patch ??= [
            'button' => [
                'decoration' => [
                    'background' => [ 'desktop' => [ 'value' => [ 'color' => 'transparent' ] ] ],
                    'spacing'    => [ 'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ] ],
                ],
            ],
        ];
        return static function ( string $block, string $role, string $on, array $kit ) use ( $patch ): ?array {
            return 'divi/button' === $block && in_array( $role, [ 'control', 'control-secondary' ], true ) ? $patch : null;
        };
    }

    private function button( string $extra = ',"aied":{"kit":"control"}' ): string
    {
        return '<!-- wp:divi/button {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},'
            . '"button":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"{{aied:color:accent-text}}"}}}},'
            . '"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}},'
            . '"border":{"desktop":{"value":{"styles":{"all":{"color":"{{aied:color:accent}}"}}}}}},'
            . '"innerContent":{"desktop":{"value":{"text":"Learn More","id":0}}}},'
            . '"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]' . $extra . '} /-->';
    }

    /** @return list<string> every divi block comment's attribute JSON, raw */
    private function comments( string $markup ): array
    {
        preg_match_all( self::BLOCK, $markup, $m );
        return $m[1];
    }

    private function noWarnings( callable $fn ): mixed
    {
        set_error_handler( static function ( int $no, string $str ): bool {
            throw new \ErrorException( $str, 0, $no );
        } );
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function testTheMarkersKeyOrderDoesNotMatter(): void
    {
        $inOrder  = KitResolver::resolve( $this->button( ',"aied":{"kit":"control","on":"dark"}' ), $this->kit(), $this->adapter() );
        $reversed = KitResolver::resolve( $this->button( ',"aied":{"on":"dark","kit":"control"}' ), $this->kit(), $this->adapter() );
        $this->assertSame( $inOrder, $reversed );
        $this->assertSame( [], KitResolver::leftovers( $reversed ), 'a reversed marker is resolved, not left' );
        $this->assertStringNotContainsString( '"aied"', $reversed );
    }

    public function testMarkedButtonGetsAdapterPatchAndLosesMarker(): void
    {
        $out   = KitResolver::resolve( $this->button(), $this->kit(), $this->adapter() );
        $json  = $this->comments( $out );
        $this->assertCount( 1, $json );
        $attrs = json_decode( $json[0], true );
        $this->assertIsArray( $attrs );

        $this->assertSame( [ 'module', 'button', 'builderVersion', 'modulePreset' ], array_keys( $attrs ), 'marker gone, order kept' );
        $this->assertSame( [ 'decoration', 'innerContent' ], array_keys( $attrs['button'] ) );
        $this->assertSame( [ 'font', 'background', 'border', 'spacing' ], array_keys( $attrs['button']['decoration'] ), 'untouched keys keep their order, new keys are appended' );
        $this->assertSame( 'transparent', $attrs['button']['decoration']['background']['desktop']['value']['color'], 'patch wins' );
        $this->assertSame( '{{aied:color:accent-text}}', $attrs['button']['decoration']['font']['font']['desktop']['value']['color'], 'recipe keys not in the patch survive' );
        $this->assertSame( [ 'text' => 'Learn More', 'id' => 0 ], $attrs['button']['innerContent']['desktop']['value'] );
        $this->assertSame( [ 'default' ], $attrs['modulePreset'] );
        $this->assertSame(
            [ 'top' => '14px', 'right' => '28px', 'bottom' => '14px', 'left' => '28px' ],
            $attrs['button']['decoration']['spacing']['desktop']['value']['padding'],
            'a kit token the adapter emits is resolved too'
        );
        $this->assertStringEndsWith( '} /-->', $out );
        $this->assertSame( [], KitResolver::leftovers( $out ) );
    }

    public function testAListInThePatchReplacesTheRecipesListAndIsNotAppended(): void
    {
        $out   = KitResolver::resolve( $this->button(), $this->kit(), $this->adapter( [ 'modulePreset' => [ 'kit' ] ] ) );
        $attrs = json_decode( $this->comments( $out )[0], true );
        $this->assertSame( [ 'kit' ], $attrs['modulePreset'] );
    }

    public function testOnlyMarkedBlocksAreReencoded(): void
    {
        $raw = SectionRecipes::rawMarkup( 'hero-cta' );
        $this->assertIsString( $raw );
        // The language step runs first, as when a recipe is served (type adapter Task 5: recipe headings carry type markers).
        $raw = LanguageResolver::resolve( $raw, (array) RecipeTokens::default() );
        $this->assertStringNotContainsString( '"aied":{"type"', $raw );
        $before = $this->comments( $raw );
        $index  = null;
        foreach ( $before as $i => $json ) {
            if ( null === $index && str_contains( $raw, 'wp:divi/button ' . $json ) ) {
                $index = $i;
            }
        }
        $this->assertNotNull( $index, 'hero-cta has a button' );
        $marked = str_replace( 'wp:divi/button ' . $before[ $index ], 'wp:divi/button ' . substr( $before[ $index ], 0, -1 ) . ',"aied":{"kit":"control"}}', $raw );
        $this->assertNotSame( $raw, $marked );

        $out   = KitResolver::resolve( $marked, $this->kit(), $this->adapter() );
        $after = $this->comments( $out );
        $this->assertCount( count( $before ), $after );
        foreach ( $before as $i => $json ) {
            if ( $i !== $index ) {
                $this->assertSame( $json, $after[ $i ], "block $i is byte-identical" );
            }
        }
        $this->assertNotSame( $before[ $index ], $after[ $index ] );
        // Everything outside the block comments is byte-identical too.
        $this->assertSame( preg_replace( self::BLOCK, '', $raw ), preg_replace( self::BLOCK, '', $out ) );
        $this->assertSame( [], KitResolver::leftovers( $out ) );
    }

    public function testTextThatContainsTheMarkerIsNeverRewritten(): void
    {
        $spelling = '"aied":{"kit":"control"}';
        $inJson   = json_encode( '<p>' . $spelling . ' and {{aied:kit:radius-card}}</p>', JSON_UNESCAPED_SLASHES );
        $text     = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":' . $inJson . '}}},"builderVersion":"5.0.0"} -->'
            . '<p>' . $spelling . '</p><p>{' . $spelling . '}</p>'
            . '<!-- /wp:divi/text -->';
        $markup = $text . $this->button( '' );

        $out = KitResolver::resolve( $markup, $this->kit(), $this->adapter() );
        $this->assertSame( $markup, $out );
        $this->assertSame( $out, KitResolver::resolve( $out, $this->kit(), $this->adapter() ) );
        // The kit token in the text is not a whole value: it stays, and is reported (never echoed).
        $this->assertSame( [ KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $out ) );
        $this->assertSame( [], KitResolver::leftovers( str_replace( '{{aied:kit:radius-card}}', '', $out ) ), 'marker spelling in text is not a marker' );

        // Hostile raw text inside an attribute string: a raw `} -->`, and a raw nested block comment. Never rewritten;
        // the cut-off attributes do not decode and are reported as malformed.
        $rawClose  = '<!-- wp:divi/text {"aied":{"kit":"control"},"t":"a} --> b"} -->x<!-- /wp:divi/text -->';
        $rawNested = '<!-- wp:divi/text {"t":"<!-- wp:divi/button {"aied":{"kit":"control"}} /-->"} -->x<!-- /wp:divi/text -->';
        $escNested = '<!-- wp:divi/text {"t":"<!-- wp:divi/button {\\"aied\\":{\\"kit\\":\\"control\\"}} /-->"} -->x<!-- /wp:divi/text -->';
        foreach ( [ 'raw -->' => $rawClose, 'raw nested comment' => $rawNested, 'escaped nested comment' => $escNested ] as $label => $hostile ) {
            $this->assertSame( $hostile, $this->noWarnings( fn() => KitResolver::resolve( $hostile, $this->kit(), $this->adapter() ) ), $label );
            $this->assertSame( [ KitResolver::LEFT_MALFORMED ], KitResolver::leftovers( $hostile ), $label );
        }
    }

    public function testUnknownRoleOnOrBlockAreLeftAndReported(): void
    {
        $buttonJson = static fn( string $extra ): string => '{"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}},"modulePreset":["default"]' . $extra . '}';
        $cases = [
            'unknown role'        => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":"card"}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'unknown on'          => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":"control","on":"sideways"}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'on null'             => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":"control","on":null}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'on not a string'     => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":"control","on":1}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'role not a string'   => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":["control"]}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'extra key'           => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":{"kit":"control","x":"y"}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'marker not object'   => [ '<!-- wp:divi/button ' . $buttonJson( ',"aied":"control"' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'control on blurb'    => [ '<!-- wp:divi/blurb ' . $buttonJson( ',"aied":{"kit":"control"}' ) . ' /-->', KitResolver::LEFT_MARKER ],
            'secondary on text'   => [ '<!-- wp:divi/text ' . $buttonJson( ',"aied":{"kit":"control-secondary"}' ) . ' -->x<!-- /wp:divi/text -->', KitResolver::LEFT_MARKER ],
            'not top level'       => [ '<!-- wp:divi/button {"button":{"aied":{"kit":"control"}},"modulePreset":["default"]} /-->', KitResolver::LEFT_NESTED ],
            'malformed json'      => [ '<!-- wp:divi/button {"button":{"aied":{"kit":"control"},} /-->', KitResolver::LEFT_MALFORMED ],
            'unknown kit token'   => [ '<!-- wp:divi/button {"radius":"{{aied:kit:radius-bogus}}"} /-->', KitResolver::LEFT_TOKEN ],
        ];
        $kit = $this->kit();
        foreach ( $cases as $label => [ $markup, $entry ] ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit, $this->adapter() ) );
            $this->assertSame( $markup, $out, $label . ': left untouched' );
            $this->assertSame( [ $entry ], KitResolver::leftovers( $out ), $label . ': reported' );
        }
    }

    public function testAKnownMarkerTheAdapterCannotServeIsLeftAndReported(): void
    {
        $none   = static fn( string $b, string $r, string $o, array $k ): ?array => null;
        $markup = $this->button( ',"aied":{"kit":"control","on":"dark"}' );
        $this->assertSame( $markup, KitResolver::resolve( $markup, $this->kit(), $none ) );
        $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $markup ) );
    }

    public function testTheAdapterReceivesTheBlockRoleSurfaceAndKit(): void
    {
        $seen    = [];
        $adapter = static function ( string $b, string $r, string $o, array $k ) use ( &$seen ): ?array {
            $seen[] = [ $b, $r, $o, $k['id'] ];
            return [];
        };
        $kit = $this->kit();
        KitResolver::resolve( $this->button() . $this->button( ',"aied":{"kit":"control-secondary","on":"accent"}' ), $kit, $adapter );
        $this->assertSame( [ [ 'divi/button', 'control', 'light', $kit['id'] ], [ 'divi/button', 'control-secondary', 'accent', $kit['id'] ] ], $seen );
    }

    public function testAnInvalidKitLeavesTheMarkupUnchangedWithoutAWarning(): void
    {
        $markup = $this->button() . '<!-- wp:divi/blurb {"radius":"{{aied:kit:radius-card}}"} /-->';
        foreach ( [ [], [ 'id' => 'x' ], [ 'radius' => 'not an object', 'space' => [ 'control' => 5 ] ], array_merge( $this->kit(), [ 'radius' => [ 'card' => [ 'nested' ] ] ] ) ] as $i => $kit ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit, $this->adapter() ) );
            $this->assertSame( $markup, $out, "kit $i" );
            $this->assertSame( [ KitResolver::LEFT_MARKER, KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $out ), "kit $i" );
        }
    }

    public function testWholeValueTokenBecomesObjectAndPartialDoesNot(): void
    {
        $kit    = $this->kit();
        $whole  = '<!-- wp:divi/blurb {"image":{"decoration":{"border":{"desktop":{"value":{"radius":"{{aied:kit:radius-card}}"}}}}},"list":["{{aied:kit:radius-media}}"]} /-->';
        $out    = KitResolver::resolve( $whole, $kit, $this->adapter() );
        $attrs  = json_decode( $this->comments( $out )[0], true );
        $card   = $kit['radius']['card'];
        $this->assertSame( [ 'topLeft' => $card, 'topRight' => $card, 'bottomRight' => $card, 'bottomLeft' => $card ], $attrs['image']['decoration']['border']['desktop']['value']['radius'] );
        $this->assertSame( 'topLeft', array_key_first( $attrs['list'][0] ), 'a token inside a list is a whole value too' );
        $this->assertSame( [], KitResolver::leftovers( $out ) );

        $partial = '<!-- wp:divi/blurb {"radius":"x {{aied:kit:radius-card}}"} /-->';
        $this->assertSame( $partial, KitResolver::resolve( $partial, $kit, $this->adapter() ) );
        $this->assertSame( [ KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $partial ) );

        $asKey = '<!-- wp:divi/blurb {"{{aied:kit:radius-card}}":"x"} /-->';
        $this->assertSame( $asKey, KitResolver::resolve( $asKey, $kit, $this->adapter() ), 'a token used as a key is not a value' );
    }

    public function testResolveIsIdempotentAndLeavesNoMarker(): void
    {
        $markup = '<!-- wp:divi/section {"a":1} -->' . $this->button() . $this->button( ',"aied":{"kit":"control-secondary","on":"dark"}' )
            . '<!-- wp:divi/blurb {"r":"{{aied:kit:radius-card}}","s":"{{aied:kit:space-card}}"} /--><!-- /wp:divi/section -->';
        $once  = KitResolver::resolve( $markup, $this->kit(), $this->adapter() );
        $twice = KitResolver::resolve( $once, $this->kit(), $this->adapter() );
        $this->assertSame( $once, $twice );
        $this->assertSame( [], KitResolver::leftovers( $once ) );
        $this->assertStringNotContainsString( '"aied"', $once );
        $this->assertStringNotContainsString( '{{aied:kit:', $once );
        $this->assertNotEmpty( KitResolver::leftovers( $markup ) );
    }

    public function testOutputIsJsonSafeWithHostileKitStrings(): void
    {
        $hostile = "--> <script>alert(1)</script> & \"quote\" \\ back -- --- <!-- \u{2028} \x01 \xff";
        $patch   = [ 'button' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => $hostile ] ] ], $hostile => [ $hostile ] ] ] ];

        // A kit whose strings bypass the schema is refused before anything is written.
        $badKit = array_merge( $this->kit(), [ 'radius' => [ 'control' => $hostile, 'card' => $hostile, 'media' => $hostile ] ] );
        $tokens = '<!-- wp:divi/blurb {"r":"{{aied:kit:radius-card}}"} /-->';
        $this->assertSame( $tokens, $this->noWarnings( fn() => KitResolver::resolve( $tokens, $badKit, $this->adapter( $patch ) ) ) );

        // A hostile value from an adapter is still written JSON-safely.
        $out = $this->noWarnings( fn() => KitResolver::resolve( $this->button(), $this->kit(), $this->adapter( $patch ) ) );
        $json = $this->comments( $out );
        $this->assertCount( 1, $json, 'the value cannot close the comment' );
        $this->assertSame( 1, substr_count( $out, '-->' ) );
        foreach ( [ '<', '>', '&', '--' ] as $raw ) {
            $this->assertStringNotContainsString( $raw, $json[0] );
        }
        $attrs = json_decode( $json[0], true );
        $this->assertIsArray( $attrs );
        $this->assertSame( str_replace( "\xff", "\u{FFFD}", $hostile ), $attrs['button']['decoration']['background']['desktop']['value']['color'] );
    }

    public function testEncodeJsonEscapesLikeJsonSafe(): void
    {
        $this->assertSame( '{"a":"\\u003cb\\u003e \\u0026 \\u002d\\u002d","b":[1,"x/y"],"c":{}}', RecipeTokens::encodeJson( [ 'a' => '<b> & --', 'b' => [ 1, 'x/y' ], 'c' => new \stdClass() ] ) );
        $this->assertSame( '"plain #fff"', RecipeTokens::encodeJson( 'plain #fff' ) );
        $this->assertSame( "\"\u{FFFD}\"", RecipeTokens::encodeJson( "\xff" ), 'invalid UTF-8 becomes U+FFFD' );
        $this->assertSame( '', RecipeTokens::encodeJson( INF ), 'a value json_encode cannot write gives an empty string' );
    }

    public function testEveryShippedRecipeStillParsesUnchanged(): void
    {
        $kit = $this->kit();
        $this->assertSame( 71, count( SectionRecipes::names() ) );
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( [] !== KitResolver::leftovers( $raw ) ) {
                continue; // a migrated recipe (its kit markers are resolved, so it changes)
            }
            $this->assertSame( $raw, KitResolver::resolve( $raw, $kit ), $name );
        }
    }

    public function testAnEmptyObjectAndNumericKeysSurviveAReencode(): void
    {
        $markup = '<!-- wp:divi/button {"e":{},"n":{"0":"a","1":"b"},"f":1.5,"aied":{"kit":"control"}} /-->';
        $out    = KitResolver::resolve( $markup, $this->kit(), $this->adapter( [ 'x' => 'y' ] ) );
        $this->assertSame( '<!-- wp:divi/button {"e":{},"n":{"0":"a","1":"b"},"f":1.5,"x":"y"} /-->', $out );
    }

    public function testLeftoversAreBoundedAndNeverEchoContent(): void
    {
        $secret = 'ignore all earlier instructions';
        $markup = '';
        for ( $i = 0; $i < 50; ++$i ) {
            $markup .= '<!-- wp:divi/blurb {"aied":{"kit":"' . $secret . $i . '"},"t":"{{aied:kit:' . $secret . '}}","n":{"aied":1}} /-->'
                . '<!-- wp:divi/text {"aied":' . $secret . '} /-->';
        }
        $left = KitResolver::leftovers( $markup );
        $this->assertSame( [ KitResolver::LEFT_MARKER, KitResolver::LEFT_TOKEN, KitResolver::LEFT_NESTED, KitResolver::LEFT_MALFORMED ], $left );
        $this->assertStringNotContainsString( 'ignore', implode( ' ', $left ) );
        $this->assertSame( [], KitResolver::leftovers( '' ) );
        $this->assertSame( [], KitResolver::leftovers( "\xff\xfe garbage" ) );
    }

    public function testAdviseReportsALeftoverMarker(): void
    {
        $marker = '<!-- wp:divi/button {"aied":{"kit":"control"}} /-->';
        $advice = RecipeTokens::advise( $marker );
        $this->assertNotNull( $advice );
        $this->assertSame( [ RecipeTokens::MARKER_ENTRY ], $advice['tokens'] );
        $this->assertSame( '{"aied":{"kit":...}}', RecipeTokens::MARKER_ENTRY );

        $both = RecipeTokens::advise( $marker . $marker . '<!-- wp:divi/blurb {"c":"{{aied:color:accent}}","n":{"aied":{"kit":"x"}}} /-->' );
        $this->assertSame( [ '{{aied:color:accent}}', RecipeTokens::MARKER_ENTRY ], $both['tokens'], 'listed once, after the tokens' );

        $this->assertNull( RecipeTokens::advise( '<p>"aied":{"kit":"control"}</p>' ), 'the spelling in text is not a marker' );
        $this->assertNotContains( RecipeTokens::MARKER_ENTRY, RecipeTokens::advise( $this->button( '' ) )['tokens'] );
        $plain = '<!-- wp:divi/blurb {"c":"{{aied:color:accent}}"} /-->';
        $this->assertSame( [ '{{aied:color:accent}}' ], RecipeTokens::advise( $plain )['tokens'], 'unchanged without a marker' );
    }

    public function testAPatchCannotPutAMarkerBack(): void
    {
        $faulty = static fn( string $b, string $r, string $o, array $k ): ?array => [ 'aied' => [ 'kit' => 'control' ], 'x' => 'y' ];
        $once   = KitResolver::resolve( $this->button(), $this->kit(), $faulty );
        $this->assertStringNotContainsString( '"aied"', $once );
        $this->assertSame( 'y', json_decode( $this->comments( $once )[0], true )['x'] );
        $this->assertSame( [], KitResolver::leftovers( $once ) );
        $this->assertSame( $once, KitResolver::resolve( $once, $this->kit(), $faulty ) );
    }

    public function testALargeBlockIsScannedAndItsMarkerResolved(): void
    {
        $long   = str_repeat( 'word - } { x -- y ', 4000 ); // about 72 KB of text with dashes and braces
        $text   = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":' . RecipeTokens::encodeJson( $long ) . '}}}} -->x<!-- /wp:divi/text -->';
        $button = '<!-- wp:divi/button {"t":' . RecipeTokens::encodeJson( $long ) . ',"aied":{"kit":"control"}} /-->';
        $this->assertGreaterThan( 65536, strlen( $text ) );
        $this->assertGreaterThan( 65536, strlen( $button ) );
        $markup = $text . $button;

        $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $markup ) );
        $this->assertContains( RecipeTokens::MARKER_ENTRY, RecipeTokens::advise( $markup )['tokens'] );

        $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $this->kit(), $this->adapter() ) );
        $this->assertStringStartsWith( $text, $out, 'the large unmarked block is byte-identical' );
        $attrs = json_decode( $this->comments( substr( $out, strlen( $text ) ) )[0], true );
        $this->assertSame( $long, $attrs['t'] );
        $this->assertArrayNotHasKey( 'aied', $attrs );
        $this->assertSame( 'transparent', $attrs['button']['decoration']['background']['desktop']['value']['color'] );
        $this->assertSame( [], KitResolver::leftovers( $out ) );
        $this->assertNull( RecipeTokens::advise( $out ) );
    }

    public function testAnUnterminatedCommentIsLeftAndReported(): void
    {
        $open = '<!-- wp:divi/text {"t":"' . str_repeat( 'a - } b ', 150000 ); // 1.2 MB, never closed
        $this->assertGreaterThan( 1000000, strlen( $open ) );

        $withToken = $open . '{{aied:kit:radius-card}}';
        $this->assertSame( $withToken, $this->noWarnings( fn() => KitResolver::resolve( $withToken, $this->kit(), $this->adapter() ) ) );
        $this->assertSame( [ KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $withToken ) );

        // A real marked button after the unterminated comment is swallowed by it: left untouched and reported.
        $withButton = $open . '"}' . $this->button();
        $this->assertSame( $withButton, $this->noWarnings( fn() => KitResolver::resolve( $withButton, $this->kit(), $this->adapter() ) ) );
        $this->assertSame( [ KitResolver::LEFT_MALFORMED ], KitResolver::leftovers( $withButton ) );
        $this->assertContains( RecipeTokens::MARKER_ENTRY, RecipeTokens::advise( $withButton )['tokens'] );
    }

    public function testAScannerFailureIsReportedNotSilent(): void
    {
        $markup   = $this->button() . '<!-- wp:divi/blurb {"r":"{{aied:kit:radius-card}}"} /-->';
        $kit      = $this->kit();
        $oldLimit = ini_get( 'pcre.backtrack_limit' );
        $oldJit   = ini_get( 'pcre.jit' );
        ini_set( 'pcre.jit', '0' );
        ini_set( 'pcre.backtrack_limit', '1' );
        try {
            $this->assertSame( $markup, $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit, $this->adapter() ) ) );
            $this->assertSame( [ KitResolver::LEFT_UNSCANNED, KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $markup ) );
            $this->assertContains( RecipeTokens::MARKER_ENTRY, (array) ( RecipeTokens::advise( $markup )['tokens'] ?? [] ) );
            $this->assertSame( [], KitResolver::leftovers( '<!-- wp:divi/blurb {"t":"\\u003c no marker"} /-->' ), 'no "aied" spelled: nothing to report' );
        } finally {
            ini_set( 'pcre.backtrack_limit', (string) $oldLimit );
            ini_set( 'pcre.jit', (string) $oldJit );
        }
        $this->assertSame( [ KitResolver::LEFT_MARKER, KitResolver::LEFT_TOKEN ], KitResolver::leftovers( $markup ) );
    }
    /** A shipped kit by id. @return array<string,mixed> */
    private function shipped( string $id ): array
    {
        $kit = StyleKits::get( $id );
        $this->assertIsArray( $kit );
        return $kit;
    }

    private function group( string $marker, string $decoration = '' ): string
    {
        return '<!-- wp:divi/group {"module":{"decoration":{' . $decoration . '"layout":{"desktop":{"value":{"flexDirection":"column"}}}}},'
            . '"builderVersion":"5.15.1"' . $marker . '} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x</p>"}}}} /--><!-- /wp:divi/group -->';
    }

    public function testCardMarkerOnAGroupResolvesAndIsStripped(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'card', 'panel' ] as $role ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                $out   = KitResolver::resolve( $this->group( ',"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ), $kit );
                $attrs = json_decode( $this->comments( $out )[0], true );
                $this->assertIsArray( $attrs );
                $this->assertArrayNotHasKey( 'aied', $attrs, "$role on $on" );
                $deco = $attrs['module']['decoration'];
                $this->assertSame( [ 'flexDirection' => 'column' ], $deco['layout']['desktop']['value'], 'the recipe layout survives' );
                $this->assertSame( 'surface' === $on ? '{{aied:color:bg}}' : '{{aied:color:surface}}', $deco['background']['desktop']['value']['color'] );
                $this->assertSame( [ 'topLeft' => '8px', 'topRight' => '8px', 'bottomRight' => '8px', 'bottomLeft' => '8px' ], $deco['border']['desktop']['value']['radius'], 'the card radius token is resolved' );
                if ( 'card' === $role ) {
                    $this->assertSame( [ 'top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px' ], $deco['spacing']['desktop']['value']['padding'] );
                } else {
                    $this->assertArrayNotHasKey( 'spacing', $deco );
                }
                $this->assertStringContainsString( '<p>x</p>', $out, 'the children are untouched' );
                $this->assertSame( [], KitResolver::leftovers( $out ) );
            }
        }
        // No `on`: a light band. A column carries a card too.
        $this->assertSame(
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card"}' ), $kit )
        );
        $column = '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_3"}}}},"aied":{"kit":"panel","on":"accent"}} --><!-- /wp:divi/column -->';
        $out    = KitResolver::resolve( $column, $kit );
        $this->assertSame( [], KitResolver::leftovers( $out ) );
        $this->assertStringContainsString( '"type":{"desktop":{"value":"1_3"}}', $out );
        $this->assertStringContainsString( '"color":"{{aied:color:surface}}"', $out );
    }

    public function testMarkerValueWinsOverARecipeLiteral(): void
    {
        $literal = '"background":{"desktop":{"value":{"color":"{{aied:color:muted}}"}}},'
            . '"border":{"desktop":{"value":{"radius":{"topLeft":"3px","topRight":"3px","bottomRight":"3px","bottomLeft":"3px"},"styles":{"all":{"width":"7px","color":"{{aied:color:accent}}"}}}}},';
        $kit   = $this->shipped( 'hairline-dashed' );
        $out   = KitResolver::resolve( $this->group( ',"aied":{"kit":"card","on":"light"}', $literal ), $kit );
        $json  = $this->comments( $out )[0];
        $attrs = json_decode( $json, true );
        $deco  = $attrs['module']['decoration'];
        $this->assertSame( '{{aied:color:surface}}', $deco['background']['desktop']['value']['color'], 'the adapter fill wins' );
        $this->assertEquals( [ 'width' => '1px', 'style' => 'dashed', 'color' => '{{aied:color:border}}' ], $deco['border']['desktop']['value']['styles']['all'], 'the adapter frame wins (the recipe key order is kept)' );
        $this->assertSame( '2px', $deco['border']['desktop']['value']['radius']['topLeft'], 'the kit card radius wins' );
        foreach ( [ '"background"', '"border"', '"radius"', '"styles"', '"all"', '"width"' ] as $key ) {
            $this->assertSame( 1, substr_count( $json, $key ), "$key appears once" );
        }
        $this->assertStringNotContainsString( '7px', $json );
        $this->assertStringNotContainsString( '3px', $json );
        $this->assertStringNotContainsString( 'muted', $json );
    }

    public function testCardMarkerOnAnImageIsLeftAndReported(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [
            '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x.svg"}}}},"aied":{"kit":"card"}} /-->',
            '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x.svg"}}}},"aied":{"kit":"panel","on":"surface"}} /-->',
            '<!-- wp:divi/blurb {"aied":{"kit":"card","on":"light"}} /-->',
            '<!-- wp:divi/section {"aied":{"kit":"panel"}} --><!-- /wp:divi/section -->',
            $this->group( ',"aied":{"kit":"card","on":"photo"}' ),
            $this->group( ',"aied":{"kit":"control"}' ),
            $this->group( ',"aied":{"kit":"card","on":"light","x":1}' ),
        ] as $markup ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
            $this->assertSame( $markup, $out, 'left untouched' );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $out ), 'reported' );
        }
    }

    public function testSurfaceOnAButtonIsLeftAndReported(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'control', 'control-secondary' ] as $role ) {
            $markup = $this->button( ',"aied":{"kit":"' . $role . '","on":"surface"}' );
            $this->assertSame( $markup, $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) ), "$role on surface is a card band only" );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $markup ) );
            // The same marker with a button band resolves.
            $this->assertSame( [], KitResolver::leftovers( KitResolver::resolve( $this->button( ',"aied":{"kit":"' . $role . '","on":"accent"}' ), $kit ) ) );
        }
        $this->assertNotNull( KitAdapters::patch( 'divi/group', 'card', 'surface', $kit ) );
    }

    public function testAGroundMarkerResolvesOnAGroupAndAColumnInAnyKeyOrder(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'card', 'panel' ] as $role ) {
            foreach ( [ 'light', 'surface' ] as $on ) {
                foreach ( [ 'inverted' => '{{aied:color:text}}', 'accent' => '{{aied:color:accent}}' ] as $ground => $fill ) {
                    $orders = [
                        ',"aied":{"kit":"' . $role . '","on":"' . $on . '","ground":"' . $ground . '"}',
                        ',"aied":{"ground":"' . $ground . '","kit":"' . $role . '","on":"' . $on . '"}',
                        ',"aied":{"on":"' . $on . '","ground":"' . $ground . '","kit":"' . $role . '"}',
                    ];
                    $outs = [];
                    foreach ( $orders as $marker ) {
                        $out   = $this->noWarnings( fn() => KitResolver::resolve( $this->group( $marker ), $kit ) );
                        $attrs = json_decode( $this->comments( $out )[0], true );
                        $this->assertIsArray( $attrs );
                        $this->assertArrayNotHasKey( 'aied', $attrs, "$role $ground on $on: the marker is stripped" );
                        $this->assertSame( $fill, $attrs['module']['decoration']['background']['desktop']['value']['color'], "$role $ground on $on" );
                        $this->assertSame( [], KitResolver::leftovers( $out ) );
                        $outs[] = $out;
                    }
                    $this->assertSame( [ $outs[0] ], array_values( array_unique( $outs ) ), 'the key order does not matter' );
                    $column = '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_3"}}}},"aied":{"kit":"' . $role . '","on":"' . $on . '","ground":"' . $ground . '"}} --><!-- /wp:divi/column -->';
                    $out    = KitResolver::resolve( $column, $kit );
                    $this->assertSame( [], KitResolver::leftovers( $out ) );
                    $this->assertStringContainsString( '"color":"' . $fill . '"', $out );
                    $this->assertStringNotContainsString( '"ground"', $out );
                }
            }
        }
        // No `on` with a ground: the default light band, as for any card marker.
        $this->assertSame(
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card","on":"light","ground":"inverted"}' ), $kit ),
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card","ground":"inverted"}' ), $kit )
        );
        // Without a ground the marker resolves exactly as before.
        $this->assertSame(
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->group( ',"aied":{"kit":"card"}' ), $kit )
        );
    }

    public function testTheAdapterReceivesTheGround(): void
    {
        $seen    = [];
        $adapter = static function ( string $b, string $r, string $o, array $k, ?string $g = null ) use ( &$seen ): ?array {
            $seen[] = [ $b, $r, $o, $g ];
            return [];
        };
        KitResolver::resolve(
            $this->group( ',"aied":{"kit":"panel","on":"surface","ground":"accent"}' ) . $this->group( ',"aied":{"kit":"card"}' ),
            $this->shipped( 'soft-solid' ),
            $adapter
        );
        $this->assertSame( [ [ 'divi/group', 'panel', 'surface', 'accent' ], [ 'divi/group', 'card', 'light', null ] ], $seen );
    }

    public function testAGroundTheResolverDoesNotKnowIsLeftAndReported(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        $cases = [
            'ground on an accent band'   => $this->group( ',"aied":{"kit":"card","on":"accent","ground":"inverted"}' ),
            'ground on a dark band'      => $this->group( ',"aied":{"kit":"panel","on":"dark","ground":"accent"}' ),
            'ground on a button'         => $this->button( ',"aied":{"kit":"control","on":"light","ground":"inverted"}' ),
            'ground on a panel button'   => $this->button( ',"aied":{"kit":"control-secondary","on":"panel","ground":"accent"}' ),
            'ground on an image'         => '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x.svg"}}}},"aied":{"kit":"card","on":"light","ground":"inverted"}} /-->',
            'unknown ground'             => $this->group( ',"aied":{"kit":"card","on":"light","ground":"x"}' ),
            'empty ground'               => $this->group( ',"aied":{"kit":"card","on":"light","ground":""}' ),
            'non-string ground'          => $this->group( ',"aied":{"kit":"card","on":"light","ground":1}' ),
            'null ground'                => $this->group( ',"aied":{"kit":"card","on":"light","ground":null}' ),
            'object ground'              => $this->group( ',"aied":{"kit":"panel","on":"surface","ground":{"a":"inverted"}}' ),
            'extra key with a ground'    => $this->group( ',"aied":{"kit":"card","on":"light","ground":"inverted","x":1}' ),
            'ground with a control role' => $this->group( ',"aied":{"kit":"control","on":"light","ground":"inverted"}' ),
            'panel band for a card'      => $this->group( ',"aied":{"kit":"card","on":"panel"}' ),
            'panel band with a ground'   => $this->group( ',"aied":{"kit":"panel","on":"panel","ground":"inverted"}' ),
        ];
        foreach ( $cases as $label => $markup ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
            $this->assertSame( $markup, $out, "$label: left untouched, nothing guessed" );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $out ), "$label: reported" );
        }
    }

    public function testAPanelBandResolvesForAButtonOnly(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'control', 'control-secondary' ] as $role ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $this->button( ',"aied":{"kit":"' . $role . '","on":"panel"}' ), $kit ) );
            $this->assertSame( [], KitResolver::leftovers( $out ), "$role on panel resolves" );
            $this->assertStringNotContainsString( '"aied"', $out );
            $this->assertStringContainsString( '{{aied:color:bg}}', $out );
        }
        $this->assertNotNull( KitAdapters::patch( 'divi/button', 'control', 'panel', $kit ) );
        $this->assertNull( KitAdapters::patch( 'divi/group', 'card', 'panel', $kit ) );
    }

    private function accordion( string $marker ): string
    {
        return '<!-- wp:divi/accordion {"module":{"decoration":{"layout":{"desktop":{"value":{"rowGap":"10px"}}}}},"builderVersion":"5.15.1"' . $marker . '} -->'
            . '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q"}}},"content":{"innerContent":{"desktop":{"value":"<p>A</p>"}}}} /-->'
            . '<!-- /wp:divi/accordion -->';
    }

    private function toggle( string $marker ): string
    {
        return '<!-- wp:divi/toggle {"title":{"innerContent":{"desktop":{"value":"Q"}}},"content":{"innerContent":{"desktop":{"value":"<p>A</p>"}}},"builderVersion":"5.15.1"' . $marker . '} /-->';
    }

    public function testItemMarkersResolveAndAreStrippedOnAnAccordionAndAToggle(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'item' => [ 'light', 'surface', 'accent', 'dark' ], 'item-rule' => [ 'light', 'surface' ] ] as $role => $bands ) {
            foreach ( $bands as $on ) {
                foreach ( [ 'divi/accordion' => $this->accordion( ',"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ), 'divi/toggle' => $this->toggle( ',"aied":{"on":"' . $on . '","kit":"' . $role . '"}' ) ] as $block => $markup ) {
                    $out   = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
                    $attrs = json_decode( $this->comments( $out )[0], true );
                    $this->assertIsArray( $attrs );
                    $this->assertArrayNotHasKey( 'aied', $attrs, "$role on $on ($block): the marker is stripped" );
                    $this->assertSame( [], KitResolver::leftovers( $out ), "$role on $on ($block)" );
                    $this->assertArrayHasKey( 'openToggle', $attrs );
                    $this->assertArrayHasKey( 'closedToggleIcon', $attrs );
                    $radius = $attrs['module']['decoration']['border']['desktop']['value']['radius'];
                    $this->assertSame( 'item' === $role ? '8px' : '0px', $radius['topLeft'], "$role on $on ($block): the radius is resolved" );
                    if ( 'divi/toggle' === $block && 'item' === $role ) {
                        $this->assertSame( [ 'top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px' ], $attrs['module']['decoration']['spacing']['desktop']['value']['padding'] );
                    }
                    if ( 'divi/toggle' === $block ) {
                        $this->assertSame( '<p>A</p>', $attrs['content']['innerContent']['desktop']['value'], 'the answer content is untouched' );
                        $this->assertSame( 'Q', $attrs['title']['innerContent']['desktop']['value'], 'the title is untouched' );
                    } else {
                        $this->assertStringContainsString( '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q"}}},"content":{"innerContent":{"desktop":{"value":"<p>A</p>"}}}} /-->', $out, 'the child item stays byte-identical' );
                    }
                }
            }
        }
        // No `on`: a light band.
        $this->assertSame(
            KitResolver::resolve( $this->toggle( ',"aied":{"kit":"item-rule","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->toggle( ',"aied":{"kit":"item-rule"}' ), $kit )
        );
        $out = KitResolver::resolve( $this->accordion( ',"aied":{"kit":"item"}' ), $kit );
        $this->assertStringContainsString( '"rowGap":"10px"', $out, 'the recipe layout survives' );
    }

    public function testAnItemMarkerTheResolverDoesNotKnowIsLeftAndReported(): void
    {
        $kit   = $this->shipped( 'soft-solid' );
        $cases = [
            'rule row on accent'        => $this->toggle( ',"aied":{"kit":"item-rule","on":"accent"}' ),
            'rule row on dark'          => $this->accordion( ',"aied":{"kit":"item-rule","on":"dark"}' ),
            'item on panel'             => $this->toggle( ',"aied":{"kit":"item","on":"panel"}' ),
            'item on photo'             => $this->accordion( ',"aied":{"kit":"item","on":"photo"}' ),
            'card role on a toggle'     => $this->toggle( ',"aied":{"kit":"card","on":"light"}' ),
            'panel role on an accordion' => $this->accordion( ',"aied":{"kit":"panel"}' ),
            'control on a toggle'       => $this->toggle( ',"aied":{"kit":"control"}' ),
            'item on a group'           => $this->group( ',"aied":{"kit":"item","on":"light"}' ),
            'item-rule on a button'     => $this->button( ',"aied":{"kit":"item-rule"}' ),
            'item on an accordion item' => '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q"}}},"aied":{"kit":"item"}} /-->',
            'extra key'                 => $this->toggle( ',"aied":{"kit":"item","on":"light","x":1}' ),
            'a ground on an item'       => $this->accordion( ',"aied":{"kit":"item","on":"light","ground":"inverted"}' ),
            'a ground on a rule row'    => $this->toggle( ',"aied":{"kit":"item-rule","on":"surface","ground":"accent"}' ),
            'non-string role'           => $this->toggle( ',"aied":{"kit":["item"]}' ),
            'non-string band'           => $this->toggle( ',"aied":{"kit":"item","on":1}' ),
        ];
        foreach ( $cases as $label => $markup ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
            $this->assertSame( $markup, $out, "$label: left untouched, nothing guessed" );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $out ), "$label: reported" );
        }
    }
    private function contactForm( string $marker ): string
    {
        return '<!-- wp:divi/contact-form {"button":{"innerContent":{"desktop":{"value":{"text":"Send"}}}},"builderVersion":"5.15.1"' . $marker . '} -->'
            . '<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Name"}}},"innerContent":{"desktop":{"value":"Name"}}}} /-->'
            . '<!-- /wp:divi/contact-form -->';
    }

    public function testAFormMarkerResolvesAndIsStrippedOnAContactForm(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
            $out   = $this->noWarnings( fn() => KitResolver::resolve( $this->contactForm( ',"aied":{"on":"' . $on . '","kit":"form"}' ), $kit ) );
            $attrs = json_decode( $this->comments( $out )[0], true );
            $this->assertIsArray( $attrs );
            $this->assertArrayNotHasKey( 'aied', $attrs, "form on $on: the marker is stripped" );
            $this->assertSame( [], KitResolver::leftovers( $out ), "form on $on" );
            $this->assertArrayHasKey( 'field', $attrs );
            $this->assertArrayHasKey( 'captcha', $attrs );
            $this->assertSame( 'Send', $attrs['button']['innerContent']['desktop']['value']['text'], 'the submit label is untouched' );
            $this->assertSame( '8px', $attrs['field']['decoration']['border']['desktop']['value']['radius']['topLeft'], 'the radius token is resolved' );
            $this->assertArrayNotHasKey( 'module', $attrs, 'the form container is never styled' );
            $this->assertStringContainsString( '<!-- wp:divi/contact-field {"fieldItem"', $out, 'the child field stays byte-identical' );
        }
        $this->assertSame(
            KitResolver::resolve( $this->contactForm( ',"aied":{"kit":"form","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->contactForm( ',"aied":{"kit":"form"}' ), $kit ),
            'no `on` is a light band'
        );
    }

    public function testAFormMarkerTheResolverDoesNotKnowIsLeftAndReported(): void
    {
        $kit   = $this->shipped( 'soft-solid' );
        $cases = [
            'form on panel'          => $this->contactForm( ',"aied":{"kit":"form","on":"panel"}' ),
            'form on photo'          => $this->contactForm( ',"aied":{"kit":"form","on":"photo"}' ),
            'a ground on a form'     => $this->contactForm( ',"aied":{"kit":"form","on":"light","ground":"inverted"}' ),
            'extra key'              => $this->contactForm( ',"aied":{"kit":"form","on":"light","x":1}' ),
            'card on a form'         => $this->contactForm( ',"aied":{"kit":"card","on":"light"}' ),
            'control on a form'      => $this->contactForm( ',"aied":{"kit":"control"}' ),
            'item on a form'         => $this->contactForm( ',"aied":{"kit":"item"}' ),
            'form on a group'        => $this->group( ',"aied":{"kit":"form","on":"light"}' ),
            'form on a button'       => $this->button( ',"aied":{"kit":"form"}' ),
            'form on a toggle'       => $this->toggle( ',"aied":{"kit":"form"}' ),
            'form on a field'        => '<!-- wp:divi/contact-field {"fieldItem":{"innerContent":{"desktop":{"value":"Name"}}},"aied":{"kit":"form"}} /-->',
            'non-string role'        => $this->contactForm( ',"aied":{"kit":["form"]}' ),
            'non-string band'        => $this->contactForm( ',"aied":{"kit":"form","on":1}' ),
        ];
        foreach ( $cases as $label => $markup ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
            $this->assertSame( $markup, $out, "$label: left untouched, nothing guessed" );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $out ), "$label: reported" );
        }
    }

    private function divider( string $marker ): string
    {
        return '<!-- wp:divi/divider {"builderVersion":"5.15.1"' . $marker . '} /-->';
    }

    private function column( string $marker ): string
    {
        return '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"' . $marker . '} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x</p>"}}}} /--><!-- /wp:divi/column -->';
    }

    private function icon( string $marker ): string
    {
        return '<!-- wp:divi/icon {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"10px","bottom":"10px","left":"10px","right":"10px"}}}}}},"icon":{"advanced":{"size":{"desktop":{"value":"20px"}}}},"builderVersion":"5.15.1"' . $marker . '} /-->';
    }

    public function testRuleMarkersResolveAndAreStrippedOnADividerAGroupAndAColumn(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'rule', 'rule-strong' ] as $role ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark', 'panel' ] as $on ) {
                foreach ( [ 'divider' => $this->divider( ',"aied":{"on":"' . $on . '","kit":"' . $role . '"}' ), 'group' => $this->group( ',"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ), 'column' => $this->column( ',"aied":{"kit":"' . $role . '","on":"' . $on . '"}' ) ] as $block => $markup ) {
                    $out   = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
                    $attrs = json_decode( $this->comments( $out )[0], true );
                    $this->assertIsArray( $attrs );
                    $this->assertArrayNotHasKey( 'aied', $attrs, "$role on $block / $on: the marker is stripped" );
                    $this->assertSame( [], KitResolver::leftovers( $out ), "$role on $block / $on" );
                    if ( 'divider' === $block ) {
                        $this->assertArrayHasKey( 'weight', $attrs['divider']['advanced']['line']['desktop']['value'] );
                        $this->assertArrayNotHasKey( 'module', $attrs, 'a divider rule never writes module.decoration' );
                    } else {
                        $top = $attrs['module']['decoration']['border']['desktop']['value']['styles']['top'];
                        $this->assertSame( 'rule' === $role ? '1px' : '2px', $top['width'], "$role $block / $on: soft-solid is a 0px kit" );
                        $this->assertSame( '0px', $attrs['module']['decoration']['border']['desktop']['value']['styles']['all']['width'] );
                    }
                    if ( 'group' === $block ) {
                        $this->assertSame( 'column', $attrs['module']['decoration']['layout']['desktop']['value']['flexDirection'], 'the recipe layout survives the merge' );
                        $this->assertStringContainsString( '<!-- wp:divi/text', $out, 'the child stays' );
                    }
                }
            }
        }
        $this->assertSame(
            KitResolver::resolve( $this->divider( ',"aied":{"kit":"rule","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->divider( ',"aied":{"kit":"rule"}' ), $kit ),
            'no `on` is a light band'
        );
    }

    public function testAChipMarkerResolvesAndIsStrippedOnAGroupAndAnIcon(): void
    {
        $kit = $this->shipped( 'soft-solid' );
        foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
            foreach ( [ 'group' => $this->group( ',"aied":{"on":"' . $on . '","kit":"chip"}' ), 'icon' => $this->icon( ',"aied":{"kit":"chip","on":"' . $on . '"}' ) ] as $block => $markup ) {
                $out   = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
                $attrs = json_decode( $this->comments( $out )[0], true );
                $this->assertIsArray( $attrs );
                $this->assertArrayNotHasKey( 'aied', $attrs, "chip on $block / $on: the marker is stripped" );
                $this->assertSame( [], KitResolver::leftovers( $out ), "chip on $block / $on" );
                $this->assertSame( '8px', $attrs['module']['decoration']['border']['desktop']['value']['radius']['topLeft'], "chip on $block / $on: the radius token is resolved in the same pass" );
            }
        }
        $icon = json_decode( $this->comments( KitResolver::resolve( $this->icon( ',"aied":{"kit":"chip","on":"accent"}' ), $kit ) )[0], true );
        $this->assertSame( '{{aied:color:surface}}', $icon['module']['decoration']['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $icon['icon']['advanced']['color']['desktop']['value'] );
        $this->assertSame( '20px', $icon['icon']['advanced']['size']['desktop']['value'], 'the recipe icon size survives' );
        $this->assertSame( '10px', $icon['module']['decoration']['spacing']['desktop']['value']['padding']['top'], 'the recipe padding is the chip size' );
        $this->assertSame(
            KitResolver::resolve( $this->icon( ',"aied":{"kit":"chip","on":"light"}' ), $kit ),
            KitResolver::resolve( $this->icon( ',"aied":{"kit":"chip"}' ), $kit ),
            'no `on` is a light band'
        );
    }

    public function testRuleAndChipMarkersTheResolverDoesNotKnowAreLeftAndReported(): void
    {
        $kit   = $this->shipped( 'soft-solid' );
        $cases = [
            'chip on a divider'          => $this->divider( ',"aied":{"kit":"chip"}' ),
            'chip on a column'           => $this->column( ',"aied":{"kit":"chip"}' ),
            'rule on an icon'            => $this->icon( ',"aied":{"kit":"rule"}' ),
            'rule-strong on an icon'     => $this->icon( ',"aied":{"kit":"rule-strong"}' ),
            'card on an icon'            => $this->icon( ',"aied":{"kit":"card"}' ),
            'rule on a button'           => $this->button( ',"aied":{"kit":"rule"}' ),
            'rule on a toggle'           => $this->toggle( ',"aied":{"kit":"rule"}' ),
            'rule on a contact form'     => $this->contactForm( ',"aied":{"kit":"rule-strong"}' ),
            'rule on photo'              => $this->divider( ',"aied":{"kit":"rule","on":"photo"}' ),
            'rule on inverted'           => $this->group( ',"aied":{"kit":"rule","on":"inverted"}' ),
            'chip on panel'              => $this->group( ',"aied":{"kit":"chip","on":"panel"}' ),
            'icon chip on panel'         => $this->icon( ',"aied":{"kit":"chip","on":"panel"}' ),
            'a ground on a rule'         => $this->group( ',"aied":{"kit":"rule","on":"light","ground":"inverted"}' ),
            'a ground on a column rule'  => $this->column( ',"aied":{"kit":"rule-strong","on":"surface","ground":"accent"}' ),
            'a ground on a chip'         => $this->group( ',"aied":{"kit":"chip","on":"light","ground":"accent"}' ),
            'extra key on a rule'        => $this->divider( ',"aied":{"kit":"rule","on":"light","x":1}' ),
            'extra key on a chip'        => $this->icon( ',"aied":{"kit":"chip","on":"light","x":1}' ),
            'non-string role'            => $this->divider( ',"aied":{"kit":["rule"]}' ),
            'non-string band'            => $this->icon( ',"aied":{"kit":"chip","on":1}' ),
        ];
        foreach ( $cases as $label => $markup ) {
            $out = $this->noWarnings( fn() => KitResolver::resolve( $markup, $kit ) );
            $this->assertSame( $markup, $out, "$label: left untouched, nothing guessed" );
            $this->assertSame( [ KitResolver::LEFT_MARKER ], KitResolver::leftovers( $out ), "$label: reported" );
        }
    }
}
