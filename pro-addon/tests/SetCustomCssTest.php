<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\CssKind;
use AiEditorDivi5\Pro\Settings\CustomCss;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetCustomCss;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetCustomCssTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetCustomCss $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetCustomCss( $this->log );
        $GLOBALS['__wp_custom_css']      = "body { color: red; }\n";
        $GLOBALS['__wp_custom_css_fail'] = false;
        $GLOBALS['__wp_can']             = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'custom_css' => new CssKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        $before = $GLOBALS['__wp_custom_css'];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_custom_css'], 'nothing was changed' );
    }

    public function testItAddsTheManagedBlockAndKeepsThePersonsCss(): void
    {
        $r = $this->tool->run( [ 'css' => '.hero { color: blue; }' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( strlen( '.hero { color: blue; }' ), $r['bytes'] );
        $this->assertStringStartsWith( "body { color: red; }\n", wp_get_custom_css() );
        $this->assertSame( '.hero { color: blue; }', CustomCss::extract( wp_get_custom_css() ) );
        $this->assertTrue( $r['batch']['undoable'] );
    }

    public function testItReplacesTheBlockOnEachCall(): void
    {
        $this->tool->run( [ 'css' => '.a{}' ] );
        $this->tool->run( [ 'css' => '.b{}' ] );
        $this->assertSame( 1, substr_count( wp_get_custom_css(), CustomCss::BEGIN ) );
        $this->assertSame( '.b{}', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testDollarAndBackslashCssIsStoredLiterally(): void
    {
        $css = '.a::before { content: "$1 \\0 $0"; }';
        $this->tool->run( [ 'css' => '.old{}' ] );
        $this->tool->run( [ 'css' => $css ] );
        $this->assertSame( $css, CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testTheSameCssAgainChangesNothingAndRecordsNothing(): void
    {
        $this->tool->run( [ 'css' => '.a{}' ] );
        $r = $this->tool->run( [ 'css' => "  .a{}\n" ] );
        $this->assertFalse( $r['changed'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertCount( 1, $this->repo->rows );
    }

    public function testADryRunChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'css' => '.a{}', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'from_bytes' => null, 'to_bytes' => 4 ], $r['would_change'] );
        $this->assertSame( "body { color: red; }\n", wp_get_custom_css() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testBadArgumentsAndDangerousCssAreRejected(): void
    {
        $this->fails( [], '"css" is required.' );
        $this->fails( [ 'css' => 5 ], '"css" must be a string.' );
        $this->fails( [ 'css' => '   ' ], '"css" must not be empty.' );
        $this->fails( [ 'css' => '.a{}', 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'css' => '.a{} </style><script>x</script>' ], 'HTML' );
        $this->fails( [ 'css' => CustomCss::END ], 'markers' );
        $this->fails( [ 'css' => str_repeat( 'a', CustomCss::MAX_BYTES + 1 ) ], '"css" must be at most 100000 bytes.' );
    }

    public function testAFailedSaveIsAnErrorNotASilentSuccess(): void
    {
        $GLOBALS['__wp_custom_css_fail'] = true;
        $this->expectException( \RuntimeException::class );
        $this->tool->run( [ 'css' => '.a{}' ] );
    }

    public function testUndoRemovesABlockThatDidNotExistAndLeavesThePersonsCssByteForByte(): void
    {
        $original = $GLOBALS['__wp_custom_css'];
        $r        = $this->tool->run( [ 'css' => '.a{}' ] );
        $undo     = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( rtrim( $original ), rtrim( wp_get_custom_css() ) );
        $this->assertStringNotContainsString( CustomCss::BEGIN, wp_get_custom_css() );
    }

    public function testUndoPutsAPreviousBlockBack(): void
    {
        $this->tool->run( [ 'css' => '.first{}' ] );
        $second = $this->tool->run( [ 'css' => '.second{}' ] );
        $this->undo()->undo( $second['batch']['id'] );
        $this->assertSame( '.first{}', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testEditsOutsideTheBlockDoNotBlockUndoButEditsInsideDo(): void
    {
        $r = $this->tool->run( [ 'css' => '.a{}' ] );
        $GLOBALS['__wp_custom_css'] = "/* owner added this */\n" . $GLOBALS['__wp_custom_css'];
        $undo                       = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'], 'the owner\'s own CSS outside the block is not "changed since"' );
        $this->assertStringContainsString( '/* owner added this */', wp_get_custom_css() );

        $r2                         = $this->tool->run( [ 'css' => '.b{}' ] );
        $GLOBALS['__wp_custom_css'] = str_replace( '.b{}', '.b{ color: green }', $GLOBALS['__wp_custom_css'] );
        $undo2                      = $this->undo()->undo( $r2['batch']['id'] );
        $this->assertSame( 'changed_since', $undo2['items'][0]['result'] );
        $this->assertSame( '.b{ color: green }', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testUndoNeverDeletesTheOwnersCssBelowADanglingBegin(): void
    {
        $owner = ".owner-a{}\n" . CustomCss::BEGIN . "\n.old{}\n.owner-b{color:red}";
        $GLOBALS['__wp_custom_css'] = $owner;
        $r    = $this->tool->run( [ 'css' => '.new{}' ] );
        $this->assertStringContainsString( '.owner-b{color:red}', wp_get_custom_css() );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( $owner, rtrim( wp_get_custom_css() ), 'every owner byte is back' );
    }

    public function testItIsRegisteredAndNeedsEditCss(): void
    {
        $this->assertContains( 'set_custom_css', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_css', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'css' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
