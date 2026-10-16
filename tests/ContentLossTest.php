<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ContentLoss;
use AiEditorDivi5\WP\StyleReferences;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';

/**
 * Counting, not guessing: the thresholds were set against the real fixtures (a 4,300 to 21,900 character page has 38 to 90
 * modules; the smallest real pages have 1 to 5), so a one-line edit never trips it and an emptied page always does.
 */
class ContentLossTest extends TestCase
{
    private function page( string $name ): string
    {
        $fx = json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/valid/{$name}.json" ), true );

        return (string) $fx['post_content'];
    }

    private const EMPTY = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    public function testMeasureCountsTextImagesAndModulesAndNothingForStructure(): void
    {
        $m = ContentLoss::measure( $this->page( 'page-43-layout-1' ) );
        $this->assertSame( [ 4344, 6, 38 ], [ $m['text'], $m['images'], $m['modules'] ] );
        $this->assertSame( [ 'text' => 0, 'images' => 0, 'modules' => 0 ], ContentLoss::measure( self::EMPTY ) );
        $this->assertNull( ContentLoss::measure( 'not blocks <!-- wp:divi/section {broken' ), 'unparseable content is not guessed at' );
    }

    public function testAnEmptiedPageIsFlaggedForEveryKindItHad(): void
    {
        $a = ContentLoss::assess( $this->page( 'page-43-layout-1' ), self::EMPTY );
        $this->assertSame( [ 'text', 'images', 'modules' ], $a['lost'] );
        $this->assertSame( [ 'before' => 4344, 'after' => 0 ], $a['text'] );
        $this->assertStringContainsString( 'restore_page_version', $a['message'] );
    }

    public function testAnUnchangedPageAndASmallEditAreNotFlagged(): void
    {
        $p = $this->page( 'page-43-layout-1' );
        $this->assertNull( ContentLoss::assess( $p, $p ) );
        $first = ContentLoss::measure( $p );
        $this->assertNotNull( $first );
        $edited = preg_replace( '/("value":")[^"]{5,}?(")/', '$1A new line$2', $p, 1 );
        $this->assertNull( ContentLoss::assess( $p, (string) $edited ) );
        $this->assertNull( ContentLoss::assess( $p, $p . ' ' ), 'growing a page is never loss' );
    }

    public function testLosingAllImagesAloneIsFlaggedAsImages(): void
    {
        $p       = $this->page( 'page-43-layout-1' );
        $noImage = (string) preg_replace( '#https?://[^"\\\\]+?\.(?:png|jpe?g|gif|webp|svg)#i', '', $p );
        $a       = ContentLoss::assess( $p, $noImage );
        $this->assertNotNull( $a );
        $this->assertSame( [ 'images' ], $a['lost'] );
        $this->assertSame( 0, $a['images']['after'] );
    }

    public function testTinyPagesAreNeverJudged(): void
    {
        $tiny = $this->page( 'page-17-variable-heading' ); // one heading, 46 characters
        $this->assertNull( ContentLoss::assess( $tiny, self::EMPTY ) );
    }

    public function testExactlyHalfIsKeptAndLessThanHalfIsLost(): void
    {
        $block = static fn ( string $t ): string => '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"' . $t . '"}}},"builderVersion":"5.14.0"} /-->';
        $wrap  = static fn ( string $inner ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->' . $inner . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $before = $wrap( $block( str_repeat( 'a', 200 ) ) . $block( str_repeat( 'b', 200 ) ) . $block( 'x' ) . $block( 'y' ) );
        $this->assertNull( ContentLoss::assess( $before, $wrap( $block( str_repeat( 'a', 200 ) ) . $block( 'x' ) . $block( 'y' ) ) ), 'half the text, 3 of 4 modules: kept' );
        $this->assertSame( [ 'text', 'modules' ], ContentLoss::assess( $before, $wrap( $block( str_repeat( 'a', 150 ) ) ) )['lost'] );
    }

    public function testAttachAddsTheAdvisoryOnlyWhenThereIsAStoredPageAndItIsLost(): void
    {
        $p = $this->page( 'page-43-layout-1' );
        $this->assertArrayNotHasKey( 'content_loss', StyleReferences::attach( [ 'saved' => true ], self::EMPTY )['advisories'] ?? [], 'a create or validate has no stored page' );
        $this->assertArrayNotHasKey( 'content_loss', StyleReferences::attach( [ 'saved' => true ], $p, $p )['advisories'] ?? [] );
        $out = StyleReferences::attach( [ 'saved' => true ], self::EMPTY, $p );
        $this->assertSame( [ 'text', 'images', 'modules' ], $out['advisories']['content_loss']['lost'] );
        $this->assertTrue( $out['saved'], 'the payload itself is untouched' );
    }
}
