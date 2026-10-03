<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class ThemeLayoutKindTest extends TestCase
{
    use ThemeLayoutFixture;

    /** @var list<array{int,int}> */
    private array $restored = [];
    private int $cleared    = 0;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->restored = [];
        $this->cleared  = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function kind( bool $ok = true ): ThemeLayoutKind
    {
        return new ThemeLayoutKind(
            function ( int $id, int $version ) use ( $ok ): array {
                $this->restored[] = [ $id, $version ];
                if ( ! $ok ) {
                    return [ 'ok' => false, 'error' => 'version_not_found' ];
                }
                $GLOBALS['__wp_posts'][ $id ]->post_content = 'OLD';

                return [ 'ok' => true, 'snapshot' => [ 'stored' => true, 'version_id' => 9, 'reason' => null ] ];
            },
            function (): void {
                $this->cleared++;
            }
        );
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return ThemeLayoutKind::itemFor( 201, 'Main Header', 'header', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
    }

    public function testItemForNamesTheKindAndRecordsTheUndoPoint(): void
    {
        $item = $this->item();
        $this->assertSame( 'theme_layout_content', $item['kind'] );
        $this->assertSame( '201', $item['ref'] );
        $this->assertSame( 'Content of the header "Main Header" (#201)', $item['label'] );
        $this->assertSame( Canon::hash( 'NEW' ), $item['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 3 ], $item['undo'] );
    }

    public function testAllowedNeedsTheToolsCapabilityAndALiveEditableLayout(): void
    {
        $this->assertTrue( $this->kind()->allowed( [ 'ref' => '201' ] ) );
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '203' ] ), 'a body layout' );
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '5' ] ), 'a page' );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true ];
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '201' ] ), 'no edit_theme_options' );
    }

    public function testRestoreUsesTheRecordedVersionClearsCachesAndBuildsTheInverse(): void
    {
        $r = $this->kind()->restore( $this->item() );
        $this->assertSame( [ [ 201, 3 ] ], $this->restored );
        $this->assertSame( 1, $this->cleared );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'theme_layout_content', $r['inverse']['kind'] );
        $this->assertSame( Canon::hash( 'OLD' ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 9 ], $r['inverse']['undo'] );
    }

    public function testAFailedRestoreClearsNothingAndReportsTheReason(): void
    {
        $this->assertSame( [ 'ok' => false, 'reason' => 'version_not_found' ], $this->kind( false )->restore( $this->item() ) );
        $this->assertSame( 0, $this->cleared );
    }

    public function testWithoutAVersionNothingIsRestored(): void
    {
        $item                       = $this->item();
        $item['undo']['version_id'] = null;
        $this->assertSame( [ 'ok' => false, 'reason' => 'no_version' ], $this->kind()->restore( $item ) );
        $this->assertSame( [], $this->restored );
        $this->assertSame( 0, $this->cleared );
    }
}
