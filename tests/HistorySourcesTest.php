<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistorySources;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/HistorySources.php';

/** Which source a page change is credited to, and which sources keep an undo point by default. */
class HistorySourcesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    public function testDefaultsAreAgentAndRestOnBuilderOff(): void
    {
        $s = HistorySources::settings();
        $this->assertTrue( $s['ability'] );
        $this->assertTrue( $s['application_password'] );
        $this->assertTrue( $s['rest'] );
        $this->assertFalse( $s['builder'], 'WordPress revisions already cover builder saves' );
        $this->assertTrue( $s['unknown'] );
    }

    public function testStoredSettingsAreNormalisedAndGarbageFallsBackToTheDefault(): void
    {
        $GLOBALS['__wp_options'][ HistorySources::OPTION ] = [ 'builder' => '1', 'rest' => '0', 'ability' => 'banana', 'evil' => true ];
        $s = HistorySources::settings();
        $this->assertTrue( $s['builder'] );
        $this->assertFalse( $s['rest'] );
        $this->assertFalse( $s['ability'], 'a value that is not a boolean is read as false, never as truthy' );
        $this->assertArrayNotHasKey( 'evil', $s );
        $this->assertSame( array_keys( HistorySources::DEFAULTS ), array_keys( $s ) );

        $GLOBALS['__wp_options'][ HistorySources::OPTION ] = 'not an array';
        $this->assertSame( HistorySources::DEFAULTS, HistorySources::settings() );
    }

    /** @return array<string,array{array<string,mixed>,string,?string}> */
    public static function cases(): array
    {
        return [
            'an ability'                       => [ [ 'ability' => 'divi/update-page' ], 'ability', 'divi/update-page' ],
            'an ability called with a password' => [ [ 'ability' => 'divi/update-page', 'app_password' => 'Claude', 'route' => '/wp-abilities/v1/abilities/divi/update-page/run' ], 'ability', 'divi/update-page (via Claude)' ],
            'the builder'                      => [ [ 'route' => '/divi/v1/sync-to-server' ], 'builder', null ],
            'the builder, even with a password' => [ [ 'route' => '/divi/v1/sync-to-server', 'app_password' => 'x' ], 'builder', null ],
            'the old builder save'             => [ [ 'ajax_action' => 'et_fb_ajax_save' ], 'builder', null ],
            'an application password'          => [ [ 'route' => '/wp/v2/pages/5', 'app_password' => 'Cursor' ], 'application_password', 'Cursor' ],
            'a plain REST request'             => [ [ 'route' => '/wp/v2/pages/5' ], 'rest', null ],
            'nothing known'                    => [ [], 'unknown', null ],
            'another ajax action'              => [ [ 'ajax_action' => 'heartbeat' ], 'unknown', null ],
        ];
    }

    /** @param array<string,mixed> $context */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'cases' )]
    public function testClassify( array $context, string $type, ?string $label ): void
    {
        $this->assertSame( [ 'type' => $type, 'label' => $label ], HistorySources::classify( $context ) );
    }

    public function testALabelIsPlainTextOfSafeLength(): void
    {
        $r = HistorySources::classify( [ 'app_password' => "<script>alert(1)</script>\n" . str_repeat( 'x', 500 ) ] );
        $this->assertStringNotContainsString( '<', (string) $r['label'] );
        $this->assertStringNotContainsString( "\n", (string) $r['label'] );
        $this->assertLessThanOrEqual( 120, mb_strlen( (string) $r['label'] ) );
        $this->assertSame( [ 'type' => 'unknown', 'label' => null ], HistorySources::classify( [ 'app_password' => "\n\t " ] ), 'a blank name is no name' );
    }

    public function testDescribeReadsWellAndLeavesOldToolNamesAlone(): void
    {
        $this->assertSame( 'AI agent: divi/update-page', HistorySources::describe( 'ability', 'divi/update-page' ) );
        $this->assertSame( 'Application password: Cursor', HistorySources::describe( 'application_password', 'Cursor' ) );
        $this->assertSame( 'REST API', HistorySources::describe( 'rest' ) );
        $this->assertSame( 'Divi builder', HistorySources::describe( 'builder' ) );
        $this->assertSame( 'Unknown source', HistorySources::describe( 'unknown', '' ) );
        $this->assertSame( 'update_page_layout', HistorySources::describe( 'update_page_layout', null ), 'a snapshot from this plugin\'s own tool keeps its name' );
        $this->assertFalse( HistorySources::isSource( 'update_page_layout' ) );
        $this->assertTrue( HistorySources::isSource( 'rest' ) );
    }

    public function testEverySourceHasAPlainEnglishNameForTheSettingsScreen(): void
    {
        $this->assertSame( array_keys( HistorySources::DEFAULTS ), array_keys( HistorySources::names() ) );
        foreach ( HistorySources::names() as $name ) {
            $this->assertStringNotContainsString( "\u{2014}", $name );
        }
    }

    /** Ruling 9 (plan 2): the history rows Lab writes carry the internal tool name; people read the product name. */
    public function testDivi5LabWritesReadAsDivi5LabNotTheInternalName(): void
    {
        $this->assertSame( 'Lab', HistorySources::describe( \AiEditorDivi5\WP\StudioService::HISTORY_TOOL ) );
        $this->assertSame( 'Lab', HistorySources::describe( 'studio', 'ignored label' ) );
        $this->assertStringNotContainsStringIgnoringCase( 'studio', HistorySources::describe( 'studio' ) );
    }
}
