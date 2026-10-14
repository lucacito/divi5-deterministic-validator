<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';

class ApprovalModeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options']       = [];
        $GLOBALS['__wp_transients']    = [];
        $GLOBALS['__wp_transient_ttl'] = [];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
    }

    public function testItIsOffByDefaultAndSetTurnsItOnAndOff(): void
    {
        $this->assertFalse( ApprovalMode::enabled() );
        ApprovalMode::set( true );
        $this->assertTrue( ApprovalMode::enabled() );
        $this->assertSame( '1', $GLOBALS['__wp_options'][ ApprovalMode::OPTION ] );
        ApprovalMode::set( false );
        $this->assertFalse( ApprovalMode::enabled() );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notOne' )]
    public function testOnlyTheStoredStringOneMeansOn( mixed $stored ): void
    {
        $GLOBALS['__wp_options'][ ApprovalMode::OPTION ] = $stored;
        $this->assertFalse( ApprovalMode::enabled() );
    }

    /** @return list<array{mixed}> */
    public static function notOne(): array
    {
        return [ [ 'yes' ], [ 'true' ], [ '' ], [ 0 ], [ '0' ], [ false ], [ [ '1' ] ], [ 1 ], [ true ] ];
    }

    public function testWhenOffNothingIsRecordedAndEverySaveIsAllowed(): void
    {
        ApprovalMode::recordPreview( 5, 'content', true, 'base' );
        $this->assertSame( [], $GLOBALS['__wp_transients'], 'no state is written while the mode is off' );
        $this->assertTrue( ApprovalMode::consume( 5, 'content', 'base' ) );
    }

    public function testASavingWithoutAPreviewIsRefusedWhenOn(): void
    {
        ApprovalMode::set( true );
        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base' ), 'a missing preview (or an evicted one) is a refusal' );
    }

    public function testAValidPreviewUnlocksExactlyOneSaveOfExactlyThatContent(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true, 'base' );

        $key = ApprovalMode::key( 7, 5, 'content', 'base' );
        $this->assertSame( ApprovalMode::TTL, $GLOBALS['__wp_transient_ttl'][ $key ] );
        $this->assertSame( 1800, ApprovalMode::TTL );

        $this->assertTrue( ApprovalMode::consume( 5, 'content', 'base' ) );
        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base' ), 'single use: the same save again needs a new preview' );
    }

    public function testAnInvalidPreviewUnlocksNothing(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', false, 'base' );
        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base' ) );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testAPreviewDoesNotUnlockOtherContentAnotherPageOrAnotherUser(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true, 'base' );

        $this->assertFalse( ApprovalMode::consume( 5, 'content ', 'base' ), 'one extra character' );
        $this->assertFalse( ApprovalMode::consume( 6, 'content', 'base' ), 'another page' );

        $GLOBALS['__wp_current_user_id'] = 8;
        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base' ), 'another user' );

        $GLOBALS['__wp_current_user_id'] = 7;
        $this->assertTrue( ApprovalMode::consume( 5, 'content', 'base' ), 'the original preview is still there for its owner' );
    }

    public function testAPreviewIsBoundToThePagesBaseContent(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true, 'base A' );

        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base B' ), 'the page changed after the preview' );
        $this->assertTrue( ApprovalMode::consume( 5, 'content', 'base A' ), 'the same base still unlocks it' );
        $this->assertFalse( ApprovalMode::consume( 5, 'content', 'base A' ), 'single use' );
    }

    public function testTheKeyIsAShortStableTransientName(): void
    {
        $key = ApprovalMode::key( 7, 5, 'content', 'base' );
        $this->assertMatchesRegularExpression( '/^aied_prev_[0-9a-f]{40}$/', $key );
        $this->assertSame( $key, ApprovalMode::key( 7, 5, 'content', 'base' ) );
        $this->assertNotSame( $key, ApprovalMode::key( 7, 5, 'other', 'base' ) );
    }

    public function testTheRefusalBodyNamesTheCodeAndTheNextStep(): void
    {
        $r = ApprovalMode::refusal();
        $this->assertFalse( $r['saved'] );
        $this->assertSame( 'PREVIEW_REQUIRED', $r['code'] );
        $this->assertStringContainsString( 'dry_run', $r['message'] );
        $this->assertStringContainsString( 'Nothing was saved', $r['message'] );
    }

    public function testWithTitleLeavesTheContentAloneWithoutATitle(): void
    {
        $this->assertSame( 'X', ApprovalMode::withTitle( 'X', null ) );
        $this->assertSame( "X\0aied-title\0A", ApprovalMode::withTitle( 'X', 'A' ) );
        $this->assertNotSame( ApprovalMode::withTitle( 'X', 'A' ), ApprovalMode::withTitle( 'X', 'B' ) );
    }

    public function testATitleChangesTheKeyButNoTitleKeepsThe4100Key(): void
    {
        $this->assertNotSame(
            ApprovalMode::key( 1, 5, 'C', 'B' ),
            ApprovalMode::key( 1, 5, ApprovalMode::withTitle( 'C', 'A' ), ApprovalMode::withTitle( 'B', 'Old' ) )
        );
        // Pinned from the 4.10.0 formula, computed before withTitle() existed.
        $this->assertSame( 'aied_prev_71cb0a267e28e767344dde00446bf785bea41de7', ApprovalMode::key( 1, 5, 'C', 'B' ) );
        $this->assertSame( 'aied_prev_71cb0a267e28e767344dde00446bf785bea41de7', ApprovalMode::key( 1, 5, ApprovalMode::withTitle( 'C', null ), ApprovalMode::withTitle( 'B', null ) ) );
    }

    public function testAPreviewWithATitleOnlyUnlocksTheSameTitleAndIsSingleUse(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, ApprovalMode::withTitle( 'C', 'A' ), true, ApprovalMode::withTitle( 'B', 'Old' ) );

        $this->assertFalse( ApprovalMode::consume( 5, ApprovalMode::withTitle( 'C', 'B' ), ApprovalMode::withTitle( 'B', 'Old' ) ), 'another title' );
        $this->assertFalse( ApprovalMode::consume( 5, 'C', 'B' ), 'no title at all' );
        $this->assertFalse( ApprovalMode::consume( 5, ApprovalMode::withTitle( 'C', 'A' ), ApprovalMode::withTitle( 'B', 'Older' ) ), 'the stored title changed since' );
        $this->assertTrue( ApprovalMode::consume( 5, ApprovalMode::withTitle( 'C', 'A' ), ApprovalMode::withTitle( 'B', 'Old' ) ) );
        $this->assertFalse( ApprovalMode::consume( 5, ApprovalMode::withTitle( 'C', 'A' ), ApprovalMode::withTitle( 'B', 'Old' ) ), 'single use' );
    }
}
