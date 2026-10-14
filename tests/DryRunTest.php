<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\PageAccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageAccess.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';

class DryRunTest extends TestCase
{
    private function fixture(): string
    {
        $j = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-7-home.json' ), true );

        return (string) $j['post_content'];
    }

    public function testBoolArgDefaultsToFalseWhenAbsentOrNull(): void
    {
        $this->assertFalse( PageAccess::boolArg( [], 'dry_run' ) );
        $this->assertFalse( PageAccess::boolArg( [ 'dry_run' => null ], 'dry_run' ) );
    }

    public function testBoolArgReturnsRealBooleans(): void
    {
        $this->assertTrue( PageAccess::boolArg( [ 'dry_run' => true ], 'dry_run' ) );
        $this->assertFalse( PageAccess::boolArg( [ 'dry_run' => false ], 'dry_run' ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notBooleans' )]
    public function testBoolArgRejectsEveryNonBoolean( mixed $value ): void
    {
        $this->assertNull( PageAccess::boolArg( [ 'dry_run' => $value ], 'dry_run' ), 'a typo must never be read as false and turn a preview into a save' );
    }

    /** @return list<array{mixed}> */
    public static function notBooleans(): array
    {
        return [ [ 'false' ], [ 'true' ], [ 0 ], [ 1 ], [ 'yes' ], [ '' ], [ [] ], [ 0.0 ] ];
    }

    public function testPreviewOfAValidEditCarriesVerdictDiffAndMessage(): void
    {
        $before = $this->fixture();
        $after  = (string) preg_replace( '/Your Title Goes Here/', 'Brand new title', $before, 1 );
        $r      = DryRun::preview( $before, $after );

        $this->assertSame( [ 'saved', 'dry_run', 'valid', 'violations', 'diff', 'message' ], array_keys( $r ) );
        $this->assertFalse( $r['saved'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( [], $r['violations'] );
        $this->assertSame( 1, $r['diff']['summary']['changed'] );
        $this->assertSame( 'Preview only. Nothing was saved.', $r['message'] );
        $this->assertSame( 'dry_run', DryRun::ENDPOINT );
    }

    public function testPreviewOfAnInvalidLayoutStillReturnsTheVerdictAndTheDiff(): void
    {
        $r = DryRun::preview( $this->fixture(), '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->' );
        $this->assertFalse( $r['saved'] );
        $this->assertFalse( $r['valid'] );
        $this->assertNotEmpty( $r['violations'] );
        $this->assertIsArray( $r['diff'] );
        $this->assertGreaterThan( 0, $r['diff']['summary']['added'] + $r['diff']['summary']['removed'] );
    }

    public function testPreviewWhenTheCurrentPageDoesNotParseKeepsTheVerdictAndExplainsWhyThereIsNoDiff(): void
    {
        $r = DryRun::preview( '<!-- wp:divi/section {"x"', $this->fixture() );
        $this->assertTrue( $r['valid'] );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'could not be parsed', $r['diff_error'] );
        $this->assertSame( [ 'saved', 'dry_run', 'valid', 'violations', 'diff', 'diff_error', 'message' ], array_keys( $r ) );
    }

    public function testPreviewResultIsJsonEncodable(): void
    {
        $r = DryRun::preview( $this->fixture(), (string) preg_replace( '/Your Title Goes Here/', "Caf\u{e9} \u{1F600}", $this->fixture(), 1 ) );
        $this->assertIsString( json_encode( $r ) );
    }

    public function testPreviewWithoutATitleHasNoTitleKey(): void
    {
        $r = DryRun::preview( $this->fixture(), $this->fixture() );
        $this->assertArrayNotHasKey( 'title', $r );
        $this->assertSame( [ 'saved', 'dry_run', 'valid', 'violations', 'diff', 'message' ], array_keys( $r ) );
    }

    public function testPreviewCarriesTheTitleReportJustBeforeTheMessage(): void
    {
        $t = [ 'changed' => true, 'before' => 'o', 'after' => 'n', 'restorable' => false ];
        $r = DryRun::preview( $this->fixture(), $this->fixture(), $t );
        $this->assertSame( $t, $r['title'] );
        $keys = array_keys( $r );
        $this->assertSame( [ 'diff', 'title', 'message' ], array_slice( $keys, -3 ) );
        $this->assertSame( DryRun::preview( $this->fixture(), $this->fixture() ), array_diff_key( $r, [ 'title' => 1 ] ) );
    }

    public function testPreviewTitleHasExactlyTheTitleOnlyShape(): void
    {
        $t = [ 'changed' => true, 'before' => 'o', 'after' => 'n', 'restorable' => false ];
        $r = DryRun::previewTitle( $t );
        $this->assertSame(
            [ 'saved' => false, 'dry_run' => true, 'valid' => true, 'violations' => [], 'layout_changed' => false, 'diff' => null, 'title' => $t, 'message' => 'Preview only. Nothing was saved.' ],
            $r
        );
        $this->assertTrue( $r['valid'] );
    }
}
