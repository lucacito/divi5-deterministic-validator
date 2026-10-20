<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ReviewPrompt;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ReviewPrompt.php';

/** The review reminder on the Dashboard: when it shows, how it is dismissed, and that it stays polite and local. */
class ReviewPromptTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function admin(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/AdminPage.php');
    }

    public function testItIsNotAskedBeforeTheAiHasSavedRealWork(): void
    {
        foreach ( [ 0, 1, ReviewPrompt::MIN_SAVED - 1 ] as $saved ) {
            $this->assertFalse( ReviewPrompt::shouldShow( $saved, '', self::NOW ), "saved={$saved}" );
        }
        $this->assertTrue( ReviewPrompt::shouldShow( ReviewPrompt::MIN_SAVED, '', self::NOW ) );
        $this->assertTrue( ReviewPrompt::shouldShow( 400, '', self::NOW ) );
    }

    public function testMaybeLaterHidesItForThirtyDaysThenItComesBackOnce(): void
    {
        $state = ReviewPrompt::later( self::NOW );
        $this->assertSame( 30, ReviewPrompt::SNOOZE_DAYS );
        $this->assertFalse( ReviewPrompt::shouldShow( 10, $state, self::NOW ) );
        $this->assertFalse( ReviewPrompt::shouldShow( 10, $state, self::NOW + 29 * 86400 ) );
        $this->assertTrue( ReviewPrompt::shouldShow( 10, $state, self::NOW + 30 * 86400 + 1 ) );
    }

    public function testDontAskAgainHidesItForever(): void
    {
        $state = ReviewPrompt::done();
        foreach ( [ 0, 1, 365 * 5 ] as $days ) {
            $this->assertFalse( ReviewPrompt::shouldShow( 1000, $state, self::NOW + $days * 86400 ) );
        }
    }

    public function testAnUnknownStoredShapeCountsAsNeverAskedAndCannotBreakAnything(): void
    {
        foreach ( [ '', null, false, 0, 1, 'later', [ 'status' => 'bogus' ], [ 'status' => 'later' ], [ 'status' => 'later', 'until' => 'x' ], [ 'until' => PHP_INT_MAX ] ] as $raw ) {
            $n = ReviewPrompt::normalise( $raw );
            $this->assertContains( $n['status'], [ '', ReviewPrompt::STATUS_LATER ], 'a garbage value is never "done"' );
            $this->assertIsInt( $n['until'] );
        }
        $this->assertTrue( ReviewPrompt::shouldShow( 10, [ 'status' => 'bogus' ], self::NOW ) );
        // a "later" with no valid time is already over
        $this->assertTrue( ReviewPrompt::shouldShow( 10, [ 'status' => 'later' ], self::NOW ) );
    }

    public function testTheCardIsRenderedFromTheDashboardOnlyAndOnce(): void
    {
        $src = $this->admin();
        $this->assertSame( 1, substr_count( $src, '$this->reviewCard(' ), 'reviewCard() is called exactly once' );
        $start = strpos( $src, 'private function viewDashboard()' );
        $this->assertNotFalse( $start );
        $end  = strpos( $src, 'private function ', (int) $start + 10 );
        $body = substr( $src, (int) $start, ( false === $end ? strlen( $src ) : $end ) - (int) $start );
        $this->assertStringContainsString( '$this->reviewCard(', $body, 'the reminder belongs to the Dashboard tab only' );
    }

    public function testBothButtonsArePerUserNonceProtectedAndGuarded(): void
    {
        $src = $this->admin();
        foreach ( [ 'ai_editor_divi5_review_later' => 'handleReviewLater', 'ai_editor_divi5_review_done' => 'handleReviewDone' ] as $action => $handler ) {
            $this->assertStringContainsString( "admin_post_{$action}", $src );
            $this->assertStringContainsString( "guard('{$action}')", $src );
            $this->assertStringContainsString( "wp_nonce_field( '{$action}' )", $src );
            $this->assertStringContainsString( "function {$handler}()", $src );
        }
        $this->assertSame( 2, substr_count( $src, 'update_user_meta( get_current_user_id(), ReviewPrompt::META' ), 'state is stored per user, only by the two handlers' );
        $this->assertStringContainsString( 'get_user_meta( $uid, ReviewPrompt::META, true )', $src );
    }

    public function testTheClassIsRegisteredInThePluginsLoaderSoTheDashboardCanFindIt(): void
    {
        $loader = (string) file_get_contents( dirname( __DIR__ ) . '/wp-plugin/src/autoload.php' );
        $this->assertStringContainsString( "require_once __DIR__ . '/ReviewPrompt.php';", $loader );
        $this->assertFileExists( dirname( __DIR__ ) . '/wp-plugin/src/ReviewPrompt.php' );
    }

    public function testUninstallRemovesTheState(): void
    {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/wp-plugin/uninstall.php' );
        $this->assertStringContainsString( "delete_metadata( 'user', 0, AiEditorDivi5\\WP\\ReviewPrompt::META, '', true )", $src );
    }

    public function testTheLinkGoesToThisPluginsOwnReviewPageAndOpensSafely(): void
    {
        $this->assertSame( 'https://wordpress.org/support/plugin/jhmg-ai-editor-for-divi-5/reviews/#new-post', ReviewPrompt::URL );
        $src = $this->admin();
        $this->assertMatchesRegularExpression( '#href="<\?php echo esc_url\( ReviewPrompt::URL \); \?>" target="_blank" rel="noopener"#', $src );
        // the slug in the link is the plugin's real slug
        $this->assertStringContainsString( '/plugin/jhmg-ai-editor-for-divi-5/', ReviewPrompt::URL );
    }

    public function testTheCopyIsPoliteAndOffersNoIncentiveOrRatingSteering(): void
    {
        $src = $this->admin();
        $start = strpos( $src, 'private function reviewCard(' );
        $this->assertNotFalse( $start );
        $end  = strpos( $src, 'The one Pro add-on notice', (int) $start ); // the docblock of the next method
        $card = substr( $src, (int) $start, (int) $end - (int) $start );
        $this->assertStringContainsString( 'Honest feedback of any kind is welcome.', $card );
        foreach ( [ 'five star', '5 star', '5-star', 'rate us', 'reward', 'free ', 'discount', 'unlock', 'upgrade', 'premium', 'Pro ', 'please' ] as $bad ) {
            $this->assertStringNotContainsStringIgnoringCase( $bad, $card, "the review card must not contain “{$bad}”" );
        }
        // it must not run in the background: no remote call, no JS
        $this->assertStringNotContainsString( 'wp_remote', $card );
        $this->assertStringNotContainsString( '<script', $card );
    }
}
