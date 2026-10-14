<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

/**
 * Source-level invariants of HistoryService::writeTitle (the behaviour is covered in HistoryServiceTest and live by
 * `make verify-page-title`): a rename puts the page's own stored content back after core's sanitising, always removes
 * that filter again, and touches no security filter.
 */
class WriteTitleKeepsContentTest extends TestCase
{
    private function body( string $method ): string
    {
        $m     = new \ReflectionMethod( HistoryService::class, $method );
        $lines = file( (string) $m->getFileName() ) ?: [];

        return implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
    }

    public function testWriteTitleRestoresTheStoredContentLateAndAlwaysRemovesTheFilter(): void
    {
        $src = $this->body( 'writeTitle' );

        $this->assertStringContainsString( "add_filter( 'wp_insert_post_data'", $src );
        $this->assertStringContainsString( 'PHP_INT_MAX', $src, 'the filter must run after every other one' );
        $this->assertStringContainsString( 'wp_slash( $storedContent )', $src, 'wp_insert_post_data receives slashed data' );
        $this->assertStringContainsString( "(int) ( \$postarr['ID'] ?? 0 ) === \$pageId", $src, 'only the page being renamed is protected' );
        $this->assertMatchesRegularExpression( '/try\s*\{.*wp_update_post.*\}\s*finally\s*\{\s*remove_filter\(\s*\'wp_insert_post_data\'/s', $src, 'removed in a finally block' );
        $this->assertLessThan( strpos( $src, 'wp_update_post(' ), strpos( $src, "add_filter( 'wp_insert_post_data'" ), 'registered before the update' );
    }

    public function testWriteTitleStillPassesOnlyTheIdAndTheTitle(): void
    {
        $this->assertStringContainsString( "wp_slash( [ 'ID' => \$pageId, 'post_title' => \$title ] )", $this->body( 'writeTitle' ) );
    }

    public function testWriteTitleTouchesNoSecurityFilter(): void
    {
        $src = $this->body( 'writeTitle' );
        foreach ( [ 'kses_remove_filters', 'kses_init_filters', 'kses_init', 'wp_filter_post_kses', 'wp_filter_kses', 'remove_all_filters', 'unfiltered_html' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $src, $needle );
        }
        $this->assertSame( 1, substr_count( $src, 'remove_filter(' ), 'it removes only its own filter' );
    }

    public function testContentWritesAreUnchanged(): void
    {
        $this->assertStringNotContainsString( 'wp_insert_post_data', $this->body( 'write' ) );
        $this->assertStringNotContainsString( 'wp_insert_post_data', $this->body( 'restore' ) );
    }
}
