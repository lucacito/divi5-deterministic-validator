<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AbilityCatalog;
use AiEditorDivi5\WP\AdminPage;
use AiEditorDivi5\WP\DiviMcpDetector;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** Detecting Divi's MCP by the abilities it registers, and the text people paste into their assistant. */
class DiviMcpDetectorTest extends TestCase
{
    public function testItFindsAbilitiesInADiviNamespaceAndNothingElse(): void
    {
        $r = DiviMcpDetector::detect( [ 'core/get-site-info', 'jhmg-ai-editor/get-style-guide', 'divi/update-page', 'divi/create-page', 'woocommerce/x', 'elegant-themes/y' ] );
        $this->assertTrue( $r['present'] );
        $this->assertSame( 3, $r['count'] );
        $this->assertSame( [ 'divi/create-page', 'divi/update-page', 'elegant-themes/y' ], $r['examples'] );
    }

    public function testOurOwnAbilitiesAndLookalikesNeverCount(): void
    {
        $this->assertFalse( DiviMcpDetector::detect( [] )['present'] );
        $this->assertFalse( DiviMcpDetector::detect( [ 'jhmg-ai-editor/check-design', 'diviextras/thing', 'my-divi/x', 'divi' ] )['present'] );
        $this->assertTrue( DiviMcpDetector::detect( [ 'acme/x' ], [ 'acme' ] )['present'], 'the namespace list is a parameter, so a real Divi namespace can be set without a release' );
    }

    public function testWithoutTheAbilitiesApiNothingIsFoundAndNothingBreaks(): void
    {
        $this->assertFalse( function_exists( 'wp_get_abilities' ) );
        $this->assertFalse( DiviMcpDetector::current()['present'] );
    }

    public function testOurNamesCanNeverBeInTheDetectorsOwnList(): void
    {
        $this->assertNotContains( AbilityCatalog::NAMESPACE, DiviMcpDetector::NAMESPACES );
    }

    public function testThePastedInstructionsNameOnlyAbilitiesThatExist(): void
    {
        $text = AdminPage::aiInstructions();
        $this->assertSame( 1, preg_match_all( '#jhmg-ai-editor/([a-z-]+)#', $text, $m ) > 0 ? 1 : 0 );
        foreach ( array_unique( $m[1] ) as $name ) {
            $this->assertNotNull( AbilityCatalog::get( $name ), "{$name} is a real ability" );
        }
        $this->assertStringContainsString( 'before building', strtolower( $text ) );
        $this->assertStringNotContainsString( "\u{2014}", $text );
        $this->assertLessThan( 1200, strlen( $text ) );
    }
}
