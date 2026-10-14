<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
use AiEditorDivi5\WP\PageTitle;
use AiEditorDivi5\WP\TitleChange;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/TitleChange.php';

class TitleChangeTest extends TestCase
{
    private function plan( string $title, string $content, ?string $newContent, array $args ): array
    {
        return TitleChange::plan( $title, $content, $newContent, PageTitle::parse( $args ) );
    }

    public function testNoTitleLeavesTheApprovalValuesRaw(): void
    {
        $p = $this->plan( 'Old', 'stored', 'new', [] );
        $this->assertFalse( $p['present'] );
        $this->assertFalse( $p['changed'] );
        $this->assertNull( $p['writeTitle'] );
        $this->assertNull( $p['report'] );
        $this->assertNull( $p['message'] );
        $this->assertSame( 'new', $p['subject'] );
        $this->assertSame( 'stored', $p['base'] );
    }

    public function testChangedTitle(): void
    {
        $p = $this->plan( 'Old', 'stored', 'new', [ 'title' => 'New' ] );
        $this->assertTrue( $p['present'] );
        $this->assertTrue( $p['changed'] );
        $this->assertSame( 'New', $p['writeTitle'] );
        $this->assertSame( PageTitle::report( 'Old', 'New', true ), $p['report'] );
        $this->assertSame( PageTitle::notRestorableMessage( 'Old' ), $p['message'] );
        $this->assertSame( ApprovalMode::withTitle( 'new', 'New' ), $p['subject'] );
        $this->assertSame( ApprovalMode::withTitle( 'stored', 'Old' ), $p['base'] );
    }

    public function testChangedTitleOnlyUsesTheStoredContentAsSubject(): void
    {
        $p = $this->plan( 'Old', 'stored', null, [ 'title' => 'New' ] );
        $this->assertSame( ApprovalMode::withTitle( 'stored', 'New' ), $p['subject'] );
        $this->assertSame( ApprovalMode::withTitle( 'stored', 'Old' ), $p['base'] );
    }

    public function testUnchangedTitleOnly(): void
    {
        $p = $this->plan( 'Old', 'stored', null, [ 'title' => 'Old' ] );
        $this->assertTrue( $p['present'] );
        $this->assertFalse( $p['changed'] );
        $this->assertNull( $p['writeTitle'] );
        $this->assertSame( PageTitle::report( 'Old', 'Old', false ), $p['report'] );
        $this->assertSame( PageTitle::unchangedMessage( 'Old' ), $p['message'] );
        $this->assertSame( 'stored', $p['subject'] );
        $this->assertSame( 'stored', $p['base'] );
    }

    public function testUnchangedTitleInACombinedCall(): void
    {
        $p = $this->plan( 'Old', 'stored', 'new', [ 'title' => 'Old' ] );
        $this->assertFalse( $p['changed'] );
        $this->assertSame( PageTitle::keptMessage( 'Old' ), $p['message'] );
        $this->assertSame( 'new', $p['subject'] );
        $this->assertSame( 'stored', $p['base'] );
    }

    public function testAnApprovalKeyEqualsThePreviousInlineFormula(): void
    {
        foreach ( [ [ 'Old', 'stored', 'new', 'New' ], [ 'Old', 'stored', null, 'New' ], [ 'Old', 'stored', 'new', 'Old' ], [ 'Old', 'stored', null, null ] ] as [ $title, $stored, $new, $arg ] ) {
            $p       = $this->plan( $title, $stored, $new, null === $arg ? [] : [ 'title' => $arg ] );
            $changed = null !== $arg && $arg !== $title;
            $write   = $changed ? $arg : null;
            $subject = ApprovalMode::withTitle( null !== $new ? $new : $stored, $write );
            $base    = ApprovalMode::withTitle( $stored, null === $write ? null : $title );
            $this->assertSame(
                ApprovalMode::key( 7, 5, $subject, $base ),
                ApprovalMode::key( 7, 5, $p['subject'], $p['base'] )
            );
        }
    }
}
