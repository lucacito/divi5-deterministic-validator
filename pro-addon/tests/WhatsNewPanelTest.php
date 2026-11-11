<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\WhatsNewPanel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/UserMetaShim.php';

/** The Pro tab's "What's new" panel: the Pro tab only, once per version, for users who can edit pages, and the update notice when the free plugin is too old. */
class WhatsNewPanelTest extends TestCase
{
    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../' . $file );
    }

    public function testTheShippedAnnouncementIsValidShortAndPlain(): void
    {
        $c = WhatsNewPanel::content();
        $this->assertNotNull( $c );
        $this->assertSame( '0.15', $c['version'] );
        $this->assertGreaterThanOrEqual( 3, count( $c['bullets'] ) );
        $this->assertLessThanOrEqual( 5, count( $c['bullets'] ) );
        foreach ( [ $c['title'], $c['link']['label'], ...$c['bullets'] ] as $text ) {
            $this->assertStringNotContainsString( "\u{2014}", $text );
            $this->assertLessThanOrEqual( 130, strlen( $text ), 'one line each' );
        }
        $text = implode( ' ', $c['bullets'] );
        foreach ( [ 'lab_restyle', 'skips pages you edited', 'build_site', 'Lab screen', 'not yet confirmed' ] as $must ) {
            $this->assertStringContainsString( $must, $text, $must );
        }
        $this->assertStringStartsWith( 'https://divi5lab.com/', $c['link']['url'] );
    }

    public function testShownOnlyToAUserWhoCanEditPagesAndHasNotDismissedThisVersion(): void
    {
        $c = WhatsNewPanel::content();
        $this->assertSame( 'whats_new', WhatsNewPanel::mode( '4.17.0', $c, '', true ) );
        $this->assertSame( 'none', WhatsNewPanel::mode( '4.17.0', $c, '', false ), 'never for a user without edit_pages' );
        $this->assertSame( 'none', WhatsNewPanel::mode( '4.17.0', $c, '0.15', true ), 'hidden after dismissal' );
        $this->assertSame( 'none', WhatsNewPanel::mode( '4.17.0', null, '', true ) );
    }

    public function testANewVersionBringsItBack(): void
    {
        $next = [ 'version' => '0.16', 'title' => 'T', 'bullets' => [ 'a', 'b', 'c' ], 'link' => [ 'label' => 'l', 'url' => 'https://divi5lab.com/x' ] ];
        $this->assertTrue( WhatsNewPanel::valid( $next ) );
        $this->assertSame( 'whats_new', WhatsNewPanel::mode( '4.17.0', $next, '0.15', true ) );
        $this->assertSame( 'none', WhatsNewPanel::mode( '4.17.0', $next, '0.16', true ) );
        $this->assertSame( 'whats_new', WhatsNewPanel::mode( '4.17.0', [ 'version' => '0.100' ] + $next, '0.15', true ), 'compared as versions' );
    }

    public function testAnOldFreePluginShowsTheUpdateMessageInsteadAndItCannotBeDismissed(): void
    {
        $c = WhatsNewPanel::content();
        $this->assertSame( 'update', WhatsNewPanel::mode( '4.16.0', $c, '', true ) );
        $this->assertSame( 'update', WhatsNewPanel::mode( '4.16.0', $c, '0.15', true ), 'dismissing the announcement does not hide the update message' );
        $this->assertSame( 'none', WhatsNewPanel::mode( '4.16.0', $c, '', false ), 'and still only for users who can edit pages' );
        $this->assertSame( 'whats_new', WhatsNewPanel::mode( null, $c, '', true ), 'an unknown free version is not called old' );
    }

    public function testMalformedAnnouncementsAreRefused(): void
    {
        $ok = [ 'version' => '0.14', 'title' => 'T', 'bullets' => [ 'a', 'b', 'c' ], 'link' => [ 'label' => 'l', 'url' => 'https://divi5lab.com/x' ] ];
        foreach ( [ [ 'version' => 'x' ] + $ok, [ 'bullets' => [ 'a', 'b' ] ] + $ok, [ 'bullets' => [ 'a', 'b', 'c', 'd', 'e', 'f' ] ] + $ok, [ 'link' => [ 'label' => 'l', 'url' => 'http://x.test' ] ] + $ok, [ 'title' => '' ] + $ok ] as $bad ) {
            $this->assertFalse( WhatsNewPanel::valid( $bad ) );
        }
        $this->assertNull( WhatsNewPanel::content( '/no/such/file.php' ) );
    }

    public function testItIsOnTheProTabOnlyNeverAnAdminNoticeAndTheHandlerIsGuarded(): void
    {
        $src = $this->src( 'src/Admin/WhatsNewPanel.php' );
        $this->assertStringContainsString( "ProPage::TAB !== \$tab", $src );
        $this->assertStringContainsString( "'jhmg_aied_render_admin_tab'", $src );
        $this->assertStringNotContainsString( 'admin_notices', $src, 'never a site-wide notice' );
        $this->assertStringContainsString( "guardPost( self::ACTION, 'edit_pages' )", $src );
        $this->assertStringContainsString( 'wp_nonce_field( self::ACTION )', $src );
        $this->assertSame( 'aied_pro_dismiss_whats_new', WhatsNewPanel::ACTION );
        foreach ( [ "esc_html( \$content['title'] )", 'esc_html( $bullet )', "esc_url( \$content['link']['url'] )", "esc_html( \$content['link']['label'] )" ] as $escaped ) {
            $this->assertStringContainsString( $escaped, $src );
        }
        $this->assertDoesNotMatchRegularExpression( '/echo\s+\$content/', $src );
        $this->assertSame( 1, substr_count( $this->src( 'src/Plugin.php' ), 'WhatsNewPanel' ), 'registered once' );
    }

    public function testEveryStringIsATranslatableLiteralWithTheProTextDomain(): void
    {
        $data = $this->src( 'data/whats-new.php' );
        preg_match_all( '/__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\')\s*,\s*\'([^\']+)\'\s*\)/', $data, $m );
        $this->assertGreaterThanOrEqual( 6, count( $m[0] ) );
        $this->assertSame( [ 'jhmg-ai-editor-for-divi-5-pro' ], array_values( array_unique( $m[2] ) ) );
        $this->assertSame( substr_count( $data, '__(' ), count( $m[0] ) );
    }

    public function testTheProZipShipsTheDataFolder(): void
    {
        $this->assertStringContainsString( '"$SRC/data"', (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/../scripts/build-pro-zip.sh' ), 'the announcement is read at run time, so the zip must carry it' );
    }

    public function testUninstallRemovesTheDismissalsAndTheCompanionOptions(): void
    {
        $u = $this->src( 'uninstall.php' );
        foreach ( [ 'aied_pro_whats_new_dismissed', 'aied_pro_agent_sessions', 'aied_pro_strict_validation', 'aied_pro_strict_flags' ] as $what ) {
            $this->assertStringContainsString( $what, $u );
        }
        $this->assertSame( WhatsNewPanel::META, 'aied_pro_whats_new_dismissed' );
    }
}
