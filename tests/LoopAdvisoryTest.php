<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LoopAdvisory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/LoopAdvisory.php';

/**
 * What Divi 5.15.1 does with these tokens is proven in docs/loop-builder-evidence-5.15.1.md (make verify-loop-builder).
 */
class LoopAdvisoryTest extends TestCase
{
    private function token( string $name ): string
    {
        return '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => $name, 'settings' => new \stdClass() ] ] ) . ')$';
    }

    private function heading( string $name ): string
    {
        return '<!-- wp:divi/heading ' . json_encode( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $this->token( $name ) ] ] ] ] ) . ' /-->';
    }

    private function group( ?array $loop, string $inner ): string
    {
        $attrs = null === $loop ? new \stdClass() : [ 'module' => [ 'advanced' => [ 'loop' => [ 'desktop' => [ 'value' => $loop ] ] ] ] ];

        return '<!-- wp:divi/group ' . json_encode( $attrs ) . " -->\n{$inner}\n<!-- /wp:divi/group -->";
    }

    public function testALoopTokenInsideAnEnabledLoopAndNoTokensAreLeftAlone(): void
    {
        $this->assertNull( LoopAdvisory::advise( $this->group( [ 'enable' => 'on', 'queryType' => 'post_types' ], $this->heading( 'loop_post_title' ) ) ) );
        $this->assertNull( LoopAdvisory::advise( $this->group( null, '<!-- wp:divi/heading {} /-->' ) ) );
        $this->assertNull( LoopAdvisory::advise( 'not blocks at all' ) );
    }

    public function testALoopTokenOutsideAnyLoopIsFlagged(): void
    {
        $a = LoopAdvisory::advise( $this->group( null, $this->heading( 'loop_post_title' ) . $this->heading( 'loop_post_title' ) ) );
        $this->assertSame( [ [ 'name' => 'loop_post_title', 'count' => 2 ] ], $a['loop_outside_loop'] );
        $this->assertSame( [], $a['page_token_in_loop'] );
        $this->assertStringContainsString( 'topic=loops', $a['message'] );
    }

    public function testAContainerWithLoopSettingsButNoEnableOrOffIsNotALoop(): void
    {
        foreach ( [ [ 'queryType' => 'post_types' ], [ 'enable' => 'off', 'loopId' => '' ] ] as $loop ) {
            $a = LoopAdvisory::advise( $this->group( $loop, $this->heading( 'loop_post_title' ) ) );
            $this->assertSame( 'loop_post_title', $a['loop_outside_loop'][0]['name'] );
        }
    }

    public function testAPagePostTokenInsideALoopIsFlaggedAndOutsideItIsNot(): void
    {
        $loop = [ 'enable' => 'on' ];
        $a    = LoopAdvisory::advise( $this->group( $loop, $this->heading( 'post_title' ) . $this->heading( 'loop_post_title' ) ) );
        $this->assertSame( [ [ 'name' => 'post_title', 'count' => 1 ] ], $a['page_token_in_loop'] );
        $this->assertSame( [], $a['loop_outside_loop'] );
        $this->assertNull( LoopAdvisory::advise( $this->group( null, $this->heading( 'post_title' ) ) ), 'the page title on a plain page is fine' );
        $this->assertNull( LoopAdvisory::advise( $this->group( $loop, $this->heading( 'site_title' ) ) ), 'not a post_ token' );
    }

    public function testTheLoopMayBeOnTheModuleItselfOrAnAncestorFurtherUp(): void
    {
        $self = '<!-- wp:divi/image ' . json_encode( [ 'module' => [ 'advanced' => [ 'loop' => [ 'desktop' => [ 'value' => [ 'enable' => 'on' ] ] ] ] ], 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'src' => $this->token( 'loop_post_featured_image' ) ] ] ] ] ] ) . ' /-->';
        $this->assertNull( LoopAdvisory::advise( $self ) );
        $nested = $this->group( [ 'enable' => 'on' ], $this->group( null, $this->heading( 'loop_post_title' ) ) );
        $this->assertNull( LoopAdvisory::advise( $nested ) );
    }

    public function testMalformedTokensAreNotCountedHere(): void
    {
        $bad = '<!-- wp:divi/heading ' . json_encode( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => '$variable({"type":"content"' ] ] ] ] ) . ' /-->';
        $this->assertNull( LoopAdvisory::advise( $this->group( null, $bad ) ) );
    }

    public function testTheRealBuilderExportRaisesNothing(): void
    {
        $fx = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/valid/loop-builder-5.3.json' ), true );
        $this->assertNull( LoopAdvisory::advise( (string) $fx['post_content'] ) );
    }

    private function nav( mixed $target ): string
    {
        return '<!-- wp:divi/post-nav ' . json_encode( [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => $target ] ] ] ] ] ) . ' /-->';
    }

    public function testATokenOfAnotherKindThanTheInnermostLoopIsFlagged(): void
    {
        $menu = [ 'enable' => 'on', 'queryType' => 'menus', 'orderBy' => 'menu_order', 'order' => 'descending' ];
        $a    = LoopAdvisory::advise( $this->group( $menu, $this->heading( 'loop_post_title' ) . $this->heading( 'loop_menu_text' ) ) );
        $this->assertSame( [ [ 'name' => 'loop_post_title', 'count' => 1 ] ], $a['token_for_other_loop'] );
        $terms = [ 'enable' => 'on', 'queryType' => 'post_taxonomies' ];
        $a     = LoopAdvisory::advise( $this->group( $terms, $this->heading( 'loop_term_name' ) . $this->heading( 'loop_user_name' ) ) );
        $this->assertSame( [ [ 'name' => 'loop_user_name', 'count' => 1 ] ], $a['token_for_other_loop'] );
        $this->assertNull( LoopAdvisory::advise( $this->group( [ 'enable' => 'on', 'queryType' => 'repeater_team' ], $this->heading( 'loop_post_title' ) ) ), 'repeater loops are not judged' );
        // the INNERMOST loop decides: a term loop inside a post loop
        $nested = $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) . $this->group( [ 'enable' => 'on', 'loopId' => 'loop-b', 'queryType' => 'post_taxonomies' ], $this->heading( 'loop_term_name' ) ) );
        $this->assertNull( LoopAdvisory::advise( $nested ) );
    }

    public function testADuplicateLoopIdIsFlagged(): void
    {
        $two = $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) ) . $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) );
        $this->assertSame( [ [ 'name' => 'loop-a', 'count' => 2 ] ], LoopAdvisory::advise( $two )['duplicate_loop_id'] );
        $ok = $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) ) . $this->group( [ 'enable' => 'on', 'loopId' => 'loop-b' ], $this->heading( 'loop_post_title' ) );
        $this->assertNull( LoopAdvisory::advise( $ok ) );
        $off = $this->group( [ 'enable' => 'off', 'loopId' => 'loop-a' ], '' ) . $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) );
        $this->assertNull( LoopAdvisory::advise( $off ), 'a switched-off loop does not page' );
    }

    public function testAPostNavigationAimedAtNoLoopOnThePageIsFlagged(): void
    {
        $loop = $this->group( [ 'enable' => 'on', 'loopId' => 'loop-a' ], $this->heading( 'loop_post_title' ) );
        $this->assertNull( LoopAdvisory::advise( $loop . $this->nav( 'loop-a' ) ) );
        $this->assertNull( LoopAdvisory::advise( $loop . $this->nav( 'main_query' ) ) );
        $this->assertNull( LoopAdvisory::advise( $loop . $this->nav( '' ) ) );
        $a = LoopAdvisory::advise( $loop . $this->nav( 'loop-zzz' ) );
        $this->assertSame( [ [ 'name' => 'loop-zzz', 'count' => 1 ] ], $a['post_nav_target_missing'] );
    }

    public function testAMenuLoopOrderedAscendingByMenuOrderRunsBackwards(): void
    {
        $menu = static fn( array $over ): array => array_replace( [ 'enable' => 'on', 'queryType' => 'menus', 'loopId' => 'loop-m' ], $over );
        $this->assertSame( 1, LoopAdvisory::advise( $this->group( $menu( [ 'order' => 'ascending', 'orderBy' => 'menu_order' ] ), $this->heading( 'loop_menu_text' ) ) )['menu_loop_backwards'] );
        $this->assertSame( 1, LoopAdvisory::advise( $this->group( $menu( [ 'order' => 'ascending' ] ), $this->heading( 'loop_menu_text' ) ) )['menu_loop_backwards'], 'menu_order is the default' );
        $this->assertNull( LoopAdvisory::advise( $this->group( $menu( [ 'order' => 'ascending', 'orderBy' => 'title' ] ), $this->heading( 'loop_menu_text' ) ) ) );
        $this->assertNull( LoopAdvisory::advise( $this->group( $menu( [ 'order' => 'descending' ] ), $this->heading( 'loop_menu_text' ) ) ) );
    }
}
