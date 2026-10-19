<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $kitTopicsTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $kitTopicsTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * Task 6 of the style kits plan: the guide topics `style-kits` (what a kit is, the catalog, how to choose) and
 * `style-kit:<id>` (one kit in words, plus a primary and a secondary button resolved by the real pipeline for the
 * `editorial` language).
 */
class StyleGuideKitTopicsTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;

    /** @return list<string> */
    private static function kitTopics(): array
    {
        return array_map( static fn ( string $id ): string => 'style-kit:' . $id, StyleKits::ids() );
    }

    /** @return array<string,array{0:string}> */
    public static function everyKitTopic(): array
    {
        $cases = [ 'style-kits' => [ 'style-kits' ] ];
        foreach ( StyleKits::ids() as $id ) {
            $cases[ 'style-kit:' . $id ] = [ 'style-kit:' . $id ];
        }

        return $cases;
    }

    public function testTopicsListTheCatalogAndOneTopicPerKit(): void
    {
        $topics = StyleGuide::topics();
        $this->assertContains( 'style-kits', $topics );
        $this->assertGreaterThanOrEqual( 2, count( self::kitTopics() ) );
        foreach ( self::kitTopics() as $topic ) {
            $this->assertContains( $topic, $topics );
        }
        $this->assertSame( $topics, array_values( array_unique( $topics ) ), 'no topic is listed twice' );
    }

    /** @dataProvider everyKitTopic */
    public function testEachKitTopicIsServedWithinBudgetAndIsPlain( string $topic ): void
    {
        $text = StyleGuide::markdown( $topic );
        $this->assertStringNotContainsString( 'Unknown topic', $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertGreaterThan( 600, strlen( $text ), 'a real topic, not a stub' );
        $this->assertStringNotContainsString( 'aied', $text, 'no token and no kit marker ever reaches the AI' );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"/', $text, 'no custom CSS is ever taught' );
        $this->assertDoesNotMatchRegularExpression( '/<script|<style|javascript:/i', $text, 'no custom code in a topic' );
        $this->assertSame( $text, ( $this->mcp( [ 'topic' => $topic ] ) )[1], 'MCP serves the same text' );
        $this->assertSame( [ 'guide' => $text ], $this->rest( [ 'topic' => $topic ] )->get_data(), 'REST serves the same text' );
    }

    public function testTheCatalogTopicIntroducesKitsChoosesAndIsHonestAboutScope(): void
    {
        $text = StyleGuide::markdown( 'style-kits' );
        $this->assertStringStartsWith( '# Style kits', $text );
        foreach ( StyleKits::ids() as $id ) {
            $this->assertStringContainsString( '`' . $id . '`', $text );
            $this->assertStringContainsString( (string) StyleKits::get( $id )['summary'], $text, $id . ' summary comes from the catalog' );
        }
        // One sentence names the per-kit topics; the ids are not enumerated a second time (the catalog above lists every kit).
        $this->assertSame( 1, substr_count( $text, 'topic=style-kit:<id> with any id listed above' ), 'one sentence points to the kit topics' );
        $this->assertStringNotContainsString( 'style-kit:' . ( StyleKits::ids()[0] ?? 'x' ), $text, 'the ids are not enumerated as topics' );
        $this->assertStringContainsString( 'bundle of decisions', $text );
        $this->assertStringContainsString( 'design language', $text );
        $this->assertStringContainsString( 'style_kit', $text );
        $this->assertStringContainsString( 'get_section_recipes', $text );
        $this->assertStringContainsString( 'Kit-driven today:', $text, 'the scope today is said honestly' );
        $this->assertMatchesRegularExpression( '/accordions/i', $text, 'what is NOT kit-driven is named' );
        $this->assertMatchesRegularExpression( '/do(n\'t| not) mix/i', $text, 'one kit per page' );
        $this->assertSame( 1, preg_match_all( '/^# /m', $text ), 'one H1' );
        // Each language's default kit is named with the language; the other kits are covered by one line.
        foreach ( \AiEditorDivi5\WP\DesignLanguages::ids() as $languageId ) {
            $kitId = (string) \AiEditorDivi5\WP\DesignLanguages::get( $languageId )['kit'];
            $this->assertMatchesRegularExpression( '/^- `' . preg_quote( $kitId, '/' ) . '`: [a-z, -]*\b' . preg_quote( $languageId, '/' ) . '\b/m', $text, $languageId );
        }
        $this->assertStringContainsString( 'Every other kit: no design language starts with it', $text );
    }

    public function testEachKitTopicStatesTheKitsDecisions(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $kit  = StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertStringStartsWith( '# Style kit: ' . $kit['title'], $text );
            $this->assertStringContainsString( (string) $kit['summary'], $text );
            $this->assertStringContainsString( (string) $kit['radius']['control'], $text );
            $this->assertStringContainsString( (string) $kit['border']['width'], $text );
            $this->assertStringContainsString( 'accent band', $text );
            $this->assertStringContainsString( 'photo', $text );
            $this->assertStringContainsString( 'editorial', $text );
        }
    }

    /** The old "only buttons / not applied" wording is gone; what the guide says is applied is exactly KitAdapters::APPLIED. */
    public function testTheGuideNamesExactlyWhatTheAdaptersApply(): void
    {
        $blocks = array_keys( KitAdapters::APPLIED );
        $roles  = array_values( array_unique( array_merge( ...array_values( KitAdapters::APPLIED ) ) ) );
        foreach ( self::everyKitTopic() as [ $topic ] ) {
            $text = StyleGuide::markdown( $topic );
            $this->assertDoesNotMatchRegularExpression( '/not applied|NOT applied|Only buttons are kit-driven|not kit-driven yet|not applied yet/i', $text, $topic );
            $this->assertSame( 1, preg_match( '/Kit-driven today: [^\n]*?\.(?= |\n)/', $text, $m ), $topic . ' has the generated sentence' );
            preg_match_all( '/`(divi\/[a-z0-9-]+)`/', $m[0], $b );
            $this->assertEqualsCanonicalizing( $blocks, array_values( array_unique( $b[1] ) ), $topic . ': the blocks named are the blocks in APPLIED' );
            foreach ( $roles as $role ) {
                $this->assertStringContainsString( '`' . $role . '`', $m[0], $topic . ': role ' . $role );
            }
        }
    }

    public function testTheAppliedSentenceIsGeneratedFromTheMapItIsGiven(): void
    {
        $this->assertSame( StyleGuide::appliedSentence( KitAdapters::APPLIED ), StyleGuide::appliedSentence(), 'the default is the shipped map' );
        $planted = StyleGuide::appliedSentence( [ 'divi/button' => [ 'control' ], 'divi/blurb' => [ 'tile', 'row' ] ] );
        $this->assertStringContainsString( '`divi/blurb`', $planted );
        $this->assertStringContainsString( '`tile`', $planted );
        $this->assertStringContainsString( '`row`', $planted );
        $this->assertStringContainsString( '`control`', $planted );
        $this->assertStringNotContainsString( '`divi/group`', $planted, 'a block that has no adapter is never claimed' );
        $this->assertStringNotContainsString( '`card`', $planted );
        $this->assertStringStartsWith( 'Kit-driven today:', $planted );
    }

    public function testTheCatalogSaysWhatIsAndIsNotKitDrivenAndHowToBuildOne(): void
    {
        $catalog = StyleGuide::markdown( 'style-kits' );
        foreach ( [ 'accordions', 'forms', 'blurbs', 'testimonials', 'image radius' ] as $word ) {
            $this->assertStringContainsStringIgnoringCase( $word, $catalog );
        }
        $this->assertStringContainsString( 'card', $catalog );
        $this->assertStringContainsString( 'panel', $catalog );
        $this->assertStringContainsString( 'copy the block from the kit\'s topic', $catalog );
        $this->assertStringNotContainsString( 'will follow later', $catalog );
    }

    /** @return array{0:string,1:string} the frame colour role and the hover sentence a card of $kit must carry (derived from the kit, not from the adapter) */
    private static function expectedCardFacts( array $kit ): array
    {
        $role   = [ 'same' => 'border', 'inverse' => 'text', 'accent' => 'text' ][ $kit['border']['color'] ] ?? $kit['border']['color'];
        $bottom = 'bottom' === ( $kit['border']['sides'] ?? 'all' );
        if ( 'lift' === $kit['hover'] ) {
            $hover = 'On hover a card lifts 4px.';
        } elseif ( ! $bottom && 'text' !== $role && in_array( $kit['hover'], [ 'fill-swap', 'invert' ], true ) ) {
            $hover = 'On hover a card\'s frame turns to the text colour.';
        } else {
            $hover = 'A card has no hover change.';
        }

        return [ $role, $hover ];
    }

    public function testEachKitTopicStatesItsCardAndPanelTruthfully(): void
    {
        $lifts = $frames = $none = $under = 0;
        foreach ( StyleKits::ids() as $id ) {
            $kit  = (array) StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            [ $role, $hover ] = self::expectedCardFacts( $kit );
            $width  = '0px' === $kit['border']['width'] ? '1px' : $kit['border']['width'];
            $bottom = 'bottom' === ( $kit['border']['sides'] ?? 'all' );
            $this->assertStringContainsString( '## Cards and panels in this kit', $text, $id );
            $frame = $bottom
                ? sprintf( 'A card has only a bottom edge (an underline): %s %s in the %s colour.', $width, $kit['border']['style'], $role )
                : sprintf( 'A card has a %s %s frame in the %s colour.', $width, $kit['border']['style'], $role );
            $this->assertStringContainsString( $frame, $text, $id );
            $this->assertStringContainsString( $hover, $text, $id );
            $this->assertStringContainsString( sprintf( 'Corner radius %s, padding %s', $kit['radius']['card'], $kit['space']['card'] ), $text, $id );
            $this->assertStringContainsString( 'A panel is a static surface: the same radius, frame and shadow, no hover, and the recipe keeps its own padding.', $text, $id );
            $this->assertStringContainsString( 'The fill is always the language\'s surface colour', $text, $id );
            $this->assertStringContainsString( 'a kit\'s media radius is recorded in the kit but no module takes it yet', $text, $id );
            $this->assertStringContainsString( 'capitalization, weight and letter spacing are button-only', $text, $id );
            $bottom ? ++$under : ( 'text' === $role ? ++$frames : ++$none );
            if ( 'lift' === $kit['hover'] ) {
                ++$lifts;
            }
        }
        $this->assertGreaterThan( 0, $lifts, 'a lift kit ships' );
        $this->assertGreaterThan( 0, $under, 'an underline kit ships' );
        $this->assertGreaterThan( 0, $frames + $none );
    }

    /**
     * Problems with the colour legends of one kit topic: every colour literal in a fenced snippet (a button, a card, a panel)
     * must be listed in the prose as `<value> = <role(s)>`, and a hex that several roles of the language share must name ALL of
     * them (editorial's accent-text and surface are both #ffffff: a legend that names only one makes an AI read the card fill
     * as accent-text). Transparent is the one literal the prose says in words.
     *
     * @param array<string,mixed> $language
     * @return list<string>
     */
    private static function legendProblems( string $topicText, array $language ): array
    {
        $prose = (string) preg_replace( '/```.*?```/s', '', $topicText );
        preg_match_all( '/```\n(.*?)\n```/s', $topicText, $blocks );
        $problems = [];
        foreach ( $blocks[1] as $block ) {
            preg_match_all( '/#[0-9a-f]{6}\b|rgba\([^)]*\)/i', $block, $lits );
            foreach ( array_unique( $lits[0] ) as $lit ) {
                if ( KitAdapters::TRANSPARENT === $lit ) {
                    continue;
                }
                if ( 1 !== preg_match( '/' . preg_quote( $lit, '/' ) . ' = ([^;.)]+)/', $prose, $entry ) ) {
                    $problems[] = $lit . ' is not in a legend';
                    continue;
                }
                if ( str_starts_with( $lit, '#' ) ) {
                    foreach ( (array) $language['colors'] as $key => $value ) {
                        $role = str_replace( '_', '-', (string) $key );
                        if ( strtolower( (string) $value ) === strtolower( $lit ) && 1 !== preg_match( '/(?<![a-z-])' . preg_quote( $role, '/' ) . '(?![a-z-])/', $entry[1] ) ) {
                            $problems[] = $lit . ' is also the ' . $role . ' role, which its legend entry does not name';
                        }
                    }
                }
            }
        }

        return $problems;
    }

    public function testEveryColourInAKitTopicSnippetIsInALegendWithAllItsRoles(): void
    {
        $editorial = (array) \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        $shared    = 0;
        foreach ( StyleKits::ids() as $id ) {
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertSame( [], self::legendProblems( $text, $editorial ), $id );
            if ( str_contains( $text, '#ffffff = surface and accent-text' ) ) {
                ++$shared;
            }
        }
        $this->assertGreaterThan( 0, $shared, 'editorial\'s surface and accent-text share #ffffff and the legend says so' );
    }

    public function testTheLegendCheckFailsWhenARoleOrAnEntryIsMissing(): void
    {
        $editorial = (array) \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        $id        = (string) StyleKits::ids()[0];
        $text      = StyleGuide::markdown( 'style-kit:' . $id );
        $this->assertSame( [], self::legendProblems( $text, $editorial ) );

        $dropped = str_replace( '#ffffff = surface and accent-text', '#ffffff = accent-text', $text );
        $this->assertNotSame( $text, $dropped, 'the fixture has the shared entry' );
        $this->assertNotSame( [], self::legendProblems( $dropped, $editorial ), 'a missing role is caught' );

        $gone = (string) preg_replace( '/#e3dccf = [^;.)]+/', '#e3dccf is', $text );
        $this->assertNotSame( $text, $gone, 'the fixture lists the border hairline' );
        $this->assertNotSame( [], self::legendProblems( $gone, $editorial ), 'a missing entry is caught' );
    }

    public function testTheCardSectionNamesTheRolesOfTheFillAndTheHairline(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertStringContainsString( 'the `surface` role', $text, $id );
            $this->assertStringContainsString( 'the `bg` role', $text, $id );
            $this->assertStringContainsString( 'the `border` role', $text, $id );
            $this->assertStringContainsString( 'border colour is `same` or `border`', $text, $id );
        }
    }

    public function testNoKitTopicClaimsAHoverTheAdapterDoesNotWrite(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $kit   = (array) StyleKits::get( $id );
            $text  = StyleGuide::markdown( 'style-kit:' . $id );
            $patch = (array) KitAdapters::patch( 'divi/group', 'card', 'light', $kit );
            $deco  = (array) $patch['module']['decoration'];
            $lifts = isset( $deco['transform'] );
            $frame = isset( $deco['border']['desktop']['hover'] );
            $this->assertSame( $lifts, str_contains( $text, 'On hover a card lifts 4px.' ), $id );
            $this->assertSame( $frame, str_contains( $text, 'On hover a card\'s frame turns to the text colour.' ), $id );
            $this->assertSame( ! $lifts && ! $frame, str_contains( $text, 'A card has no hover change.' ), $id );
        }
    }

    /** @return array<string,array<string,mixed>> role => decoded attributes of the first block of that role in the topic */
    private function cardBlocks( string $topic ): array
    {
        $text = StyleGuide::markdown( $topic );
        $out  = [];
        foreach ( [ 'Card' => 'card', 'Panel' => 'panel' ] as $label => $role ) {
            $this->assertSame( 1, preg_match( '/' . $label . ':\n\n```\n(<!-- wp:divi\/group (\{.*?\}) -->.*?<!-- \/wp:divi\/group -->)\n```/s', $text, $m ), $topic . ': a ' . $role . ' snippet' );
            $attrs = json_decode( $m[2], true );
            $this->assertIsArray( $attrs, 'block attributes decode' );
            $out[ $role ] = [ 'attrs' => $attrs, 'block' => $m[1] ];
        }

        return $out;
    }

    public function testEachKitTopicCarriesACardAndAPanelFromTheRealPipelineThatValidate(): void
    {
        $registry = ShapeRegistry::load();
        $editorial = \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            foreach ( $this->cardBlocks( 'style-kit:' . $id ) as $role => $found ) {
                $expected = json_decode( substr(
                    \AiEditorDivi5\WP\RecipeTokens::resolve(
                        \AiEditorDivi5\WP\KitResolver::resolve( '<!-- wp:divi/group {"aied":{"kit":"' . $role . '","on":"light"}} -->', $kit ),
                        $editorial
                    ),
                    strlen( '<!-- wp:divi/group ' ),
                    -strlen( ' -->' )
                ), true );
                $this->assertIsArray( $expected, "$id $role: the pipeline resolves the marker" );
                $attrs = $found['attrs'];
                // The snippet adds a layout and a version of its own; everything else is the pipeline's patch, exactly.
                unset( $attrs['module']['decoration']['layout'], $attrs['builderVersion'] );
                $this->assertSame( $expected, $attrs, "$id $role: the snippet is the resolved kit, not hand-written" );
                $this->assertArrayNotHasKey( 'aied', $found['attrs'] );
                $this->assertStringNotContainsString( 'aied', $found['block'] );

                $page   = '<!-- wp:divi/section {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/column {"builderVersion":"5.0.0-public-beta.1"} -->' . $found['block'] . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
                $result = ( new Validator() )->validateContent( $page );
                $this->assertTrue( $result->isValid(), "$id $role: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
                $this->assertTrue( LayoutGate::check( $page )->isValid(), "$id $role passes the plugin gate" );
                foreach ( ShapePaths::fromBlockAttrs( $found['attrs'] ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "$id $role uses an unregistered path $path" );
                }
                $this->assertSame( 'card' === $role, isset( $found['attrs']['module']['decoration']['spacing'] ), "$id: only a card carries the kit padding" );
                $hover = json_encode( $found['attrs'] );
                if ( 'panel' === $role ) {
                    $this->assertStringNotContainsString( '"hover"', (string) $hover, "$id: a panel has no hover" );
                }
            }
        }
    }

    public function testEachKitTopicDescribesItsShadowLiftAndUnderlineTruthfully(): void
    {
        $seen = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit    = (array) StyleKits::get( $id );
            $text   = StyleGuide::markdown( 'style-kit:' . $id );
            $pair   = $kit['shadow']['rest'] . '/' . $kit['shadow']['hover'];
            $lift   = 'lift' === $kit['hover'];
            $bottom = 'bottom' === ( $kit['border']['sides'] ?? 'all' );
            $expect = [
                'A soft shadow sits under the button'                => 'soft/soft' === $pair,
                'A hard offset shadow sits under the button'         => 'hard/hard' === $pair,
                'A hard edge sits straight under the button'         => 'edge/edge' === $pair,
                'A soft glow surrounds the button'                   => 'glow/glow' === $pair,
                'A soft shadow appears under the button on hover'    => 'none/soft' === $pair,
                'On hover the button lifts 4px'                      => $lift,
                'Only the bottom border is drawn: an underline'      => $bottom,
            ];
            foreach ( $expect as $sentence => $applies ) {
                if ( $applies ) {
                    $seen[ $sentence ] = true;
                    $this->assertStringContainsString( $sentence, $text, $id );
                } else {
                    $this->assertStringNotContainsString( $sentence, $text, $id );
                }
            }
            $effects = 'none/none' !== $pair || $lift || $bottom;
            $this->assertSame( $effects, str_contains( $text, '## Shadow, lift and underline' ), $id );
        }
        $this->assertCount( 7, $seen, 'the shipped kits use every effect' );
    }

    public function testATextOnlyButtonIsNamedExactlyWhenTheKitDrawsOne(): void
    {
        $seen = 0;
        foreach ( StyleKits::ids() as $id ) {
            $kit  = (array) StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            foreach ( [ 'control' => 'primary', 'control-secondary' => 'secondary' ] as $role => $name ) {
                $d        = KitAdapters::patch( 'divi/button', $role, 'light', $kit )['button']['decoration'];
                // No fill and no side wider than 0px (an underline kit's ghost secondary keeps a zero-width bottom side).
                $widths   = array_map( static fn ( array $s ): string => (string) $s['width'], (array) $d['border']['desktop']['value']['styles'] );
                $textOnly = KitAdapters::TRANSPARENT === $d['background']['desktop']['value']['color'] && [ '0px' ] === array_values( array_unique( $widths ) );
                $sentence = sprintf( 'The %s button is text-only', $name );
                if ( $textOnly ) {
                    ++$seen;
                    $this->assertStringContainsString( $sentence, $text, "$id: its $name button has no fill and no border" );
                    // The reason given is true: the kit's own width is 0px, or the button is a ghost (no frame whatever the width).
                    $fill = 'control' === $role ? (string) $kit['fill'] : KitAdapters::secondaryFill( (string) $kit['fill'] );
                    $this->assertSame( '0px' === $kit['border']['width'] && 'ghost' !== $fill, str_contains( $text, "The $name button is text-only: no fill and no border (this kit's border width is 0px)" ), "$id $name" );
                    $this->assertSame( 'ghost' === $fill, str_contains( $text, "The $name button is text-only: a ghost, no fill and no frame" ), "$id $name" );
                } else {
                    $this->assertStringNotContainsString( $sentence, $text, "$id: its $name button has a fill or a border" );
                }
            }
        }
        $this->assertGreaterThan( 0, $seen, 'soft-solid\'s borderless secondary is text-only' );
    }

    /** The underline sentence speaks of the primary; when the secondary draws no underline (a ghost) the topic says so. */
    public function testAnUnderlineKitTopicSaysWhenTheSecondaryHasNoUnderline(): void
    {
        $seen = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            if ( 'bottom' !== ( $kit['border']['sides'] ?? 'all' ) ) {
                continue;
            }
            $bottom      = KitAdapters::patch( 'divi/button', 'control-secondary', 'light', $kit )['button']['decoration']['border']['desktop']['value']['styles']['bottom'];
            $plain       = '0px' === $bottom['width'];
            $seen[ $id ] = $plain;
            $this->assertSame( $plain, str_contains( StyleGuide::markdown( 'style-kit:' . $id ), 'The secondary button draws no underline' ), $id );
        }
        $this->assertSame( [ 'underline-link' => false, 'underlined-label' => true ], $seen );
    }

    /** @return array{attrs:array<string,mixed>,block:string,button:array<string,mixed>} the inverted panel snippet of a kit topic, decoded */
    private function invertedPanel( string $topic ): array
    {
        $text = StyleGuide::markdown( $topic );
        $this->assertSame( 1, preg_match( '/Inverted panel:\n\n```\n(<!-- wp:divi\/group (\{.*?\}) -->\n<!-- wp:divi\/button (\{.*?\}) \/-->\n<!-- \/wp:divi\/group -->)\n```/s', $text, $m ), $topic . ': an inverted panel snippet' );
        $out = [ 'block' => $m[1] ];
        foreach ( [ 'attrs' => 2, 'button' => 3 ] as $key => $i ) {
            $out[ $key ] = json_decode( $m[ $i ], true );
            $this->assertIsArray( $out[ $key ], $topic . ': ' . $key . ' decodes' );
        }

        return $out;
    }

    /**
     * The attributes the real resolver steps give a marker (kit patch, kit value tokens, then colour tokens for editorial),
     * minus the marker: what the adapter alone says a block must carry. The marker's patch is also checked against the adapter.
     *
     * @param array<string,mixed> $marker
     * @param array<string,mixed> $kit
     * @return array<string,mixed>
     */
    private static function resolvedPatch( string $block, array $marker, array $kit ): array
    {
        $markup = \AiEditorDivi5\WP\RecipeTokens::resolve(
            \AiEditorDivi5\WP\KitResolver::resolve( '<!-- wp:' . $block . ' ' . json_encode( [ 'aied' => $marker ] ) . ' -->', $kit ),
            (array) \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' )
        );
        self::assertSame( 1, preg_match( '/^<!-- wp:' . preg_quote( $block, '/' ) . ' (\{.*\}) -->$/', $markup, $m ), 'the marker resolves' );

        return (array) json_decode( $m[1], true );
    }

    public function testEachKitTopicCarriesAnInvertedPanelWithAPanelButtonFromTheRealPipeline(): void
    {
        $editorial = (array) \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        $registry  = ShapeRegistry::load();
        $text      = strtolower( (string) $editorial['colors']['text'] );
        $bg        = strtolower( (string) $editorial['colors']['bg'] );
        $this->assertNotSame( $text, $bg );
        foreach ( StyleKits::ids() as $id ) {
            $kit   = (array) StyleKits::get( $id );
            $found = $this->invertedPanel( 'style-kit:' . $id );
            $this->assertStringNotContainsString( 'aied', $found['block'], $id );

            // The panel is filled with the text colour (an inverted ground), exactly the adapter's patch for that ground.
            $this->assertSame( $text, strtolower( $found['attrs']['module']['decoration']['background']['desktop']['value']['color'] ), "$id: the inverted panel is filled with the text colour" );
            $panelPatch = self::resolvedPatch( 'divi/group', [ 'kit' => 'panel', 'on' => 'light', 'ground' => 'inverted' ], $kit );
            $this->assertSame( $found['attrs']['module']['decoration']['background'], $panelPatch['module']['decoration']['background'], $id );
            $this->assertSame( $found['attrs'], array_replace_recursive( $found['attrs'], $panelPatch ), "$id: the panel carries the adapter's whole ground patch" );
            $this->assertArrayNotHasKey( 'hover', (array) json_decode( (string) json_encode( $found['attrs'] ), true )['module']['decoration']['border']['desktop'], $id );

            // The button follows the kit's `panel` treatment, computed from the adapter, not from the guide.
            $buttonPatch = self::resolvedPatch( 'divi/button', [ 'kit' => 'control', 'on' => 'panel' ], $kit );
            $this->assertSame( $found['button'], array_replace_recursive( $found['button'], $buttonPatch ), "$id: the button carries the adapter's whole panel patch" );
            $label = strtolower( (string) $found['button']['button']['decoration']['font']['font']['desktop']['value']['color'] );
            $fill  = $found['button']['button']['decoration']['background']['desktop']['value']['color'];
            if ( 'solid' === $kit['fill'] ) {
                $this->assertSame( $bg, strtolower( (string) $fill ), "$id: a solid button on the panel is filled with the background colour" );
                $this->assertSame( $text, $label, "$id: its label is the text colour" );
            } else {
                $this->assertSame( KitAdapters::TRANSPARENT, $fill, "$id: an outline or ghost button on the panel has no fill" );
                $this->assertSame( $bg, $label, "$id: its label is the background colour" );
            }
            $this->assertArrayNotHasKey( 'aied', $found['button'] );
            $this->assertSame( 'Button', $found['button']['button']['innerContent']['desktop']['value']['text'] );

            $page   = '<!-- wp:divi/section {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/column {"builderVersion":"5.0.0-public-beta.1"} -->' . $found['block'] . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "$id inverted panel: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "$id inverted panel passes the plugin gate" );
            foreach ( [ $found['attrs'], $found['button'] ] as $attrs ) {
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "$id inverted panel uses an unregistered path $path" );
                }
            }
        }
    }

    public function testEachKitTopicSaysGenericallyWhatAnInvertedPanelAndItsButtonTake(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $kit  = (array) StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertStringContainsString( 'A panel can sit on an `inverted` ground (text colour as fill; its text in the background colour, or the surface colour on a surface band) or an `accent` ground (accent colour as fill; its text in the accent-text colour). The frame takes the fill colour; the kit never re-inks the children.', $text, $id );
            $this->assertStringContainsString( 'A button on an inverted panel takes the `panel` treatment: ', $text, $id );
            $d    = KitAdapters::patch( 'divi/button', 'control', 'panel', $kit )['button']['decoration'];
            $role = static fn ( string $v ): string => preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $v, $m ) ? 'the ' . $m[1] . ' colour as' : 'a transparent';
            $this->assertStringContainsString(
                sprintf( 'The primary button on it has %s fill, %s label and %s border.', $role( $d['background']['desktop']['value']['color'] ), $role( $d['font']['font']['desktop']['value']['color'] ), $role( $d['border']['desktop']['value']['styles']['all']['color'] ) ),
                $text,
                $id
            );
        }
    }

    public function testTheGroundInkTableMatchesTheAdapter(): void
    {
        $adapter = new \ReflectionClassConstant( KitAdapters::class, 'ON' );
        $guide   = new \ReflectionClassConstant( StyleGuide::class, 'GROUND_INK' );
        $this->assertSame( $adapter->getValue(), $guide->getValue(), 'the guide names the ink of an inverted and of an accent ground exactly as the adapter pairs a fill with its label' );
    }

    public function testTheLegendCoversTheInvertedPanelColoursAndFailsWhenOneIsDropped(): void
    {
        $editorial = (array) \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        foreach ( StyleKits::ids() as $id ) {
            $text   = StyleGuide::markdown( 'style-kit:' . $id );
            $legend = $this->invertedPanel( 'style-kit:' . $id );
            $prose  = (string) preg_replace( '/```.*?```/s', '', $text );
            $this->assertMatchesRegularExpression( '/' . preg_quote( (string) $editorial['colors']['text'], '/' ) . ' = [^;.)]*\btext\b/', $prose, "$id: the panel fill hex names its role" );
            $this->assertMatchesRegularExpression( '/' . preg_quote( (string) $editorial['colors']['bg'], '/' ) . ' = [^;.)]*\bbg\b/', $prose, "$id: the ink hex names its role" );
            $this->assertSame( [], self::legendProblems( $text, $editorial ), $id );
            $this->assertNotSame( '', $legend['block'] );
        }
        // Planted: drop the ink's entry from the legend and the check fails.
        $id   = (string) StyleKits::ids()[0];
        $text = StyleGuide::markdown( 'style-kit:' . $id );
        $bg   = (string) $editorial['colors']['bg'];
        $gone = (string) preg_replace( '/' . preg_quote( $bg, '/' ) . ' = [^;.)]+/', $bg . ' is', $text );
        $this->assertNotSame( $text, $gone );
        $this->assertNotSame( [], self::legendProblems( $gone, $editorial ), 'an ink hex with no legend entry is caught' );
    }

    public function testTheLargestKitTopicIsPinnedAndUnderTheBudgetAndTheDefaultGuideDidNotGrow(): void
    {
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $max = max( $max, strlen( StyleGuide::markdown( 'style-kit:' . $id ) ) );
        }
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, $max );
        $this->assertSame( 9992, $max, 'the largest kit topic today (neo-brutal; was 8124 before the inverted panel)' );
        $this->assertSame( 28964, strlen( StyleGuide::markdown() ), 'the default guide is byte-for-byte the size it was' );
        $this->assertSame( 8464, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog topic is untouched' );
    }

    /** @return list<array{0:string,1:array<string,mixed>}> block comment text and its decoded attributes, in document order */
    private function buttons( string $topic ): array
    {
        // The inverted panel's button is a third button, on its own surface; it has its own test.
        preg_match_all( '/<!-- wp:divi\/button (\{.*?\}) \/-->/', (string) preg_replace( '/Inverted panel:\n\n```.*?```/s', '', StyleGuide::markdown( $topic ) ), $m );
        $out = [];
        foreach ( $m[0] as $i => $block ) {
            $attrs = json_decode( $m[1][ $i ], true );
            $this->assertIsArray( $attrs, 'block attributes decode' );
            $out[] = [ $block, $attrs ];
        }

        return $out;
    }

    public function testEachKitTopicCarriesAPrimaryAndASecondaryButtonThatValidateAndAreRegistered(): void
    {
        $registry = ShapeRegistry::load();
        foreach ( StyleKits::ids() as $id ) {
            $buttons = $this->buttons( 'style-kit:' . $id );
            $this->assertCount( 2, $buttons, $id . ': a primary and a secondary button, no more' );
            foreach ( $buttons as $i => [ $block, $attrs ] ) {
                $page   = '<!-- wp:divi/section {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/column {"builderVersion":"5.0.0-public-beta.1"} -->' . $block . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
                $result = ( new Validator() )->validateContent( $page );
                $this->assertTrue( $result->isValid(), "$id button $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
                $this->assertTrue( LayoutGate::check( $page )->isValid(), "$id button $i passes the plugin gate (custom-code policy included)" );
                $this->assertArrayNotHasKey( 'aied', $attrs, 'the marker is resolved away' );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "$id button $i uses an unregistered path $path" );
                }
                $this->assertNotSame( '', (string) ( $attrs['button']['innerContent']['desktop']['value']['text'] ?? '' ), 'the button has placeholder text' );
            }
        }
    }

    public function testTheSnippetsAreTheResolvedKitInTheEditorialLanguage(): void
    {
        $editorial = \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $editorial );
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            [ [ , $primary ], [ , $secondary ] ] = $this->buttons( 'style-kit:' . $id );
            foreach ( [ $primary, $secondary ] as $attrs ) {
                $deco = $attrs['button']['decoration'];
                $this->assertSame( array_fill_keys( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ], $kit['radius']['control'] ), $deco['border']['desktop']['value']['radius'], 'the kit radius is written as the four corners' );
                $this->assertSame( $kit['text']['weight'], $deco['font']['font']['desktop']['value']['weight'] );
                $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', $deco['font']['font']['desktop']['value']['color'], 'colours are the language values' );
                // The button padding is the MODULE's spacing (the button element's spacing is dead on divi/button).
                $this->assertSame(
                    [ 'top' => $kit['space']['control']['vertical'], 'right' => $kit['space']['control']['horizontal'], 'bottom' => $kit['space']['control']['vertical'], 'left' => $kit['space']['control']['horizontal'] ],
                    $attrs['module']['decoration']['spacing']['desktop']['value']['padding'],
                    'the kit padding is written on module.decoration.spacing'
                );
                $this->assertArrayNotHasKey( 'spacing', $deco, 'never on button.decoration.spacing' );
                $this->assertSame( [ 'display' => 'block' ], $attrs['module']['decoration']['layout']['desktop']['value'], 'the module layout survives' );
            }
            $this->assertSame( 'solid' === $kit['fill'] ? $editorial['colors'][ $kit['color'] ] ?? $editorial['colors'][ str_replace( '-', '_', $kit['color'] ) ] : KitAdapters::TRANSPARENT, $primary['button']['decoration']['background']['desktop']['value']['color'], 'the primary fill follows the kit' );
            // A ghost kit's secondary is its outline treatment: both transparent, told apart by the outline.
            // An underline carries the shape with its bottom side alone: judge that side (as the contrast proof does).
            $look = static fn ( array $a ): array => [ $a['button']['decoration']['background']['desktop']['value']['color'], ( $a['button']['decoration']['border']['desktop']['value']['styles']['bottom'] ?? $a['button']['decoration']['border']['desktop']['value']['styles']['all'] )['width'] ];
            $this->assertNotSame( $look( $primary ), $look( $secondary ), 'primary and secondary differ in their fill or their outline' );
            // A solid kit's primary is filled and its secondary is not; an outline or ghost kit's are both transparent and
            // are told apart by the frame (the look above).
            if ( 'solid' === $kit['fill'] ) {
                $this->assertNotSame(
                    $primary['button']['decoration']['background']['desktop']['value']['color'],
                    $secondary['button']['decoration']['background']['desktop']['value']['color'],
                    'primary and secondary differ in their fill'
                );
            }
        }
    }

    public function testUnknownKitIdIsAnsweredLikeAnUnknownLanguage(): void
    {
        foreach ( [ 'style-kit:nope', 'style-kit:', 'style-kit:SOFT-SOLID', "style-kit:soft-solid\n# Injected" ] as $bad ) {
            $text = StyleGuide::markdown( $bad );
            $this->assertStringStartsWith( '# Unknown topic', $text );
            foreach ( self::kitTopics() as $topic ) {
                $this->assertStringContainsString( '`' . $topic . '`', $text );
            }
            $this->assertStringNotContainsString( 'Divi 5 Layout Authoring Guide', $text );
            $this->assertSame( 1, preg_match_all( '/^# /m', $text ), 'a hostile id adds no heading' );
        }
    }

    /**
     * An outline kit's secondary button is a ghost (KitAdapters' role table, kit limits Decision 4): a plain label with no
     * fill and no frame. The AI must read that where it chooses and where it copies, and no line may still call it a block.
     */
    public function testTheSecondaryOfAnOutlineKitIsStatedInTheCatalogAndInTheKitTopic(): void
    {
        $catalog = StyleGuide::markdown( 'style-kits' );
        $seen    = 0;
        $this->assertStringNotContainsStringIgnoringCase( 'filled block', $catalog, 'no secondary is a filled block any more' );
        foreach ( StyleKits::ids() as $id ) {
            $kit = (array) StyleKits::get( $id );
            // Derived from the adapter, never a literal: an outline kit's secondary fill is "ghost".
            $secondaryFill = KitAdapters::secondaryFill( (string) $kit['fill'] );
            $line          = $this->catalogLine( $catalog, $id );
            $topic         = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertNotSame( 'solid', $secondaryFill, $id );
            if ( 'ghost' === $secondaryFill ) {
                $seen++;
                $this->assertMatchesRegularExpression( '/secondary is a plain (text link|label)/i', $line, $id . ': the catalog line states the secondary' );
                $this->assertStringContainsString( 'The secondary button is text-only: a ghost, no fill and no frame', $topic, $id );
            }
            // Every kit topic states primary AND secondary on a light section, from the adapter's own patch.
            $this->assertStringContainsString( 'The primary button on a light section has', $topic, $id );
            $this->assertStringContainsString( 'The secondary button on a light section has', $topic, $id );
        }
        $this->assertSame( 13, $seen, 'the shipped kits include 13 outline kits' );
    }

    private function catalogLine( string $catalog, string $id ): string
    {
        $this->assertSame( 1, preg_match( '/^- \*\*[^\n]*\(`' . preg_quote( $id, '/' ) . '`\): [^\n]*$/m', $catalog, $m ), $id . ' has one catalog line' );
        return $m[0];
    }

    /** StyleKits uses SectionRecipes (the layouts an `avoids` may name, the plain quote of an unknown id): loaded alone it must not fatal. */
    public function testStyleKitsAloneNeedsNoOtherFileLoadedFirst(): void
    {
        $file = realpath( __DIR__ . '/../wp-plugin/src/StyleKits.php' );
        $code = 'define("ABSPATH","/"); require ' . var_export( $file, true ) . ';'
            . '$kit = \\AiEditorDivi5\\WP\\StyleKits::get( \\AiEditorDivi5\\WP\\StyleKits::ids()[0] ); $kit["avoids"] = ["hero"];'
            . 'echo json_encode( [ \\AiEditorDivi5\\WP\\StyleKits::problems( $kit ) !== null, strlen( \\AiEditorDivi5\\WP\\StyleKits::markdown( "unknown" ) ) > 0 ] );';
        $proc = proc_open( [ PHP_BINARY, '-r', $code ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
        $this->assertIsResource( $proc );
        $out = stream_get_contents( $pipes[1] );
        $err = stream_get_contents( $pipes[2] );
        $this->assertSame( 0, proc_close( $proc ), 'a fresh process that loads only StyleKits.php runs: ' . $err );
        $this->assertSame( '[true,true]', $out );
    }

    /** The catalog lists every kit once (id + summary), so its size is linear in the kits; with 50 kits (the owner's cap) it must still fit. */
    public function testTheCatalogTopicStaysWithinItsBudgetWithFiftyKits(): void
    {
        $property = new \ReflectionProperty( StyleKits::class, 'cache' );
        $property->setAccessible( true );
        $real = array_map( static fn ( string $id ): array => (array) StyleKits::get( $id ), StyleKits::ids() );
        $this->assertNotSame( [], $real );
        try {
            $kits = $real;
            for ( $n = 1; count( $kits ) < 50; $n++ ) {
                $clone            = $real[ $n % count( $real ) ];
                $clone['id']      = 'extra-kit-' . $n;
                $clone['title']   = 'Extra kit ' . $n;
                $kits[]           = $clone;
            }
            $property->setValue( null, $kits );
            $this->assertCount( 50, StyleKits::ids() );
            $text = StyleGuide::markdown( 'style-kits' );
            $this->assertLessThanOrEqual( 10000, strlen( $text ), 'the catalog topic fits with 50 kits' );
            $this->assertStringContainsString( '`extra-kit-' . ( 50 - count( $real ) ) . '`', $text, 'the 50th kit is listed' );
            $this->assertStringContainsString( 'topic=style-kit:<id> with any id listed above', $text );
        } finally {
            StyleKits::clearCache();
        }
        $this->assertSame( array_column( $real, 'id' ), StyleKits::ids(), 'clearCache() restores the file data' );
    }

    public function testTheDefaultGuideNamesTheKitTopicsAndStaysUnderItsCeiling(): void
    {
        $text = StyleGuide::markdown();
        $last = substr( $text, (int) strrpos( $text, "\n\n" ) + 2 );
        $named = explode( ', ', substr( $last, strlen( 'Topics: call get_style_guide with topic=<name>: ' ), -1 ) );
        $this->assertContains( 'style-kits', $named );
        $this->assertContains( 'style-kit:<id>', $named );
        $this->assertSame( 1, substr_count( $text, 'style-kit:<id>' ), 'the kit ids are one placeholder' );
        foreach ( self::kitTopics() as $topic ) {
            $this->assertStringNotContainsString( $topic, $last, 'ids are not listed one by one' );
        }
        $this->assertLessThanOrEqual( 29108, strlen( $text ), 'Ruling 5 ceiling' );
    }

    public function testTheParameterDescriptionNamesTheKitTopicsWithinItsBound(): void
    {
        $desc = StyleGuide::topicParameterDescription();
        $this->assertStringContainsString( 'style-kits', $desc );
        $this->assertStringContainsString( 'style-kit:<id>', $desc );
        $this->assertLessThanOrEqual( 300, strlen( $desc ) );
    }

    /** @return array{0:?int,1:string} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_style_guide', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'] ];
        }

        return [ null, (string) $data['result']['content'][0]['text'] ];
    }

    private function rest( array $params ): \WP_REST_Response
    {
        return ( new RestController() )->style_guide( new \WP_REST_Request( [], $params ) );
    }
}
