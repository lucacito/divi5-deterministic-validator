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
        ApprovalMode::recordPreview( 5, 'content', true );
        $this->assertSame( [], $GLOBALS['__wp_transients'], 'no state is written while the mode is off' );
        $this->assertTrue( ApprovalMode::consume( 5, 'content' ) );
    }

    public function testASavingWithoutAPreviewIsRefusedWhenOn(): void
    {
        ApprovalMode::set( true );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'a missing preview (or an evicted one) is a refusal' );
    }

    public function testAValidPreviewUnlocksExactlyOneSaveOfExactlyThatContent(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true );

        $key = ApprovalMode::key( 7, 5, 'content' );
        $this->assertSame( ApprovalMode::TTL, $GLOBALS['__wp_transient_ttl'][ $key ] );
        $this->assertSame( 1800, ApprovalMode::TTL );

        $this->assertTrue( ApprovalMode::consume( 5, 'content' ) );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'single use: the same save again needs a new preview' );
    }

    public function testAnInvalidPreviewUnlocksNothing(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', false );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ) );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testAPreviewDoesNotUnlockOtherContentAnotherPageOrAnotherUser(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true );

        $this->assertFalse( ApprovalMode::consume( 5, 'content ' ), 'one extra character' );
        $this->assertFalse( ApprovalMode::consume( 6, 'content' ), 'another page' );

        $GLOBALS['__wp_current_user_id'] = 8;
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'another user' );

        $GLOBALS['__wp_current_user_id'] = 7;
        $this->assertTrue( ApprovalMode::consume( 5, 'content' ), 'the original preview is still there for its owner' );
    }

    public function testTheKeyIsAShortStableTransientName(): void
    {
        $key = ApprovalMode::key( 7, 5, 'content' );
        $this->assertMatchesRegularExpression( '/^aied_prev_[0-9a-f]{40}$/', $key );
        $this->assertSame( $key, ApprovalMode::key( 7, 5, 'content' ) );
        $this->assertNotSame( $key, ApprovalMode::key( 7, 5, 'other' ) );
    }

    public function testTheRefusalBodyNamesTheCodeAndTheNextStep(): void
    {
        $r = ApprovalMode::refusal();
        $this->assertFalse( $r['saved'] );
        $this->assertSame( 'PREVIEW_REQUIRED', $r['code'] );
        $this->assertStringContainsString( 'dry_run', $r['message'] );
        $this->assertStringContainsString( 'Nothing was saved', $r['message'] );
    }
}
