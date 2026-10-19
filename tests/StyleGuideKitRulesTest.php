<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $kitRulesTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $kitRulesTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * Accordion plan Task 4 (merged into Task 2, Ruling 1b): the generated `kit-rules` topic says once what every kit topic
 * repeated (what a button, a card, a panel and an accordion or toggle item take from a kit, how to reuse the blocks); each
 * `style-kit:<id>` keeps its own values and resolved blocks (now with a toggle) and one pointer line; the wording that names
 * what is and is not kit-driven is generated from KitAdapters::APPLIED.
 */
class StyleGuideKitRulesTest extends TestCase
{
    private const BUDGET = 10000;

    /** The prose that moved: each must be in `kit-rules` once and in no kit topic. */
    private const MOVED = [
        'The fill is always the language\'s surface colour (the `surface` role; the `bg` role on a surface-coloured band), never transparent.',
        'A panel is a static surface: the same radius, frame and shadow, no hover, and the recipe keeps its own padding.',
        'border colour is `same` or `border`',
        'The frame takes the fill colour; the kit never re-inks the children.',
        'capitalization, weight and letter spacing are button-only',
        'a kit\'s media radius is recorded in the kit but no module takes it yet',
        'Shadows and transforms belong on the button module',
        'two roles can share one value',
        'Kit-driven today:',
    ];

    public function testKitRulesIsATopicServedWithinBudget(): void
    {
        $this->assertContains( 'kit-rules', StyleGuide::topics() );
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringStartsWith( '# Kit rules', $text );
        $this->assertLessThanOrEqual( self::BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( 'aied', $text );
        $this->assertSame( 1, preg_match_all( '/^# /m', $text ), 'one H1' );
        $this->assertSame( StyleGuide::kitRules(), $text, 'the topic is the generated text for the shipped map' );
        foreach ( [ '## Buttons', '## Cards and panels', '## Accordion and toggle items' ] as $heading ) {
            $this->assertStringContainsString( $heading, $text );
        }
    }

    public function testKitRulesIsGeneratedFromTheMapItIsGiven(): void
    {
        $buttons = StyleGuide::kitRules( [ 'divi/button' => [ 'control', 'control-secondary' ] ] );
        $this->assertStringContainsString( '## Buttons', $buttons );
        $this->assertStringNotContainsString( '## Cards and panels', $buttons, 'no card adapter, no card rules' );
        $this->assertStringNotContainsString( '## Accordion and toggle items', $buttons );
        $this->assertStringNotContainsString( '`divi/group`', $buttons );
        $this->assertMatchesRegularExpression( '/Accordions, forms, blurbs/', $buttons, 'without the item adapter accordions keep the recipe\'s styling' );

        $noItems = KitAdapters::APPLIED;
        unset( $noItems['divi/accordion'], $noItems['divi/toggle'] );
        $this->assertStringNotContainsString( '## Accordion and toggle items', StyleGuide::kitRules( $noItems ) );
        $this->assertNotSame( StyleGuide::kitRules(), StyleGuide::kitRules( $noItems ) );
    }

    public function testTheNotAppliedSentenceNeverNamesAModuleAnAdapterStyles(): void
    {
        $shipped = StyleGuide::notAppliedSentence();
        $this->assertSame( 'Forms, blurbs, testimonials, image radius and every other module keep the recipe\'s own styling.', $shipped );
        $this->assertStringNotContainsStringIgnoringCase( 'accordion', $shipped, 'accordions are kit-driven now' );
        $this->assertStringContainsString( 'accordion and toggle items (`divi/accordion`, `divi/toggle`: `item`, `item-rule`)', StyleGuide::appliedSentence() );
        $this->assertSame( 'Accordions, forms, blurbs, testimonials, image radius and every other module keep the recipe\'s own styling.', StyleGuide::notAppliedSentence( [ 'divi/button' => [ 'control' ] ] ) );
        $this->assertSame( 'Accordions, forms, testimonials, image radius and every other module keep the recipe\'s own styling.', StyleGuide::notAppliedSentence( [ 'divi/blurb' => [ 'tile' ] ] ) );
        foreach ( [ 'style-kits', 'kit-rules' ] as $topic ) {
            $this->assertStringContainsString( StyleGuide::appliedSentence() . ' ' . $shipped, StyleGuide::markdown( $topic ), $topic );
        }
    }

    public function testTheRepeatedProseIsSaidOnceInKitRulesAndInNoKitTopic(): void
    {
        $rules = StyleGuide::markdown( 'kit-rules' );
        foreach ( self::MOVED as $sentence ) {
            $this->assertSame( 1, substr_count( $rules, $sentence ), "kit-rules says once: $sentence" );
        }
        foreach ( StyleKits::ids() as $id ) {
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            foreach ( self::MOVED as $sentence ) {
                $this->assertStringNotContainsString( $sentence, $text, "$id no longer repeats: $sentence" );
            }
            $this->assertSame( 1, substr_count( $text, 'topic=kit-rules' ), "$id: one pointer to kit-rules" );
        }
        $this->assertSame( 1, substr_count( StyleGuide::markdown( 'style-kits' ), 'topic=kit-rules' ), 'the catalog\'s pointer sentence names kit-rules' );
        $this->assertStringContainsString( 'topic=kit-rules', StyleGuide::markdown( 'toggles' ) );
    }

    public function testTheTogglesTopicSentenceIsGenerated(): void
    {
        $this->assertStringContainsString( 'topic=style-kit:<id>', StyleGuide::togglesKitSentence() );
        $this->assertSame( '', StyleGuide::togglesKitSentence( [ 'divi/button' => [ 'control' ] ] ) );
        $this->assertStringEndsWith( StyleGuide::togglesKitSentence(), StyleGuide::markdown( 'toggles' ) );
        $this->assertStringContainsString( 'Divi paints the tab bar #f4f4f4', StyleGuide::markdown( 'toggles' ), 'the tabs limit stays' );
    }

    /** @return array{attrs:array<string,mixed>,block:string} */
    private function toggleSnippet( string $topic ): array
    {
        $text = StyleGuide::markdown( $topic );
        $this->assertSame( 1, preg_match( '/\nToggle \(an `item`[^\n]*\n\n```\n(<!-- wp:divi\/toggle (\{.*?\}) \/-->)\n```/s', $text, $m ), "$topic: a toggle snippet" );
        $attrs = json_decode( $m[2], true );
        $this->assertIsArray( $attrs );
        return [ 'attrs' => $attrs, 'block' => $m[1] ];
    }

    public function testEachKitTopicCarriesAToggleFromTheRealPipelineThatValidates(): void
    {
        $registry  = ShapeRegistry::load();
        $editorial = (array) DesignLanguages::get( 'editorial' );
        foreach ( StyleKits::ids() as $id ) {
            $kit   = (array) StyleKits::get( $id );
            $found = $this->toggleSnippet( 'style-kit:' . $id );
            $this->assertStringNotContainsString( 'aied', $found['block'] );
            $resolved = RecipeTokens::resolve( KitResolver::resolve( '<!-- wp:divi/toggle {"aied":{"kit":"item","on":"light"}} /-->', $kit ), $editorial );
            $this->assertSame( 1, preg_match( '/^<!-- wp:divi\/toggle (\{.*\}) \/-->$/', $resolved, $m ) );
            $patch = (array) json_decode( $m[1], true );
            $attrs = $found['attrs'];
            unset( $attrs['title']['innerContent'], $attrs['content']['innerContent'], $attrs['builderVersion'] );
            $attrs = array_filter( $attrs, static fn ( $v ): bool => [] !== $v );
            $this->assertEquals( $patch, $attrs, "$id: the toggle is the resolved kit, not hand-written" );
            $this->assertSame( (string) $editorial['colors']['muted'], $found['attrs']['content']['decoration']['bodyFont']['body']['font']['desktop']['value']['color'], "$id: the answer is muted" );
            $this->assertSame( 'Toggle title', $found['attrs']['title']['innerContent']['desktop']['value'] );

            $page   = '<!-- wp:divi/section {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/column {"builderVersion":"5.0.0-public-beta.1"} -->' . $found['block'] . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "$id toggle: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "$id toggle passes the plugin gate" );
            foreach ( ShapePaths::fromBlockAttrs( $found['attrs'] ) as $path ) {
                $this->assertTrue( $registry->covers( $path ), "$id toggle uses an unregistered path $path" );
            }
            $this->assertStringContainsString( sprintf( 'Toggle (an `item`, padding %s).', $kit['space']['card'] ), StyleGuide::markdown( 'style-kit:' . $id ) );
        }
    }

    public function testTheLegendNamesEveryColourOfTheToggle(): void
    {
        $editorial = (array) DesignLanguages::get( 'editorial' );
        foreach ( StyleKits::ids() as $id ) {
            $text  = StyleGuide::markdown( 'style-kit:' . $id );
            $prose = (string) preg_replace( '/```.*?```/s', '', $text );
            preg_match_all( '/#[0-9a-f]{6}\b|rgba\([^)]*\)/i', $this->toggleSnippet( 'style-kit:' . $id )['block'], $lits );
            $this->assertNotSame( [], $lits[0] );
            foreach ( array_unique( $lits[0] ) as $lit ) {
                $this->assertMatchesRegularExpression( '/' . preg_quote( $lit, '/' ) . ' = [^;.)]+/', $prose, "$id: $lit of the toggle is in a legend" );
            }
            $this->assertMatchesRegularExpression( '/' . preg_quote( (string) $editorial['colors']['muted'], '/' ) . ' = [^;.)]*\bmuted\b/', $prose, "$id: the answer colour names its role" );
        }
    }

    public function testTheInvertedPanelSnippetKeepsAPanelPadding(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertSame( 1, preg_match( '/Inverted panel:\n\n```\n<!-- wp:divi\/group (\{.*?\}) -->/s', $text, $m ), $id );
            $attrs = (array) json_decode( $m[1], true );
            $this->assertSame( [ 'top' => '32px', 'right' => '32px', 'bottom' => '32px', 'left' => '32px' ], $attrs['module']['decoration']['spacing']['desktop']['value']['padding'] ?? null, "$id: the button does not sit on the panel's edges" );
            $this->assertStringContainsString( 'The recipe keeps its own padding on a panel (32px here).', $text, $id );
        }
    }

    public function testTheSizesArePinnedDeliberately(): void
    {
        $max = 0;
        $at  = '';
        foreach ( StyleKits::ids() as $id ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            if ( $len > $max ) {
                [ $max, $at ] = [ $len, $id ];
            }
        }
        $this->assertLessThanOrEqual( self::BUDGET, $max );
        $this->assertSame( [ 'neo-brutal', 9599 ], [ $at, $max ], 'the largest kit topic (was 9,992 before kit-rules, without the toggle)' );
        $this->assertSame( 3525, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        $this->assertSame( 8423, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog (was 8,464)' );
    }

    public function testTheGroundSentenceNamesTheAdaptersFillsAndFailsClosed(): void
    {
        $grounds = ( new \ReflectionClassConstant( KitAdapters::class, 'GROUNDS' ) )->getValue();
        $this->assertSame( [ 'inverted' => 'text', 'accent' => 'accent' ], $grounds );
        $rules = StyleGuide::markdown( 'kit-rules' );
        foreach ( $grounds as $ground => $fill ) {
            $this->assertStringContainsString( '`' . $ground . '` ground (' . $fill . ' colour as fill;', $rules, "$ground: the fill role is the adapter's" );
        }
        $this->assertStringContainsString( StyleGuide::groundSentence(), $rules );
        $this->assertNotSame( '', StyleGuide::groundSentence() );
        foreach ( [ [ 'inverted' => 'muted', 'accent' => 'accent' ], [ 'inverted' => 'text', 'accent' => 'surface' ], [ 'inverted' => 'text' ], [] ] as $planted ) {
            $this->assertSame( '', StyleGuide::groundSentence( $planted ), 'fill roles the sentence does not describe drop the paragraph: ' . json_encode( $planted ) );
        }
    }

    /** Every fact kit-rules states about items, checked against the adapter's own patches for every shipped kit. */
    public function testKitRulesItemFactsMatchTheAdapter(): void
    {
        $rules = StyleGuide::markdown( 'kit-rules' );
        $facts = [
            'An `item-rule` (on a light or surface band only)'                 => static fn ( array $kit ): bool => null === KitAdapters::patch( 'divi/toggle', 'item-rule', 'accent', $kit ) && null === KitAdapters::patch( 'divi/accordion', 'item-rule', 'dark', $kit ) && null !== KitAdapters::patch( 'divi/toggle', 'item-rule', 'surface', $kit ),
            'on a toggle it also takes the card padding'                         => static fn ( array $kit ): bool => [ 'padding' => '{{aied:kit:space-card}}' ] === ( KitAdapters::patch( 'divi/toggle', 'item', 'light', $kit )['module']['decoration']['spacing']['desktop']['value'] ?? null ),
            'an accordion keeps Divi\'s own item padding'                        => static fn ( array $kit ): bool => ! isset( KitAdapters::patch( 'divi/accordion', 'item', 'light', $kit )['module']['decoration']['spacing'] ),
            'has square corners'                                                 => static fn ( array $kit ): bool => '0px' === ( KitAdapters::patch( 'divi/toggle', 'item-rule', 'light', $kit )['module']['decoration']['border']['desktop']['value']['radius']['topLeft'] ?? null ),
            'and no shadow; the recipe keeps its own padding'                    => static fn ( array $kit ): bool => [] === array_intersect( [ 'boxShadow', 'spacing' ], array_keys( (array) KitAdapters::patch( 'divi/toggle', 'item-rule', 'light', $kit )['module']['decoration'] ) ),
            'an accordion has no open icon'                                      => static fn ( array $kit ): bool => ! isset( KitAdapters::patch( 'divi/accordion', 'item', 'light', $kit )['openToggleIcon'] ) && isset( KitAdapters::patch( 'divi/toggle', 'item', 'light', $kit )['openToggleIcon'] ),
            'the answer in the muted colour'                                     => static fn ( array $kit ): bool => '{{aied:color:muted}}' === KitAdapters::patch( 'divi/accordion', 'item-rule', 'light', $kit )['content']['decoration']['bodyFont']['body']['font']['desktop']['value']['color'],
            'takes the card\'s frame, radius and rest shadow'                     => static fn ( array $kit ): bool => ( KitAdapters::patch( 'divi/group', 'panel', 'dark', $kit )['module']['decoration']['border'] ?? null ) === KitAdapters::patch( 'divi/toggle', 'item', 'dark', $kit )['module']['decoration']['border'] && ( KitAdapters::patch( 'divi/group', 'panel', 'dark', $kit )['module']['decoration']['boxShadow'] ?? null ) === ( KitAdapters::patch( 'divi/toggle', 'item', 'dark', $kit )['module']['decoration']['boxShadow'] ?? null ),
            'no hover'                                                           => static fn ( array $kit ): bool => ! str_contains( (string) json_encode( [ KitAdapters::patch( 'divi/accordion', 'item', 'light', $kit ), KitAdapters::patch( 'divi/toggle', 'item', 'accent', $kit ) ] ), '"hover"' ),
        ];
        foreach ( $facts as $sentence => $holds ) {
            $this->assertStringContainsString( $sentence, $rules, 'kit-rules states it' );
            foreach ( StyleKits::ids() as $id ) {
                $this->assertTrue( $holds( (array) StyleKits::get( $id ) ), "$id: the adapter does what kit-rules says: $sentence" );
            }
        }
    }
}
