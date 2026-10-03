<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\PhpProposals;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ProposePhpSnippet;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProposePhpSnippetTest extends TestCase
{
    private ProposePhpSnippet $tool;

    protected function setUp(): void
    {
        $this->tool              = new ProposePhpSnippet();
        $GLOBALS['__wp_options'] = [];
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 0, PhpProposals::count() );
    }

    public function testItStoresAnInertProposalAndSaysItWasNotExecuted(): void
    {
        $r = $this->tool->run( [ 'title' => 'CPT', 'description' => 'Adds <b>a</b> type', 'code' => "<?php\nregister_post_type( 'x' );" ] );
        $this->assertTrue( $r['proposed'] );
        $this->assertFalse( $r['executed'] );
        $this->assertStringContainsString( 'NOT executed', $r['message'] );
        $this->assertStringContainsString( 'tab=pro', $r['review_url'] );
        $stored = PhpProposals::all()[0];
        $this->assertSame( $r['id'], $stored['id'] );
        $this->assertSame( "<?php\nregister_post_type( 'x' );", $stored['code'], 'the code is kept exactly as sent' );
        $this->assertSame( 'Adds a type', $stored['description'], 'tags are stripped from the description' );
        $this->assertArrayNotHasKey( 'batch', $r, 'it changes nothing on the site, so there is nothing to undo' );
    }

    public function testTheArgumentsAreTypeChecked(): void
    {
        $this->fails( [], '"code" is required.' );
        $this->fails( [ 'code' => '   ' ], '"code" must not be empty.' );
        $this->fails( [ 'code' => 5 ], '"code" must be a string.' );
        $this->fails( [ 'code' => 'x', 'title' => [ 'a' ] ], '"title" must be a string.' );
        $this->fails( [ 'code' => 'x', 'description' => 5 ], '"description" must be a string.' );
        $this->fails( [ 'code' => str_repeat( 'a', 100001 ) ], '"code" must be at most 100000 bytes.' );
        $this->fails( [ 'code' => 'x', 'title' => str_repeat( 'a', 121 ) ], '"title" must be at most 120 bytes.' );
        $this->fails( [ 'code' => 'x', 'description' => str_repeat( 'a', 2001 ) ], '"description" must be at most 2000 bytes.' );
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'propose_php_snippet', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'code' ], $d['inputSchema']['required'] );
    }
}
