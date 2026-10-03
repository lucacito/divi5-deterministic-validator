<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class EditThemeLayoutTest extends TestCase
{
    use ThemeLayoutFixture;

    private EditThemeLayout $tool;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new EditThemeLayout( $this->log );
        // a header with visible text to edit
        $text = self::header();
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop {"builderVersion":"5.14.0"} /', 'wp:divi/search {"builderVersion":"5.14.0","note":"AIED-OLD"} /', $text );
        $GLOBALS['__wp_posts'][202]->post_content = $GLOBALS['__wp_posts'][201]->post_content;
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ) ] );
    }

    public function testItReplacesTheTextAndLeavesTheRestUntouched(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 1, $r['replaced'] );
        $this->assertSame( str_replace( 'AIED-OLD', 'AIED-NEW', $before ), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( $before, $this->layoutContent( 202 ), 'the footer is a different layout and is untouched' );
    }

    public function testAnEmptyReplacementIsAllowed(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'find' => ',"note":"AIED-OLD"', 'replace' => '' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertStringNotContainsString( 'AIED-OLD', $this->layoutContent( 201 ) );
    }

    public function testTheFreePluginsAmbiguityRulesApply(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => 'builderVersion', 'replace' => 'x' ], 'ambiguous' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'NOT-THERE', 'replace' => 'x' ], 'was not found' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-OLD' ], 'identical' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'x', 'expect_count' => 2 ], 'Expected 2' );
    }

    public function testExpectCountReplacesAllOfExactlyThatMany(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'find' => '"builderVersion":"5.14.0"', 'replace' => '"builderVersion":"5.14.1"', 'expect_count' => 4 ] );
        $this->assertSame( 4, $r['replaced'] );
    }

    public function testAnEditThatBreaksTheLayoutIsRefusedByTheGate(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => 'wp:divi/search', 'replace' => 'wp:divi/not-a-module', 'expect_count' => 1 ], 'The layout was not saved' );
    }

    public function testAnEditThatAddsCustomCssIsRefused(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => '"note":"AIED-OLD"', 'replace' => '"css":{"desktop":{"value":{"freeForm":"a{b:c}"}}}' ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testADryRunPreviewsWithTheReplacedCountAndTouchesNothing(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['saved'] );
        $this->assertSame( 1, $r['replaced'] );
        $this->assertSame( $before, $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testOnlyLiveHeadersAndFootersCanBeEdited(): void
    {
        foreach ( [ 5, 203, 204, 200, 9999 ] as $id ) {
            try {
                $this->tool->run( [ 'layout_id' => $id, 'find' => 'builderVersion', 'replace' => 'x', 'expect_count' => 99 ] );
                $this->fail( "{$id} must be refused" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage(), (string) $id );
            }
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'find' => 'a', 'replace' => 'b' ], '"layout_id" is required.' );
        $this->refused( [ 'layout_id' => 201, 'replace' => 'b' ], '"find" is required.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 5, 'replace' => 'b' ], '"find" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a' ], '"replace" is required' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 5 ], '"replace" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 'b', 'expect_count' => '1' ], '"expect_count" must be an integer.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 'b', 'dry_run' => 1 ], '"dry_run" must be true or false.' );
    }

    public function testUndoRestoresTheEditedLayout(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ] );
        $undo   = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( $before, $this->layoutContent( 201 ) );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'edit_theme_layout', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'layout_id', 'find', 'replace' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
