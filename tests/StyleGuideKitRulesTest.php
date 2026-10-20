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
        // Image radius pass: the old "recorded in the kit but no module takes it yet" sentence is generated only while
        // KitAdapters::MEDIA_RADIUS_BLOCKS is empty (see testKitRulesHasAnImagesParagraphGeneratedFromTheConst); while a block
        // takes the media radius the Images paragraph is the sentence that moved.
        'take the kit\'s media radius, the third radius of every kit topic.',
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
        // Forms left the list with the form adapter (the sentence is generated from KitAdapters::APPLIED).
        // Image radius left the list with the media-radius const (the sentence is generated from KitAdapters::MEDIA_RADIUS_BLOCKS too).
        $this->assertSame( 'Blurbs, testimonials and every other module keep the recipe\'s own styling.', $shipped );
        $this->assertSame( 'Blurbs, testimonials, image radius and every other module keep the recipe\'s own styling.', StyleGuide::notAppliedSentence( null, [] ), 'with no block taking the media radius the sentence names it again' );
        $this->assertStringNotContainsStringIgnoringCase( 'accordion', $shipped, 'accordions are kit-driven now' );
        $this->assertStringContainsString( 'accordion and toggle items (`divi/accordion`, `divi/toggle`: `item`, `item-rule`)', StyleGuide::appliedSentence() );
        $this->assertSame( 'Accordions, forms, blurbs, testimonials, image radius and every other module keep the recipe\'s own styling.', StyleGuide::notAppliedSentence( [ 'divi/button' => [ 'control' ] ], [] ) );
        $this->assertSame( 'Accordions, forms, testimonials, image radius and every other module keep the recipe\'s own styling.', StyleGuide::notAppliedSentence( [ 'divi/blurb' => [ 'tile' ] ], [] ) );
        foreach ( [ 'style-kits', 'kit-rules' ] as $topic ) {
            $this->assertStringContainsString( StyleGuide::appliedSentence() . ' ' . $shipped, StyleGuide::markdown( $topic ), $topic );
        }
    }

    public function testKitRulesHasAnImagesParagraphGeneratedFromTheConst(): void
    {
        $old = 'a kit\'s media radius is recorded in the kit but no module takes it yet';
        $this->assertSame( [ 'divi/image' ], KitAdapters::MEDIA_RADIUS_BLOCKS );

        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringContainsString( "\n## Images\n", $text );
        $this->assertStringContainsString( '(`divi/image`)', $text );
        $this->assertStringContainsString( 'image.decoration.border.desktop.value.radius', $text );
        $this->assertStringNotContainsString( $old, $text, 'the shipped guide must not say the media radius is recorded but unused' );
        $this->assertStringNotContainsString( 'no module takes it', $text );
        $this->assertSame( StyleGuide::kitRules(), StyleGuide::kitRules( null, null, KitAdapters::MEDIA_RADIUS_BLOCKS ) );

        // Planted empty const: the old sentence is back and the Images paragraph is gone (the text follows the const).
        $empty = StyleGuide::kitRules( null, null, [] );
        $this->assertStringContainsString( $old, $empty );
        $this->assertSame( 1, substr_count( $empty, $old ) );
        $this->assertStringNotContainsString( '## Images', $empty );
        $this->assertStringNotContainsString( '`divi/image`', $empty );

        // A different block is named in the paragraph, so it is generated, not canned.
        $other = StyleGuide::kitRules( null, null, [ 'divi/blurb' ] );
        $this->assertStringContainsString( '(`divi/blurb`)', $other );
        $this->assertStringNotContainsString( '`divi/image`', $other );
        $this->assertStringNotContainsString( $old, $other );

        // Budgets: the paragraph costs at most 400 B net of the clause it replaced; no kit topic or other guide moved.
        $this->assertLessThanOrEqual( 4301 + 400, strlen( $text ) );
        $this->assertLessThanOrEqual( self::BUDGET, strlen( $text ) );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide is exactly as before' );
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
        // Re-pinned deliberately (forms adapter T2): kit-rules 3,525 + the Contact forms paragraph (+776 B in all after fix round 2, with the 30 B applied-sentence change: fix round 1's underline wording, the accent-band clause of the field frame and the `button.decoration.*` path clause, all generated; the hard cap is 10,000 B); the catalog 8,423 + "forms (`divi/contact-form`: `form`)" less "Forms, " in the not-applied sentence (30 B).
        $this->assertSame( 4301, strlen( StyleGuide::kitRules( null, null, [] ) ), 'kit-rules as it was before the image radius pass (no block takes the media radius)' );
        // Image radius pass (Task 1): the Images paragraph replaces the "recorded but unused" clause: +291 B net (plan bound: 4,301 + 400).
        $this->assertSame( 4592, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        $this->assertSame( 8439, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog (was 8,464, then 8,423 before the form adapter, 8,453 with it; 14 B less since "image radius" left the not-applied sentence)' );
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
    public function testKitRulesHasAFormsParagraphGeneratedFromTheMap(): void
    {
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringContainsString( '## Contact forms', $text );
        $this->assertStringContainsString( '`form`', $text );
        $this->assertStringContainsString( '`divi/contact-form`', $text );

        $noForm = KitAdapters::APPLIED;
        unset( $noForm['divi/contact-form'] );
        $this->assertStringNotContainsString( '## Contact forms', StyleGuide::kitRules( $noForm ), 'no form adapter, no form rules' );
        $this->assertStringNotContainsString( '`divi/contact-form`', StyleGuide::kitRules( $noForm ) );
        $this->assertNotSame( StyleGuide::kitRules(), StyleGuide::kitRules( $noForm ) );

        // The paragraph follows FORM_PROVEN: each decision changes its own wording, and an unproven one is never claimed.
        $yes = KitAdapters::FORM_PROVEN;
        $this->assertSame( StyleGuide::kitRules(), StyleGuide::kitRules( null, $yes ) );
        $wording = [
            'field-padding'   => [ 'control padding', 'padding stays the recipe\'s' ],
            'field-underline' => [ 'bottom rule', 'full 1px frame' ],
            'button-padding'  => [ 'submit padding', 'submit button keeps the recipe\'s padding' ],
            'button-shadow'   => [ 'submit shadow', 'submit button has no shadow' ],
            'button-lift'     => [ 'submit lift', 'submit button does not lift' ],
            // Keyed to the underline decisions: proven it says the edge does not change on hover, unproven it says full frame.
            'button-underline' => [ 'keeps its colour on hover', 'an underline edge is a full frame' ],
        ];
        foreach ( $wording as $decision => [ $when, $unless ] ) {
            // The Contact forms paragraph is the last section (other paragraphs also say "control padding" and "bottom rule").
            $with    = StyleGuide::kitRules( null, $yes );
            $without = StyleGuide::kitRules( null, array_merge( $yes, [ $decision => false ] ) );
            $with    = substr( $with, (int) strpos( $with, '## Contact forms' ) );
            $without = substr( $without, (int) strpos( $without, '## Contact forms' ) );
            $this->assertNotSame( $with, $without, "$decision changes the paragraph" );
            $this->assertStringContainsString( $when, $with, "$decision proven: said" );
            $this->assertStringNotContainsString( $when, $without, "$decision unproven: not claimed" );
            $this->assertStringContainsString( $unless, $without, "$decision unproven: the fallback is said" );
            $this->assertStringNotContainsString( $unless, $with, "$decision proven: no fallback wording" );
        }
        // The form paragraph does not leak into a map that has no form, whatever is proven.
        $this->assertSame( StyleGuide::kitRules( $noForm ), StyleGuide::kitRules( $noForm, array_map( static fn ( bool $v ): bool => false, $yes ) ) );
    }

    public function testFormsTopicGainsOneGeneratedKitSentence(): void
    {
        $sentence = StyleGuide::formsKitSentence();
        $this->assertStringContainsString( 'topic=kit-rules', $sentence );
        $this->assertStringContainsString( '## Style kits', $sentence );
        $this->assertStringEndsWith( $sentence, StyleGuide::markdown( 'forms' ) );
        $this->assertSame( '', StyleGuide::formsKitSentence( [ 'divi/button' => [ 'control' ] ] ), 'no form adapter, no sentence' );
        $this->assertLessThanOrEqual( 350, strlen( $sentence ), 'budget: at most 350 B on the forms topic' );
        $this->assertSame( 6315 + strlen( $sentence ), strlen( StyleGuide::markdown( 'forms' ) ), 'the forms topic grew by exactly the sentence (6,315 B before)' );
    }

    public function testTheDefaultGuideAndTheBudgetsOfTheFormAdapter(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        // The 700 B planned for kit-rules growth became 776 B: the accent-band clause of the field frame and the path clause
        // (`button.decoration.*`, not `module.decoration`) were added after review. The bound below is the real one, the cap is 10,000 B.
        $this->assertLessThanOrEqual( 3525 + 800, strlen( StyleGuide::kitRules( null, null, [] ) ), 'kit-rules without the Images paragraph: the form paragraph at most 800 B' );
        $this->assertLessThanOrEqual( 4301 + 400, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules with the Images paragraph (image radius pass): at most 400 B more' );
        $this->assertLessThanOrEqual( 10000, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        $this->assertLessThanOrEqual( 10000, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog' );
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertLessThanOrEqual( 9599 + 40, $len, "$id grows at most 40 B (no form example in a kit topic)" );
            $max = max( $max, $len );
        }
        $this->assertLessThanOrEqual( 9700, $max, 'the largest kit topic' );
        $this->assertLessThanOrEqual( 260, strlen( StyleGuide::topicParameterDescription() ), 'headroom for the next adapters' );
    }

    public function testTheAppliedSentenceNamesTheForm(): void
    {
        $this->assertStringContainsString( 'forms (`divi/contact-form`: `form`)', StyleGuide::appliedSentence() );
        $this->assertStringNotContainsStringIgnoringCase( 'forms, ', StyleGuide::notAppliedSentence(), 'forms are kit-driven now' );
    }
    /** The Contact forms paragraph of kit-rules (the last section). */
    private function formsParagraph( ?array $proven = null ): string
    {
        $text = StyleGuide::kitRules( null, $proven );
        return substr( $text, (int) strpos( $text, '## Contact forms' ) );
    }

    public function testTheFormParagraphStatesTheAccentBandFrameException(): void
    {
        // KitAdapters::formPatch writes a `text` frame on an accent band whatever the kit says (muted is 2.55:1 or less there).
        $this->assertStringContainsString( 'muted for a `same` or `border` border colour (never on an accent band)', $this->formsParagraph() );
        $kit = (array) StyleKits::get( 'soft-solid' );
        $this->assertContains( $kit['border']['color'], [ 'same', 'border' ], 'a kit whose frame is muted on every other band' );
        $frame = static fn ( string $on ): string => KitAdapters::formPatch( $on, $kit )['field']['decoration']['border']['desktop']['value']['styles']['all']['color'];
        $this->assertSame( '{{aied:color:text}}', $frame( 'accent' ), 'the clause is true: text on the accent band' );
        foreach ( [ 'light', 'surface', 'dark' ] as $on ) {
            $this->assertSame( '{{aied:color:muted}}', $frame( $on ), "and muted on $on, as the paragraph says" );
        }
    }

    public function testTheFormParagraphSaysWhereTheSubmitPaddingShadowAndLiftGo(): void
    {
        $yes  = KitAdapters::FORM_PROVEN;
        $path = 'at `button.decoration.*`, not `module.decoration`';
        $this->assertStringContainsString( $path, $this->formsParagraph( $yes ), 'proven: said, in a few bytes' );
        foreach ( [ 'button-padding', 'button-shadow', 'button-lift' ] as $decision ) {
            $this->assertStringContainsString( $path, $this->formsParagraph( array_merge( $yes, [ $decision => false ] ) ), "$decision alone unproven: the other two still go there" );
        }
        $none = array_merge( $yes, [ 'button-padding' => false, 'button-shadow' => false, 'button-lift' => false ] );
        $this->assertStringNotContainsString( 'button.decoration', $this->formsParagraph( $none ), 'nothing is written there, nothing is claimed' );
        $this->assertStringNotContainsString( 'module.decoration', $this->formsParagraph( $none ) );
        // The Buttons sentence stays true for divi/button and is not copied onto the form.
        $rules = StyleGuide::kitRules();
        $this->assertStringContainsString( 'never on button.decoration: Divi drops those on a button.', $rules );
        $this->assertSame( 1, substr_count( $rules, 'never on button.decoration' ) );
        // The claim is true: the submit's padding, shadow and lift are at button.decoration on the form and absent from module.
        $kit   = (array) StyleKits::get( 'chunky-pill-offset' );
        $patch = KitAdapters::formPatch( 'light', $kit );
        $this->assertArrayHasKey( 'spacing', $patch['button']['decoration'] );
        $this->assertArrayHasKey( 'boxShadow', $patch['button']['decoration'] );
        $this->assertArrayNotHasKey( 'module', $patch );
    }
}
