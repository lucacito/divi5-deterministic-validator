<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\StrictModePanel;
use AiEditorDivi5\Pro\Companion\StrictMode;
use AiEditorDivi5\WP\HistoryCapture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

/** Strict validation: a flag after the save, never a block, never a rollback, nothing stored but codes. */
class StrictModeTest extends TestCase
{
    private const VALID   = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private bool $unlocked = true;

    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
        HistoryCapture::reset();
        $this->unlocked = true;
    }

    protected function tearDown(): void
    {
        HistoryCapture::reset();
        $GLOBALS['__wp_options'] = [];
    }

    private function strict(): StrictMode
    {
        return new StrictMode( fn (): bool => $this->unlocked );
    }

    private function post( string $content, string $type = 'page', string $title = 'Home' ): object
    {
        return (object) [ 'post_type' => $type, 'post_content' => $content, 'post_title' => $title ];
    }

    private function viaRest(): void
    {
        HistoryCapture::onRestPreDispatch( null, null, new class() {
            public function get_route(): string { return '/wp/v2/pages/5'; }
        } );
    }

    public function testItIsOffByDefaultAndFlagsNothing(): void
    {
        $this->assertFalse( StrictMode::enabled() );
        $this->viaRest();
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->assertSame( [], StrictMode::flags() );
    }

    public function testOnAndUnlockedAnInvalidSaveByRestIsFlaggedWithCodesOnly(): void
    {
        StrictMode::set( true );
        $this->viaRest();
        $this->strict()->onSaved( 5, $this->post( self::INVALID, 'page', "Home <b>page</b>\n" ), true );
        $flags = StrictMode::flags();
        $this->assertCount( 1, $flags );
        $this->assertSame( 5, $flags[0]['post_id'] );
        $this->assertSame( 'rest', $flags[0]['source'] );
        $this->assertNotSame( [], $flags[0]['codes'] );
        $this->assertStringNotContainsString( '<', $flags[0]['title'] );
        $this->assertStringNotContainsString( 'not-a-module', (string) json_encode( $GLOBALS['__wp_options'][ StrictMode::FLAGS ] ?? [] ) . '', 'no page content is stored' );
    }

    public function testAnApplicationPasswordSaveCarriesThePasswordsName(): void
    {
        StrictMode::set( true );
        HistoryCapture::onApplicationPassword( null, [ 'name' => 'Cursor', 'password' => 'SECRET' ] );
        $this->viaRest();
        $this->strict()->onSaved( 6, $this->post( self::INVALID ), true );
        $f = StrictMode::flags()[0];
        $this->assertSame( [ 'application_password', 'Cursor' ], [ $f['source'], $f['label'] ] );
        $this->assertStringNotContainsString( 'SECRET', (string) json_encode( $GLOBALS['__wp_options'] ) );
    }

    public function testALaterValidSaveClearsTheFlagAndAFlagIsReplacedNotDuplicated(): void
    {
        StrictMode::set( true );
        $this->viaRest();
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->assertCount( 1, StrictMode::flags() );
        $this->strict()->onSaved( 5, $this->post( self::VALID ), true );
        $this->assertSame( [], StrictMode::flags() );
    }

    public function testNothingIsCheckedWhenLockedOrForOtherSourcesOrOwnWritesOrOtherContent(): void
    {
        StrictMode::set( true );
        $this->viaRest();
        $this->unlocked = false;
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->assertSame( [], StrictMode::flags(), 'locked' );
        $this->unlocked = true;

        HistoryCapture::reset(); // no REST, no password, no ability: an unknown source
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->assertSame( [], StrictMode::flags(), 'unknown source' );

        HistoryCapture::onRestPreDispatch( null, null, new class() {
            public function get_route(): string { return '/divi/v1/sync-to-server'; }
        } );
        $this->strict()->onSaved( 5, $this->post( self::INVALID ), true );
        $this->assertSame( [], StrictMode::flags(), 'the Divi builder' );

        HistoryCapture::reset();
        $this->viaRest();
        HistoryCapture::suspend( fn () => $this->strict()->onSaved( 5, $this->post( self::INVALID ), true ) );
        $this->assertSame( [], StrictMode::flags(), 'this plugin\'s own validated write path' );

        $this->strict()->onSaved( 5, $this->post( 'plain html, no divi' ), true );
        $this->strict()->onSaved( 5, $this->post( self::INVALID, 'post' ), true );
        $this->strict()->onSaved( 5, null, true );
        $this->strict()->onSaved( '5', $this->post( self::INVALID ), true );
        $this->assertSame( [], StrictMode::flags() );
    }

    public function testTheListIsCappedAtFifty(): void
    {
        for ( $i = 1; $i <= 60; ++$i ) {
            StrictMode::record( $i, 'P' . $i, 'rest', null, [ 'X' ], '2026-11-05T10:00:00Z' );
        }
        $flags = StrictMode::flags();
        $this->assertCount( StrictMode::MAX_FLAGS, $flags );
        $this->assertSame( 60, $flags[0]['post_id'], 'newest first' );
    }

    public function testItOnlyEverFlagsAfterTheSaveAndNeverTouchesOne(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../src/Companion/StrictMode.php' );
        $this->assertStringContainsString( "'wp_after_insert_post'", $src );
        foreach ( [ 'wp_insert_post_data', 'pre_post_update', 'wp_update_post', 'wp_insert_post(', 'wp_delete_post', 'wp_trash_post', 'rest_pre_insert', 'rest_pre_dispatch', 'wp_die', 'WP_Error' ] as $bad ) {
            $this->assertStringNotContainsString( $bad, $src, "strict mode must not block or change a save ({$bad})" );
        }
        $this->assertSame( 'void', ( new \ReflectionMethod( StrictMode::class, 'onSaved' ) )->getReturnType()?->getName() );
    }

    public function testThePanelIsGatedByLicenceAndCapability(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/../src/Admin/StrictModePanel.php' );
        $this->assertStringContainsString( "guardPost( self::ACTION_SAVE, 'manage_options' )", $src );
        $this->assertStringContainsString( "guardPost( self::ACTION_CLEAR, 'manage_options' )", $src );
        $this->assertSame( 'aied_pro_strict_save', StrictModePanel::ACTION_SAVE );
    }
}
