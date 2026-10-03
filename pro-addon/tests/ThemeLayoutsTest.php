<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

class ThemeLayoutsTest extends TestCase
{
    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, string $status = 'publish', string $title = '', array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = $title;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200, 210 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );

        $this->post( 100, 'et_theme_builder', 'publish', 'Theme Builder' );
        $this->post( 200, 'et_template', 'publish', 'Everything', [
            '_et_default' => '1', '_et_enabled' => '1',
            '_et_use_on' => [ 'singular:post_type:post:all' ], '_et_exclude_from' => [],
            '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1',
            '_et_footer_layout_id' => 202, '_et_footer_layout_enabled' => '0',
            '_et_body_layout_id' => 203,   '_et_body_layout_enabled' => '1',
        ] );
        $this->post( 201, 'et_header_layout', 'publish', 'Main Header' );
        $this->post( 202, 'et_footer_layout', 'publish', 'Main Footer' );
        $this->post( 203, 'et_body_layout', 'publish', 'Body' );
        $this->post( 210, 'et_template', 'publish', 'Shop', [
            '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => [ 'archive:taxonomy:product_cat:all' ],
            '_et_header_layout_id' => 211, '_et_header_layout_enabled' => '1',
            '_et_footer_layout_id' => 0, '_et_body_layout_id' => 0,
        ] );
        $this->post( 211, 'et_header_layout', 'publish', 'Shop Header' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can'] = true;
    }

    public function testKindOfMapsOnlyHeaderAndFooter(): void
    {
        $this->assertSame( 'header', ThemeLayouts::kindOf( 'et_header_layout' ) );
        $this->assertSame( 'footer', ThemeLayouts::kindOf( 'et_footer_layout' ) );
        foreach ( [ 'et_body_layout', 'et_template', 'et_theme_builder', 'page', 'post', '' ] as $other ) {
            $this->assertNull( ThemeLayouts::kindOf( $other ), $other );
        }
    }

    public function testWithoutDiviFunctionsTheLiveSetIsEmpty(): void
    {
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( null, null ) );
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( static fn () => 100, null ) );
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( null, static fn () => [ 200 ] ) );
    }

    public function testTheThemeBuilderPostIsAskedForWithCreateFalseAndNeverCreated(): void
    {
        $seen = [];
        $ids  = ThemeLayouts::liveTemplateIdsWith(
            static function ( $live, $create ) use ( &$seen ) { $seen[] = [ 'post_id', $live, $create ]; return 100; },
            static function ( $live, $tb ) use ( &$seen ) { $seen[] = [ 'ids', $live, $tb ]; return [ '200', 210, 0, 'x' ]; }
        );
        $this->assertSame( [ [ 'post_id', true, false ], [ 'ids', true, 100 ] ], $seen );
        $this->assertSame( [ 200, 210 ], $ids );

        ThemeLayouts::liveTemplateIds();
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertTrue( $call[1], 'the LIVE Theme Builder is the one read' );
                $this->assertFalse( $call[2], 'create must be false: reading must never create a Theme Builder post' );
            }
        }
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
    }

    public function testNoLiveThemeBuilderMeansNothing(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( [], ThemeLayouts::liveTemplateIds() );
        $this->assertSame( [], ThemeLayouts::liveTemplates() );
        $this->assertSame( [], ThemeLayouts::liveLayoutKinds() );
        $l = ThemeLayouts::listing();
        $this->assertSame( 0, $l['count'] );
        $this->assertSame( [], $l['templates'] );
        $this->assertStringContainsString( 'No live', (string) $l['note'] );
    }

    public function testLiveTemplatesCarryTheVerifiedMetaAndTheirLayouts(): void
    {
        $t = ThemeLayouts::liveTemplates();
        $this->assertCount( 2, $t );
        $this->assertSame( [
            'template_id'  => 200,
            'title'        => 'Everything',
            'default'      => true,
            'enabled'      => true,
            'use_on'       => [ 'singular:post_type:post:all' ],
            'exclude_from' => [],
            'header'       => [ 'layout_id' => 201, 'title' => 'Main Header', 'enabled' => true ],
            'footer'       => [ 'layout_id' => 202, 'title' => 'Main Footer', 'enabled' => false ],
            'body'         => [ 'layout_id' => 203, 'title' => 'Body', 'enabled' => true ],
        ], $t[0] );
        $this->assertFalse( $t[1]['default'] );
        $this->assertNull( $t[1]['footer'] );
        $this->assertNull( $t[1]['body'] );
        $this->assertSame( 211, $t[1]['header']['layout_id'] );
    }

    public function testOnlyHeadersAndFootersOfLiveTemplatesAreEditableKinds(): void
    {
        $this->assertSame( [ 201 => 'header', 202 => 'footer', 211 => 'header' ], ThemeLayouts::liveLayoutKinds() );
    }

    public function testBrokenReferencesAreIgnored(): void
    {
        $this->post( 220, 'et_template', 'publish', 'Broken', [
            '_et_header_layout_id' => 5,   // a page, not a header layout
            '_et_footer_layout_id' => 221, // a trashed footer
            '_et_body_layout_id' => 9999,  // does not exist
        ] );
        $this->post( 5, 'page', 'publish', 'A page' );
        $this->post( 221, 'et_footer_layout', 'trash', 'Gone' );
        $this->post( 230, 'page', 'publish', 'Not a template' );
        $this->post( 231, 'et_template', 'draft', 'Unpublished template', [ '_et_header_layout_id' => 232 ] );
        $this->post( 232, 'et_header_layout', 'publish', 'Header of a draft template' );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200, 220, 230, 231, 404 ];

        $kinds = ThemeLayouts::liveLayoutKinds();
        $this->assertSame( [ 201 => 'header', 202 => 'footer' ], $kinds );
        $templates = ThemeLayouts::liveTemplates();
        $this->assertSame( [ 200, 220 ], array_column( $templates, 'template_id' ), 'non-templates, unpublished templates and missing ids are skipped' );
        $this->assertNull( $templates[1]['header'] );
        $this->assertNull( $templates[1]['footer'] );
        $this->assertNull( $templates[1]['body'] );
    }

    public function testLayoutsOfADraftThemeBuilderAreNotEditable(): void
    {
        $this->post( 300, 'et_theme_builder', 'auto-draft', 'Draft Theme Builder' );
        $this->post( 301, 'et_template', 'publish', 'Draft template', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout', 'publish', 'Draft-only header' );
        $GLOBALS['__divi_tb']['draft_id']           = 300;
        $GLOBALS['__divi_tb']['draft_template_ids'] = [ 301 ];

        $this->assertArrayNotHasKey( 302, ThemeLayouts::liveLayoutKinds() );
    }

    public function testListingMarksEditableForTheCurrentUserAndOmitsAbsentLayouts(): void
    {
        $l = ThemeLayouts::listing();
        $this->assertSame( 2, $l['count'] );
        $this->assertNull( $l['note'] );
        $first = $l['templates'][0];
        $this->assertTrue( $first['header']['editable'] );
        $this->assertTrue( $first['footer']['editable'] );
        $this->assertFalse( $first['body']['editable'], 'body layouts are listed but never editable' );
        $second = $l['templates'][1];
        $this->assertArrayNotHasKey( 'footer', $second );
        $this->assertArrayNotHasKey( 'body', $second );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $l = ThemeLayouts::listing();
        $this->assertFalse( $l['templates'][0]['header']['editable'] );
    }

    public function testUserMayWithFailsClosedAndAsksForTheThemeBuilderCapability(): void
    {
        $this->assertFalse( ThemeLayouts::userMayWith( true, null ), 'a missing Divi function fails closed' );
        $this->assertFalse( ThemeLayouts::userMayWith( true, static fn () => false ) );
        $asked = [];
        $this->assertTrue( ThemeLayouts::userMayWith( true, static function ( $cap ) use ( &$asked ) { $asked[] = $cap; return true; } ) );
        $this->assertSame( [ 'theme_builder' ], $asked );
        $this->assertFalse( ThemeLayouts::userMayWith( false, static fn () => true ) );
    }

    public function testListingIsNotEditableWhenDiviRoleEditorDeniesTheThemeBuilder(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $l = ThemeLayouts::listing();
        $this->assertFalse( $l['templates'][0]['header']['editable'] );
        $this->assertFalse( $l['templates'][0]['footer']['editable'] );
        $this->assertFalse( $l['templates'][0]['body']['editable'] );
        $this->assertFalse( $l['templates'][1]['header']['editable'] );
    }

    public function testLiveTemplatesAreNeverWrittenOrCreatedBySimplyReadingThem(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ThemeLayouts::listing();
        ThemeLayouts::liveLayoutKinds();
        $this->assertSame( $before[1], $GLOBALS['__wp_postmeta'] );
        $this->assertCount( count( $before[0] ), $GLOBALS['__wp_posts'] );
    }
}
