<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The generated operation summaries and the documented responses of the Pro ChatGPT/OpenAPI face. */
class ProOpenApiTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function summaries(): array
    {
        $menu = 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the theme\'s menu location. Replaces that menu\'s items.';

        return [
            'the first sentence, without its period'     => [ 'Set a published page as the front page. Recorded as a batch.', 'Set a published page as the front page' ],
            'no period at all'                           => [ 'List recent changes', 'List recent changes' ],
            'an ellipsis inside brackets is not an end'  => [ 'Propose a PHP snippet (post type, hook, shortcode...) for the owner. Say what it does.', 'Propose a PHP snippet (post type, hook, shortcode...) for the owner' ],
            'a question mark ends it too'                => [ 'Which page? Then more.', 'Which page' ],
            'a long sentence is cut at a word boundary'  => [ $menu, 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the...' ],
            'a cut inside brackets drops the fragment'   => [ 'Enable or disable one live Divi Theme Builder template, or change where it applies (assign: use_on / exclude_from, in the ids list_theme_layouts shows). Affects the whole site.', 'Enable or disable one live Divi Theme Builder template, or change where it applies...' ],
            'a cut inside quotes drops the fragment'     => [ 'Rename the page called "' . str_repeat( 'very long title ', 10 ) . '" to something else.', 'Rename the page called...' ],
            'text with no space is cut hard'             => [ str_repeat( 'x', 200 ), str_repeat( 'x', 117 ) . '...' ],
            'whitespace is collapsed'                    => [ "Replace  text\n across   pages.", 'Replace text across pages' ],
            'a full stop inside quotes is not an end'    => [ 'Set the label to "Done. Next" for the page. More text.', 'Set the label to "Done. Next" for the page' ],
            'an unclosed bracket in a short sentence'    => [ 'Hello (', 'Hello...' ],
            'an unclosed quote and bracket'              => [ 'Say "Hi" (now.', 'Say "Hi"...' ],
            'a leading stop is kept, not emptied'        => [ '. Foo', '. Foo' ],
            'nothing but an opener is nothing'           => [ '(', '' ],
            'an empty description'                       => [ '', '' ],
            'dropping a fragment never passes 120 bytes' => [ str_repeat( 'a', 118 ) . ' (', str_repeat( 'a', 117 ) . '...' ],
        ];
    }

    #[DataProvider( 'summaries' )]
    public function testSummaries( string $description, string $expected ): void
    {
        $this->assertSame( $expected, ProOpenApi::summary( $description ) );
    }

    /** A summary never ends inside an opened bracket or quote, is valid UTF-8 and is at most 120 bytes. */
    private static function assertClean( string $summary, string $input ): void
    {
        $depth = 0;
        for ( $i = 0, $n = strlen( $summary ); $i < $n; $i++ ) {
            if ( '(' === $summary[ $i ] ) {
                ++$depth;
            } elseif ( ')' === $summary[ $i ] && $depth > 0 ) {
                --$depth;
            }
        }
        $label = json_encode( $input );
        self::assertSame( 0, $depth, "unclosed bracket in the summary of {$label}" );
        self::assertSame( 0, substr_count( $summary, '"' ) % 2, "unclosed quote in the summary of {$label}" );
        self::assertLessThanOrEqual( 120, strlen( $summary ), "too long for {$label}" );
        self::assertNotFalse( json_encode( $summary ), "invalid UTF-8 in the summary of {$label}" );
    }

    public function testASentenceThatOpensWithABracketOrQuoteDoesNotKeepTheOpener(): void
    {
        foreach ( [
            '(' . str_repeat( 'word ', 30 ) . ') tail',
            '"' . str_repeat( 'word ', 30 ) . '" tail',
            '(a (b) ' . str_repeat( 'w ', 70 ) . ')',
        ] as $input ) {
            $summary = ProOpenApi::summary( $input );
            self::assertClean( $summary, $input );
            $this->assertStringEndsWith( '...', $summary, $input );
            $this->assertNotSame( '...', $summary );
        }
    }

    public function testAMultibyteCharacterIsNeverSplit(): void
    {
        foreach ( [ str_repeat( 'é', 100 ), str_repeat( 'a', 116 ) . 'é' . str_repeat( 'b', 20 ), str_repeat( '日本語 ', 40 ) ] as $input ) {
            $summary = ProOpenApi::summary( $input );
            self::assertClean( $summary, $input );
            $this->assertStringEndsWith( '...', $summary );
        }
    }

    public function testNoInputCanProduceAnUncleanSummary(): void
    {
        mt_srand( 7 );
        $alphabet = [ 'a', 'b', ' ', ' ', '(', ')', '"', '.', '!', '?', 'é', '…', 'word', "\n", ',' ];
        for ( $n = 0; $n < 3000; $n++ ) {
            $input = '';
            for ( $k = mt_rand( 0, 170 ); $k > 0; $k-- ) {
                $input .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
            }
            self::assertClean( ProOpenApi::summary( $input ), $input );
        }
    }

    public function testEveryRealSummaryIsShortCleanAndAPrefixOfItsDescription(): void
    {
        foreach ( Tools::all() as $tool ) {
            $def     = $tool->definition();
            $summary = ProOpenApi::summary( $def['description'] );
            $text    = (string) preg_replace( '/\s+/', ' ', $def['description'] );
            $this->assertNotSame( '', $summary, $def['name'] );
            $this->assertLessThanOrEqual( 120, strlen( $summary ), $def['name'] );
            $this->assertSame( substr_count( $summary, '(' ), substr_count( $summary, ')' ), "{$def['name']}: unbalanced brackets in '{$summary}'" );
            $this->assertSame( 0, substr_count( $summary, '"' ) % 2, "{$def['name']}: unclosed quote in '{$summary}'" );
            $stem = str_ends_with( $summary, '...' ) && ! str_contains( substr( $summary, 0, -3 ), '...' ) ? substr( $summary, 0, -3 ) : $summary;
            $this->assertStringStartsWith( $stem, $text, $def['name'] );
            $next = substr( $text, strlen( $stem ), 1 );
            $this->assertTrue( '' === $next || ' ' === $next || '.' === $next, "{$def['name']}: '{$summary}' stops inside a word" );
        }
    }

    public function testEveryOperationDocumentsTheStatusesAClientCanGet(): void
    {
        $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
        $paths    = ( new ProOpenApi( $registry ) )->paths( [], 'https://example.com' );
        $this->assertCount( 13, $paths );
        foreach ( $paths as $path => $methods ) {
            $responses = $methods['post']['responses'];
            $this->assertSame( [ '200', '400', '401', '403', '500' ], array_map( 'strval', array_keys( $responses ) ), $path );
            $this->assertStringContainsString( 'authenticat', strtolower( $responses['401']['description'] ), $path );
            $this->assertStringContainsString( 'unexpectedly', $responses['500']['description'], $path );
            $this->assertSame( '#/components/schemas/ProError', $responses['500']['content']['application/json']['schema']['$ref'], $path );
            foreach ( $responses as $code => $r ) {
                $this->assertLessThanOrEqual( 300, strlen( $r['description'] ), "{$path} {$code}" );
            }
        }
    }
}
