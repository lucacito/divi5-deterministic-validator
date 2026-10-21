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

        // Budgets: the paragraph costs at most 400 B net of the clause it replaced; no kit topic or other guide moved. Re-pinned
        // deliberately (rules and chips adapter T3, plan 2026-10-21): the applied tail (+119 B) and the Rules and chips paragraph
        // (+529 B with its heading) take the bound to 4,607 + 650.
        // Guide polish (plan 2026-10-21-guide-polish-rules-chips) replaces the self-imposed 4,607 + 650 pin with 7,000 B (the real bound is 10,000 B).
        $this->assertLessThanOrEqual( 7900, strlen( $text ) );
        $this->assertLessThanOrEqual( self::BUDGET, strlen( $text ) );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
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
        // Rules and chips adapter T3 (plan 2026-10-21), re-pinned deliberately: without the Images paragraph kit-rules was 4,301; the
        // applied sentence gained its tail (+119 B) and the Rules and chips paragraph (+529 B) arrived: 4,949.
        // Guide polish (plan 2026-10-21-guide-polish-rules-chips) re-pinned deliberately: + the shapes passage under Rules and chips, the
        // "kit wins" sentence and the contact form's live-path clause: 4,949 -> 6,289 (and 5,255 -> 6,595 with the Images paragraph; the review fix reworded two clauses, +133 B).
        // Guide polish second pass (spec Ruling 5), re-pinned deliberately: the tag fill/padding sentence, the form field padding path and the accordion paths: 6,289 -> 6,996 (6,595 -> 7,302 with the Images paragraph; the pin is 7,600).
        // Left items pass (guide), re-pinned deliberately: the accordion clause split per role and the toggle clause: 6,996 -> 7,558 (7,302 -> 7,864 with the Images paragraph; the bound is 7,900).
        $this->assertSame( 7558, strlen( StyleGuide::kitRules( null, null, [] ) ), 'kit-rules without the Images paragraph (4,301 before the rules and chips adapter)' );
        // Image radius pass (Task 1): the Images paragraph replaces the "recorded but unused" clause: +291 B net. Rules and chips T3: 4,607 -> 5,255 (plan bound: 4,607 + 650).
        $this->assertSame( 7864, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        // Rules and chips adapter Step 0 (plan 2026-10-21): the catalog's fixed prose was reworded to reclaim headroom BEFORE the applied sentence gained its rules and chips tail: 8,439 -> 8,325 B (50-kit projection 9,923 -> 9,809 B); the tail then added 119 B: 8,444 B (9,928 B with 50 kits).
        $this->assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog (was 8,464, then 8,423 before the form adapter, 8,453 with it, 8,439 at image radius; 8,325 after the Step 0 rewording, 8,444 with the rules and chips tail)' );
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
        // Re-bounded deliberately (rules and chips adapter T3): + the applied tail and the Rules and chips paragraph (648 B in all; the bound is 650).
        // Guide polish (plan 2026-10-21-guide-polish-rules-chips): the self-imposed 3,525 + 800 + 650 and 4,301 + 400 + 650 pins are replaced by 7,000 B.
        $this->assertLessThanOrEqual( 7900, strlen( StyleGuide::kitRules( null, null, [] ) ), 'kit-rules without the Images paragraph' );
        $this->assertLessThanOrEqual( 7900, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules with the Images paragraph' );
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
        $form = substr( $text, (int) strpos( $text, '## Contact forms' ) );
        // Up to the next section (Rules and chips follows it and names module.decoration paths of its own).
        $next = strpos( $form, "\n## ", 4 );
        return false === $next ? $form : substr( $form, 0, $next + 1 );
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

    /** The rules and chips paragraph of kit-rules (the last section) for the given decisions. */
    private function rulesParagraph( ?array $applied = null, ?array $ruleProven = null, ?array $chipProven = null ): string
    {
        $text = StyleGuide::kitRules( $applied, null, null, $ruleProven, $chipProven );
        $at   = strpos( $text, '## Rules and chips' );
        return false === $at ? '' : substr( $text, $at );
    }

    public function testKitRulesHasARulesAndChipsParagraphGeneratedFromTheMapAndTheDecisions(): void
    {
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertStringContainsString( "\n## Rules and chips\n", $text );
        $this->assertSame( StyleGuide::kitRules(), StyleGuide::kitRules( null, null, null, KitAdapters::RULE_PROVEN, KitAdapters::CHIP_PROVEN ), 'the default is the shipped decisions' );
        $para = $this->rulesParagraph();
        foreach ( [ '`rule`', '`rule-strong`', '`chip`', '`divi/divider`', '`divi/group`', '`divi/column`', '`divi/icon`', 'top edge' ] as $needle ) {
            $this->assertStringContainsString( $needle, $para, $needle );
        }
        $this->assertStringContainsString( '`divi/group` or `divi/column`', $para, 'the top edge carriers' );
        $this->assertSame( 1, substr_count( $text, '## Rules and chips' ) );

        // A map without the rule and chip roles drops the whole paragraph (and names no rule carrier).
        $noThin = KitAdapters::APPLIED;
        unset( $noThin['divi/divider'], $noThin['divi/icon'] );
        $noThin['divi/group']  = [ 'card', 'panel' ];
        $noThin['divi/column'] = [ 'card', 'panel' ];
        $this->assertSame( '', $this->rulesParagraph( $noThin ), 'no rule or chip role, no paragraph' );
        $this->assertStringNotContainsString( '`divi/divider`', StyleGuide::kitRules( $noThin ) );
        $this->assertStringNotContainsString( '`divi/icon`', StyleGuide::kitRules( $noThin ) );
        $this->assertNotSame( StyleGuide::kitRules(), StyleGuide::kitRules( $noThin ) );
        // Only rules: no chip words. Only chips: no rule words.
        $rulesOnly = $noThin;
        $rulesOnly['divi/divider'] = [ 'rule', 'rule-strong' ];
        $para = $this->rulesParagraph( $rulesOnly );
        $this->assertStringContainsString( '`rule-strong`', $para );
        $this->assertStringNotContainsString( '`chip`', $para );
        $chipsOnly = $noThin;
        $chipsOnly['divi/icon'] = [ 'chip' ];
        $para = $this->rulesParagraph( $chipsOnly );
        $this->assertStringContainsString( '`chip`', $para );
        $this->assertStringNotContainsString( '`rule-strong`', $para );

        // The wording follows every decision: a false one states its fallback and the proven form is not claimed.
        $yes  = KitAdapters::RULE_PROVEN;
        $cyes = KitAdapters::CHIP_PROVEN;
        $withParagraph = $this->rulesParagraph();
        // Base decisions: a carrier whose base is unproven is not offered.
        $noDivider = $this->rulesParagraph( null, array_merge( $yes, [ 'divider-line' => false ] ) );
        $this->assertStringNotContainsString( '`divi/divider`', $noDivider, 'divider-line unproven: the divider is not offered' );
        $this->assertStringContainsString( 'top edge', $noDivider );
        $noGroup = $this->rulesParagraph( null, array_merge( $yes, [ 'group-top' => false ] ) );
        $this->assertStringNotContainsString( '`divi/group`', preg_replace( '/\(`divi\/group`[^)]*\)/', '', $noGroup ), 'group-top unproven: a group rule is not offered' );
        $this->assertStringContainsString( '`divi/column`', $noGroup );
        $noColumn = $this->rulesParagraph( null, array_merge( $yes, [ 'column-top' => false ] ) );
        $this->assertStringNotContainsString( '`divi/column`', $noColumn );
        // Style fallbacks name the carrier and the style, and only while unproven.
        foreach ( [ 'dashed', 'dotted', 'double' ] as $style ) {
            foreach ( [ 'divider' => '`divi/divider`', 'group-top' => '`divi/group`', 'column-top' => '`divi/column`' ] as $prefix => $carrier ) {
                $off = $this->rulesParagraph( null, array_merge( $yes, [ "$prefix-$style" => false ] ) );
                $this->assertNotSame( $withParagraph, $off, "$prefix-$style changes the paragraph" );
                $this->assertStringContainsString( 'solid', $off, "$prefix-$style unproven: the fallback is said" );
                $this->assertStringContainsString( $style, $off, "$prefix-$style unproven: the style is named in the fallback" );
            }
        }
        $this->assertStringNotContainsString( 'as solid', $withParagraph, 'all styles proven: no fallback wording' );
        $this->assertStringNotContainsString( 'falls back', $withParagraph );
        // Alpha: unproven means the quiet rule takes the strong colour on accent, dark and panel.
        foreach ( [ 'divider-alpha', 'group-top-alpha', 'column-top-alpha' ] as $decision ) {
            $off = $this->rulesParagraph( null, array_merge( $yes, [ $decision => false ] ) );
            $this->assertNotSame( $withParagraph, $off, "$decision changes the paragraph" );
            $this->assertStringContainsString( 'strong colour', $off, "$decision unproven: the fallback is said" );
        }
        $this->assertStringNotContainsString( 'strong colour', $withParagraph );
        // In a card.
        $off = $this->rulesParagraph( null, array_merge( $yes, [ 'group-top-in-card' => false ] ) );
        $this->assertNotSame( $withParagraph, $off );
        $this->assertStringContainsString( 'card', $off );
        $this->assertStringNotContainsString( 'in a card', $withParagraph );
        // Chips.
        foreach ( [ 'tag-frame' => 'outline frame', 'icon-fill' => 'accent fill', 'icon-glyph' => 'accent-text glyph' ] as $decision => $claim ) {
            $off = $this->rulesParagraph( null, null, array_merge( $cyes, [ $decision => false ] ) );
            $this->assertStringContainsString( $claim, $withParagraph, "$decision proven: said" );
            $this->assertStringNotContainsString( $claim, $off, "$decision unproven: not claimed" );
        }
        foreach ( [ 'icon-fill', 'icon-glyph' ] as $decision ) {
            $off = $this->rulesParagraph( null, null, array_merge( $cyes, [ $decision => false ] ) );
            $this->assertStringContainsString( 'not offered on an accent band', $off, "$decision unproven: the accent band is refused" );
        }
        $this->assertStringNotContainsString( 'not offered', $withParagraph );
        foreach ( [ 'dashed', 'dotted', 'double' ] as $style ) {
            $off = $this->rulesParagraph( null, null, array_merge( $cyes, [ "tag-$style" => false ] ) );
            $this->assertStringContainsString( $style, $off, "tag-$style unproven: named" );
            $this->assertStringContainsString( 'solid', $off );
        }
        // The paragraph never leaks into another map's guide and the form paragraph above it is unchanged.
        $this->assertSame( StyleGuide::kitRules( $noThin ), StyleGuide::kitRules( $noThin, null, null, array_map( static fn ( bool $v ): bool => false, $yes ), array_map( static fn ( bool $v ): bool => false, $cyes ) ) );
    }

    public function testTheRulesAndChipsParagraphSaysNothingTheDecisionsDoNotProve(): void
    {
        // Review fix (rules and chips T3): the all-true text is byte-identical to what shipped in 2b3dc4a.
        $body = trim( (string) substr( $this->rulesParagraph(), strlen( "## Rules and chips\n\n" ) ) );
        $body = (string) substr( $body, 0, (int) strpos( $body, "\n\nWidth by hand:" ) ); // the shapes passage follows (guide polish) and has its own tests
        $this->assertSame(
            '`rule` (quiet), `rule-strong`: a `divi/divider` (colour, weight, style) or the top edge of a `divi/group` or `divi/column`; `on`: light, surface, accent, dark or panel. Width from the kit: 1-2px quiet, 2-4px strong, 3px+ for a double. Quiet: hairline colour on light and surface, 40% ink elsewhere; strong: full ink. `chip`: tag (`divi/group`: control radius, outline frame) or icon (`divi/icon`: control radius, accent fill, accent-text glyph; surface/text on accent); `on`: light, surface, accent or dark.',
            $body
        );
        $this->assertSame( 7864, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'the shipped kit-rules (5,255 before the guide polish, 6,595 before the second pass, 7,302 before the per-role item clauses)' );

        $yes    = KitAdapters::RULE_PROVEN;
        $cyes   = KitAdapters::CHIP_PROVEN;
        $prefix = [ 'divider-', 'group-top-', 'column-top-' ];

        // (a) alpha: said while any present carrier proves it; gone (with its fallback stated) when none does.
        $none = $yes;
        foreach ( $prefix as $p ) {
            $none[ $p . 'alpha' ] = false;
        }
        $off = $this->rulesParagraph( null, $none );
        $this->assertStringNotContainsString( '40% ink', $off, 'no carrier proves the 40% colour: not claimed' );
        $this->assertStringContainsString( 'Quiet: hairline colour on light and surface; strong: full ink.', $off );
        $this->assertStringContainsString( 'takes the strong colour', $off );
        $some = $this->rulesParagraph( null, array_merge( $yes, [ 'divider-alpha' => false ] ) );
        $this->assertStringContainsString( '40% ink elsewhere', $some, 'one carrier still proves it: said, and the other is named in the fallback' );
        $this->assertStringContainsString( '`divi/divider`', substr( $some, (int) strpos( $some, 'takes the strong colour' ) - 40 ) );
        // A carrier that is not offered does not count: only the divider is present and its alpha is false.
        $dividerOnly = [ 'divi/divider' => [ 'rule', 'rule-strong' ] ];
        $this->assertStringNotContainsString( '40% ink', $this->rulesParagraph( $dividerOnly, array_merge( $yes, [ 'divider-alpha' => false ] ) ) );
        $this->assertStringContainsString( '40% ink', $this->rulesParagraph( $dividerOnly, array_merge( $yes, [ 'group-top-alpha' => false ] ) ), 'a group decision does not touch a divider-only map' );

        // (b) double: the "3px+" clause only while a present carrier draws a double.
        $noDouble = $yes;
        foreach ( $prefix as $p ) {
            $noDouble[ $p . 'double' ] = false;
        }
        $off = $this->rulesParagraph( null, $noDouble );
        $this->assertStringNotContainsString( '3px', $off, 'a double is drawn solid on every carrier: no 3px double floor claimed' );
        $this->assertStringContainsString( 'Width from the kit: 1-2px quiet, 2-4px strong. ', $off );
        $this->assertStringContainsString( 'double is drawn as solid', $off );
        $this->assertStringContainsString( '3px+ for a double', $this->rulesParagraph( null, array_merge( $yes, [ 'column-top-double' => false ] ) ), 'two carriers still draw a double' );
        $this->assertStringNotContainsString( '3px', $this->rulesParagraph( $dividerOnly, array_merge( $yes, [ 'divider-double' => false ] ) ) );

        // (c) icon: with no proven icon decision there is no icon chip offer and nothing malformed.
        $noIcon = array_merge( $cyes, [ 'icon-radius' => false, 'icon-fill' => false, 'icon-glyph' => false ] );
        $off    = $this->rulesParagraph( null, null, $noIcon );
        $this->assertStringNotContainsString( 'divi/icon', $off );
        $this->assertStringNotContainsString( '(`divi/icon`', $off );
        $this->assertStringNotContainsString( ': ;', $off );
        $this->assertStringNotContainsString( ' or icon', $off );
        $this->assertStringContainsString( '`chip`: tag (`divi/group`:', $off, 'the tag is still offered' );
        $allNo = array_map( static fn ( bool $v ): bool => false, $cyes );
        $off   = $this->rulesParagraph( null, null, $allNo );
        $this->assertStringNotContainsString( '`chip`', $off, 'nothing proven for a chip: no chip sentence at all' );
        $this->assertStringNotContainsString( '(`divi/icon`: ;', $off );
        // One proven icon decision: well formed, accent band refused.
        $onlyRadius = $this->rulesParagraph( null, null, array_merge( $allNo, [ 'icon-radius' => true ] ) );
        $this->assertStringContainsString( 'icon (`divi/icon`: control radius; not offered on an accent band)', $onlyRadius );
        $this->assertStringNotContainsString( ': ;', $onlyRadius );
    }

    public function testTheTagFallbackFollowsTheGroupAlphaDecision(): void
    {
        $kit = (array) StyleKits::get( 'soft-solid' );
        $this->assertSame( 'same', $kit['border']['color'], 'a hairline-frame kit: the tag frame is a quiet rule colour' );
        $planted = array_merge( KitAdapters::RULE_PROVEN, [ 'group-top-alpha' => false ] );
        $colour  = static fn ( array $patch ): string => $patch['module']['decoration']['border']['desktop']['value']['styles']['all']['color'];
        foreach ( [ 'accent' => 'accent-text', 'dark' => 'inverse' ] as $on => $strong ) {
            $this->assertSame( '{{aied:color:' . $strong . ':40}}', $colour( KitAdapters::chipPatch( 'divi/group', $on, $kit ) ), "$on: the 40% form while proven" );
            $this->assertSame( '{{aied:color:' . $strong . '}}', $colour( KitAdapters::chipPatch( 'divi/group', $on, $kit, KitAdapters::CHIP_PROVEN, $planted ) ), "$on: group-top-alpha unproven, the strong colour" );
        }
        foreach ( [ 'light' => 'border', 'surface' => 'border' ] as $on => $role ) {
            $this->assertSame( '{{aied:color:' . $role . '}}', $colour( KitAdapters::chipPatch( 'divi/group', $on, $kit, KitAdapters::CHIP_PROVEN, $planted ) ), "$on: a plain hairline needs no alpha" );
        }
        $this->assertSame( KitAdapters::chipPatch( 'divi/group', 'dark', $kit ), KitAdapters::chipPatch( 'divi/group', 'dark', $kit, KitAdapters::CHIP_PROVEN, KitAdapters::RULE_PROVEN ), 'the default is the shipped decisions' );
        // The guide states the tag fallback only when that decision is false.
        $on  = $this->rulesParagraph();
        $off = $this->rulesParagraph( null, $planted );
        $this->assertStringNotContainsString( 'the frame is the strong colour', $on );
        $this->assertStringContainsString( 'tag (`divi/group`: control radius, outline frame; on accent and dark the frame is the strong colour)', $off );
        // And not when the tag has no frame to colour.
        $this->assertStringNotContainsString( 'the frame is the strong colour', $this->rulesParagraph( null, $planted, array_merge( KitAdapters::CHIP_PROVEN, [ 'tag-frame' => false ] ) ) );
    }

    public function testTheRulesAndChipsParagraphNamesTheBandsAndTheWidthRule(): void
    {
        $para = $this->rulesParagraph();
        $this->assertStringContainsString( 'light, surface, accent, dark or panel', $para, 'the rule bands (KitAdapters::RULE_BANDS)' );
        $this->assertStringContainsString( 'light, surface, accent or dark', $para, 'the chip bands (KitAdapters::CHIP_BANDS)' );
        $this->assertSame( [ 'light', 'surface', 'accent', 'dark', 'panel' ], KitAdapters::RULE_BANDS );
        $this->assertSame( [ 'light', 'surface', 'accent', 'dark' ], KitAdapters::CHIP_BANDS );
        // The width sentence is the width table, checked against ruleWidth() over every kit width.
        $quiet  = [];
        $strong = [];
        foreach ( [ '0px', '1px', '2px', '3px', '4px' ] as $w ) {
            $b        = [ 'width' => $w, 'style' => 'solid', 'color' => 'text' ];
            $quiet[]  = (int) KitAdapters::ruleWidth( 'rule', $b );
            $strong[] = (int) KitAdapters::ruleWidth( 'rule-strong', $b );
        }
        $this->assertSame( [ 1, 2 ], [ min( $quiet ), max( $quiet ) ] );
        $this->assertSame( [ 2, 4 ], [ min( $strong ), max( $strong ) ] );
        $this->assertStringContainsString( '1-2px quiet, 2-4px strong', $para, 'the width rule' );
        $this->assertSame( '3px', KitAdapters::ruleWidth( 'rule', [ 'width' => '1px', 'style' => 'double', 'color' => 'text' ] ) );
        $this->assertStringContainsString( '3px+ for a double', $para );
        // Colour by band: quiet is the border hairline on light and surface, strong the full ink.
        $this->assertSame( 'border', KitAdapters::RULE_COLOUR['rule']['light'] );
        $this->assertSame( 'border', KitAdapters::RULE_COLOUR['rule']['surface'] );
        $this->assertStringContainsString( 'hairline colour on light and surface', $para );
        // The facts are true of the patch (a divider writes the line only; a group the top edge only).
        $kit = (array) StyleKits::get( 'soft-solid' );
        $this->assertSame( [ 'divider' ], array_keys( (array) KitAdapters::patch( 'divi/divider', 'rule', 'light', $kit ) ) );
        $this->assertSame( [ 'module' ], array_keys( (array) KitAdapters::patch( 'divi/group', 'rule', 'light', $kit ) ) );
        $this->assertStringContainsString( 'a `divi/divider` (colour, weight, style)', $para );
        // The icon chip's accent-band treatment is the patch's.
        $icon = KitAdapters::patch( 'divi/icon', 'chip', 'accent', $kit );
        $this->assertSame( '{{aied:color:surface}}', $icon['module']['decoration']['background']['desktop']['value']['color'] );
        $this->assertSame( '{{aied:color:text}}', $icon['icon']['advanced']['color']['desktop']['value'] );
        $this->assertStringContainsString( 'surface/text on accent', $para );
    }

    public function testTheControlsTopicGainsOneGeneratedKitSentence(): void
    {
        $sentence = StyleGuide::controlsKitSentence();
        $this->assertNotSame( '', $sentence );
        foreach ( [ '`rule`', '`rule-strong`', '`chip`', 'topic=kit-rules' ] as $needle ) {
            $this->assertStringContainsString( $needle, $sentence, $needle );
        }
        $this->assertStringStartsWith( "\n\n## Style kits\n\n", $sentence );
        $this->assertLessThanOrEqual( 250, strlen( $sentence ), 'budget: at most 250 B on the controls topic' );
        $this->assertStringEndsWith( $sentence, StyleGuide::markdown( 'controls' ) );
        $this->assertSame( 8123 + strlen( $sentence ), strlen( StyleGuide::markdown( 'controls' ) ), 'the controls topic grew by exactly the sentence (8,123 B before)' );
        $this->assertSame( '', StyleGuide::controlsKitSentence( [ 'divi/button' => [ 'control' ], 'divi/group' => [ 'card', 'rule' ] ] ), 'neither a divider nor an icon adapter: no sentence' );
        $this->assertSame( StyleGuide::controlsKitSentence(), StyleGuide::controlsKitSentence( KitAdapters::APPLIED ) );
        $dividerOnly = StyleGuide::controlsKitSentence( [ 'divi/divider' => [ 'rule', 'rule-strong' ] ] );
        $this->assertStringContainsString( '`rule-strong`', $dividerOnly );
        $this->assertStringNotContainsString( '`chip`', $dividerOnly, 'no icon adapter, no chip claim' );
        $iconOnly = StyleGuide::controlsKitSentence( [ 'divi/icon' => [ 'chip' ] ] );
        $this->assertStringContainsString( '`chip`', $iconOnly );
        $this->assertStringNotContainsString( '`rule`', $iconOnly );
        $this->assertLessThanOrEqual( 9500, strlen( StyleGuide::markdown( 'controls' ) ), 'StyleGuideRecipeVocabularyTopicTest keeps controls <= 9,500' );
        // Review fix: re-pinned deliberately (8,317 -> 8,331): the sentence says what really follows the kit (width, style, corners)
        // and that colours come from the design language, and names only the carriers in the map.
        $this->assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ) );
        $this->assertStringContainsString( 'colours come from the design language', $sentence );
        $this->assertStringNotContainsString( 'colour and corners follow the kit', $sentence );
        $this->assertStringNotContainsString( 'A divider or an icon chip', $sentence );
        $this->assertStringNotContainsString( 'icon', $dividerOnly, 'no icon adapter, no icon claim' );
        $this->assertStringNotContainsString( 'divider', $iconOnly, 'no divider adapter, no divider claim' );
        $this->assertStringContainsString( 'a divider takes the kit\'s width and style', $dividerOnly );
        $this->assertStringContainsString( 'an icon takes the kit\'s corners', $iconOnly );
    }

    public function testTheAppliedSentenceGroupsByNoun(): void
    {
        $old = 'Kit-driven today: buttons (`divi/button`: `control`, `control-secondary`), cards (`divi/group`, `divi/column`: `card`, `panel`), accordion and toggle items (`divi/accordion`, `divi/toggle`: `item`, `item-rule`)';
        $this->assertSame(
            $old . ', forms (`divi/contact-form`: `form`), rules (`divi/group`, `divi/column`, `divi/divider`: `rule`, `rule-strong`) and chips (`divi/group`, `divi/icon`: `chip`).',
            StyleGuide::appliedSentence()
        );
        $this->assertStringStartsWith( $old, StyleGuide::appliedSentence(), 'today\'s text is kept up to the forms' );
        $this->assertSame( 1, substr_count( StyleGuide::appliedSentence(), 'rules (' ) );
        $this->assertSame( 1, substr_count( StyleGuide::appliedSentence(), 'chips (' ) );
        // A block that carries roles of two nouns is named under each, with only that noun's roles.
        $this->assertSame( 'Kit-driven today: cards (`divi/group`: `card`) and rules (`divi/group`: `rule`).', StyleGuide::appliedSentence( [ 'divi/group' => [ 'card', 'rule' ] ] ) );
        $this->assertSame( 'Kit-driven today: rules (`divi/divider`: `rule-strong`).', StyleGuide::appliedSentence( [ 'divi/divider' => [ 'rule-strong' ] ] ) );
        // A role without a noun is named by its id, after the nouns.
        $other = StyleGuide::appliedSentence( [ 'divi/blurb' => [ 'tile', 'row' ], 'divi/button' => [ 'control' ] ] );
        $this->assertStringContainsString( 'buttons (`divi/button`: `control`)', $other );
        $this->assertStringContainsString( '`tile`', $other );
        $this->assertStringContainsString( '`row`', $other );
        $this->assertLessThan( (int) strpos( $other, '`tile`' ), (int) strpos( $other, 'buttons' ), 'named roles come after the nouns' );
        $this->assertSame( 'Kit-driven today: nothing.', StyleGuide::appliedSentence( [] ) );
        // The sentence is in the catalog and kit-rules, and in no kit topic.
        foreach ( [ 'style-kits', 'kit-rules' ] as $topic ) {
            $this->assertStringContainsString( StyleGuide::appliedSentence(), StyleGuide::markdown( $topic ), $topic );
        }
        foreach ( StyleKits::ids() as $id ) {
            $this->assertStringNotContainsString( 'rules (`divi/group`', StyleGuide::markdown( 'style-kit:' . $id ), "$id: the applied sentence is not repeated in a kit topic" );
        }
    }

    /** Every kit topic's size before the rules and chips adapter (Task 3 Step 0 capture). */
    private const KIT_TOPIC_SIZES_BEFORE = [
        'quiet-solid' => 8232,
        'glass-edge' => 8230,
        'warm-pill' => 8255,
        'hairline-mono' => 8116,
        'chunky-pill' => 8124,
        'steady-solid' => 8236,
        'soft-rounded' => 8136,
        'heavy-block' => 8210,
        'raw-frame' => 8109,
        'gilt-hairline' => 8118,
        'soft-solid' => 8191,
        'outline-2' => 8145,
        'warm-pill-soft' => 9259,
        'chunky-pill-offset' => 9307,
        'hairline-dashed' => 8124,
        'underline-link' => 8779,
        'neo-brutal' => 9599,
        'double-frame' => 8206,
        'soft-lift' => 9215,
        'ghost-swap' => 8107,
        'dotted-pill' => 8207,
        'hairline-frame-caps' => 8180,
        'offset-frame' => 9320,
        'soft-hairline-caps' => 8205,
        'thick-frame-caps' => 8189,
        'thick-rounded-caps' => 8219,
        'rimmed-solid' => 8025,
        'flat-square' => 8303,
        'block-caps' => 8297,
        'pill-caps' => 8345,
        'outline-pill' => 8174,
        'outline-soft' => 8153,
        'thick-frame' => 8128,
        'raised-square' => 9425,
        'offset-block-caps' => 9590,
        'text-link' => 8115,
        'text-link-caps' => 8148,
        'underlined-label' => 8892,
        'pressable' => 9575,
        'hairline-halo' => 9294
    ];

    public function testTheBudgetsOfTheRulesAndChipsAdapter(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ), 'no topic name was added' );
        $rules = strlen( StyleGuide::markdown( 'kit-rules' ) );
        $this->assertLessThanOrEqual( 7900, $rules, 'kit-rules (guide polish replaced the 4,607 + 650 pin; the real bound is 10,000 B)' );
        $paragraph = $this->rulesParagraph();
        $split     = (int) strpos( $paragraph, "\n\nWidth by hand:" );
        $this->assertGreaterThan( 0, $split, 'the shapes passage follows the paragraph' );
        $this->assertLessThanOrEqual( 525, strlen( trim( (string) substr( substr( $paragraph, 0, $split ), strlen( "## Rules and chips\n\n" ) ) ) ), 'the generated Rules and chips paragraph (ruleChipRules) is at most 525 B' );
        // Second pass (spec Ruling 5): the tag's fill/padding sentence is +59 B, so the passage is 1,204 B; the bound moves 1,200 -> 1,300 deliberately.
        $this->assertLessThanOrEqual( 1300, strlen( trim( substr( $paragraph, $split ) ) ), 'the shapes passage (ruleChipShapes) is at most 1,300 B (1,100 before the review fix, 1,145 after it)' );
        $this->assertLessThanOrEqual( self::BUDGET, $rules );
        $max = 0;
        $this->assertCount( 40, self::KIT_TOPIC_SIZES_BEFORE );
        foreach ( self::KIT_TOPIC_SIZES_BEFORE as $id => $before ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertLessThanOrEqual( $before + 40, $len, "$id grows at most 40 B" );
            $this->assertSame( $before, $len, "$id: the applied sentence is not in a kit topic, so a kit topic does not move" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9599, $max, 'the largest kit topic (neo-brutal) is unchanged' );
        $this->assertLessThanOrEqual( 9700, $max );

        // The catalog with 50 kits (the owner's cap) stays within 9,950 B with the new tail.
        $property = new \ReflectionProperty( StyleKits::class, 'cache' );
        $real     = array_map( static fn ( string $id ): array => (array) StyleKits::get( $id ), StyleKits::ids() );
        try {
            $kits = $real;
            for ( $n = 1; count( $kits ) < 50; $n++ ) {
                $clone          = $real[ $n % count( $real ) ];
                $clone['id']    = 'extra-kit-' . $n;
                $clone['title'] = 'Extra kit ' . $n;
                $kits[]         = $clone;
            }
            $property->setValue( null, $kits );
            $this->assertCount( 50, StyleKits::ids() );
            $this->assertLessThanOrEqual( 9950, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the 50-kit catalog' );
        } finally {
            StyleKits::clearCache();
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Guide polish for rules and chips, Task 1 (plan 2026-10-21-guide-polish-rules-chips): the dogfood check found that the
    // guide named the roles but gave no shapes, no exact width rule, no word on kit-vs-language, and no live path for a
    // contact form's submit. Everything below is generated from the same proven-decision maps as the paragraph above.
    // ---------------------------------------------------------------------------------------------------------------

    /** The sentence of the shapes passage that states the width rule, as a regular expression with six captures. */
    private const WIDTH_RULE = '/quiet = the kit\'s border width, at least (\d)px, at most (\d)px; strong = that width plus (\d)px, at least (\d)px, at most (\d)px(?:; a double is never under (\d)px)?\./';

    public function testTheRulesPassageStatesTheExactWidthRuleAndMatchesRuleWidth(): void
    {
        $this->assertSame( 1, preg_match( self::WIDTH_RULE, $this->rulesParagraph(), $m ), 'the served kit-rules states the quiet and the strong width rule' );
        [ , $qMin, $qMax, $sPlus, $sMin, $sMax, $dMin ] = array_map( 'intval', $m + array_fill( 0, 7, 0 ) );
        $this->assertSame( [ 1, 2, 1, 2, 4, 3 ], [ $qMin, $qMax, $sPlus, $sMin, $sMax, $dMin ] );
        // The numbers the sentence implies equal ruleWidth() for both roles, every kit width 0px-4px, solid and double.
        foreach ( [ 0, 1, 2, 3, 4 ] as $w ) {
            foreach ( [ 'solid', 'dashed', 'dotted', 'double' ] as $style ) {
                $border  = [ 'width' => $w . 'px', 'style' => $style, 'color' => 'text' ];
                $quiet   = max( $qMin, min( $qMax, $w ) );
                $strong  = max( $sMin, min( $sMax, $w + $sPlus ) );
                if ( 'double' === $style ) {
                    $quiet  = max( $dMin, $quiet );
                    $strong = max( $dMin, $strong );
                    $this->assertGreaterThanOrEqual( 3, (int) KitAdapters::ruleWidth( 'rule', $border ), 'a double is never under 3px (quiet)' );
                    $this->assertGreaterThanOrEqual( 3, (int) KitAdapters::ruleWidth( 'rule-strong', $border ), 'a double is never under 3px (strong)' );
                }
                $this->assertSame( $quiet . 'px', KitAdapters::ruleWidth( 'rule', $border ), "quiet: kit width {$w}px, $style" );
                $this->assertSame( $strong . 'px', KitAdapters::ruleWidth( 'rule-strong', $border ), "strong: kit width {$w}px, $style" );
            }
        }
        // And over every shipped kit: the 3px kit gets 4px strong (the dogfood miss), the 2px kit 3px strong.
        foreach ( StyleKits::ids() as $id ) {
            $border = (array) ( (array) StyleKits::get( $id ) )['border'];
            $w      = (int) $border['width'];
            $strong = max( $sMin, min( $sMax, $w + $sPlus ) );
            $quiet  = max( $qMin, min( $qMax, $w ) );
            if ( 'double' === $border['style'] ) {
                [ $quiet, $strong ] = [ max( $dMin, $quiet ), max( $dMin, $strong ) ];
            }
            $this->assertSame( [ $quiet . 'px', $strong . 'px' ], [ KitAdapters::ruleWidth( 'rule', $border ), KitAdapters::ruleWidth( 'rule-strong', $border ) ], $id );
        }
        $this->assertSame( '4px', KitAdapters::ruleWidth( 'rule-strong', [ 'width' => '3px', 'style' => 'solid' ] ) );
        // The double clause is said only while a present carrier draws a double.
        $none = KitAdapters::RULE_PROVEN;
        foreach ( [ 'divider-', 'group-top-', 'column-top-' ] as $p ) {
            $none[ $p . 'double' ] = false;
        }
        $off = $this->rulesParagraph( null, $none );
        $this->assertSame( 1, preg_match( self::WIDTH_RULE, $off, $o ) );
        $this->assertArrayNotHasKey( 6, $o, 'a double is drawn solid everywhere: no 3px floor claimed' );
        $this->assertStringNotContainsString( 'never under', $off );
        // No rule carrier, no width rule.
        $chipsOnly = [ 'divi/group' => [ 'chip' ], 'divi/icon' => [ 'chip' ] ];
        $this->assertStringNotContainsString( 'at most 4px', $this->rulesParagraph( $chipsOnly ) );
    }

    public function testTheRulesPassageNamesTheProvenShapes(): void
    {
        $para = $this->rulesParagraph();
        // Divider.
        $this->assertStringContainsString( '`divider.advanced.line.desktop.value`', $para );
        $this->assertMatchesRegularExpression( '/`divider\.advanced\.line\.desktop\.value` with `color`, `weight`, `style`/', $para );
        // The line style is the kit's border style.
        $this->assertStringContainsString( 'style = the kit\'s border style', $para );
        // Top edge of a group or column.
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.styles`', $para );
        $this->assertMatchesRegularExpression( '/`all` = width `0px`/', $para );
        $this->assertMatchesRegularExpression( '/`top` = the rule width, the same style and colour/', $para );
        $this->assertStringContainsString( 'the other three sides stay 0', $para );
        $this->assertStringContainsString( 'to rule the top of a specific group or column (for example between plan columns), write the edge on that block itself; a divider module is for a separate line between blocks.', $para );
        // Tag.
        $this->assertMatchesRegularExpression( '/Tag: `module\.decoration\.border\.desktop\.value` with `radius`.*`styles\.all`/', $para );
        // Icon chip and its glyph.
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.radius`', $para );
        $this->assertStringContainsString( '`module.decoration.background.desktop.value.color`', $para );
        $this->assertStringContainsString( '`icon.advanced.color.desktop.value`', $para );
        $this->assertMatchesRegularExpression( '/`icon\.innerContent\.desktop\.value` with `unicode`, `type`, `weight`/', $para );
        $this->assertStringNotContainsString( 'aied', $para, 'the guide never names the marker token' );
        // The claims are true of the patches the adapter writes.
        $kit  = (array) StyleKits::get( 'hairline-dashed' );
        $div  = KitAdapters::patch( 'divi/divider', 'rule-strong', 'light', $kit );
        $this->assertSame( [ 'color', 'weight', 'style' ], array_keys( $div['divider']['advanced']['line']['desktop']['value'] ) );
        $grp  = KitAdapters::patch( 'divi/column', 'rule', 'light', $kit );
        $edge = $grp['module']['decoration']['border']['desktop']['value']['styles'];
        $this->assertSame( [ 'all', 'top' ], array_keys( $edge ) );
        $this->assertSame( '0px', $edge['all']['width'] );
        $this->assertSame( [ $edge['all']['style'], $edge['all']['color'] ], [ $edge['top']['style'], $edge['top']['color'] ], 'top has the same style and colour' );
        $tag = KitAdapters::patch( 'divi/group', 'chip', 'light', $kit );
        $this->assertSame( [ 'radius', 'styles' ], array_keys( $tag['module']['decoration']['border']['desktop']['value'] ) );
        $this->assertSame( [ 'all' ], array_keys( $tag['module']['decoration']['border']['desktop']['value']['styles'] ) );
        $icon = KitAdapters::patch( 'divi/icon', 'chip', 'light', $kit );
        $this->assertArrayHasKey( 'radius', $icon['module']['decoration']['border']['desktop']['value'] );
        $this->assertArrayHasKey( 'color', $icon['module']['decoration']['background']['desktop']['value'] );
        $this->assertArrayHasKey( 'color', $icon['icon']['advanced'] );
    }

    public function testEachShapeIsOnlyStatedWhileItsDecisionIsProven(): void
    {
        $yes  = KitAdapters::RULE_PROVEN;
        $cyes = KitAdapters::CHIP_PROVEN;
        $all  = $this->rulesParagraph();
        $this->assertStringStartsWith( '`rule` (quiet), `rule-strong`:', trim( (string) substr( $all, strlen( "## Rules and chips\n\n" ) ) ), 'the earlier sentences come first and are unchanged' );

        // Divider shape: gone with divider-line.
        $off = $this->rulesParagraph( null, array_merge( $yes, [ 'divider-line' => false ] ) );
        $this->assertStringNotContainsString( 'divider.advanced.line', $off );
        $this->assertStringNotContainsString( 'Divider:', $off );
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.styles`', $off, 'the top edge is still proven' );
        $this->assertStringContainsString( 'write the edge on that block itself.', $off, 'no divider offered: the edge sentence does not mention a divider module' );
        $this->assertStringNotContainsString( 'divider module', $off );
        // Top-edge shape: gone only when BOTH group-top and column-top are false.
        $oneEdge = $this->rulesParagraph( null, array_merge( $yes, [ 'group-top' => false ] ) );
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.styles`', $oneEdge, 'the column still proves it' );
        $this->assertStringContainsString( 'rule the top of a specific column (for example', $oneEdge );
        $noEdge = $this->rulesParagraph( null, array_merge( $yes, [ 'group-top' => false, 'column-top' => false ] ) );
        $this->assertStringNotContainsString( 'value.styles`', $noEdge );
        $this->assertStringNotContainsString( 'the other three sides', $noEdge );
        $this->assertStringNotContainsString( 'write the edge on that block itself', $noEdge );
        $this->assertStringNotContainsString( 'a divider module is for', $noEdge );
        $this->assertStringContainsString( 'divider.advanced.line', $noEdge );
        // No rule carrier at all: no width rule and no rule shape.
        $noRule = $this->rulesParagraph( null, array_merge( $yes, [ 'divider-line' => false, 'group-top' => false, 'column-top' => false ] ) );
        $this->assertDoesNotMatchRegularExpression( '/at most 4px|divider\.advanced|the other three sides|Divider:/', $noRule );
        $this->assertStringContainsString( 'Tag:', $noRule, 'the chips are untouched' );

        // Tag clauses follow tag-radius and tag-frame.
        $noFrame = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'tag-frame' => false ] ) );
        $this->assertMatchesRegularExpression( '/Tag: `module\.decoration\.border\.desktop\.value` with `radius`/', $noFrame );
        $this->assertStringNotContainsString( '`styles.all`', $noFrame );
        $noRadius = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'tag-radius' => false ] ) );
        $this->assertStringContainsString( 'Tag: `module.decoration.border.desktop.value` with `styles.all`', $noRadius );
        $noTag = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'tag-radius' => false, 'tag-frame' => false ] ) );
        $this->assertStringNotContainsString( 'Tag:', $noTag );
        $this->assertStringContainsString( 'Icon chip:', $noTag );

        // Icon: all three false -> no icon shape at all; each clause follows its own decision.
        $allNo  = array_map( static fn ( bool $v ): bool => false, $cyes );
        $noIcon = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'icon-radius' => false, 'icon-fill' => false, 'icon-glyph' => false ] ) );
        foreach ( [ 'Icon chip:', 'icon.advanced.color', 'icon.innerContent', 'background.desktop.value.color' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $noIcon, $needle );
        }
        $this->assertStringContainsString( 'Tag:', $noIcon );
        $noFill = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'icon-fill' => false ] ) );
        $this->assertStringNotContainsString( 'background.desktop.value.color', $noFill );
        $this->assertStringContainsString( '`icon.advanced.color.desktop.value`', $noFill );
        $this->assertStringContainsString( '`icon.innerContent.desktop.value`', $noFill, 'a glyph colour is proven: the glyph is shown' );
        $noGlyphColour = $this->rulesParagraph( null, null, array_merge( $cyes, [ 'icon-glyph' => false ] ) );
        $this->assertStringNotContainsString( '`icon.advanced.color.desktop.value`', $noGlyphColour );
        $this->assertStringContainsString( '`icon.innerContent.desktop.value`', $noGlyphColour, 'a fill is proven: the glyph is still shown' );
        $radiusOnly = $this->rulesParagraph( null, null, array_merge( $allNo, [ 'icon-radius' => true ] ) );
        $this->assertStringContainsString( 'Icon chip: `module.decoration.border.desktop.value.radius`', $radiusOnly );
        $this->assertStringNotContainsString( 'icon.innerContent', $radiusOnly, 'neither fill nor glyph colour offered: the glyph clause is tied to them' );
        $this->assertStringNotContainsString( 'icon.advanced.color', $radiusOnly );
        $this->assertStringNotContainsString( 'background.desktop.value.color', $radiusOnly );
        // A map without the icon block offers no icon shape.
        $noIconBlock = KitAdapters::APPLIED;
        unset( $noIconBlock['divi/icon'] );
        $this->assertStringNotContainsString( 'icon.innerContent', $this->rulesParagraph( $noIconBlock ) );
        // Nothing proven for chips and nothing for rules: no shapes passage, no heading either way (the earlier tests pin that).
        $this->assertStringNotContainsString( 'Tag:', $this->rulesParagraph( null, null, $allNo ) );
    }

    public function testTheKitWinsOverTheLanguage(): void
    {
        $text = StyleGuide::markdown( 'kit-rules' );
        $this->assertSame( 1, substr_count( $text, 'the kit wins' ) );
        $this->assertStringContainsString( 'Where a design language\'s prose and the kit disagree about the corners, borders, shadow or hover of a block a kit styles, the kit wins.', $text );
        // It sits in the "Reusing" section, before the next heading.
        $reuse = substr( $text, (int) strpos( $text, "## Reusing a kit topic's blocks" ) );
        $reuse = substr( $reuse, 0, (int) strpos( $reuse, "\n## ", 4 ) );
        $this->assertStringContainsString( 'the kit wins', $reuse );
    }

    public function testTheFormsTextSaysTheSubmitPathIsLiveOnAContactForm(): void
    {
        $yes   = KitAdapters::FORM_PROVEN;
        $forms = $this->formsParagraph( $yes );
        $this->assertStringContainsString( 'at `button.decoration.*`, not `module.decoration`', $forms, 'the earlier clause is kept' );
        $this->assertStringContainsString( 'live on a contact form, the opposite of a `divi/button`', $forms );
        foreach ( [ 'button-padding', 'button-shadow', 'button-lift' ] as $decision ) {
            $this->assertStringContainsString( 'the opposite of a `divi/button`', $this->formsParagraph( array_merge( $yes, [ $decision => false ] ) ), "$decision alone unproven" );
        }
        $none = array_merge( $yes, [ 'button-padding' => false, 'button-shadow' => false, 'button-lift' => false ] );
        $this->assertStringNotContainsString( 'divi/button', $this->formsParagraph( $none ), 'nothing proven on the submit: no live-path claim' );
        // The contrary statement about a plain button is kept, once, and the two do not contradict: one is a button, one a form.
        $rules = StyleGuide::kitRules();
        $this->assertSame( 1, substr_count( $rules, 'never on button.decoration' ) );
    }

    public function testControlsKitSentenceIsConditionalOnTheProvenDecisions(): void
    {
        $yes  = KitAdapters::RULE_PROVEN;
        $cyes = KitAdapters::CHIP_PROVEN;
        $now  = StyleGuide::controlsKitSentence();
        $this->assertLessThanOrEqual( 250, strlen( $now ) );
        $this->assertSame( $now, StyleGuide::controlsKitSentence( null, $yes, $cyes ), 'the default is the shipped decisions' );
        $this->assertStringContainsString( 'a divider takes the kit\'s width and style', $now );
        $this->assertStringContainsString( 'an icon takes the kit\'s corners', $now );
        $noDivider = StyleGuide::controlsKitSentence( null, array_merge( $yes, [ 'divider-line' => false ] ), $cyes );
        $this->assertStringNotContainsString( 'divider', $noDivider );
        $this->assertStringContainsString( 'an icon takes the kit\'s corners', $noDivider );
        $this->assertStringNotContainsString( '`rule`', $noDivider );
        $noIcon = StyleGuide::controlsKitSentence( null, $yes, array_merge( $cyes, [ 'icon-radius' => false, 'icon-fill' => false, 'icon-glyph' => false ] ) );
        $this->assertStringNotContainsString( 'icon', $noIcon );
        $this->assertStringNotContainsString( '`chip`', $noIcon );
        $this->assertStringContainsString( 'a divider takes the kit\'s width and style', $noIcon );
        $this->assertSame( '', StyleGuide::controlsKitSentence( null, array_merge( $yes, [ 'divider-line' => false ] ), array_map( static fn ( bool $v ): bool => false, $cyes ) ), 'neither half proven: no sentence' );
        // An icon with only a fill or glyph colour proven takes no corners: the corner claim follows icon-radius.
        $noRadius = StyleGuide::controlsKitSentence( null, $yes, array_merge( $cyes, [ 'icon-radius' => false ] ) );
        $this->assertStringNotContainsString( 'corners', $noRadius );
        $this->assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ), 'the shipped controls topic did not move' );
    }

    public function testTheCompositionOneSideRuleSectionSaysTheKitDecides(): void
    {
        $text    = StyleGuide::markdown( 'composition' );
        $section = substr( $text, (int) strpos( $text, '## One-side rule' ) );
        $section = substr( $section, 0, (int) strpos( $section, "\n## ", 4 ) );
        // The example is kept.
        $this->assertStringContainsString( '`module.decoration.border.{bp}.value.styles.all.{width,color,style}`: "0px", the rule colour, "solid"', $section );
        $this->assertStringContainsString( '`module.decoration.border.{bp}.value.styles.top.width`: "2px"', $section );
        // And says that a kit decides.
        $this->assertStringContainsString( 'Under a style kit the width, style and colour come from the kit (topic=kit-rules); this example is the default look.', $section );
        $this->assertLessThanOrEqual( 10000, strlen( $text ) );
        $this->assertStringNotContainsString( 'aied', $text );
    }

    // ---- Second wording pass (spec Ruling 5): the gaps all three dogfood assistants reported ----

    public function testTheStructureBlockSaysTheRootWrapperIsOptional(): void
    {
        $text = StyleGuide::markdown();
        $this->assertStringNotContainsString( '(root wrapper, required)', $text );
        $this->assertStringContainsString( 'divi/placeholder              (root wrapper, optional)', $text );
        // Size-neutral by owner rule: "optional" and "required" are both 8 characters; the default guide does not grow.
        $this->assertSame( 28975, strlen( $text ) );
        $this->assertLessThanOrEqual( 29108, strlen( $text ) );
        // The claim is the validator's own: both roots are accepted.
        foreach ( [ 'divi/placeholder', 'divi/section' ] as $root ) {
            $layout = '<!-- wp:' . $root . ' {"builderVersion":"5.8.0"} /-->';
            $codes  = array_map( static fn ( $v ): string => $v->code(), ( new Validator() )->validateContent( $layout )->violations() );
            $this->assertNotContains( Validator::E_WRONG_NESTING, $codes, "$root is a valid root" );
        }
    }

    public function testTheTagChipClauseSaysTransparentFillAndOwnPadding(): void
    {
        $clause = 'Its fill stays transparent; its padding and size are yours.';
        $text   = StyleGuide::kitRules();
        $this->assertSame( 1, substr_count( $text, $clause ) );
        $this->assertStringContainsString( 'Tag: `module.decoration.border.desktop.value`', $text );
        $this->assertStringNotContainsString( 'aied', $text );

        $cyes = KitAdapters::CHIP_PROVEN;
        // Only while the tag is offered (radius or frame proven on the group), and only while a group answers `chip`.
        $noTag = array_merge( $cyes, [ 'tag-radius' => false, 'tag-frame' => false ] );
        $this->assertStringNotContainsString( $clause, StyleGuide::kitRules( null, null, null, null, $noTag ) );
        $this->assertStringContainsString( $clause, StyleGuide::kitRules( null, null, null, null, array_merge( $cyes, [ 'tag-radius' => false ] ) ), 'one tag decision still offers the tag' );
        $noGroupChip = KitAdapters::APPLIED;
        $noGroupChip['divi/group'] = array_values( array_diff( $noGroupChip['divi/group'], [ 'chip' ] ) );
        $this->assertStringNotContainsString( $clause, StyleGuide::kitRules( $noGroupChip ) );
        // An icon chip alone does not say it.
        $iconOnly = array_merge( $cyes, [ 'tag-radius' => false, 'tag-frame' => false ] );
        $this->assertStringContainsString( 'Icon chip:', StyleGuide::kitRules( null, null, null, null, $iconOnly ) );
        $this->assertStringNotContainsString( 'Its fill stays', StyleGuide::kitRules( null, null, null, null, $iconOnly ) );
    }

    public function testTheContactFormsParagraphNamesTheFieldPaddingPath(): void
    {
        $path = '`field.decoration.spacing.desktop.value.padding`';
        $yes  = KitAdapters::FORM_PROVEN;
        $with = StyleGuide::kitRules( null, $yes );
        $with = substr( $with, (int) strpos( $with, '## Contact forms' ) );
        $this->assertSame( 1, substr_count( $with, $path ) );
        $this->assertStringContainsString( 'It takes the control radius and control padding (the four sides at ' . $path . ').', $with );

        $without = StyleGuide::kitRules( null, array_merge( $yes, [ 'field-padding' => false ] ) );
        $this->assertStringNotContainsString( 'field.decoration.spacing', $without, 'padding not proven on the field: no path claimed' );
        $this->assertStringContainsString( 'its padding stays the recipe\'s', $without );
        $this->assertStringNotContainsString( 'aied', $with );
        // The path is the probe's: kf-field-padding PASS.
        $registry = file_get_contents( __DIR__ . '/../fixtures/shapes/kit-form-vocabulary.md' );
        $this->assertStringContainsString( '`field.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}`', (string) $registry );
        $this->assertTrue( KitAdapters::FORM_PROVEN['field-padding'] );
    }

    /** @return array<string,mixed> the patch's leaves as dotted path => value */
    private function leafMap( array $patch, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $patch as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) ) {
                $out += $this->leafMap( $value, $path );
            } else {
                $out[ $path ] = $value;
            }
        }
        return $out;
    }

    /** Every dotted path a block writes for a role, over every shipped kit and every band the role has. */
    private function writtenPaths( string $block, string $role ): array
    {
        $paths = [];
        foreach ( StyleKits::ids() as $id ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $band ) {
                $patch = KitAdapters::patch( $block, $role, $band, (array) StyleKits::get( $id ) );
                foreach ( null === $patch ? [] : array_keys( $this->leafMap( $patch ) ) as $leaf ) {
                    $paths[ preg_replace( '/\.(topLeft|topRight|bottomRight|bottomLeft)$/', '', $leaf ) ] = true;
                }
            }
        }
        return array_keys( $paths );
    }

    /** The Items paragraph of a kit-rules text, cut into its clauses (null when a clause is absent). */
    private function itemClauses( string $text ): array
    {
        $para = substr( $text, (int) strpos( $text, '## Accordion and toggle items' ) );
        $para = substr( $para, 0, (int) strpos( $para, "\n## ", 4 ) ?: null );
        $cut  = static function ( string $from, array $ends ) use ( $para ): ?string {
            $at = strpos( $para, $from );
            if ( false === $at ) {
                return null;
            }
            $end = strlen( $para );
            foreach ( $ends as $e ) {
                $pos = strpos( $para, $e, $at + strlen( $from ) );
                if ( false !== $pos ) {
                    $end = min( $end, $pos );
                }
            }
            return substr( $para, $at, $end - $at );
        };
        $marks = [ ' On the accordion write', ' A `divi/toggle` carries', ' An `item` also writes', ' An `item-rule` writes only' ];
        return [
            'para'      => $para,
            'accordion' => $cut( 'On the accordion write', array_diff( $marks, [ ' On the accordion write' ] ) ),
            'toggle'    => $cut( 'A `divi/toggle` carries', array_diff( $marks, [ ' A `divi/toggle` carries' ] ) ),
            'item'      => $cut( 'An `item` also writes', array_diff( $marks, [ ' An `item` also writes' ] ) ),
            'rule'      => $cut( 'An `item-rule` writes only', [ "\n" ] ),
        ];
    }

    private static function backticked( string $path ): string
    {
        return '`' . $path . '`';
    }

    public function testTheItemsParagraphNamesWhereAnAccordionCarriesTheKitLook(): void
    {
        $c = $this->itemClauses( StyleGuide::kitRules() );
        $this->assertNotNull( $c['accordion'], 'the accordion clause' );
        $paths = [
            'openToggle.decoration.background.desktop.value.color',
            'closedToggle.decoration.background.desktop.value.color',
            'openToggle.decoration.font.font.desktop.value.color',
            'closedToggle.decoration.font.font.desktop.value.color',
            'closedToggleIcon.decoration.icon.desktop.value.color',
            'content.decoration.bodyFont.body.font.desktop.value.color',
        ];
        $written = [ ...$this->writtenPaths( 'divi/accordion', 'item' ), ...$this->writtenPaths( 'divi/accordion', 'item-rule' ) ];
        foreach ( $paths as $path ) {
            $this->assertSame( 1, substr_count( $c['accordion'], self::backticked( $path ) ), $path );
            $this->assertContains( $path, $this->writtenPaths( 'divi/accordion', 'item' ), "$path: the adapter writes it for an accordion item" );
            $this->assertContains( $path, $this->writtenPaths( 'divi/accordion', 'item-rule' ), "$path: the adapter writes it for an accordion rule row" );
        }
        // The accordion clause names no frame, radius, shadow or role-specific path any more: those belong to a role.
        $this->assertStringNotContainsString( 'module.decoration', $c['accordion'] );
        $this->assertStringContainsString( 'accordion', $c['accordion'] );
        // Every leaf group the adapter writes on an accordion is named.
        foreach ( [ 'openToggle', 'closedToggle', 'closedToggleIcon', 'content' ] as $group ) {
            $this->assertStringContainsString( '`' . $group . '.', $c['accordion'] );
        }
        $this->assertNotContains( 'openToggleIcon.decoration.icon.desktop.value.color', $written, 'an accordion has no open icon: none is claimed' );
        $this->assertStringNotContainsString( 'openToggleIcon', $c['accordion'] );
        $this->assertStringNotContainsString( 'closedTitle', $c['accordion'], 'the accordion has no closedTitle group' );
        $this->assertStringNotContainsString( 'aied', $c['para'] );

        // Gated on the map: no accordion item role, no accordion clause; the toggle still has its paragraph and its own clause.
        $noAccordion = KitAdapters::APPLIED;
        unset( $noAccordion['divi/accordion'] );
        $off = StyleGuide::kitRules( $noAccordion );
        $this->assertStringNotContainsString( 'On the accordion write', $off );
        $this->assertStringNotContainsString( 'closedToggle.decoration.font', $off, 'that path is the accordion\'s' );
        $this->assertStringContainsString( '## Accordion and toggle items', $off, 'the toggle still has its paragraph' );
        $this->assertStringContainsString( 'A `divi/toggle` carries', $off );
    }

    public function testEachItemRoleClauseNamesOnlyWhatThatRoleWrites(): void
    {
        $c = $this->itemClauses( StyleGuide::kitRules() );
        $this->assertNotNull( $c['item'] );
        $this->assertNotNull( $c['rule'] );
        // item: frame, radius and shadow (and the toggle's padding), all written by the adapter for an item on both carriers.
        foreach ( [ 'module.decoration.border.desktop.value', 'module.decoration.boxShadow.desktop.value' ] as $path ) {
            $this->assertSame( 1, substr_count( $c['item'], self::backticked( $path ) ), $path );
        }
        $this->assertStringContainsString( 'module.decoration.spacing.desktop.value.padding', $c['item'] );
        foreach ( [ 'divi/accordion', 'divi/toggle' ] as $block ) {
            $written = $this->writtenPaths( $block, 'item' );
            $this->assertContains( 'module.decoration.border.desktop.value.radius', $written, "$block item radius" );
            $this->assertContains( 'module.decoration.border.desktop.value.styles.all.width', $written, "$block item frame" );
            $this->assertContains( 'module.decoration.boxShadow.desktop.value.blur', $written, "$block item shadow" );
        }
        $this->assertContains( 'module.decoration.spacing.desktop.value.padding', $this->writtenPaths( 'divi/toggle', 'item' ) );
        $this->assertNotContains( 'module.decoration.spacing.desktop.value.padding', $this->writtenPaths( 'divi/accordion', 'item' ), 'an accordion item takes no padding' );
        $this->assertStringNotContainsString( 'item-rule', $c['item'], 'the item clause does not speak for the rule row' );

        // item-rule: only the bottom rule and square corners; NO shadow, NO frame beyond the rule, on either carrier.
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.styles.bottom`', $c['rule'] );
        $this->assertStringContainsString( '`module.decoration.border.desktop.value.radius`', $c['rule'] );
        $this->assertStringNotContainsString( 'boxShadow', $c['rule'] );
        $this->assertStringNotContainsString( 'spacing', $c['rule'] );
        foreach ( [ 'divi/accordion', 'divi/toggle' ] as $block ) {
            $written = $this->writtenPaths( $block, 'item-rule' );
            $this->assertContains( 'module.decoration.border.desktop.value.styles.bottom.width', $written, "$block rule bottom" );
            $this->assertContains( 'module.decoration.border.desktop.value.styles.all.width', $written, "$block rule: the other sides are set to 0px" );
            $this->assertSame( [], array_filter( $written, static fn ( string $p ): bool => str_contains( $p, 'boxShadow' ) || str_contains( $p, 'spacing' ) ), "$block item-rule writes no shadow or padding" );
            foreach ( StyleKits::ids() as $id ) {
                $patch = KitAdapters::patch( $block, 'item-rule', 'light', (array) StyleKits::get( $id ) );
                $v     = $patch['module']['decoration']['border']['desktop']['value'];
                $this->assertSame( '0px', $v['styles']['all']['width'], "$id: the other sides" );
                $this->assertSame( [ '0px', '0px', '0px', '0px' ], array_values( $v['radius'] ), "$id: square corners" );
            }
        }
    }

    public function testTheItemClausesFollowTheRoleMap(): void
    {
        $itemOnly = [ 'divi/accordion' => [ 'item' ], 'divi/toggle' => [ 'item' ] ];
        $ruleOnly = [ 'divi/accordion' => [ 'item-rule' ], 'divi/toggle' => [ 'item-rule' ] ];

        $a = $this->itemClauses( StyleGuide::kitRules( $itemOnly ) );
        $this->assertNotNull( $a['para'] );
        $this->assertNotNull( $a['item'] );
        $this->assertNull( $a['rule'], 'no item-rule answered: no rule-row clause' );
        $this->assertStringNotContainsString( 'styles.bottom', $a['para'] );
        $this->assertStringNotContainsString( 'item-rule', $a['para'] );
        $this->assertStringContainsString( 'module.decoration.boxShadow', $a['item'] );

        $b = $this->itemClauses( StyleGuide::kitRules( $ruleOnly ) );
        $this->assertNotNull( $b['rule'], 'the paragraph exists for a rule-row-only map' );
        $this->assertNull( $b['item'], 'no item answered: no frame/shadow clause' );
        $this->assertStringNotContainsString( 'boxShadow', $b['para'] );
        $this->assertStringNotContainsString( 'module.decoration.spacing', $b['para'] );
        $this->assertStringNotContainsString( '`item`', $b['para'] );
        $this->assertStringNotContainsString( 'card padding', $b['para'] );

        // The padding is claimed only while a toggle answers `item`.
        $accItemToggleRule = [ 'divi/accordion' => [ 'item' ], 'divi/toggle' => [ 'item-rule' ] ];
        $c = $this->itemClauses( StyleGuide::kitRules( $accItemToggleRule ) );
        $this->assertNotNull( $c['item'] );
        $this->assertNotNull( $c['rule'] );
        $this->assertStringNotContainsString( 'module.decoration.spacing', $c['item'], 'only an accordion answers item: no padding' );
        $toggleItem = $this->itemClauses( StyleGuide::kitRules( [ 'divi/accordion' => [ 'item-rule' ], 'divi/toggle' => [ 'item' ] ] ) );
        $this->assertStringContainsString( 'module.decoration.spacing.desktop.value.padding', (string) $toggleItem['item'] );

        // Planted false: a carrier that answers no item role gets no clause at all.
        $accordionOnly = $this->itemClauses( StyleGuide::kitRules( [ 'divi/accordion' => [ 'item', 'item-rule' ], 'divi/toggle' => [ 'something-else' ] ] ) );
        $this->assertNotNull( $accordionOnly['accordion'] );
        $this->assertNull( $accordionOnly['toggle'], 'toggle answers no item role: no toggle clause' );
        $this->assertStringNotContainsString( 'closedTitle', $accordionOnly['para'] );
        $this->assertStringNotContainsString( 'openToggleIcon', $accordionOnly['para'] );
        $toggleOnly = $this->itemClauses( StyleGuide::kitRules( [ 'divi/accordion' => [ 'something-else' ], 'divi/toggle' => [ 'item', 'item-rule' ] ] ) );
        $this->assertNotNull( $toggleOnly['toggle'] );
        $this->assertNull( $toggleOnly['accordion'], 'accordion answers no item role: no parent clause' );
        $this->assertStringNotContainsString( 'On the accordion', $toggleOnly['para'] );
        $this->assertStringNotContainsString( 'closedToggle.decoration.font', $toggleOnly['para'] );
        // No item role anywhere: no paragraph at all.
        $this->assertStringNotContainsString( '## Accordion and toggle items', StyleGuide::kitRules( [ 'divi/button' => [ 'control' ] ] ) );
    }

    public function testTheToggleClauseNamesTheLeavesTheAdapterWritesOnAToggle(): void
    {
        $paths = [
            'openToggle.decoration.background.desktop.value.color',
            'closedToggle.decoration.background.desktop.value.color',
            'openToggle.decoration.font.font.desktop.value.color',
            'closedTitle.decoration.font.font.desktop.value.color',
            'closedToggleIcon.decoration.icon.desktop.value.color',
            'openToggleIcon.decoration.icon.desktop.value.color',
            'content.decoration.bodyFont.body.font.desktop.value.color',
        ];
        foreach ( [ 'item', 'item-rule' ] as $role ) {
            $written = $this->writtenPaths( 'divi/toggle', $role );
            foreach ( $paths as $path ) {
                $this->assertContains( $path, $written, "$path: the adapter writes it on a toggle $role" );
            }
            $this->assertNotContains( 'closedToggle.decoration.font.font.desktop.value.color', $written, 'a toggle has no closedToggle font' );
        }

        // A toggle on its own: the full list, each path once.
        $alone = $this->itemClauses( StyleGuide::kitRules( [ 'divi/toggle' => [ 'item', 'item-rule' ] ] ) );
        $this->assertNotNull( $alone['toggle'] );
        $this->assertStringContainsString( 'itself, not on a parent', $alone['toggle'] );
        foreach ( $paths as $path ) {
            $this->assertSame( 1, substr_count( $alone['toggle'], self::backticked( $path ) ), $path );
        }
        $this->assertStringNotContainsString( '`closedToggle.decoration.font', $alone['toggle'] );
        $this->assertStringContainsString( '(not `closedToggle`)', $alone['toggle'] );
        $this->assertStringNotContainsString( 'aied', $alone['toggle'] );

        // Next to the accordion clause: "the same paths except ..." is true, and the two exceptions are the only differences.
        $c = $this->itemClauses( StyleGuide::kitRules() );
        $this->assertNotNull( $c['toggle'] );
        $this->assertStringContainsString( 'itself, not on a parent, at the same paths except', $c['toggle'] );
        $this->assertSame( 1, substr_count( $c['toggle'], '`closedTitle.decoration.font.font.desktop.value.color`' ) );
        $this->assertSame( 1, substr_count( $c['toggle'], '`openToggleIcon.decoration.icon.desktop.value.color`' ) );
        $this->assertStringContainsString( '(not `closedToggle`)', $c['toggle'] );
        $colour = static fn ( string $p ): bool => str_ends_with( $p, '.color' ) && ! str_contains( $p, 'border' ) && ! str_contains( $p, 'boxShadow' );
        foreach ( [ 'item', 'item-rule' ] as $role ) {
            $acc = array_values( array_filter( $this->writtenPaths( 'divi/accordion', $role ), $colour ) );
            $tog = array_values( array_filter( $this->writtenPaths( 'divi/toggle', $role ), $colour ) );
            sort( $acc );
            sort( $tog );
            $this->assertSame(
                [ 'closedToggle.decoration.font.font.desktop.value.color' ],
                array_values( array_diff( $acc, $tog ) ),
                "$role: only the accordion's closed title differs (toggle side: closedTitle)"
            );
            $this->assertSame(
                [ 'closedTitle.decoration.font.font.desktop.value.color', 'openToggleIcon.decoration.icon.desktop.value.color' ],
                array_values( array_diff( $tog, $acc ) ),
                "$role: the toggle's only extra colour paths are the ones the clause names"
            );
        }
        $this->assertStringNotContainsString( 'aied', $c['toggle'] );
    }

    public function testTheKitRulesBudgetOfTheLeftItemsPass(): void
    {
        // Deliberate raise from 7,600 B to 7,900 B for the per-role accordion clauses and the toggle clause; the real bound is 10,000 B.
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertLessThanOrEqual( 7900, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        $this->assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ) );
        $this->assertSame( 6474, strlen( StyleGuide::markdown( 'forms' ) ) );
        $this->assertSame( 8706, strlen( StyleGuide::markdown( 'composition' ) ) );
        $this->assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $max = max( $max, strlen( StyleGuide::markdown( 'style-kit:' . $id ) ) );
        }
        $this->assertSame( 9599, $max, 'the largest kit topic did not move' );
        foreach ( StyleGuide::topics() as $topic ) {
            $this->assertStringNotContainsString( 'aied', StyleGuide::markdown( $topic ), "$topic names no marker token" );
        }
    }

    public function testTheBudgetsOfTheSecondPass(): void
    {
        $size = strlen( StyleGuide::markdown() );
        // The default guide does not grow (owner rule): the Structure fix is size-neutral.
        $this->assertSame( 28975, $size, 'the default guide, pinned exactly' );
        $this->assertLessThanOrEqual( 29108, $size, 'Ruling 5 ceiling' );
        $this->assertLessThanOrEqual( 7900, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules (spec Ruling 5 raised the 7,000 B pin to 7,600 B; the real bound is 10,000 B)' );
        $this->assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ) );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
        $this->assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog did not move' );
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $max = max( $max, strlen( StyleGuide::markdown( 'style-kit:' . $id ) ) );
        }
        $this->assertSame( 9599, $max, 'the largest kit topic did not move' );
        foreach ( StyleGuide::topics() as $topic ) {
            $this->assertStringNotContainsString( 'aied', StyleGuide::markdown( $topic ), "$topic names no marker token" );
        }
        $this->assertStringNotContainsString( 'aied', StyleGuide::markdown() );
    }

    public function testTheBudgetsOfTheGuidePolish(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertLessThanOrEqual( 7900, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules (plan 2026-10-21-guide-polish-rules-chips; the real bound is 10,000 B)' );
        $this->assertLessThanOrEqual( 9500, strlen( StyleGuide::markdown( 'controls' ) ) );
        $this->assertLessThanOrEqual( 10000, strlen( StyleGuide::markdown( 'composition' ) ) );
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
        $this->assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the catalog did not move' );
        $max = 0;
        foreach ( self::KIT_TOPIC_SIZES_BEFORE as $id => $before ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertSame( $before, $len, "$id: byte-equal to the size pinned before the polish" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9599, $max );
        foreach ( StyleGuide::topics() as $topic ) {
            $this->assertStringNotContainsString( 'aied', StyleGuide::markdown( $topic ), "$topic names no marker token" );
        }
        // The 50-kit catalog projection stays within 9,950 B (the same check as the rules and chips budget test).
        $property = new \ReflectionProperty( StyleKits::class, 'cache' );
        $real     = array_map( static fn ( string $id ): array => (array) StyleKits::get( $id ), StyleKits::ids() );
        try {
            $kits = $real;
            for ( $n = 1; count( $kits ) < 50; $n++ ) {
                $clone          = $real[ $n % count( $real ) ];
                $clone['id']    = 'extra-kit-' . $n;
                $clone['title'] = 'Extra kit ' . $n;
                $kits[]         = $clone;
            }
            $property->setValue( null, $kits );
            $this->assertLessThanOrEqual( 9950, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        } finally {
            StyleKits::clearCache();
        }
    }
}
