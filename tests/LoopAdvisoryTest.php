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
}
