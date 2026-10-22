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
        $this->assertSame( [ $this->decl( 'color', null, false, 'effect' ) ], $this->block( 'opacity: .5' ) );
        $this->assertSame( [], $this->block( 'opacity: 1' ) );
        $this->assertSame( [ $this->decl( 'color', null, false, 'effect' ) ], $this->block( 'filter: blur(2px)' ) );
        $this->assertSame( [], $this->block( 'filter: none; mix-blend-mode: normal' ) );
        $this->assertSame( [ $this->decl( 'color', null, false, 'effect' ) ], $this->block( '-webkit-text-fill-color: transparent' ) );
        $this->assertSame( [ $this->decl( 'color', null, false, 'effect' ), $this->decl( 'background-color', null, false, 'effect' ) ], $this->block( 'background-clip: text' ) );
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'image_or_gradient' ) ], $this->block( 'background-image: url(a.png)' ) );
        $this->assertSame( [], $this->block( 'background-image: none' ) );
        $this->assertSame( [ $this->decl( 'padding-left', null, false, 'property' ), $this->decl( 'padding-right', null, false, 'property' ) ], $this->block( 'padding-inline: 1px' ) );
        $this->assertSame( 4, count( $this->block( '-webkit-border-radius: 3px' ) ) );
        $this->assertSame( 15, count( $this->block( 'all: unset' ) ) );
        $this->assertSame( [ $this->decl( 'max-width', null, false, 'property' ) ], $this->block( 'max-inline-size: 10px' ) );
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
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ], $r['rules'][2]['subject'], 'an & selector could match anything' );
        $this->assertSame( [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ], $r['rules'][3]['subject'] );

        // In an inline style a nested rule poisons what it sets, on the element.
        $out = $this->block( 'color: red; & p { color: blue; padding: 1px }' );
        $this->assertSame( [ $this->decl( 'color', '#ff0000' ), $this->decl( 'color', null, false, 'unresolved' ), $this->decl( 'padding-top', null, false, 'unresolved' ), $this->decl( 'padding-right', null, false, 'unresolved' ), $this->decl( 'padding-bottom', null, false, 'unresolved' ), $this->decl( 'padding-left', null, false, 'unresolved' ) ], $out );
    }

    public function testASelectorListWithAnItemThatIsNotUnderstoodPoisonsEverywhere(): void
    {
        $universal = [ 'tag' => null, 'ids' => [], 'classes' => [], 'other' => true ];
        foreach ( [ 'h1, a:made-up', 'h1, svg|a', 'p > ', 'h1, ', '.a..b', '#', 'a:-webkit-foo, h1' ] as $selector ) {
            $r = HtmlCss::stylesheet( $selector . ' { color: red }' );
            $this->assertCount( 1, $r['rules'], $selector );
            $this->assertSame( $universal, $r['rules'][0]['subject'], $selector );
            $this->assertTrue( $r['rules'][0]['poison_only'], $selector );
            $this->assertFalse( $r['rules'][0]['simple'], $selector );
        }
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
        $this->assertSame( [ $this->decl( 'background-color', null, false, 'image_or_gradient' ) ], $this->block( 'background: #fff url(a.png)' ) );
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
    // Caps
    // ---------------------------------------------------------------

    public function testCapsAreEnforcedAndCounted(): void
    {
        // Bytes: the rest is not read.
        $r = HtmlCss::stylesheet( 'p { color: red }' . str_repeat( ' ', HtmlCss::MAX_CSS_BYTES ) . 'h1 { color: blue }' );
        $this->assertSame( [ 'css_cap' => 1 ], $r['ignored'] );
        $this->assertCount( 1, $r['rules'] );

        // Rules.
        $css = '';
        for ( $i = 0; $i < HtmlCss::MAX_RULES + 1; $i++ ) {
            $css .= '.c' . $i . '{color:red}';
        }
        $r = HtmlCss::stylesheet( $css );
        $this->assertCount( HtmlCss::MAX_RULES, $r['rules'] );
        $this->assertSame( [ 'css_cap' => 1 ], $r['ignored'] );
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
        $this->assertCount( HtmlCss::MAX_DECLARATIONS + count( HtmlCss::READ ), $out );
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
            'bold', '900', 'center', 'h1', 'p', '.card', '#hero', 'a:hover', 'a:not(.x)', '::before', '&', '> ', '+ ', '~ ', '*', "\xFF\xFE", "\xC3", 'é', '@', '.', '#', '|', '=', '!', "\r\n" ];
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
        $out[] = str_repeat( '.a ', 60000 ) . '{color:red}';

        return $out;
    }

    private function assertNormalised( array $d, string $label ): void
    {
        $this->assertSame( [ 'property', 'value', 'important', 'poison' ], array_keys( $d ), $label );
        $this->assertContains( $d['property'], HtmlCss::READ, $label );
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
        $maxEntries = HtmlCss::MAX_DECLARATIONS * count( HtmlCss::READ ) + count( HtmlCss::READ );
        foreach ( $inputs as $k => $css ) {
            $label = 'input ' . $k . ' (' . strlen( $css ) . ' bytes)';

            $first  = HtmlCss::stylesheet( $css );
            $second = HtmlCss::stylesheet( $css );
            $this->assertSame( $first, $second, $label . ' is deterministic' );
            $this->assertLessThanOrEqual( HtmlCss::MAX_RULES, count( $first['rules'] ), $label );
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
