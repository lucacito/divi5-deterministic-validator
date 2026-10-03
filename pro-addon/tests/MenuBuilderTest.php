<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\MenuBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class MenuBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_menus']            = [];
        $GLOBALS['__wp_menu_items']       = [];
        $GLOBALS['__wp_theme_mods']       = [];
        $GLOBALS['__wp_menu_item_fail_at'] = 0;
        $GLOBALS['__wp_menu_item_calls']   = 0;
        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
    }

    /** @return array<string,mixed> */
    private function custom( string $title, string $url, int $parent = -1 ): array
    {
        return [ 'title' => $title, 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => $url, 'parent' => $parent, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];
    }

    /** @return array<string,mixed> */
    private function page( string $title, int $id ): array
    {
        return [ 'title' => $title, 'type' => 'post_type', 'object' => 'page', 'object_id' => $id, 'url' => '', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];
    }

    /** Seeds a hand-made "Main Menu" with nesting, classes and a link target, assigned to the primary location. */
    private function seedOldMenu(): int
    {
        $id  = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        $top = wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old Top', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/top', 'menu-item-classes' => 'btn big', 'menu-item-target' => '_blank', 'menu-item-xfn' => 'nofollow', 'menu-item-attr-title' => 'Tip', 'menu-item-description' => 'Desc' ] );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old Child', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => 9, 'menu-item-parent-id' => $top ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );

        return $id;
    }

    public function testTheTargetLocationPrefersPrimaryOrMainThenTheFirstThenNone(): void
    {
        $GLOBALS['__wp_registered_menus'] = [ 'footer-menu' => 'Footer', 'primary-menu' => 'Primary' ];
        $this->assertSame( 'primary-menu', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [ 'x' => 'X', 'main_nav' => 'Main' ];
        $this->assertSame( 'main_nav', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [ 'alpha' => 'A', 'beta' => 'B' ];
        $this->assertSame( 'alpha', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [];
        $this->assertSame( '', MenuBuilder::targetLocation() );
    }

    public function testBuildCreatesTheMenuInOrderAndPointsTheLocationAtIt(): void
    {
        $added = MenuBuilder::build( [ $this->page( 'Home', 5 ), $this->custom( 'Blog', 'https://example.com/blog' ) ], 'primary-menu' );
        $this->assertSame( 2, $added );
        $s = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertTrue( $s['exists'] );
        $this->assertSame( [ 'Home', 'Blog' ], array_column( $s['items'], 'title' ) );
        $this->assertSame( [ 'post_type', 'custom' ], array_column( $s['items'], 'type' ) );
        $this->assertSame( 5, $s['items'][0]['object_id'] );
        $this->assertSame( '', $s['items'][0]['url'], 'a page link keeps no url of its own' );
        $this->assertSame( 'https://example.com/blog', $s['items'][1]['url'] );
        $this->assertSame( $s['menu_id'], $s['location_menu_id'] );
        $this->assertTrue( MenuBuilder::canonical( $s )['at_location'] );
    }

    public function testRebuildingReplacesTheItemsWithoutDuplicates(): void
    {
        MenuBuilder::build( [ $this->page( 'Home', 5 ), $this->page( 'About', 6 ) ], 'primary-menu' );
        MenuBuilder::build( [ $this->page( 'Contact', 8 ) ], 'primary-menu' );
        $this->assertSame( [ 'Contact' ], array_column( MenuBuilder::snapshot( 'primary-menu' )['items'], 'title' ) );
        $this->assertCount( 1, $GLOBALS['__wp_menus'] );
    }

    public function testAMenuWithNoThemeLocationIsStillBuiltButNotAssigned(): void
    {
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], '' );
        $s = MenuBuilder::snapshot( '' );
        $this->assertTrue( $s['exists'] );
        $this->assertFalse( MenuBuilder::canonical( $s )['at_location'] );
        $this->assertSame( [], $GLOBALS['__wp_theme_mods'] );
    }

    public function testSnapshotThenRestoreRoundTripsTitlesLinksNestingClassesAndTargets(): void
    {
        $this->seedOldMenu();
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( -1, $snap['items'][0]['parent'] );
        $this->assertSame( 0, $snap['items'][1]['parent'], 'the child points at its parent\'s position' );
        $this->assertSame( 'btn big', $snap['items'][0]['classes'] );
        $this->assertSame( '_blank', $snap['items'][0]['target'] );

        MenuBuilder::build( [ $this->page( 'New', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );

        $after = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( MenuBuilder::canonical( $snap ), MenuBuilder::canonical( $after ) );
        $this->assertSame( 'Tip', $after['items'][0]['attr_title'] );
        $this->assertSame( 'nofollow', $after['items'][0]['xfn'] );
        $this->assertSame( 'Desc', $after['items'][0]['description'] );
        $this->assertSame( 0, $after['items'][1]['parent'] );
    }

    public function testRestoreOfAMenuThatDidNotExistDeletesItAndClearsTheLocation(): void
    {
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertFalse( $snap['exists'] );
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
        $this->assertArrayNotHasKey( 'primary-menu', $GLOBALS['__wp_theme_mods']['nav_menu_locations'] ?? [] );
    }

    public function testRestoreGivesTheLocationBackToTheOtherMenuItPointedAt(): void
    {
        $footer = wp_create_nav_menu( 'Footer Links' );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $footer ] );
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );
        $this->assertSame( $footer, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary-menu'] );
        $this->assertFalse( wp_get_nav_menu_object( MenuBuilder::MENU_NAME ) );
    }

    public function testRestoreRecreatesAMenuThatWasDeletedInTheMeantime(): void
    {
        $id   = $this->seedOldMenu();
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        wp_delete_nav_menu( $id );
        MenuBuilder::restore( $snap );
        $after = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( MenuBuilder::canonical( $snap ), MenuBuilder::canonical( $after ) );
        $this->assertNotSame( $id, $after['menu_id'], 'a recreated menu has a new id; the canonical value must not depend on it' );
        $this->assertSame( $after['menu_id'], $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary-menu'] );
    }
}
