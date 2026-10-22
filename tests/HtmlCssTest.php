<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlCss;

/** The pure CSS text and value reader behind html_to_divi styles: what it keeps, what it poisons, what it counts, and that nothing can break it. */
final class HtmlCssTest extends HtmlTestCase
{
    private function decl( string $property, ?string $value, bool $important = false, ?string $poison = null ): array
    {
        return [ 'property' => $property, 'value' => $value, 'important' => $important, 'poison' => $poison ];
    }

    private function subject( ?string $tag, array $classes = [], bool $other = false, array $ids = [] ): array
    {
        return [ 'tag' => $tag, 'ids' => $ids, 'classes' => $classes, 'other' => $other ];
    }

    /** @return list<array{property:string,value:?string,important:bool,poison:?string}> */
    private function block( string $css, array &$ignored = [] ): array
    {
        return HtmlCss::declarations( $css, $ignored );
    }

    // ---------------------------------------------------------------
    // Selectors and rules
    // ---------------------------------------------------------------

    public function testSimpleSelectorsApplyAndCommaListsSplitPerItem(): void
    {
        $r    = HtmlCss::stylesheet( 'h1, .lead, #top, .a .b { color: red }' );
        $decl = [ $this->decl( 'color', '#ff0000' ) ];

        $this->assertSame( [
            [ 'subject' => $this->subject( 'h1' ), 'simple' => true, 'specificity' => [ 0, 0, 1 ], 'poison_only' => false, 'order' => 0, 'declarations' => $decl ],
            [ 'subject' => $this->subject( null, [ 'lead' ] ), 'simple' => true, 'specificity' => [ 0, 1, 0 ], 'poison_only' => false, 'order' => 1, 'declarations' => $decl ],
            [ 'subject' => $this->subject( null, [], false, [ 'top' ] ), 'simple' => true, 'specificity' => [ 1, 0, 0 ], 'poison_only' => false, 'order' => 2, 'declarations' => $decl ],
            [ 'subject' => $this->subject( null, [ 'b' ] ), 'simple' => false, 'specificity' => [ 0, 2, 0 ], 'poison_only' => true, 'order' => 3, 'declarations' => $decl ],
        ], $r['rules'] );
        $this->assertSame( [ 'selector' => 1 ], $r['ignored'] );
    }

    public function testCompoundAttributeAndStructuralSelectorsArePoisonOnly(): void
    {
        $cases = [
            'p.lead'         => [ $this->subject( 'p', [ 'lead' ] ), [ 0, 1, 1 ] ],
            'a[href]'        => [ $this->subject( 'a', [], true ), [ 0, 1, 1 ] ],
            'li:first-child' => [ $this->subject( 'li', [], true ), [ 0, 1, 1 ] ],
            ':root'          => [ $this->subject( null, [], true ), [ 0, 1, 0 ] ],
            ':is(h1,h2)'     => [ $this->subject( null, [], true ), [ 0, 0, 1 ] ],
            'ul > li'        => [ $this->subject( 'li' ), [ 0, 0, 2 ] ],
            '*'              => [ $this->subject( null ), [ 0, 0, 0 ] ],
            '.a:not(#x)'     => [ $this->subject( null, [ 'a' ], true ), [ 1, 1, 0 ] ],
            'a:where(.x)'    => [ $this->subject( 'a', [], true ), [ 0, 0, 1 ] ],
        ];
        foreach ( $cases as $selector => [ $subject, $spec ] ) {
            $r = HtmlCss::stylesheet( $selector . ' { color: red }' );
            $this->assertCount( 1, $r['rules'], $selector );
            $this->assertSame( $subject, $r['rules'][0]['subject'], $selector );
            $this->assertSame( $spec, $r['rules'][0]['specificity'], $selector );
            $this->assertFalse( $r['rules'][0]['simple'], $selector );
            $this->assertTrue( $r['rules'][0]['poison_only'], $selector );
            $this->assertSame( [ 'selector' => 1 ], $r['ignored'], $selector );
        }
    }

    public function testStatesAndPseudoElementsYieldNoRule(): void
    {
        $r = HtmlCss::stylesheet( 'a:hover { color: red } .btn:focus-visible { color: red } p::first-line { color: red } li::marker { color: red }' );
        $this->assertSame( [], $r['rules'] );
        $this->assertSame( [ 'pseudo_state' => 4 ], $r['ignored'] );

        // A state beside a structural pseudo-class is still a rule that could apply: it poisons.
        $r = HtmlCss::stylesheet( 'a:hover:first-child { color: red }' );
        $this->assertCount( 1, $r['rules'] );
        $this->assertTrue( $r['rules'][0]['poison_only'] );
    }

    public function testMediaAndSupportsBlocksArePoisonOnly(): void
    {
        $r = HtmlCss::stylesheet(
            '@media (min-width: 600px) { p { color: red; margin: 0 } h1 { font-size: 2em } }'
            . '@supports (display: grid) { .x { color: blue } }'
            . '@font-face { font-family: X; src: url(a.woff) }'
            . '@keyframes x { from { color: red } to { color: blue } }'
            . '@import url(x.css);'
        );
        $this->assertCount( 3, $r['rules'] );
        $this->assertSame( [ 'p', 'h1', null ], array_map( static fn( $rule ) => $rule['subject']['tag'], $r['rules'] ) );
        foreach ( $r['rules'] as $rule ) {
            $this->assertTrue( $rule['simple'] );
            $this->assertTrue( $rule['poison_only'] );
        }
        $this->assertSame( [ 'at_rule' => 2, 'external' => 1, 'media' => 3, 'property' => 1 ], $r['ignored'] );
    }

    public function testImportantIsReadAndShorthandsExpand(): void
    {
        $this->assertSame( [
            $this->decl( 'padding-top', '1px' ), $this->decl( 'padding-right', '2px' ), $this->decl( 'padding-bottom', '3px' ), $this->decl( 'padding-left', '2px' ),
        ], $this->block( 'padding: 1px 2px 3px' ) );
        $this->assertSame( [
            $this->decl( 'border-top-left-radius', '4px' ), $this->decl( 'border-top-right-radius', '8px' ),
            $this->decl( 'border-bottom-right-radius', '4px' ), $this->decl( 'border-bottom-left-radius', '8px' ),
        ], $this->block( 'border-radius: 4px 8px' ) );
        $this->assertSame( [
            $this->decl( 'font-size', null, false, 'shorthand' ), $this->decl( 'font-weight', null, false, 'shorthand' ), $this->decl( 'font-family', null, false, 'shorthand' ),
        ], $this->block( 'font: 700 20px Inter' ) );
        $this->assertSame( [ $this->decl( 'color', '#ff0000', true ) ], $this->block( 'color: red !important' ) );
        $this->assertSame( [ $this->decl( 'color', '#ff0000', true ) ], $this->block( 'COLOR : Red ! IMPORTANT ;' ) );
        $this->assertSame( [ $this->decl( 'font-family', null, false, 'unreadable' ) ], $this->block( 'font-family: unimportant!' ), 'only a real !important counts' );
        $this->assertSame( [ $this->decl( 'font-family', 'Inter', true ) ], $this->block( 'font-family: Inter!important' ) );

        // Four values, three values, one value; elliptical radii are not read.
        $this->assertSame( [ '1px', '2px', '3px', '4px' ], array_column( $this->block( 'padding: 1px 2px 3px 4px' ), 'value' ) );
        $this->assertSame( [ '5px', '5px', '5px', '5px' ], array_column( $this->block( 'padding: 5px' ), 'value' ) );
        $this->assertSame( [ '0px', '0px', '0px', '0px' ], array_column( $this->block( 'padding: 0' ), 'value' ) );
        $this->assertSame( [ 'shorthand', 'shorthand', 'shorthand', 'shorthand' ], array_column( $this->block( 'border-radius: 4px / 8px' ), 'poison' ) );
        $this->assertSame( [ '1px', null, '3px', null ], array_column( $this->block( 'padding: 1px 2vw 3px' ), 'value' ), 'a side that cannot be read poisons only itself' );
        $this->assertSame( [ null, null, null, null ], array_column( $this->block( 'padding: var(--p) 2px' ), 'value' ) );
        $this->assertSame( [ 'function', 'function', 'function', 'function' ], array_column( $this->block( 'padding: var(--p) 2px' ), 'poison' ) );
    }

    public function testInheritIsKeptOnInheritedPropertiesAndPoisonsTheOthers(): void
    {
        $this->assertSame( [ $this->decl( 'color', 'inherit' ) ], $this->block( 'color: inherit' ) );
        $this->assertSame( [ $this->decl( 'font-size', 'inherit' ) ], $this->block( 'font-size: INHERIT' ) );
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'keyword' ) ], $this->block( 'background-color: inherit' ) );
        $this->assertSame( [ $this->decl( 'color', null, false, 'keyword' ) ], $this->block( 'color: initial' ) );
        $this->assertSame( [ $this->decl( 'font-weight', null, false, 'keyword' ) ], $this->block( 'font-weight: unset' ) );
        $this->assertSame( [ $this->decl( 'text-align', null, false, 'keyword' ) ], $this->block( 'text-align: revert-layer' ) );
        $this->assertSame( [ $this->decl( 'max-width', null, false, 'keyword' ) ], $this->block( 'max-width: revert' ) );
        $this->assertSame( 4, count( array_filter( $this->block( 'padding: inherit' ), static fn( $d ) => 'keyword' === $d['poison'] ) ) );
    }

    public function testDeclarationsThatChangeWhatTheReaderCannotSeePoisonTheProperty(): void
    {
        $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ) ], $this->block( 'opacity: .5' ) );
        $this->assertSame( [], $this->block( 'opacity: 1' ) );
        $this->assertSame( [], $this->block( 'filter: none; mix-blend-mode: normal' ) );
        $this->assertSame( [ $this->decl( 'padding-left', null, false, 'property' ), $this->decl( 'padding-right', null, false, 'property' ) ], $this->block( 'padding-inline: 1px' ) );
        $this->assertSame( 4, count( $this->block( '-webkit-border-radius: 3px' ) ) );
        $this->assertSame( count( HtmlCss::ALL ), count( $this->block( 'all: unset' ) ) );
        $this->assertSame( [ $this->decl( 'max-width', null, false, 'property' ) ], $this->block( 'max-inline-size: 10px' ) );
        $this->assertSame( [ $this->decl( 'background-image', null, false, 'image_or_gradient' ) ], $this->block( 'background-image: url(a.png)' ) );
        $this->assertSame( [], $this->block( 'background-image: none' ) );
    }

    public function testCustomPropertiesAndUnknownPropertiesAreCountedNotReturned(): void
    {
        $ignored = [];
        $out     = $this->block( '--x: red; margin: 0; display: none; nonsense; color: blue', $ignored );
        $this->assertSame( [ $this->decl( 'color', '#0000ff' ) ], $out );
        $this->assertSame( [ 'property' => 4 ], $ignored );
    }

    public function testABlockThatIsNotClosedIsReadToTheEndAndAStrayBraceSwallowsOnlyTheNextRule(): void
    {
        $r = HtmlCss::stylesheet( 'p { color: red } p { color: blue' );
        $this->assertCount( 2, $r['rules'] );
        $this->assertSame( '#0000ff', $r['rules'][1]['declarations'][0]['value'] );
        $this->assertSame( [ 'selector' => 1 ], $r['ignored'] );

        // The browser reads "} p" as one invalid prelude: that rule is not understood (it poisons), the next one is read.
        $r = HtmlCss::stylesheet( '} p { color: red } h1 { color: blue }' );
        $this->assertCount( 2, $r['rules'] );
        $this->assertTrue( $r['rules'][0]['poison_only'] );
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ], $r['rules'][0]['subject'] );
        $this->assertTrue( $r['rules'][1]['simple'] );
        $this->assertFalse( $r['rules'][1]['poison_only'] );
    }

    public function testNestedRulesAreFlaggedAndNeverApply(): void
    {
        $r = HtmlCss::stylesheet( '.card { color: red; b { color: blue } &:is(.x) { color: green } @media (x) { color: black } }' );
        $this->assertSame( 4, count( $r['rules'] ) );
        $this->assertTrue( $r['rules'][0]['simple'] );
        $this->assertFalse( $r['rules'][0]['poison_only'] );
        $this->assertSame( 'b', $r['rules'][1]['subject']['tag'] );
        $this->assertTrue( $r['rules'][1]['poison_only'] );
        $this->assertFalse( $r['rules'][1]['simple'] );
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [ 'card' ], 'other' => true ], $r['rules'][2]['subject'], 'an & compound is the parent plus its own parts' );
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [ 'card' ], 'other' => false ], $r['rules'][3]['subject'], 'a nested at-rule is the parent' );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][1]['specificity'] );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][3]['specificity'] );

        // In an inline style a nested rule poisons what it sets, on the element.
        $out = $this->block( 'color: red; & p { color: blue; padding: 1px }' );
        $this->assertSame( [ $this->decl( 'color', '#ff0000' ), $this->decl( 'color', null, false, 'unresolved' ), $this->decl( 'padding-top', null, false, 'unresolved' ), $this->decl( 'padding-right', null, false, 'unresolved' ), $this->decl( 'padding-bottom', null, false, 'unresolved' ), $this->decl( 'padding-left', null, false, 'unresolved' ) ], $out );
    }

    public function testASelectorListWithAnItemThatIsNotUnderstoodPoisonsEverywhere(): void
    {
        $universal = [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ];
        foreach ( [ 'h1, a:made-up', 'h1, svg|a', 'p > ', 'h1, ', '.a..b', '#' ] as $selector ) {
            $r = HtmlCss::stylesheet( $selector . ' { color: red }' );
            $this->assertCount( 1, $r['rules'], $selector );
            $this->assertSame( $universal, $r['rules'][0]['subject'], $selector );
            $this->assertTrue( $r['rules'][0]['poison_only'], $selector );
            $this->assertFalse( $r['rules'][0]['simple'], $selector );
        }
        // A vendor pseudo beside a real selector: a browser may drop the list, so each real item only poisons.
        $r = HtmlCss::stylesheet( 'a:-webkit-foo, h1 { color: red }' );
        $this->assertSame( [ true, true ], array_column( $r['rules'], 'poison_only' ) );
        $this->assertSame( [ 'a', 'h1' ], array_map( static fn( $x ) => $x['subject']['tag'], $r['rules'] ) );
        // An unclosed bracket swallows the rest of the text, as it does in a browser.
        $this->assertSame( [], HtmlCss::stylesheet( '[x { color: red }' )['rules'] );
        // Escapes are decoded; a vendor pseudo-element alone is simply a pseudo-element.
        $r = HtmlCss::stylesheet( '.sm\:flex, .a\31 b { color: red } input::-webkit-foo { color: red }' );
        $this->assertSame( [ [ 'sm:flex' ], [ 'a1b' ] ], array_map( static fn( $x ) => $x['subject']['classes'], $r['rules'] ) );
        $this->assertSame( 1, $r['ignored']['pseudo_state'] );
    }

    // ---------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------

    public function testValueFormsColours(): void
    {
        $hex = static fn( string $h ): array => [ 'kind' => 'colour', 'hex' => $h ];
        $bad = static fn( string $r ): array => [ 'kind' => 'poison', 'reason' => $r ];

        $this->assertSame( $hex( '#aabbcc' ), HtmlCss::colour( '#ABC' ) );
        $this->assertSame( $hex( '#0a141e' ), HtmlCss::colour( 'rgb(10, 20, 30)' ) );
        $this->assertSame( $hex( '#0a141e' ), HtmlCss::colour( 'rgb(10 20 30)' ) );
        $this->assertSame( $hex( '#0a141e' ), HtmlCss::colour( ' RGB( 10 , 20 , 30 ) ' ) );
        $this->assertSame( $hex( '#663399' ), HtmlCss::colour( 'RebeccaPurple' ) );
        $this->assertSame( $hex( '#123456' ), HtmlCss::colour( '#123456' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( 'rgba(0,0,0,.5)' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( '#0008' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( '#11223344' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( 'transparent' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( 'rgb(0 0 0 / 50%)' ) );
        $this->assertSame( $bad( 'alpha' ), HtmlCss::colour( 'hsla(0, 0%, 0%, .5)' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'hsl(0 0% 0%)' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'currentcolor' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'ButtonFace' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'rgb(300,0,0)' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'rgb(10%, 20%, 30%)' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'rgb(1, 2 3)' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( '#12' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( '#ggg' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'reddish' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'red blue' ) );
        $this->assertSame( $bad( 'colour_form' ), HtmlCss::colour( 'oklch(50% .1 20)' ) );
        $this->assertSame( $bad( 'function' ), HtmlCss::colour( 'var(--x)' ) );
        $this->assertSame( $bad( 'function' ), HtmlCss::colour( 'rgb(var(--r) 0 0)' ) );
        $this->assertSame( $bad( 'function' ), HtmlCss::colour( 'url(x.png)' ) );
        $this->assertSame( $bad( 'keyword' ), HtmlCss::colour( 'initial' ) );
        $this->assertSame( $bad( 'keyword' ), HtmlCss::colour( 'inherit' ) );

        // In a declaration a transparent background means "no background", a transparent colour is an alpha.
        $this->assertSame( [ $this->decl( 'background-color', 'none' ) ], $this->block( 'background-color: transparent' ) );
        $this->assertSame( [ $this->decl( 'color', null, false, 'alpha' ) ], $this->block( 'color: transparent' ) );
    }

    public function testValueFormsBackground(): void
    {
        $this->assertSame( [ 'kind' => 'colour', 'hex' => '#112233' ], HtmlCss::background( '#123' ) );
        $this->assertSame( [ 'kind' => 'colour', 'hex' => '#ff0000' ], HtmlCss::background( 'red' ) );
        $this->assertSame( [ 'kind' => 'none' ], HtmlCss::background( 'none' ) );
        $this->assertSame( [ 'kind' => 'none' ], HtmlCss::background( 'transparent' ) );
        $image = [ 'kind' => 'poison', 'reason' => 'image_or_gradient' ];
        $this->assertSame( $image, HtmlCss::background( '#fff url(a.png)' ) );
        $this->assertSame( $image, HtmlCss::background( 'linear-gradient(red, blue)' ) );
        $this->assertSame( $image, HtmlCss::background( 'url("a.png")' ) );
        $this->assertSame( $image, HtmlCss::background( 'red no-repeat' ) );
        $this->assertSame( [ 'kind' => 'poison', 'reason' => 'alpha' ], HtmlCss::background( 'rgba(0,0,0,.5)' ) );
        $this->assertSame( [ 'kind' => 'poison', 'reason' => 'function' ], HtmlCss::background( 'var(--bg)' ) );

        $this->assertSame( [ $this->decl( 'background-color', '#112233' ) ], $this->block( 'background: #123' ) );
        $this->assertSame( [ $this->decl( 'background-color', 'none' ) ], $this->block( 'background: none' ) );
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'image_or_gradient' ), $this->decl( 'background-image', null, false, 'image_or_gradient' ) ], $this->block( 'background: #fff url(a.png)' ) );
    }

    public function testValueFormsLengths(): void
    {
        $this->assertSame( 24.0, HtmlCss::length( '24px', 16.0, false ) );
        $this->assertSame( 24.0, HtmlCss::length( '1.5rem', 99.0, false ) );
        $this->assertSame( 36.0, HtmlCss::length( '2em', 18.0, false ) );
        $this->assertSame( 24.0, HtmlCss::length( '150%', 16.0, true ) );
        $this->assertNull( HtmlCss::length( '150%', 16.0, false ) );
        $this->assertSame( 0.0, HtmlCss::length( '0', 16.0, false ) );
        foreach ( [ '5vw', 'calc(1px)', 'large', '-3px', '12', '1e3px', '', 'px', '10pt', 'var(--x)' ] as $bad ) {
            $this->assertNull( HtmlCss::length( $bad, 16.0, true ), $bad );
        }

        // In a declaration the value is a canonical token that length() reads back.
        $this->assertSame( [ $this->decl( 'font-size', '1.5rem' ) ], $this->block( 'font-size: 1.50REM' ) );
        $this->assertSame( [ $this->decl( 'font-size', '150%' ) ], $this->block( 'font-size: 150%' ) );
        $this->assertSame( [ $this->decl( 'font-size', '0.5em' ) ], $this->block( 'font-size: .5em' ) );
        $this->assertSame( [ $this->decl( 'font-size', null, false, 'unit' ) ], $this->block( 'font-size: large' ) );
        $this->assertSame( [ $this->decl( 'font-size', null, false, 'function' ) ], $this->block( 'font-size: clamp(1rem, 2vw, 3rem)' ) );
        $this->assertSame( [ $this->decl( 'font-size', null, false, 'unit' ) ], $this->block( 'font-size: -4px' ) );
        $this->assertSame( [ $this->decl( 'padding-top', null, false, 'unit' ) ], $this->block( 'padding-top: 50%' ) );
        $this->assertSame( [ $this->decl( 'max-width', '1200px' ) ], $this->block( 'max-width: 1200px' ) );
        $this->assertSame( [ $this->decl( 'max-width', 'none' ) ], $this->block( 'max-width: none' ) );
        $this->assertSame( [ $this->decl( 'max-width', null, false, 'unit' ) ], $this->block( 'max-width: 100%' ) );
        $this->assertSame( [ $this->decl( 'max-width', null, false, 'unit' ) ], $this->block( 'max-width: 70ch' ) );
        $this->assertSame( [ $this->decl( 'font-size', null, false, 'unit' ) ], $this->block( 'font-size: 99999999px' ), 'more than seven digits is not read' );
    }

    public function testValueFormsFamiliesWeightsAligns(): void
    {
        $this->assertSame( [ 'Playfair Display', 'Georgia', 'serif' ], HtmlCss::families( '"Playfair Display", Georgia , serif' ) );
        $this->assertSame( [ 'Open Sans', 'Segoe UI', 'sans-serif' ], HtmlCss::families( "'Open Sans',  Segoe   UI ,sans-serif" ) );
        $this->assertSame( [ 'Inter' ], HtmlCss::families( 'Inter' ) );
        $this->assertSame( [], HtmlCss::families( '' ) );
        $this->assertSame( [], HtmlCss::families( 'Good, "Bad<script>"' ), 'one unreadable item makes the whole value unreadable' );
        $this->assertSame( [], HtmlCss::families( 'Good, 123bad' ) );
        $this->assertSame( [], HtmlCss::families( 'Good,, Other' ) );
        $this->assertSame( [], HtmlCss::families( '"Esc\\"aped"' ) );
        $this->assertSame( [ 'Ünï Code' ], HtmlCss::families( '"Ünï Code"' ) );
        $this->assertSame( [ 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j' ], HtmlCss::families( 'a,b,c,d,e,f,g,h,i,j,k,l' ) );

        $this->assertSame( 700, HtmlCss::weight( 'bold' ) );
        $this->assertSame( 400, HtmlCss::weight( 'NORMAL' ) );
        $this->assertSame( 900, HtmlCss::weight( '900' ) );
        $this->assertNull( HtmlCss::weight( '350' ) );
        $this->assertNull( HtmlCss::weight( 'bolder' ) );
        $this->assertNull( HtmlCss::weight( 'lighter' ) );
        $this->assertNull( HtmlCss::weight( '1000' ) );
        $this->assertNull( HtmlCss::weight( '0' ) );

        $this->assertSame( 'center', HtmlCss::align( 'CENTER' ) );
        $this->assertSame( 'justify', HtmlCss::align( ' justify ' ) );
        $this->assertNull( HtmlCss::align( 'start' ) );
        $this->assertNull( HtmlCss::align( 'end' ) );
        $this->assertNull( HtmlCss::align( '-webkit-center' ) );
        $this->assertNull( HtmlCss::align( 'match-parent' ) );

        $this->assertSame( [ $this->decl( 'font-family', 'Playfair Display, Georgia, serif' ) ], $this->block( 'font-family: "Playfair Display", Georgia , serif' ) );
        $this->assertSame( [ $this->decl( 'font-family', null, false, 'unreadable' ) ], $this->block( 'font-family: "Bad<x>"' ) );
        $this->assertSame( [ $this->decl( 'font-weight', '700' ) ], $this->block( 'font-weight: bold' ) );
        $this->assertSame( [ $this->decl( 'font-weight', null, false, 'keyword' ) ], $this->block( 'font-weight: bolder' ) );
        $this->assertSame( [ $this->decl( 'font-weight', null, false, 'unit' ) ], $this->block( 'font-weight: 350' ) );
        $this->assertSame( [ $this->decl( 'text-align', 'center' ) ], $this->block( 'text-align: center' ) );
        $this->assertSame( [ $this->decl( 'text-align', null, false, 'keyword' ) ], $this->block( 'text-align: start' ) );
    }

    // ---------------------------------------------------------------
    // The cascade contract: a poison must never lose to a value a browser would not use
    // ---------------------------------------------------------------

    /**
     * A miniature cascade over stylesheet() output, as HtmlStyles must do it: candidates are the rules whose subject could match the
     * element; the winner has the highest (important, sentinel specificity, specificity, order); a later declaration in the same rule wins.
     *
     * @param array{tag:string,ids?:list<string>,classes?:list<string>} $el
     * @return string the winning value, "POISON:<reason>" or "NONE"
     */
    private function winner( string $css, array $el, string $property ): string
    {
        $r    = HtmlCss::stylesheet( $css );
        $best = null;
        $key  = null;
        foreach ( $r['rules'] as $rule ) {
            $s = $rule['subject'];
            if ( null !== $s['tag'] && $s['tag'] !== $el['tag'] ) {
                continue;
            }
            if ( array() !== array_diff( $s['ids'], $el['ids'] ?? [] ) || array() !== array_diff( $s['classes'], $el['classes'] ?? [] ) ) {
                continue;
            }
            foreach ( $rule['declarations'] as $i => $d ) {
                if ( $d['property'] !== $property ) {
                    continue;
                }
                $sentinel = HtmlCss::MAX_SPECIFICITY === $rule['specificity'] ? 1 : 0;
                $k        = [ $d['important'] ? 1 : 0, $sentinel, $rule['specificity'][0], $rule['specificity'][1], $rule['specificity'][2], $rule['order'], $i ];
                if ( null === $key || $k > $key ) {
                    $key  = $k;
                    $best = $rule['poison_only'] && null === $d['poison'] ? 'POISON:rule' : ( null !== $d['poison'] ? 'POISON:' . $d['poison'] : (string) $d['value'] );
                }
            }
        }

        return $best ?? 'NONE';
    }

    /** @return array<string,array{0:string,1:array,2:string}> */
    public static function falseCarryCases(): array
    {
        return [
            'nested rule'                 => [ '.hero { h1 { color: #fff } } .title { color: #111 }', [ 'tag' => 'h1', 'classes' => [ 'title' ] ], 'color' ],
            'nested media in a rule'      => [ '#hero { color: blue } #hero { @media (min-width: 1px) { color: red } }', [ 'tag' => 'div', 'ids' => [ 'hero' ] ], 'color' ],
            'vendor pseudo in a list'     => [ '#hero { color: blue } #hero:-webkit-any(p), .x { color: red }', [ 'tag' => 'p', 'ids' => [ 'hero' ] ], 'color' ],
            'selector over the cap'       => [ '#hero { color: blue } #hero.' . str_repeat( 'a', 210 ) . ' { color: red }', [ 'tag' => 'div', 'ids' => [ 'hero' ] ], 'color' ],
            'is() too deep'               => [ '#a { color: blue } :is(:is(:is(#a))) { color: red }', [ 'tag' => 'div', 'ids' => [ 'a' ] ], 'color' ],
            'nth-child of S'              => [ '#a { color: blue } li:nth-child(odd of #a) { color: red }', [ 'tag' => 'li', 'ids' => [ 'a' ] ], 'color' ],
            'important inside a layer'    => [ '@layer x { p { color: red !important } } #t { color: blue !important }', [ 'tag' => 'p', 'ids' => [ 't' ] ], 'color' ],
            'media nested deeper than 8'  => [ 'p { color: blue }' . str_repeat( '@media all {', 9 ) . 'p { color: red }' . str_repeat( '}', 9 ), [ 'tag' => 'p' ], 'color' ],
            'escaped property name'       => [ 'p { color: blue } p { col\\6f r: red }', [ 'tag' => 'p' ], 'color' ],
            'escaped !important'          => [ '#t { color: blue } p { color: red !imp\\ortant }', [ 'tag' => 'p', 'ids' => [ 't' ] ], 'color' ],
            'escaped at-rule name'        => [ 'p { color: blue } @\\6d edia all { p { color: red } }', [ 'tag' => 'p' ], 'color' ],
            'more than 100 declarations'  => [ '#t { color: blue } p { ' . str_repeat( 'margin: 0;', 101 ) . ' color: red }', [ 'tag' => 'p', 'ids' => [ 't' ] ], 'color' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'falseCarryCases' )]
    public function testAPoisonOutranksTheLowerValueItMightOverride( string $css, array $element, string $property ): void
    {
        $this->assertStringStartsWith( 'POISON:', $this->winner( $css, $element, $property ), 'the lower value must not be carried' );
    }

    public function testASimpleRuleStillWinsWhereNothingUnreadableCouldApply(): void
    {
        $this->assertSame( '#111111', $this->winner( '.title { color: #111 } .hero { h1 { color: #fff } }', [ 'tag' => 'p', 'classes' => [ 'title' ] ], 'color' ) );
        $this->assertSame( '#0000ff', $this->winner( 'a { color: blue; &:hover { color: red } }', [ 'tag' => 'a' ], 'color' ) );
        $this->assertSame( '#ff0000', $this->winner( 'p { color: blue } p { color: red }', [ 'tag' => 'p' ], 'color' ) );
        $this->assertSame( '#0000ff', $this->winner( '@layer x { p { color: red } } p { color: blue }', [ 'tag' => 'p' ], 'color' ), 'a layered normal declaration loses to an unlayered one' );
    }

    public function testTheSentinelSpecificityIsPublicAndRanksFirst(): void
    {
        $this->assertSame( [ PHP_INT_MAX, 0, 0 ], HtmlCss::MAX_SPECIFICITY );
        $r = HtmlCss::stylesheet( '.hero { h1 { color: #fff } }' );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][0]['specificity'] );
        $this->assertTrue( $r['rules'][0]['poison_only'] );
        $this->assertSame( 'h1', $r['rules'][0]['subject']['tag'] );
    }

    public function testEffectsAndBackgroundImagesAreMarkersNotColourEntries(): void
    {
        $r = HtmlCss::stylesheet( 'p { opacity: .3 } .x { color: #000 }' );
        $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ) ], $r['rules'][0]['declarations'] );
        $this->assertSame( [ $this->decl( 'color', '#000000' ) ], $r['rules'][1]['declarations'] );
        $this->assertSame( '#000000', $this->winner( 'p { opacity: .3 } .x { color: #000 }', [ 'tag' => 'p', 'classes' => [ 'x' ] ], 'color' ) );
        $this->assertSame( 'POISON:effect', $this->winner( 'p { opacity: .3 } .x { color: #000 }', [ 'tag' => 'p', 'classes' => [ 'x' ] ], 'effect' ) );

        $r = HtmlCss::stylesheet( '.hero { background-image: url(x.jpg) } #h { background-color: #123 }' );
        $this->assertSame( [ $this->decl( 'background-image', null, false, 'image_or_gradient' ) ], $r['rules'][0]['declarations'] );
        $this->assertSame( [ $this->decl( 'background-color', '#112233' ) ], $r['rules'][1]['declarations'] );

        foreach ( [ 'filter: blur(1px)', 'mix-blend-mode: multiply', '-webkit-text-fill-color: red', 'background-clip: text', '-webkit-background-clip: text', 'color-scheme: dark', 'color-scheme: light dark' ] as $css ) {
            $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ) ], $this->block( $css ), $css );
        }
        $this->assertSame( [], $this->block( 'background-clip: border-box; color-scheme: normal' ) );

        // The background shorthand with an image is both: the colour is unknown and an image is present.
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'image_or_gradient' ), $this->decl( 'background-image', null, false, 'image_or_gradient' ) ], $this->block( 'background: #fff url(a.png)' ) );
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'alpha' ) ], $this->block( 'background: rgba(0,0,0,.5)' ) );
        $this->assertSame( [ $this->decl( 'text-align', null, false, 'property' ) ], $this->block( 'direction: rtl' ) );
        $this->assertContains( 'effect', HtmlCss::MARKERS );
        $this->assertContains( 'background-image', HtmlCss::MARKERS );
    }

    public function testStatesAndPseudoElementsInANestedRuleOrAListAddNoPoison(): void
    {
        $r = HtmlCss::stylesheet( 'a { color: blue; &:hover { color: red } &::before { color: red } }' );
        $this->assertCount( 1, $r['rules'] );
        $this->assertSame( 2, $r['ignored']['pseudo_state'] );

        $r = HtmlCss::stylesheet( '::-moz-selection, ::selection { color: #fff; background: #000 }' );
        $this->assertSame( [], $r['rules'] );
        $this->assertSame( [ 'pseudo_state' => 2 ], $r['ignored'] );

        // A pseudo-element beside a real selector: the real part still poisons, with its own subject.
        $r = HtmlCss::stylesheet( 'h1, ::-moz-selection { color: red }' );
        $this->assertCount( 1, $r['rules'] );
        $this->assertSame( 'h1', $r['rules'][0]['subject']['tag'] );
        $this->assertTrue( $r['rules'][0]['poison_only'] );
        $r = HtmlCss::stylesheet( 'h1, ::selection { color: red }' );
        $this->assertTrue( $r['rules'][0]['simple'] && ! $r['rules'][0]['poison_only'], 'a standard pseudo-element beside h1 is no reason to doubt h1' );

        // An & compound is the parent: &.x is the parent plus .x, and a nested rule under a hover-only parent adds nothing.
        $r = HtmlCss::stylesheet( 'p.a { color: blue; &.x { color: red } } a:hover { b { color: red } }' );
        $this->assertCount( 2, $r['rules'] );
        $this->assertSame( [ 'tag' => 'p', 'ids' => [], 'classes' => [ 'a', 'x' ], 'other' => false ], $r['rules'][1]['subject'] );
    }

    public function testEscapesInANameAnAtRuleOrImportantAreUnknownAndPoisonEverythingTheyCouldSet(): void
    {
        $ignored = [];
        $out     = HtmlCss::declarations( 'color: red; col\\6f r: blue', $ignored );
        $this->assertSame( $this->decl( 'color', '#ff0000' ), $out[0] );
        $this->assertCount( 1 + count( HtmlCss::ALL ), $out );
        $this->assertSame( 'unresolved', $out[1]['poison'] );
        $this->assertTrue( $out[1]['important'] );

        $r = HtmlCss::stylesheet( 'p { color: red !imp\\ortant }' );
        $this->assertCount( 1, $r['rules'] );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][0]['specificity'] );
        $this->assertSame( [ $this->decl( 'color', null, true, 'unresolved' ) ], $r['rules'][0]['declarations'] );
    }

    public function testTheByteCapCutAppendsAnAllPropertiesPoisonAndImportDocumentsItself(): void
    {
        $css = str_repeat( ' ', HtmlCss::MAX_CSS_BYTES - 12 ) . 'p{color:#123456}';
        $r   = HtmlCss::stylesheet( $css );
        $this->assertSame( [ 'css_cap' => 1, 'selector' => 1 ], $r['ignored'], 'the cut rule is unclosed' );
        $last = $r['rules'][ count( $r['rules'] ) - 1 ];
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $last['specificity'] );
        $this->assertTrue( $last['poison_only'] );
        $this->assertCount( count( HtmlCss::ALL ), $last['declarations'] );
        $this->assertSame( 'POISON:unresolved', $this->winner( $css, [ 'tag' => 'p' ], 'color' ) );

        // @import is counted, not followed: the imported sheet is outside the text this reader is given (documented contract).
        $r = HtmlCss::stylesheet( '@import url(a.css); p { color: red }' );
        $this->assertSame( [ 'external' => 1 ], $r['ignored'] );
        $this->assertCount( 1, $r['rules'] );
    }

    public function testFamilyNamesThatAreReservedWordsAreNotFamilies(): void
    {
        foreach ( [ 'Arial, inherit', 'default', 'DEFAULT', 'unset, serif', 'Arial, revert-layer', 'serif, initial' ] as $v ) {
            $this->assertSame( [ $this->decl( 'font-family', null, false, 'unreadable' ) ], $this->block( 'font-family: ' . $v ), $v );
            $this->assertSame( [], HtmlCss::families( $v ), $v );
        }
        $this->assertSame( [], HtmlCss::families( 'inherit' ) );
        $this->assertSame( [ $this->decl( 'font-family', 'inherit' ) ], $this->block( 'font-family: inherit' ), 'the CSS-wide keyword on its own is not a family but a keyword' );
        $this->assertSame( [ 'Open default' ], HtmlCss::families( 'Open default' ) );
        $this->assertSame( [ 'default' ], HtmlCss::families( '"default"' ) );
    }


    // ---------------------------------------------------------------
    // Fan-out, budgets and the leftovers of the review
    // ---------------------------------------------------------------

    /** @return array{0:float,1:float,2:array} seconds, peak MB above the start, the result */
    private function measure( string $css ): array
    {
        if ( function_exists( 'memory_reset_peak_usage' ) ) {
            memory_reset_peak_usage();
        }
        $before = memory_get_peak_usage();
        $t      = microtime( true );
        $r      = HtmlCss::stylesheet( $css );

        return [ microtime( true ) - $t, ( memory_get_peak_usage() - $before ) / 1048576, $r ];
    }

    /** @return array<string,array{string}> */
    public static function fanOutInputs(): array
    {
        $list = static fn( string $prefix, int $n ): string => implode( ',', array_map( static fn( int $i ): string => $prefix . $i, range( 1, $n ) ) );
        $six  = 'p,p,p,p,p,p';
        $amp6 = '&,&,&,&,&,&';

        return [
            'six parents, six & items, 8 levels' => [ $six . '{' . str_repeat( $amp6 . '{', 7 ) . 'color:red' . str_repeat( '}', 8 ) ],
            'six x six x six, 8 levels, distinct' => [ $list( '.a', 6 ) . '{' . str_repeat( '&.x,&.y,&.z,&.w,&.v,&.u{', 7 ) . 'color:red' . str_repeat( '}', 8 ) ],
            '50 x 50 x 50'                        => [ $list( '.a', 50 ) . '{' . str_replace( ',', ',&', '&.' . $list( 'b', 50 ) ) . '{' . str_replace( ',', ',&', '&.' . $list( 'c', 50 ) ) . '{color:red}}}' ],
            '50 x 50 x 50 x 50 x 50'              => [ $list( '.a', 50 ) . '{' . str_repeat( str_replace( ',', ',&', '&.' . $list( 'b', 50 ) ) . '{', 4 ) . 'color:red' . str_repeat( '}', 5 ) ],
            'a thousand & spellings'              => [ 'p{' . implode( ',', array_map( static fn( int $i ): string => str_repeat( '&', $i ), range( 1, 300 ) ) ) . '{b{color:red}}}' ],
            'a hundred thousand empty nested'     => [ 'a{' . str_repeat( 'b{}', 30000 ) . '}' ],
            'empty nested levels with lists'      => [ str_repeat( $list( '.a', 40 ) . '{' . str_repeat( '&,', 40 ) . '&{', 2 ) . str_repeat( '}', 4 ) ],
            'one huge list'                       => [ $list( '.a', 9000 ) . '{color:red}' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'fanOutInputs' )]
    public function testAListThatFansOutIsCappedInMemoryAndTime( string $css ): void
    {
        [ $seconds, $megabytes, $r ] = $this->measure( $css );
        $this->assertLessThan( 20.0, $megabytes, 'peak memory above the start' );
        $this->assertLessThan( 0.2, $seconds, 'seconds' );
        $this->assertLessThanOrEqual( HtmlCss::MAX_RULES + 1, count( $r['rules'] ) );
        $this->assertSame( $r, HtmlCss::stylesheet( $css ), 'deterministic' );
    }

    public function testTheTwoReportedFanOutInputsAreCappedAndPoisonWhatTheyCannotKeep(): void
    {
        // 109 bytes: six parents with six & items, 8 levels. Distinct subjects collapse to one: no growth, no cap.
        $css = 'p,p,p,p,p,p{' . str_repeat( '&,&,&,&,&,&{', 7 ) . 'color:red' . str_repeat( '}', 8 );
        [ , , $r ] = $this->measure( $css );
        $this->assertCount( 1, $r['rules'] );
        $this->assertSame( 'p', $r['rules'][0]['subject']['tag'] );

        // 835 bytes: 50 x 50 x 50 distinct subjects. Past MAX_SUBJECTS the level falls back to one universal sentinel subject.
        $list = static fn( string $prefix, int $n ): string => implode( ',', array_map( static fn( int $i ): string => $prefix . $i, range( 1, $n ) ) );
        $css  = $list( '.a', 50 ) . '{&.' . str_replace( ',', ',&.', $list( 'b', 50 ) ) . '{&.' . str_replace( ',', ',&.', $list( 'c', 50 ) ) . '{color:red}}}';
        $this->assertLessThan( 1000, strlen( $css ) );
        [ , , $r ] = $this->measure( $css );
        $this->assertSame( 1, $r['ignored']['css_cap'] );
        $this->assertStringStartsWith( 'POISON:', $this->winner( '#x{color:blue}' . $css, [ 'tag' => 'p', 'ids' => [ 'x' ], 'classes' => [ 'a1', 'b1', 'c1' ] ], 'color' ) );
    }

    public function testNestedRulesCountAgainstTheRuleBudgetEvenWhenEmpty(): void
    {
        $r = HtmlCss::stylesheet( 'a{' . str_repeat( 'b{}', HtmlCss::MAX_RULES + 5 ) . 'color:red}' );
        $this->assertSame( 1, $r['ignored']['css_cap'] );
        $last = $r['rules'][ count( $r['rules'] ) - 1 ];
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $last['specificity'] );
        $this->assertCount( count( HtmlCss::ALL ), $last['declarations'] );
    }

    /** @return array<string,array{string,array,string}> */
    public static function leftoverCases(): array
    {
        $a = [ 'tag' => 'li', 'ids' => [ 'a' ], 'classes' => [ 'x', 'y' ] ];

        return [
            'nth-child of, no space before #'  => [ '#a { color: blue } li:nth-child(odd of#a) { color: red }', $a, 'color' ],
            'nth-child of, no space before .'  => [ '#a { color: blue } li:nth-child(odd of.x.y) { color: red }', $a, 'color' ],
            'nth-child of, bracket'            => [ '#a { color: blue } li:nth-last-child(2n+1 of [id]) { color: red }', $a, 'color' ],
            'nth-child of, star'               => [ '#a { color: blue } li:nth-child(odd of*) { color: red }', $a, 'color' ],
            'forgiving :is with a bad item'    => [ '#a { color: blue } :is(*|p#a#b, li) { color: red }', $a, 'color' ],
            'forgiving :matches bad item'      => [ '#a { color: blue } :matches(*|p#a#b, li) { color: red }', $a, 'color' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'leftoverCases' )]
    public function testASpecificityThisReaderCannotComputeIsTheSentinel( string $css, array $element, string $property ): void
    {
        $this->assertStringStartsWith( 'POISON:', $this->winner( $css, $element, $property ) );
    }

    public function testNthChildWithoutOfKeepsItsSpecificity(): void
    {
        $r = HtmlCss::stylesheet( 'li:nth-child(2n+1) { color: red } li:nth-child(odd) { color: red }' );
        $this->assertSame( [ [ 0, 1, 1 ], [ 0, 1, 1 ] ], array_column( $r['rules'], 'specificity' ) );
        $r = HtmlCss::stylesheet( ':is(h1, h2) { color: red }' );
        $this->assertSame( [ 0, 0, 1 ], $r['rules'][0]['specificity'] );
    }

    public function testMoreEffectsAndAnimationsAreMarkers(): void
    {
        foreach ( [ '-webkit-filter: blur(1px)', 'backdrop-filter: blur(4px)', '-webkit-backdrop-filter: blur(4px)' ] as $css ) {
            $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ) ], $this->block( $css ), $css );
        }
        foreach ( [ 'animation: f 1ms forwards', 'animation-name: f' ] as $css ) { // review round 2: an animation may also scale
            $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ), $this->decl( 'scale', null, false, 'effect' ) ], $this->block( $css ), $css );
        }
        foreach ( [ '-webkit-filter: none', 'backdrop-filter: none', 'animation: none', 'animation-name: none' ] as $css ) {
            $this->assertSame( [], $this->block( $css ), $css );
        }
        $css = '@keyframes f { to { color: red } } p { color: blue; animation: f 1ms forwards }';
        $r   = HtmlCss::stylesheet( $css );
        $this->assertCount( 1, $r['rules'] );
        $this->assertSame( [ 'color', 'effect', 'scale' ], array_column( $r['rules'][0]['declarations'], 'property' ) );
        $this->assertSame( 'POISON:effect', $this->winner( $css, [ 'tag' => 'p' ], 'effect' ) );
        $this->assertSame( 1, $r['ignored']['at_rule'] );
    }

    public function testAnEscapedImportantIsCountedAsUnresolved(): void
    {
        $r = HtmlCss::stylesheet( 'p { color: red !imp\\ortant }' );
        $this->assertSame( [ 'unresolved' => 1 ], $r['ignored'] );
        $ignored = [];
        HtmlCss::declarations( 'col\\6f r: red', $ignored );
        $this->assertSame( [ 'unresolved' => 1 ], $ignored );
    }

    public function testBothCapsCoverWithOneSentinel(): void
    {
        $css = str_repeat( '.c{color:red}', HtmlCss::MAX_RULES + 1 ) . str_repeat( ' ', HtmlCss::MAX_CSS_BYTES );
        $r   = HtmlCss::stylesheet( $css );
        $this->assertSame( 2, $r['ignored']['css_cap'] );
        $this->assertCount( HtmlCss::MAX_RULES + 1, $r['rules'] );
    }


    // ---------------------------------------------------------------
    // Round 3: subject keys, nested at-rule copies, @layer sharing, more markers
    // ---------------------------------------------------------------

    /** @return array<string,array{string,array,string}> */
    public static function keyCollisionCases(): array
    {
        return [
            'escaped comma vs two classes'     => [ 'span { color: blue } div { .a\\,b, .a.b { color: red } }', [ 'tag' => 'span', 'classes' => [ 'a,b' ] ], 'color' ],
            'escaped comma, other order'       => [ 'span { color: blue } div { .a.b, .a\\,b { color: red } }', [ 'tag' => 'span', 'classes' => [ 'a,b' ] ], 'color' ],
            'two classes (the other subject)'  => [ 'span { color: blue } div { .a\\,b, .a.b { color: red } }', [ 'tag' => 'span', 'classes' => [ 'a', 'b' ] ], 'color' ],
            'tag * vs any tag'                 => [ 'p { color: blue } div { .c, \\*.c { color: red } }', [ 'tag' => 'p', 'classes' => [ 'c' ] ], 'color' ],
            'id and class with an escaped bar' => [ 'p { color: blue } div { #x\\|.y, #x.\\|y { color: red } }', [ 'tag' => 'p', 'ids' => [ 'x|' ], 'classes' => [ 'y' ] ], 'color' ],
            'id and class, other subject'      => [ 'p { color: blue } div { #x\\|.y, #x.\\|y { color: red } }', [ 'tag' => 'p', 'ids' => [ 'x' ], 'classes' => [ '|y' ] ], 'color' ],
            'collision in the parents'         => [ 'p { color: blue } .a\\,b, .a.b { &.z { color: red } }', [ 'tag' => 'p', 'classes' => [ 'a,b', 'z' ] ], 'color' ],
            'collision in the parents (2)'     => [ 'p { color: blue } .a\\,b, .a.b { &.z { color: red } }', [ 'tag' => 'p', 'classes' => [ 'a', 'b', 'z' ] ], 'color' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'keyCollisionCases' )]
    public function testDifferentSubjectsAreNeverMergedByTheirKey( string $css, array $element, string $property ): void
    {
        $this->assertStringStartsWith( 'POISON:', $this->winner( $css, $element, $property ), 'the lower value must not be carried' );
    }

    public function testKeyControlsOnlyTheRealSubjectPoisonsAndDuplicatesStillCollapse(): void
    {
        // Only .a\,b is poisoned: an element with the classes a and b (not "a,b") keeps the lower value.
        $this->assertSame( '#0000ff', $this->winner( 'span { color: blue } div { .a\\,b { color: red } }', [ 'tag' => 'span', 'classes' => [ 'a', 'b' ] ], 'color' ) );
        $this->assertSame( '#0000ff', $this->winner( 'p { color: blue } div { #x\\|.y { color: red } }', [ 'tag' => 'p', 'ids' => [ 'x' ], 'classes' => [ '|y' ] ], 'color' ) );
        // Identical subjects collapse to one entry.
        $r = HtmlCss::stylesheet( 'div { color: blue; .x, .x, .x { color: red } }' );
        $this->assertCount( 2, $r['rules'] );
        $r = HtmlCss::stylesheet( 'div, div { b { color: red } }' );
        $this->assertCount( 1, $r['rules'] );
    }

    public function testNestedAtRulesUnderManyParentsStayCheap(): void
    {
        $list = static fn( string $p, int $n ): string => implode( ',', array_map( static fn( int $i ): string => $p . $i, range( 1, $n ) ) );
        foreach ( [
            $list( '.a', 2000 ) . '{' . str_repeat( '@media x{@media y{&{}}}', 1500 ) . '}',
            $list( '.a', 2000 ) . '{' . str_repeat( str_repeat( '@media x{', 7 ) . '&{}' . str_repeat( '}', 7 ), 300 ) . '}',
            $list( '.a', 2000 ) . '{' . str_repeat( '@media x{color:red}', 1500 ) . '}',
        ] as $css ) {
            [ $seconds, $megabytes, $r ] = $this->measure( $css );
            $this->assertLessThan( 0.2, $seconds );
            $this->assertLessThan( 20.0, $megabytes );
            $this->assertLessThanOrEqual( HtmlCss::MAX_RULES + 1, count( $r['rules'] ) );
        }
    }

    public function testALayerRuleSharesItsSplitAcrossEntries(): void
    {
        $list = implode( ',', array_map( static fn( int $i ): string => '.a' . $i, range( 1, 2000 ) ) );
        $css  = '@layer x{' . $list . '{' . str_repeat( 'all:red;all:red!important;', 25 ) . '}}';
        [ $seconds, $megabytes, $r ] = $this->measure( $css );
        $this->assertLessThan( 8.0, $megabytes, 'peak memory' );
        $this->assertLessThan( 0.2, $seconds );
        $this->assertSame( 'POISON:unresolved', $this->winner( $css, [ 'tag' => 'p', 'classes' => [ 'a5' ] ], 'color' ) );
    }

    public function testMasksTextShadowsAndStrokesAreEffectMarkers(): void
    {
        foreach ( [ 'mask: url(a.svg)', '-webkit-mask: linear-gradient(red, blue)', 'mask-image: url(a.svg)', '-webkit-mask-image: url(a.svg)', 'mask-size: cover',
            'text-shadow: 0 0 2px #fff', '-webkit-text-stroke: 2px red', '-webkit-text-stroke-width: 3px', '-webkit-text-stroke-color: #fff', 'text-shadow: inherit' ] as $css ) {
            $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ) ], $this->block( $css ), $css );
        }
        foreach ( [ 'mask: none', 'mask-image: none', 'text-shadow: none', '-webkit-text-stroke-width: 0' ] as $css ) {
            $this->assertSame( [], $this->block( $css ), $css );
        }
        $this->assertSame( [ $this->decl( 'effect', null, true, 'effect' ) ], $this->block( 'text-shadow: 0 0 1px red !important' ) );
        $this->assertSame( 'POISON:effect', $this->winner( 'p { color: #fff; text-shadow: 0 0 3px #000 }', [ 'tag' => 'p' ], 'effect' ) );
    }

    /** Task 6 review C2: scope proximity ranks between specificity and order, so every declaration inside @scope has an unknown rank. */
    public function testScopedRulesHaveTheSentinelRank(): void
    {
        foreach ( [ '@scope (.card) { p { color: #f00 } } p { color: #00f }', '@scope (.a) to (.b) { p { color: #f00 } } p { color: #00f }', '@scope { p { color: #f00 } } p { color: #00f }' ] as $css ) {
            $r = HtmlCss::stylesheet( $css );
            $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][0]['specificity'], $css );
            $this->assertTrue( $r['rules'][0]['poison_only'], $css );
            $this->assertSame( 'p', $r['rules'][0]['subject']['tag'], $css );
            $this->assertSame( [ 0, 0, 1 ], $r['rules'][1]['specificity'], 'the rule after @scope is unaffected' );
            $this->assertStringStartsWith( 'POISON', $this->winner( $css, [ 'tag' => 'p' ], 'color' ), $css );
        }
        $this->assertSame( [ 0, 1, 0 ], HtmlCss::stylesheet( '@media (min-width:1px) { .x { color: red } }' )['rules'][0]['specificity'], '@media keeps its specificity' );
    }

    /** Task 6 review C9: a quoted family keeps its inner spaces (a browser does), and the generic keywords are lowercase (a browser serialises them so). */
    public function testFamilyNamesAreKeptAsTheBrowserComputesThem(): void
    {
        $this->assertSame( [ 'Open  Sans' ], HtmlCss::families( '"Open  Sans"' ) );
        $this->assertSame( [ 'Open Sans' ], HtmlCss::families( 'Open   Sans' ), 'unquoted words are joined by one space' );
        $this->assertSame( [ 'Arial', 'sans-serif', 'monospace', 'system-ui' ], HtmlCss::families( 'Arial, SANS-SERIF, MonoSpace, System-UI' ) );
        $this->assertSame( [ 'SANS-SERIF' ], HtmlCss::families( '"SANS-SERIF"' ), 'a quoted name is a family name, not the keyword' );
    }

    /** Task 6 review I3 (round 2): presence, whatever the value: no "safe" transform (rotateX, translateZ under a perspective ... scale). */
    public function testZoomScaleAndTransformsAreScaleMarkers(): void
    {
        $this->assertContains( 'scale', HtmlCss::MARKERS );
        foreach ( [ 'zoom: 1.5', 'zoom: 1', 'zoom: normal', 'scale: 2', 'scale: none', 'transform: scale(2)', 'transform: none', 'transform: translateY(-50%)',
            'transform: rotateX(60deg)', 'transform: rotate3d(1,0,0,60deg)', 'transform: translateZ(50px)', 'transform: translate3d(0,0,50px)', 'rotate: x 60deg',
            'translate: 0 0 50px', 'perspective: 100px', 'perspective: none', 'font-size-adjust: 1', 'font-size-adjust: none', '-webkit-transform: scale(2)',
            '-webkit-zoom: 2', '-webkit-text-size-adjust: 200%', 'transform: var(--t)', 'zoom: inherit', 'TRANSFORM: SCALE(2)' ] as $css ) {
            $this->assertSame( [ $this->decl( 'scale', null, false, 'effect' ) ], $this->block( $css ), $css );
        }
        $this->assertSame( [ $this->decl( 'scale', null, true, 'effect' ) ], $this->block( 'zoom: 2 !important' ) );
        $this->assertSame( [ $this->decl( 'effect', null, false, 'effect' ), $this->decl( 'scale', null, false, 'effect' ) ], $this->block( 'animation: z 1s' ) );
        $this->assertSame( [], $this->block( 'animation: none; transition: all 1s' ) );
    }

    // ---------------------------------------------------------------
    // Caps
    // ---------------------------------------------------------------

    public function testCapsAreEnforcedAndCounted(): void
    {
        // Bytes: the rest is not read.
        $r = HtmlCss::stylesheet( 'p { color: red }' . str_repeat( ' ', HtmlCss::MAX_CSS_BYTES ) . 'h1 { color: blue }' );
        $this->assertSame( [ 'css_cap' => 1 ], $r['ignored'] );
        $this->assertCount( 2, $r['rules'], 'the rule and the poison that covers the unseen rest' );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][1]['specificity'] );

        // Rules.
        $css = '';
        for ( $i = 0; $i < HtmlCss::MAX_RULES + 1; $i++ ) {
            $css .= '.c' . $i . '{color:red}';
        }
        $r = HtmlCss::stylesheet( $css );
        $this->assertCount( HtmlCss::MAX_RULES + 1, $r['rules'], 'the rules kept and the poison that covers the unseen rest' );
        $this->assertSame( [ 'css_cap' => 1 ], $r['ignored'] );
        $this->assertSame( HtmlCss::MAX_SPECIFICITY, $r['rules'][ HtmlCss::MAX_RULES ]['specificity'] );
        $this->assertCount( count( HtmlCss::ALL ), $r['rules'][ HtmlCss::MAX_RULES ]['declarations'] );
        $this->assertSame( 'POISON:unresolved', $this->winner( $css, [ 'tag' => 'p', 'classes' => [ 'c2000' ] ], 'color' ) );
        $this->assertSame( 'c1999', $r['rules'][ HtmlCss::MAX_RULES - 1 ]['subject']['classes'][0] );
        $this->assertSame( HtmlCss::MAX_RULES - 1, $r['rules'][ HtmlCss::MAX_RULES - 1 ]['order'] );
        $r = HtmlCss::stylesheet( substr( $css, 0, (int) strrpos( $css, '.c2000' ) ) );
        $this->assertCount( HtmlCss::MAX_RULES, $r['rules'] );
        $this->assertSame( [], $r['ignored'], 'exactly the cap is not over it' );

        // A selector longer than the cap is not understood: it poisons and is counted.
        $r = HtmlCss::stylesheet( str_repeat( 'a', HtmlCss::MAX_SELECTOR + 1 ) . ' { color: red }' );
        $this->assertSame( [ 'selector' => 1 ], $r['ignored'] );
        $this->assertCount( 1, $r['rules'] );
        $this->assertTrue( $r['rules'][0]['poison_only'] );
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ], $r['rules'][0]['subject'] );
        $r = HtmlCss::stylesheet( '.' . str_repeat( 'a', HtmlCss::MAX_SELECTOR - 1 ) . ' { color: red }' );
        $this->assertSame( [], $r['ignored'] );
        $this->assertTrue( $r['rules'][0]['simple'] );

        // Declarations per block: the rest is replaced by a poison of every read property, important.
        $ignored = [];
        $out     = HtmlCss::declarations( str_repeat( 'color: red;', HtmlCss::MAX_DECLARATIONS + 1 ), $ignored );
        $this->assertSame( [ 'css_cap' => 1 ], $ignored );
        $this->assertCount( HtmlCss::MAX_DECLARATIONS + count( HtmlCss::ALL ), $out );
        $this->assertSame( 'unresolved', $out[ HtmlCss::MAX_DECLARATIONS ]['poison'] );
        $this->assertTrue( $out[ HtmlCss::MAX_DECLARATIONS ]['important'] );
        $ignored = [];
        $this->assertCount( HtmlCss::MAX_DECLARATIONS, HtmlCss::declarations( str_repeat( 'color: red;', HtmlCss::MAX_DECLARATIONS ), $ignored ) );
        $this->assertSame( [], $ignored );

        // A value longer than the cap is unreadable, whatever it is.
        $out = $this->block( 'font-family: ' . str_repeat( 'a', HtmlCss::MAX_VALUE + 1 ) );
        $this->assertSame( [ $this->decl( 'font-family', null, true, 'unreadable' ) ], $out );
        $this->assertCount( 4, $this->block( 'padding: ' . str_repeat( '1', HtmlCss::MAX_VALUE + 1 ) ) );

        // The declarations() text is cut at the byte cap too.
        $ignored = [];
        HtmlCss::declarations( str_repeat( ' ', HtmlCss::MAX_CSS_BYTES + 5 ), $ignored );
        $this->assertSame( [ 'css_cap' => 1 ], $ignored );
    }

    public function testTheNamedColourListIsCssColourFour(): void
    {
        $this->assertCount( 148, HtmlCss::NAMED );
        foreach ( HtmlCss::NAMED as $name => $hex ) {
            $this->assertSame( strtolower( $name ), $name );
            $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $hex, $name );
        }
        $this->assertArrayNotHasKey( 'transparent', HtmlCss::NAMED );
        $this->assertSame( '#663399', HtmlCss::NAMED['rebeccapurple'] );
        $this->assertSame( '#f0f8ff', HtmlCss::NAMED['aliceblue'] );
        $this->assertSame( HtmlCss::NAMED['gray'], HtmlCss::NAMED['grey'] );
        $this->assertSame( '#808080', HtmlCss::NAMED['gray'] );
        $this->assertSame( '#ff0000', HtmlCss::NAMED['red'] );
        $this->assertSame( '#ffffff', HtmlCss::NAMED['white'] );
        $this->assertSame( '#000000', HtmlCss::NAMED['black'] );
        $this->assertSame( '#00ffff', HtmlCss::NAMED['aqua'] );
        $this->assertSame( HtmlCss::NAMED['aqua'], HtmlCss::NAMED['cyan'] );
        $this->assertSame( HtmlCss::NAMED['fuchsia'], HtmlCss::NAMED['magenta'] );
        $this->assertSame( '#9acd32', HtmlCss::NAMED['yellowgreen'] );
    }

    public function testTheReasonsAreAClosedList(): void
    {
        $this->assertSame( count( HtmlCss::REASONS ), count( array_unique( HtmlCss::REASONS ) ) );
        $this->assertContains( 'css_cap', HtmlCss::REASONS );
        $this->assertContains( 'button_ghosted', HtmlCss::REASONS );
        $source = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/HtmlCss.php' );
        preg_match_all( "/bump\( [^,]+, '([a-z_]+)'\s*[,)]/", $source, $m );
        $this->assertSame( [], array_values( array_diff( array_unique( $m[1] ), HtmlCss::REASONS ) ), 'every counted reason is in REASONS' );
        preg_match_all( "/(?:poisons\(\s*[^,]+,\s*|entry\( [^,]+, null, [^,]+, |poisonKind\( )'([a-z_]+)'/", $source, $m );
        $this->assertSame( [], array_values( array_diff( array_unique( $m[1] ), HtmlCss::REASONS ) ), 'every poison reason is in REASONS' );
    }

    public function testNothingInTheClassCanReachTheNetworkOrTheFilesystem(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/HtmlCss.php' );
        $code   = (string) preg_replace( '~/\*.*?\*/~s', '', $source );
        foreach ( [ 'file_get_contents', 'file_put_contents', 'fopen', 'readfile', 'curl_\w*', 'wp_remote\w*', 'fsockopen', 'stream_\w*', 'include(?:_once)?', 'require(?:_once)?', 'eval', 'exec', 'shell_exec', 'system', 'passthru', 'unserialize', 'mt_rand', 'rand', 'random_int', 'time', 'date', 'microtime', 'hrtime', 'number_format', 'setlocale', 'DOMDocument', 'simplexml\w*', 'libxml\w*', 'file', 'glob', 'unlink' ] as $needle ) {
            $this->assertSame( 0, preg_match( '/\b' . $needle . '\s*\(/', $code ), $needle );
        }
        $this->assertStringNotContainsString( 'http', $code );
    }

    // ---------------------------------------------------------------
    // Fuzz
    // ---------------------------------------------------------------

    /** A tiny seeded generator (no mt_rand: its sequence must not depend on the PHP version). */
    private function next( int &$state ): int
    {
        $state = ( $state * 1103515245 + 12345 ) & 0x7FFFFFFF;

        return $state >> 8;
    }

    /** @return list<string> */
    private function garbage( int $count ): array
    {
        $pieces = [ '{', '}', '{', '}', ';', ':', ',', '"', "'", '(', ')', '[', ']', '/*', '*/', '\\', '@media', '@media (min-width: 1px) {', '@import url(http://example.com/x.css);',
            '@font-face {', '@keyframes k {', '@supports (x:y) {', '<!--', '-->', "\0", "\n", ' ', ' ', 'color', 'background', 'background-color', 'font', 'font-family', 'font-size', 'font-weight',
            'text-align', 'padding', 'padding-top', 'border-radius', 'max-width', 'opacity', 'all', '--x', 'margin', 'red', '#abc', '#12345678', 'rgb(', 'rgb(1,2,3)', 'rgba(1,2,3,.5)', 'hsl(0 0% 0%)',
            'var(--a)', 'calc(1px + 2px)', 'url(a.png)', 'linear-gradient(red,blue)', 'inherit', 'initial', '!important', '! important', '1px', '2.5rem', '150%', '-3px', '1e9', '"Open Sans"', 'serif',
            'bold', '900', 'center', 'h1', 'p', '.card', '#hero', 'a:hover', 'a:not(.x)', '::before', '&', '> ', '+ ', '~ ', '*', '@layer x {', '@layer a, b;', '@media all {', '@container (min-width: 1px) {', '@scope (.a) {', '.hero {', 'b {', '&:hover {', '&.x {', '& p {', 'a:is(:is(:is(#a)))', 'li:nth-child(odd of #a)', ':-webkit-any(p)', '::-moz-selection', '::selection',
            'mask-image', 'text-shadow', '-webkit-text-stroke', '.a\\,b', '\\*.c', '#x\\|.y', 'background-image', 'color-scheme', 'direction', 'opacity', '-webkit-text-fill-color', 'background-clip', 'text', 'filter', 'url(x.png)', 'col\\6f r', '!imp\\ortant', '@\\6d edia', '\\3a ', 'inherit, serif', 'default', "\xFF\xFE", "\xC3", 'é', '@', '.', '#', '|', '=', '!', "\r\n" ];
        $n     = count( $pieces );
        $out   = [];
        $state = 20261022;
        for ( $i = 0; $i < $count; $i++ ) {
            $len = 1 + $this->next( $state ) % 70;
            $s   = '';
            for ( $j = 0; $j < $len; $j++ ) {
                $s .= $pieces[ $this->next( $state ) % $n ];
            }
            $out[] = $s;
        }
        // Raw random bytes.
        for ( $i = 0; $i < 60; $i++ ) {
            $s = '';
            for ( $j = 0, $len = 1 + $this->next( $state ) % 200; $j < $len; $j++ ) {
                $s .= chr( $this->next( $state ) % 256 );
            }
            $out[] = $s;
        }
        // Shapes that stress the scanners.
        $out[] = str_repeat( '{', 10 * 1024 * 1024 );
        $out[] = str_repeat( '}', 200000 );
        $out[] = str_repeat( '(', 150000 );
        $out[] = str_repeat( '"', 150000 );
        $out[] = str_repeat( 'a{', 60000 );
        $out[] = str_repeat( '@media{', 30000 );
        $out[] = str_repeat( 'a{b{', 20000 );
        $out[] = str_repeat( ';', 150000 );
        $out[] = str_repeat( '/*', 80000 );
        $out[] = str_repeat( '\\', 150001 );
        $out[] = str_repeat( 'a,', 80000 ) . '{color:red}';
        $out[] = 'p{' . str_repeat( 'color:red;', 20000 ) . '}';
        $out[] = 'p{padding:' . str_repeat( '1px ', 20000 ) . '}';
        $out[] = 'p{color:' . str_repeat( ' ', 150000 ) . '!important}';
        $out[] = str_repeat( 'a:not(a:not(a:not(a:not(', 4000 );
        $out[] = str_repeat( '@media all {', 20 ) . 'p { color: red }' . str_repeat( '}', 20 ) . ' p { color: blue }';
        $out[] = str_repeat( '@layer x { p { color: red !important } } ', 900 );
        $out[] = str_repeat( 'a { ', 12 ) . 'color: red;' . str_repeat( ' & b { color: blue }', 40 ) . str_repeat( ' }', 12 );
        $out[] = str_repeat( '.a { & .b { @media (x) { &:hover { color: red } } } } ', 800 );
        $out[] = str_repeat( 'p { col\\6f r: red; opacity: .5; color-scheme: dark } ', 1500 );
        $out[] = str_repeat( ':is(', 3000 ) . 'p' . str_repeat( ')', 3000 ) . ' { color: red }';
        $out[] = 'p,p,p,p,p,p{' . str_repeat( '&,&,&,&,&,&{', 7 ) . 'color:red' . str_repeat( '}', 8 );
        $out[] = str_repeat( '.a,', 600 ) . '.a{' . str_repeat( '&.b,&.c,&.d,&.e,&.f,&.g{', 8 ) . 'color:red' . str_repeat( '}', 9 );
        $out[] = 'p{' . str_repeat( 'b{}', 40000 ) . '}';
        $out[] = str_repeat( 'li:nth-child(odd of#a),:is(*|p#a),', 800 ) . 'p{color:red}';
        $out[] = str_repeat( 'a{animation:f 1s;backdrop-filter:blur(1px);-webkit-filter:blur(1px)}', 1500 );
        $out[] = str_repeat( '.a ', 60000 ) . '{color:red}';

        return $out;
    }

    private function assertNormalised( array $d, string $label ): void
    {
        $this->assertSame( [ 'property', 'value', 'important', 'poison' ], array_keys( $d ), $label );
        $this->assertContains( $d['property'], HtmlCss::ALL, $label );
        $this->assertIsBool( $d['important'], $label );
        if ( null !== $d['poison'] ) {
            $this->assertNull( $d['value'], $label );
            $this->assertContains( $d['poison'], HtmlCss::REASONS, $label );

            return;
        }
        $v = $d['value'];
        $this->assertIsString( $v, $label );
        $length = '/^\d{1,7}(?:\.\d{1,6})?(?:px|rem|em|%)$/';
        if ( 'inherit' === $v ) {
            $this->assertContains( $d['property'], [ 'color', 'font-family', 'font-size', 'font-weight', 'text-align' ], $label );

            return;
        }
        switch ( $d['property'] ) {
            case 'color':
                $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $v, $label );
                break;
            case 'background-color':
                $this->assertMatchesRegularExpression( '/^(?:#[0-9a-f]{6}|none)$/', $v, $label );
                break;
            case 'font-family':
                $names = explode( ', ', $v );
                $this->assertLessThanOrEqual( 10, count( $names ), $label );
                foreach ( $names as $name ) {
                    $this->assertMatchesRegularExpression( '/^-?[\p{L}\p{N}_][\p{L}\p{N} _.+&-]{0,99}$/u', $name, $label );
                }
                break;
            case 'font-size':
                $this->assertMatchesRegularExpression( $length, $v, $label );
                break;
            case 'font-weight':
                $this->assertMatchesRegularExpression( '/^[1-9]00$/', $v, $label );
                break;
            case 'text-align':
                $this->assertContains( $v, [ 'left', 'right', 'center', 'justify' ], $label );
                break;
            case 'max-width':
                $this->assertTrue( 'none' === $v || 1 === preg_match( $length, $v ) && ! str_ends_with( $v, '%' ), $label );
                break;
            default:
                $this->assertMatchesRegularExpression( $length, $v, $label );
                $this->assertStringEndsNotWith( '%', $v, $label );
        }
    }

    public function testGarbageNeverThrowsAndIsDeterministic(): void
    {
        $inputs = $this->garbage( 560 );
        $this->assertGreaterThanOrEqual( 500, count( $inputs ) );
        $maxEntries = HtmlCss::MAX_DECLARATIONS * count( HtmlCss::ALL ) + count( HtmlCss::ALL );
        foreach ( $inputs as $k => $css ) {
            $label = 'input ' . $k . ' (' . strlen( $css ) . ' bytes)';

            $first  = HtmlCss::stylesheet( $css );
            $second = HtmlCss::stylesheet( $css );
            $this->assertSame( $first, $second, $label . ' is deterministic' );
            $this->assertLessThanOrEqual( HtmlCss::MAX_RULES + 1, count( $first['rules'] ), $label );
            foreach ( $first['ignored'] as $reason => $count ) {
                $this->assertContains( $reason, HtmlCss::REASONS, $label );
                $this->assertIsInt( $count, $label );
                $this->assertGreaterThan( 0, $count, $label );
            }
            foreach ( $first['rules'] as $i => $rule ) {
                $this->assertSame( [ 'subject', 'simple', 'specificity', 'poison_only', 'order', 'declarations' ], array_keys( $rule ), $label );
                $this->assertSame( $i, $rule['order'], $label );
                $this->assertSame( [ 'tag', 'ids', 'classes', 'other' ], array_keys( $rule['subject'] ), $label );
                $this->assertTrue( null === $rule['subject']['tag'] || is_string( $rule['subject']['tag'] ), $label );
                $this->assertCount( 3, $rule['specificity'], $label );
                if ( HtmlCss::MAX_SPECIFICITY === $rule['specificity'] ) {
                    $this->assertTrue( $rule['poison_only'] && ! $rule['simple'], $label . ' a sentinel rule never applies' );
                } else {
                    $this->assertLessThan( 1000000, $rule['specificity'][0], $label );
                }
                $this->assertTrue( ! $rule['simple'] || ( 1 === count( $rule['subject']['ids'] ) + count( $rule['subject']['classes'] ) + ( null === $rule['subject']['tag'] ? 0 : 1 ) && ! $rule['subject']['other'] ), $label );
                $this->assertNotSame( [], $rule['declarations'], $label );
                $this->assertLessThanOrEqual( $maxEntries, count( $rule['declarations'] ), $label );
                foreach ( $rule['declarations'] as $d ) {
                    $this->assertNormalised( $d, $label );
                }
            }

            $a1 = [];
            $a2 = [];
            $d1 = HtmlCss::declarations( $css, $a1 );
            $d2 = HtmlCss::declarations( $css, $a2 );
            $this->assertSame( $d1, $d2, $label . ' declarations are deterministic' );
            $this->assertSame( $a1, $a2, $label );
            foreach ( $a1 as $reason => $count ) {
                $this->assertContains( $reason, HtmlCss::REASONS, $label );
            }
            foreach ( $d1 as $d ) {
                $this->assertNormalised( $d, $label . ' (declarations)' );
            }

            // The value readers on the raw text too.
            foreach ( [ 'colour', 'background' ] as $method ) {
                $res = HtmlCss::$method( $css );
                $this->assertContains( $res['kind'], [ 'colour', 'none', 'poison' ], $label );
                if ( 'colour' === $res['kind'] ) {
                    $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $res['hex'], $label );
                } elseif ( 'poison' === $res['kind'] ) {
                    $this->assertContains( $res['reason'], HtmlCss::REASONS, $label );
                }
            }
            $len = HtmlCss::length( $css, 16.0, true );
            $this->assertTrue( null === $len || ( is_float( $len ) && $len >= 0 && is_finite( $len ) ), $label );
            HtmlCss::families( $css );
            $w = HtmlCss::weight( $css );
            $this->assertTrue( null === $w || ( $w >= 100 && $w <= 900 && 0 === $w % 100 ), $label );
            $this->assertContains( HtmlCss::align( $css ), [ null, 'left', 'right', 'center', 'justify' ], $label );
        }
    }
}
