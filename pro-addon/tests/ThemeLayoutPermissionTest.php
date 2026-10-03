<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use AiEditorDivi5\Pro\Tools\ManageThemeTemplate;
use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

/**
 * Standing rule for every Pro tool: the narrowest real capability, checked by the REGISTRY, and a user without it is
 * refused with nothing changed. These tests go through the real ToolRegistry, not the tool's run().
 */
class ThemeLayoutPermissionTest extends TestCase
{
    use ThemeLayoutFixture;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $GLOBALS['__divi_tb_store_calls'] = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    /** @return array<string,array{string,array<string,mixed>}> */
    public static function calls(): array
    {
        return [
            'list_theme_layouts'  => [ ListThemeLayouts::NAME, [ 'layout_id' => 201 ] ],
            'update_theme_layout' => [ UpdateThemeLayout::NAME, [ 'layout_id' => 201, 'content' => '__OTHER__' ] ],
            'manage_theme_template' => [ ManageThemeTemplate::NAME, [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ] ],
            'edit_theme_layout'   => [ EditThemeLayout::NAME, [ 'layout_id' => 201, 'find' => '"builderVersion":"5.14.0"', 'replace' => '"builderVersion":"5.14.1"', 'expect_count' => 4 ] ],
        ];
    }

    /** @return list<ProTool> */
    private function tools(): array
    {
        return [ new ListThemeLayouts(), new UpdateThemeLayout( $this->log ), new EditThemeLayout( $this->log ), new ManageThemeTemplate( $this->log ) ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function call( string $name, array $args ): array
    {
        if ( ( $args['content'] ?? null ) === '__OTHER__' ) {
            $args['content'] = self::otherHeader();
        }
        $r = ( new ToolRegistry( $this->tools(), static fn (): bool => true ) )->claim( null, $name, $args, 1 );

        return (array) $r->get_data();
    }

    private function assertRefusedAndUnchanged( array $data, string $why ): void
    {
        $this->assertArrayHasKey( 'error', $data, $why );
        $this->assertSame( -32602, $data['error']['code'], $why );
        $this->assertSame( self::header(), $this->layoutContent( 201 ), $why );
        $this->assertSame( '1', $GLOBALS['__wp_postmeta'][200]['_et_enabled'], $why );
        $this->assertSame( [], $GLOBALS['__divi_tb_store_calls'] ?? [], $why );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
        $this->assertSame( [], $this->repo->rows, $why );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testAnAdministratorIsAllowed( string $name, array $args ): void
    {
        $data = $this->call( $name, $args );
        $this->assertArrayHasKey( 'result', $data, $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditThemeOptionsTheRegistryRefusesBeforeTheToolRuns( string $name, array $args ): void
    {
        // An editor: may edit others' posts and has Divi's permission, but not edit_theme_options.
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'manage_options' => false ];
        $data                 = $this->call( $name, $args );
        $this->assertRefusedAndUnchanged( $data, $name );
        $this->assertStringContainsString( 'permission', $data['error']['message'] );
        $this->assertSame( [], $GLOBALS['__divi_tb_calls'], 'the tool never ran: Divi was not even asked' );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutDivisThemeBuilderPermissionTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditOthersPostsTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditPostOnThatLayoutOrTemplateTheToolRefuses( string $name, array $args ): void
    {
        $id                   = (int) ( $args['layout_id'] ?? $args['template_id'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, "edit_post:{$id}" => false, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    public function testLockedLicenceRegistersNothingAndClaimsNothing(): void
    {
        $r = new ToolRegistry( $this->tools(), static fn (): bool => false );
        $this->assertSame( [ [ 'name' => 'x' ] ], $r->definitions( [ [ 'name' => 'x' ] ] ) );
        $this->assertNull( $r->claim( null, 'update_theme_layout', [ 'layout_id' => 201, 'content' => self::otherHeader() ], 1 ) );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
    }
}
