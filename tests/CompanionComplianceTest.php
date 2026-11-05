<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The companion files of the free plugin must follow the WordPress.org rules: no remote calls, no licensing or premium
 * gating, no custom code saved, and no change to site settings. (WpOrgComplianceTest checks the whole plugin; this pins the
 * files the companion work added so a regression names them.)
 */
class CompanionComplianceTest extends TestCase
{
    private const FILES = [
        'ToolCatalog.php', 'AbilityCatalog.php', 'Abilities.php', 'AbilityHandlers.php', 'ContrastCheck.php', 'DesignCheck.php',
        'DefaultFallbacks.php', 'HistorySources.php', 'HistoryCapture.php',
    ];

    /** @return array<string,string> */
    private function sources(): array
    {
        $out = [];
        foreach ( self::FILES as $file ) {
            $out[ $file ] = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
            $this->assertNotSame( '', $out[ $file ], $file );
        }

        return $out;
    }

    public function testNoRemoteCalls(): void
    {
        foreach ( $this->sources() as $file => $src ) {
            foreach ( [ 'wp_remote_', 'curl_', 'fsockopen', 'stream_socket_client', 'wp_safe_remote', 'http://', 'https://' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $src, "{$file} must make no remote call ({$bad})" );
            }
            $this->assertDoesNotMatchRegularExpression( '/file_get_contents\(\s*[\'"]https?:/', $src, $file );
        }
    }

    public function testNoLicensingOrPremiumGating(): void
    {
        foreach ( $this->sources() as $file => $src ) {
            if ( 'ToolCatalog.php' === $file ) {
                continue; // the tool descriptions moved here verbatim (pinned by ToolsListGoldenTest); WpOrgComplianceTest scans them
            }
            $this->assertDoesNotMatchRegularExpression( '/licen[sc]e|premium|upgrade|pro add-?on|is_pro|unlock/i', $src, "{$file} must not gate or advertise anything" );
        }
    }

    public function testNoCustomCodeOrSettingsAreWritten(): void
    {
        foreach ( $this->sources() as $file => $src ) {
            $src = 'HistorySources.php' === $file ? str_replace( 'update_option( self::OPTION,', '', $src ) : $src; // the owner's own setting, saved from the nonce-protected form (see WpOrgComplianceTest::OPTION_WRITERS)
            foreach ( [ 'update_option(', 'add_option(', 'update_post_meta(', 'add_post_meta(', 'set_theme_mod(', 'wp_update_nav_menu', 'eval(', 'wp_insert_post(', 'wp_update_post(' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $src, "{$file} must not call {$bad}" );
            }
        }
    }

    public function testNoEmDashInTheNewUserFacingText(): void
    {
        foreach ( $this->sources() as $file => $src ) {
            if ( 'ToolCatalog.php' === $file ) {
                continue; // existing descriptions, unchanged on purpose
            }
            $this->assertStringNotContainsString( "\u{2014}", $src, $file );
        }
    }

    public function testTheCompanionNeverTouchesAnotherPluginsAbilities(): void
    {
        foreach ( $this->sources() as $file => $src ) {
            foreach ( [ 'wp_unregister_ability(', 'wp_ability_permission_result', 'wp_pre_execute_ability', 'wp_ability_execute_result', 'wp_ability_validate_input', 'wp_get_abilities_result', 'wp_register_ability_args' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $src, "{$file} must only read other plugins' abilities, never wrap or block them ({$bad})" );
            }
        }
    }

    public function testTheCapturedAbilityNameIsTheOnlyThingReadFromAnotherToolsAbility(): void
    {
        $src = $this->sources()['HistoryCapture.php'];
        $this->assertStringContainsString( 'get_name()', $src, 'only the ability\'s name is read' );
        $this->assertStringNotContainsString( 'wp_ability_invoked', $src, 'no raw input of another tool is read' );
        $this->assertStringNotContainsString( 'get_input', $src );
    }
}
