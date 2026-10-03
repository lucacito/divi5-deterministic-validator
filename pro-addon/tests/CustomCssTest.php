<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\CustomCss;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class CustomCssTest extends TestCase
{
    public function testApplyAddsABlockAfterThePersonsOwnCssAndExtractReadsItBack(): void
    {
        $out = CustomCss::apply( "body { color: red; }\n", '.a { color: blue; }' );
        $this->assertStringStartsWith( "body { color: red; }\n\n" . CustomCss::BEGIN, $out );
        $this->assertSame( '.a { color: blue; }', CustomCss::extract( $out ) );
    }

    public function testApplyOnEmptyCssWritesJustTheBlock(): void
    {
        $out = CustomCss::apply( '', '.a{}' );
        $this->assertSame( CustomCss::BEGIN . "\n.a{}\n" . CustomCss::END, $out );
    }

    public function testApplyReplacesTheBlockInPlaceAndNeverDuplicatesIt(): void
    {
        $one = CustomCss::apply( "top{}\n", '.a{}' ) . "\n\nbottom{}";
        $two = CustomCss::apply( $one, '.b{}' );
        $this->assertSame( 1, substr_count( $two, CustomCss::BEGIN ) );
        $this->assertSame( '.b{}', CustomCss::extract( $two ) );
        $this->assertStringStartsWith( 'top{}', $two );
        $this->assertStringEndsWith( "\n\nbottom{}", $two );
    }

    public function testDollarSignsAndBackslashesInTheCssAreLiteral(): void
    {
        foreach ( [ '.a::before { content: "$1 \\0 $0 \\\\ ${x}"; }', 'a{b:c}$2', '\\1' ] as $css ) {
            $with = CustomCss::apply( CustomCss::apply( 'x{}', '.old{}' ), $css );
            $this->assertSame( $css, CustomCss::extract( $with ), $css );
        }
    }

    public function testRemovingTheBlockLeavesThePersonsCssIntact(): void
    {
        $own  = "body { color: red; }\n\n.footer { margin: 0; }";
        $with = CustomCss::apply( $own, '.a{}' );
        $this->assertSame( $own, CustomCss::apply( $with, null ) );
        $this->assertSame( $own, CustomCss::apply( $own, null ), 'no block: nothing to remove' );
        $this->assertSame( '', CustomCss::apply( CustomCss::apply( '', '.a{}' ), null ) );
    }

    public function testRemovingABlockInTheMiddleJoinsTheTwoSides(): void
    {
        $all = "top{}\n\n" . CustomCss::BEGIN . "\n.a{}\n" . CustomCss::END . "\n\nbottom{}";
        $this->assertSame( "top{}\n\nbottom{}", CustomCss::apply( $all, null ) );
    }

    public function testExtractWithoutACompleteBlockIsNull(): void
    {
        $this->assertNull( CustomCss::extract( 'body{}' ) );
        $this->assertNull( CustomCss::extract( CustomCss::BEGIN . "\n.a{}" ) );
        $this->assertSame( '', CustomCss::extract( CustomCss::BEGIN . "\n" . CustomCss::END ) );
    }

    public function testADanglingBeginIsIgnoredAndAnOwnerRuleBelowItIsNeverSwallowed(): void
    {
        $all = ".owner-a{}\n" . CustomCss::BEGIN . "\n.old{}\n.owner-b{color:red}";
        $this->assertNull( CustomCss::extract( $all ), 'no END anywhere: there is no complete block' );

        $out = CustomCss::apply( $all, '.new{}' );
        $this->assertStringStartsWith( $all, $out, 'every owner byte stays; the orphan BEGIN is just owner text' );
        $this->assertSame( '.new{}', CustomCss::extract( $out ) );
        $this->assertStringContainsString( '.owner-b{color:red}', $out );

        // removing the new block puts back exactly the owner text
        $this->assertSame( $all, CustomCss::apply( $out, null ) );
    }

    public function testADanglingBeginBeforeAProperBlockOnlyTheProperBlockIsTouched(): void
    {
        $all = ".a{}\n" . CustomCss::BEGIN . "\n.orphan{}\n.b{}\n\n" . CustomCss::BEGIN . "\n.managed{}\n" . CustomCss::END . "\n.c{}";
        $this->assertSame( '.managed{}', CustomCss::extract( $all ) );

        $out = CustomCss::apply( $all, '.next{}' );
        $this->assertStringContainsString( ".orphan{}\n.b{}", $out );
        $this->assertStringNotContainsString( '.managed{}', $out );
        $this->assertSame( '.next{}', CustomCss::extract( $out ) );
        $this->assertSame( 2, substr_count( $out, CustomCss::BEGIN ) );

        $removed = CustomCss::apply( $all, null );
        $this->assertStringContainsString( ".orphan{}\n.b{}", $removed );
        $this->assertStringContainsString( '.c{}', $removed );
        $this->assertStringNotContainsString( '.managed{}', $removed );
    }

    public function testAnOrphanEndBeforeAProperBlockIsLeftAloneAndTheProperBlockIsStillFound(): void
    {
        $all = ".a{}\n" . CustomCss::END . "\n.b{}\n" . CustomCss::BEGIN . "\n.managed{}\n" . CustomCss::END . "\n.c{}";
        $this->assertSame( '.managed{}', CustomCss::extract( $all ) );

        $out = CustomCss::apply( $all, '.x{}' );
        $this->assertSame( '.x{}', CustomCss::extract( $out ) );
        $this->assertStringStartsWith( ".a{}\n" . CustomCss::END . "\n.b{}\n" . CustomCss::BEGIN . "\n.x{}\n" . CustomCss::END, $out );
        $this->assertStringEndsWith( "\n.c{}", $out );

        $removed = CustomCss::apply( $all, null );
        $this->assertSame( ".a{}\n" . CustomCss::END . "\n.b{}\n\n.c{}", $removed );
    }

    public function testAnEndWithoutAnyBeginMeansNoBlock(): void
    {
        $all = '.a{}' . CustomCss::END . '.b{}';
        $this->assertNull( CustomCss::extract( $all ) );
        $this->assertSame( $all, CustomCss::apply( $all, null ) );
    }

    /** @return array<string,array{string}> */
    public static function badCss(): array
    {
        return [
            'empty'                 => [ '   ' ],
            'a closing style tag'   => [ '.a{} </style><script>alert(1)</script>' ],
            'a style tag'           => [ '<style>.a{}</style>' ],
            'a script tag'          => [ '.a{} <  script src=x>' ],
            'an html comment'       => [ '.a{} <!-- x' ],
            'the begin marker'      => [ '.a{} ' . CustomCss::BEGIN ],
            'the end marker'        => [ CustomCss::END . ' .a{}' ],
            'a control character'   => [ ".a{}\x00" ],
            'too large'             => [ str_repeat( 'a', CustomCss::MAX_BYTES + 1 ) ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badCss' )]
    public function testProblemRejectsCssThatCouldBreakOutOfTheBlockOrThePage( string $css ): void
    {
        $this->assertNotNull( CustomCss::problem( $css ) );
    }

    public function testProblemAcceptsOrdinaryCss(): void
    {
        $css = "@media (max-width: 600px) { .a > .b:hover::after { content: \"a < b\"; transform: translate(0, 5px); } }\n/* ok */";
        $this->assertNull( CustomCss::problem( $css ) );
    }
}
