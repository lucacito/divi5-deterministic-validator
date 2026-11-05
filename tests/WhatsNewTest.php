<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\WhatsNew;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/UserMetaShim.php';
require_once __DIR__ . '/../wp-plugin/src/WhatsNew.php';


/** The free plugin's "What's new" panel: the plugin's own Dashboard only, once per version, for users who can edit pages. */
class WhatsNewTest extends TestCase
{
    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/' . $file );
    }

    public function testTheShippedAnnouncementIsValidShortAndPlain(): void
    {
        $c = WhatsNew::content();
        $this->assertNotNull( $c, 'data/whats-new.php is well formed' );
        $this->assertSame( '4.17', $c['version'] );
        $this->assertGreaterThanOrEqual( 3, count( $c['bullets'] ) );
        $this->assertLessThanOrEqual( 5, count( $c['bullets'] ) );
        foreach ( [ $c['title'], $c['link']['label'], ...$c['bullets'] ] as $text ) {
            $this->assertStringNotContainsString( "\u{2014}", $text, 'no em dash' );
            $this->assertLessThanOrEqual( 130, strlen( $text ), 'one line each' );
        }
        $this->assertStringStartsWith( 'https://wordpress.org/plugins/jhmg-ai-editor-for-divi-5/', $c['link']['url'], 'the changelog on this plugin\'s own page' );
        $all = strtolower( implode( ' ', [ $c['title'], ...$c['bullets'], $c['link']['label'] ] ) );
        $this->assertDoesNotMatchRegularExpression( '/\bpro\b|premium|upgrade|licen[sc]e|buy|price|discount/', $all, 'no offer in the panel' );
        $text = implode( ' ', $c['bullets'] );
        foreach ( [ 'abilities', 'check-design', 'Undo', "Divi's upcoming MCP", 'Instructions for your AI' ] as $must ) {
            $this->assertStringContainsString( $must, $text, "the announcement mentions {$must}" );
        }
    }

    public function testItIsShownOnlyToAUserWhoCanEditPagesAndHasNotDismissedThisVersion(): void
    {
        $c = WhatsNew::content();
        $this->assertTrue( WhatsNew::shouldShow( $c, '', true ) );
        $this->assertTrue( WhatsNew::shouldShow( $c, null, true ) );
        $this->assertTrue( WhatsNew::shouldShow( $c, false, true ), 'get_user_meta returns false/empty when nothing is stored' );
        $this->assertFalse( WhatsNew::shouldShow( $c, '', false ), 'never for a user without edit_pages' );
        $this->assertFalse( WhatsNew::shouldShow( $c, '4.17', true ), 'hidden after dismissal' );
        $this->assertFalse( WhatsNew::shouldShow( $c, '4.18', true ), 'a dismissal of anything newer also hides it' );
        $this->assertFalse( WhatsNew::shouldShow( null, '', true ), 'no announcement, no panel' );
    }

    public function testAnewVersionBringsItBackOnce(): void
    {
        $next = [ 'version' => '4.18', 'title' => 'T', 'bullets' => [ 'a', 'b', 'c' ], 'link' => [ 'label' => 'l', 'url' => 'https://wordpress.org/x' ] ];
        $this->assertTrue( WhatsNew::valid( $next ) );
        $this->assertTrue( WhatsNew::shouldShow( $next, '4.17', true ), 'dismissed 4.17, so 4.18 shows' );
        $this->assertFalse( WhatsNew::shouldShow( $next, '4.18', true ), 'and not again after it is dismissed' );
        $this->assertTrue( WhatsNew::shouldShow( [ 'version' => '4.17.1' ] + $next, '4.17', true ), 'a point release counts as new' );
        $this->assertTrue( WhatsNew::shouldShow( [ 'version' => '4.100' ] + $next, '4.17', true ), 'versions compare as versions, not text' );
    }

    public function testDismissingStoresTheVersionForThatUserOnly(): void
    {
        $GLOBALS['__user_meta'] = [];
        WhatsNew::dismiss( 7, '4.17' );
        $this->assertSame( [ 7 => [ WhatsNew::META => '4.17' ] ], $GLOBALS['__user_meta'] );
    }

    public function testMalformedAnnouncementsAreRefused(): void
    {
        $ok = [ 'version' => '4.17', 'title' => 'T', 'bullets' => [ 'a', 'b', 'c' ], 'link' => [ 'label' => 'l', 'url' => 'https://wordpress.org/x' ] ];
        $this->assertTrue( WhatsNew::valid( $ok ) );
        foreach ( [
            [ 'version' => 'four' ] + $ok, [ 'version' => '' ] + $ok, [ 'title' => ' ' ] + $ok,
            [ 'bullets' => [ 'a', 'b' ] ] + $ok, [ 'bullets' => [ 'a', 'b', 'c', 'd', 'e', 'f' ] ] + $ok, [ 'bullets' => [ 'a', 'b', 7 ] ] + $ok, [ 'bullets' => 'x' ] + $ok,
            [ 'link' => [ 'label' => 'l', 'url' => 'http://insecure.example' ] ] + $ok, [ 'link' => [ 'label' => '', 'url' => 'https://x.test' ] ] + $ok, [ 'link' => 'x' ] + $ok,
        ] as $bad ) {
            $this->assertFalse( WhatsNew::valid( $bad ), json_encode( $bad ) );
        }
        $this->assertFalse( WhatsNew::valid( 'x' ) );
        $this->assertNull( WhatsNew::content( '/no/such/file.php' ) );
        $file = sys_get_temp_dir() . '/aied-whats-new-bad.php';
        file_put_contents( $file, "<?php\nreturn [ 'version' => 'x' ];\n" );
        $this->assertNull( WhatsNew::content( $file ) );
        unlink( $file );
    }

    public function testThePanelIsOnTheDashboardOnlyAndNeverASiteWideNotice(): void
    {
        $admin = $this->src( 'src/AdminPage.php' );
        $this->assertSame( 1, substr_count( $admin, '$this->whatsNewCard();' ), 'rendered from exactly one place' );
        $dash = (int) strpos( $admin, 'private function viewDashboard' );
        $call = (int) strpos( $admin, '$this->whatsNewCard();' );
        $next = (int) strpos( $admin, 'private function reviewCard' );
        $this->assertTrue( $call > $dash && $call < $next, 'that place is viewDashboard' );
        $this->assertLessThan( 400, $call - $dash, 'at the top of the Dashboard' );
        $this->assertStringNotContainsString( 'admin_notices', $this->src( 'src/WhatsNew.php' ), 'never an admin notice' );
        $this->assertStringNotContainsString( 'all_admin_notices', $admin );
        $this->assertDoesNotMatchRegularExpression( '/add_action\(\s*[\'"]admin_notices[\'"][^;]*whatsNew/i', $admin );
        $this->assertStringNotContainsString( 'WhatsNew', $this->src( 'jhmg-ai-editor-for-divi-5.php' ), 'nothing hooks it outside the admin page' );
    }

    public function testTheDismissHandlerChecksTheNonceAndTheCapabilityAndEscapesTheOutput(): void
    {
        $admin = $this->src( 'src/AdminPage.php' );
        $this->assertStringContainsString( "add_action('admin_post_' . WhatsNew::ACTION", $admin );
        $this->assertMatchesRegularExpression( '/function handleDismissWhatsNew\(\): void\s*\{\s*\$this->guard\( WhatsNew::ACTION \)/', $admin, 'guard() checks manage_options and the nonce' );
        $card = substr( $admin, (int) strpos( $admin, 'private function whatsNewCard' ), 2400 );
        $this->assertStringContainsString( "wp_nonce_field( WhatsNew::ACTION )", $card );
        $this->assertStringContainsString( "current_user_can( 'edit_pages' )", $card );
        foreach ( [ "esc_html( \$content['title'] )", 'esc_html( $bullet )', "esc_url( \$content['link']['url'] )", "esc_html( \$content['link']['label'] )" ] as $escaped ) {
            $this->assertStringContainsString( $escaped, $card );
        }
        $this->assertDoesNotMatchRegularExpression( '/echo\s+\$content/', $card, 'nothing is echoed raw' );
        $this->assertStringContainsString( 'WhatsNew::META', $this->src( 'uninstall.php' ), 'uninstall removes the per-user value' );
    }

    public function testEveryStringIsATranslatableLiteralWithThePluginsTextDomain(): void
    {
        $data = $this->src( 'data/whats-new.php' );
        preg_match_all( '/__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\')\s*,\s*\'([^\']+)\'\s*\)/', $data, $m );
        $this->assertGreaterThanOrEqual( 7, count( $m[0] ), 'title, bullets and link label' );
        $this->assertSame( [ 'jhmg-ai-editor-for-divi-5' ], array_values( array_unique( $m[2] ) ) );
        $this->assertSame( substr_count( $data, '__(' ), count( $m[0] ), 'no translation call with a variable' );
    }
}
