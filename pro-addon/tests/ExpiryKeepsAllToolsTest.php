<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Verify that all 14 Pro tools are callable in lapsed states: expired, canceled, past_due, and lifetime.
 * This test ensures that expiry and lapses never disable the Pro tools.
 */
class ExpiryKeepsAllToolsTest extends TestCase
{
    private const KEY = 'JHMG-TEST-TEST-TEST-TEST';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_http_queue'] = [];
        $GLOBALS['__wp_http_log']   = [];
        $GLOBALS['__wp_filters']    = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );
        Licensing::resetForTests();
    }

    private function queue( int $code, array|string $body ): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => $code, 'body' => $body ];
    }

    private function ageState(): void
    {
        $state = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $state['checked_at'] = time() - 2 * DAY_IN_SECONDS;
        $GLOBALS['__wp_options']['aied_pro_license_state'] = $state;
    }

    private function activatePremium(): void
    {
        $this->queue( 200, [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => '2027-07-12T00:00:00.000Z' ] );
        $res = Licensing::activate( self::KEY );
        $this->assertTrue( $res['ok'] );
        $this->assertTrue( Licensing::isPremium() );
    }

    /** @return array<string,ProTool> */
    private function getToolsByName(): array
    {
        $tools = [];
        foreach ( Tools::all() as $tool ) {
            $tools[ $tool->name() ] = $tool;
        }

        return $tools;
    }

    /** Verify all 14 tools are claimed for an expired licence. */
    public function testAllToolsClaimableWhenExpired(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'expired' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() ); // lapse never locks

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $tools    = $this->getToolsByName();

        $this->assertCount( 14, $tools, 'Expected 14 Pro tools' );

        foreach ( $tools as $name => $tool ) {
            $this->assertNotNull( $registry->get( $name ), "Tool {$name} not in registry" );
            $this->assertTrue( $registry->isUnlocked(), "Registry not unlocked for tool {$name}" );
        }
    }

    /** Verify all 14 tools are claimed for a canceled licence. */
    public function testAllToolsClaimableWhenCanceled(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'canceled' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $tools    = $this->getToolsByName();

        $this->assertCount( 14, $tools );

        foreach ( $tools as $name => $tool ) {
            $this->assertNotNull( $registry->get( $name ) );
            $this->assertTrue( $registry->isUnlocked() );
        }
    }

    /** Verify all 14 tools are claimed for a past_due licence. */
    public function testAllToolsClaimableWhenPastDue(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'license_not_usable', 'status' => 'past_due' ] );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium() );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $tools    = $this->getToolsByName();

        $this->assertCount( 14, $tools );

        foreach ( $tools as $name => $tool ) {
            $this->assertNotNull( $registry->get( $name ) );
            $this->assertTrue( $registry->isUnlocked() );
        }
    }

    /** Verify all 14 tools are claimed for a lifetime licence. */
    public function testAllToolsClaimableWhenLifetime(): void
    {
        // A lifetime licence is active with no expiry
        $this->queue( 200, [ 'status' => 'active', 'product' => 'ai-editor-divi5-pro', 'expires' => null, 'lifetime' => true ] );
        $res = Licensing::activate( self::KEY );
        $this->assertTrue( $res['ok'] );
        $this->assertTrue( Licensing::isPremium() );

        $registry = new ToolRegistry( Tools::all(), static fn (): bool => Licensing::isPremium() );
        $tools    = $this->getToolsByName();

        $this->assertCount( 14, $tools );

        foreach ( $tools as $name => $tool ) {
            $this->assertNotNull( $registry->get( $name ) );
            $this->assertTrue( $registry->isUnlocked() );
        }
    }
}
