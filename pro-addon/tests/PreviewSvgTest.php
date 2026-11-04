<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\Palette;
use AiEditorDivi5\Pro\MegaMenu\PreviewSvg;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PreviewSvgTest extends TestCase
{
    /** The preview with its aria-label removed, so only the drawing is compared. */
    private static function drawing( string $svg ): string
    {
        return (string) preg_replace( '/ aria-label="[^"]*"/', '', $svg );
    }

    public function testTheTwentyFourPreviewsDifferInTheirDrawingNotOnlyTheirName(): void
    {
        $drawings = [];
        foreach ( Layouts::all() as $layout ) {
            $drawings[ $layout['id'] ] = self::drawing( PreviewSvg::for( $layout ) );
        }
        $this->assertCount( 24, $drawings );
        $this->assertCount( 24, array_unique( $drawings ), 'two layouts draw the same wireframe' );

        $ids = array_keys( $drawings );
        for ( $i = 0; $i < count( $ids ); $i++ ) {
            for ( $j = $i + 1; $j < count( $ids ); $j++ ) {
                $this->assertNotSame( $drawings[ $ids[ $i ] ], $drawings[ $ids[ $j ] ], $ids[ $i ] . ' and ' . $ids[ $j ] );
            }
        }
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function layouts(): iterable
    {
        foreach ( Layouts::all() as $layout ) {
            yield $layout['id'] => [ $layout ];
        }
    }

    /** @param array<string,mixed> $layout */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'layouts' )]
    public function testEachPreviewIsDeterministicWellFormedAndNamesItsLayout( array $layout ): void
    {
        $svg = PreviewSvg::for( $layout );
        $this->assertSame( $svg, PreviewSvg::for( $layout ), 'same input, same bytes' );
        $this->assertStringStartsWith( '<svg viewBox="0 0 240 150" role="img" aria-label="' . $layout['name'] . '">', $svg );

        $doc = new \DOMDocument();
        $this->assertTrue( $doc->loadXML( $svg ), 'the preview is well-formed XML' );
        $this->assertSame( 'svg', $doc->documentElement->nodeName );
    }

    /** @param array<string,mixed> $layout */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'layouts' )]
    public function testNoPreviewHasScriptsLinksHandlersOrPriceNumbers( array $layout ): void
    {
        $svg = PreviewSvg::for( $layout );
        $this->assertStringNotContainsString( '<script', $svg );
        $this->assertStringNotContainsString( 'http', $svg );
        $this->assertSame( 0, preg_match( '/\son[a-z]+\s*=/i', $svg ), 'an inline event handler' );
        $this->assertSame( 0, preg_match( '/\b(49|99|149|449)\b/', $svg ), 'a price-like number' );
    }

    public function testTheDarkToneUsesTheDarkPaletteAndTheLightToneDoesNot(): void
    {
        $dark  = Palette::tone( 'dark' )['bg'];
        $light = Palette::tone( 'light' )['bg'];
        $this->assertStringContainsString( 'fill="' . $dark . '"', PreviewSvg::for( Layouts::find( 'columns-dark' ) ) );
        $this->assertStringNotContainsString( $dark, PreviewSvg::for( Layouts::find( 'list-plain' ) ) );
        $this->assertStringContainsString( 'fill="' . $light . '"', PreviewSvg::for( Layouts::find( 'list-plain' ) ) );
    }

    public function testTheNameIsEscapedInTheLabel(): void
    {
        $layout         = Layouts::find( 'list-plain' );
        $layout['name'] = 'Q "one" & <two>';
        $svg            = PreviewSvg::for( $layout );
        $this->assertStringContainsString( 'aria-label="Q &quot;one&quot; &amp; &lt;two&gt;"', $svg );
        $this->assertNotFalse( ( new \DOMDocument() )->loadXML( $svg ) );
    }
}
