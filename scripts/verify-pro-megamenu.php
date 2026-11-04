<?php
/**
 * Setup, step and teardown helper for `make verify-pro-megamenu` (Pro mega menu, real Divi 5.15.1 on the Docker dev site).
 *
 * It is called by scripts/verify-pro-megamenu.cjs through WP-CLI, one step per call, and every step prints one line:
 *   RESULT {json}   the step worked; the JSON is what the browser part reads
 *   ERROR {json}    the step failed (exit 1)
 *
 * Steps (the first argument; a second one where noted):
 *   setup              records the stored global header byte for byte, creates the throwaway menu "aied megamenu test" (Services with
 *                      grouped sub-items, Blog with five posts, Home, About), points the header's Menu module at it (the only change to
 *                      the header besides the mega menu itself, reverted by teardown)
 *   list               the 20 layout ids
 *   dry <layout>       MegaMenuService::apply(layout, dry run): the verdict
 *   apply <layout>     the real apply through the real service and LayoutWriter, then the same call again (must be changed:false)
 *   remove             MegaMenuService::remove() twice (the second must be changed:false), and whether the blocks equal the original
 *   undo <batch>       Batches::undo()->undo(batch) under user 1
 *   state              sha1 of the stored header and whether a mega menu is in it
 *   negative           the refusals: no global header, a copy without a Menu module, a user without the Theme Builder permission
 *   teardown           restores the header raw, removes the menu, items, fixtures and users; a leftover run is cleaned by setup too
 *
 * Dev site only (scripts/dev-guard.php). Every object it creates is titled or named `aied megamenu ...` and recorded in one
 * option, so an aborted run is cleaned by the next setup or teardown.
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutCaches;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;

if ( ! class_exists( MegaMenuService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! function_exists( 'et_theme_builder_insert_layout' ) || ! function_exists( 'et_theme_builder_store_template' ) ) {
    WP_CLI::error( 'Divi Theme Builder functions not available.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = []; // the shared gate layout (DevGuardTest): the guard above runs before any work

const AIED_MM_STATE     = 'aied_megamenu_gate_state';
const AIED_MM_MENU_NAME = 'aied megamenu test';
const AIED_MM_NOT_FOUND = '__none__';

$aiedMmStep = (string) ( $args[0] ?? '' );

function mm_out( array $data ): void
{
    echo 'RESULT ' . wp_json_encode( $data ) . "\n";
}

function mm_fail( string $message ): void
{
    echo 'ERROR ' . wp_json_encode( [ 'message' => $message ] ) . "\n";
    exit( 1 );
}

function mm_state(): ?array
{
    $s = get_option( AIED_MM_STATE, null );

    return is_array( $s ) ? $s : null;
}

function mm_save_state( array $s ): void
{
    update_option( AIED_MM_STATE, $s, false );
}

/** The stored content exactly as it is in the database (no filters, no normalisation). */
function mm_raw( int $id ): string
{
    global $wpdb;

    return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
}

function mm_service( ?\Closure $header = null ): MegaMenuService
{
    return new MegaMenuService( new LayoutWriter( Batches::log() ), null, null, $header );
}

function mm_admin(): void
{
    wp_set_current_user( 1 );
}

function mm_header_id(): int
{
    $s = mm_state();
    if ( null === $s ) {
        mm_fail( 'no run is set up: run setup first' );
    }

    return (int) $s['header_id'];
}

/** Restores the header raw and removes everything the run created. Returns what it found. */
function mm_teardown(): array
{
    $s = mm_state();
    if ( null === $s ) {
        return [ 'restored' => false, 'note' => 'nothing to restore' ];
    }
    global $wpdb;
    mm_admin();
    $hid      = (int) $s['header_id'];
    $fallback = false;
    if ( mm_raw( $hid ) !== (string) $s['original'] ) {
        wp_update_post( [ 'ID' => $hid, 'post_content' => wp_slash( (string) $s['original'] ) ] );
        clean_post_cache( $hid );
        if ( mm_raw( $hid ) !== (string) $s['original'] ) {
            $wpdb->update( $wpdb->posts, [ 'post_content' => (string) $s['original'] ], [ 'ID' => $hid ] );
            clean_post_cache( $hid );
            $fallback = true;
        }
    }
    try {
        LayoutCaches::clear();
    } catch ( \Throwable $e ) {
        // Divi's caches are cleared by the next save anyway.
    }
    $sameBytes = mm_raw( $hid ) === (string) $s['original'];

    $menuLeft = 0;
    $menu     = wp_get_nav_menu_object( AIED_MM_MENU_NAME );
    if ( $menu ) {
        wp_delete_nav_menu( (int) $menu->term_id );
    }
    $menuLeft = wp_get_nav_menu_object( AIED_MM_MENU_NAME ) ? 1 : 0;

    foreach ( (array) ( $s['item_ids'] ?? [] ) as $itemId ) {
        if ( get_post( (int) $itemId ) ) {
            wp_delete_post( (int) $itemId, true );
        }
    }
    $extraLeft = 0;
    if ( ! empty( $s['tb_id'] ) && ! empty( $s['template_id'] ) ) {
        delete_post_meta( (int) $s['tb_id'], '_et_template', (int) $s['template_id'] );
    }
    foreach ( (array) ( $s['extra_ids'] ?? [] ) as $extraId ) {
        if ( get_post( (int) $extraId ) ) {
            wp_delete_post( (int) $extraId, true );
        }
        if ( get_post( (int) $extraId ) ) {
            $extraLeft++;
        }
    }
    $usersLeft = 0;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( (array) ( $s['user_ids'] ?? [] ) as $userId ) {
        if ( get_userdata( (int) $userId ) ) {
            wp_delete_user( (int) $userId );
        }
        if ( get_userdata( (int) $userId ) ) {
            $usersLeft++;
        }
    }
    delete_option( AIED_MM_STATE );

    return [
        'restored'    => true,
        'same_bytes'  => $sameBytes,
        'fallback'    => $fallback,
        'menu_left'   => $menuLeft,
        'extra_left'  => $extraLeft,
        'users_left'  => $usersLeft,
        'header_id'   => $hid,
    ];
}

/** The blocks of a stored content, compared semantically (attribute order and escaping do not count). */
function mm_blocks_equal( string $a, string $b ): bool
{
    // Divi adds per-parse bookkeeping (id, index, orderIndex, parentId, storeInstance) that depends on how many blocks were parsed
    // before in the same process, so it is left out of the comparison.
    $strip = static function ( array $blocks ) use ( &$strip ): array {
        $out = [];
        foreach ( $blocks as $block ) {
            $out[] = [
                'blockName'   => $block['blockName'] ?? null,
                'attrs'       => $block['attrs'] ?? [],
                'innerHTML'   => $block['innerHTML'] ?? '',
                'innerBlocks' => $strip( (array) ( $block['innerBlocks'] ?? [] ) ),
            ];
        }

        return $out;
    };

    return $strip( parse_blocks( $a ) ) === $strip( parse_blocks( $b ) );
}

switch ( $aiedMmStep ) {

    // ---- setup -------------------------------------------------------------------------------------------------------
    case 'setup':
        mm_admin();
        if ( null !== mm_state() ) {
            mm_teardown();
        }
        $header = mm_service()->globalHeader();
        if ( null === $header ) {
            mm_fail( MegaMenuService::NO_HEADER );
        }
        $hid  = (int) $header['layout_id'];
        $orig = mm_raw( $hid );
        if ( '' === $orig ) {
            mm_fail( 'the global header has no stored content' );
        }
        $posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'has_password' => false ] );
        if ( count( $posts ) < 4 ) {
            mm_fail( 'the "latest" layouts need at least 4 published posts on the site (found ' . count( $posts ) . ')' );
        }

        $menuId = wp_create_nav_menu( AIED_MM_MENU_NAME );
        if ( is_wp_error( $menuId ) || ! $menuId ) {
            mm_fail( 'could not create the throwaway menu' );
        }
        $menuId = (int) $menuId;
        $items  = [];
        $add    = static function ( array $args, int $parent ) use ( $menuId, &$items ): int {
            $id = wp_update_nav_menu_item( $menuId, 0, array_merge( [ 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ], $args ) );
            if ( is_wp_error( $id ) || ! $id ) {
                mm_fail( 'could not add a menu item' );
            }
            $items[] = (int) $id;

            return (int) $id;
        };
        $custom = static fn ( string $title, string $url, string $desc = '' ): array => [ 'menu-item-type' => 'custom', 'menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-description' => $desc ];

        $add( $custom( 'Home', home_url( '/' ) ), 0 );
        $services = $add( $custom( 'Services', home_url( '/services/' ) ), 0 );
        foreach ( [
            'Design'  => [ [ 'Branding', 'Logos, colours and type systems' ], [ 'Web design', 'Sites that turn visitors into customers' ], [ 'Print', '' ] ],
            'Build'   => [ [ 'Websites', '' ], [ 'Online shops', 'Catalogues that sell on their own' ], [ 'Landing pages', '' ] ],
            'Grow'    => [ [ 'SEO', '' ], [ 'Ads', '' ], [ 'Email newsletters', 'One message a month, written well' ], [ 'Analytics', '' ] ],
        ] as $heading => $leaves ) {
            $head = $add( $custom( $heading, home_url( '/services/' . strtolower( $heading ) . '/' ) ), $services );
            foreach ( $leaves as [ $title, $desc ] ) {
                $add( $custom( $title, home_url( '/services/' . strtolower( $heading ) . '/' . sanitize_title( $title ) . '/' ), $desc ), $head );
            }
        }
        $blog = $add( $custom( 'Blog', home_url( '/blog/' ) ), 0 );
        foreach ( array_slice( $posts, 0, 5 ) as $postId ) {
            $add(
                [ 'menu-item-type' => 'post_type', 'menu-item-object' => 'post', 'menu-item-object-id' => (int) $postId, 'menu-item-title' => '' ],
                $blog
            );
        }
        $add( $custom( 'About', home_url( '/about/' ) ), 0 );

        if ( 1 !== preg_match_all( '/"menuId":\{"desktop":\{"value":"(\d*)"\}\}/', $orig, $m ) || 1 !== count( $m[0] ) ) {
            mm_fail( 'the header has no single Menu module menuId to point at the throwaway menu' );
        }
        $oldMenuId = (string) $m[1][0];
        $new       = (string) preg_replace( '/"menuId":\{"desktop":\{"value":"\d*"\}\}/', '"menuId":{"desktop":{"value":"' . $menuId . '"}}', $orig, 1 );
        mm_save_state( [ 'header_id' => $hid, 'original' => $orig, 'original_sha' => sha1( $orig ), 'baseline' => $new, 'baseline_sha' => sha1( $new ), 'menu_id' => $menuId, 'item_ids' => $items, 'old_menu_id' => $oldMenuId, 'extra_ids' => [], 'user_ids' => [] ] );
        wp_update_post( [ 'ID' => $hid, 'post_content' => wp_slash( $new ) ] );
        clean_post_cache( $hid );
        if ( mm_raw( $hid ) !== $new ) {
            mm_fail( 'the header did not take the throwaway menu id byte for byte' );
        }
        // What the browser part expects: the top-level items in menu order and every item under each of them, plus the
        // permalinks of the newest posts (the "latest" layouts show those).
        $flat    = wp_get_nav_menu_items( $menuId ) ?: [];
        $byParent = [];
        foreach ( $flat as $it ) {
            $byParent[ (int) $it->menu_item_parent ][] = $it;
        }
        $descendants = static function ( int $pid ) use ( &$descendants, $byParent ): array {
            $out = [];
            foreach ( $byParent[ $pid ] ?? [] as $c ) {
                $out[] = [ 'title' => wp_strip_all_tags( (string) $c->title ), 'url' => (string) $c->url ];
                $out   = array_merge( $out, $descendants( (int) $c->ID ) );
            }

            return $out;
        };
        $top = [];
        foreach ( $byParent[0] ?? [] as $t ) {
            $top[] = [ 'title' => wp_strip_all_tags( (string) $t->title ), 'url' => (string) $t->url, 'children' => count( $byParent[ (int) $t->ID ] ?? [] ), 'leaves' => $descendants( (int) $t->ID ) ];
        }
        $postUrls = array_map( static fn ( $pid ): string => (string) get_permalink( (int) $pid ), array_slice( $posts, 0, 5 ) );
        mm_out( [
            'top'          => $top,
            'panels'       => count( array_filter( $top, static fn ( array $t ): bool => $t['children'] > 0 ) ),
            'post_urls'    => $postUrls,
            'header_id'    => $hid,
            'header_title' => (string) $header['title'],
            'menu_id'      => $menuId,
            'old_menu_id'  => $oldMenuId,
            'items'        => count( $items ),
            'original_sha' => sha1( $orig ),
            'baseline_sha' => sha1( $new ),
            'original_len' => strlen( $orig ),
            'posts'        => array_map( 'intval', array_slice( $posts, 0, 5 ) ),
        ] );
        break;

    case 'list':
        mm_out( [ 'ids' => Layouts::ids() ] );
        break;

    // ---- one layout --------------------------------------------------------------------------------------------------
    case 'dry':
        mm_admin();
        $layout = (string) ( $args[1] ?? '' );
        try {
            $r = mm_service()->apply( $layout, true );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        mm_out( [
            'layout'     => $layout,
            'valid'      => $r['valid'] ?? null,
            'changed'    => $r['changed'] ?? null,
            'violations' => array_map( static fn ( $v ) => (string) ( $v['code'] ?? '' ), (array) ( $r['violations'] ?? [] ) ),
            'panels'     => $r['mega_menu']['panels'] ?? null,
            'menu'       => $r['mega_menu']['menu'] ?? null,
            'menu_source' => $r['mega_menu']['menu_source'] ?? null,
        ] );
        break;

    case 'apply':
        mm_admin();
        $layout = (string) ( $args[1] ?? '' );
        $hid    = mm_header_id();
        $before = mm_raw( $hid );
        try {
            $first  = mm_service()->apply( $layout, false );
            $after  = mm_raw( $hid );
            $second = mm_service()->apply( $layout, false );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $info = HeaderEdit::inspect( $after );
        mm_out( [
            'layout'            => $layout,
            'pre_sha'           => sha1( $before ),
            'first_changed'     => $first['changed'] ?? null,
            'batch'             => $first['batch']['id'] ?? null,
            'undoable'          => $first['batch']['undoable'] ?? null,
            'second_changed'    => $second['changed'] ?? null,
            'second_batch'      => $second['batch']['id'] ?? null,
            'applied_sha'       => sha1( $after ),
            'applied'           => HeaderEdit::inspect( $after )['hasMega'],
            'wrappers'          => substr_count( $after, 'JHMG Mega Menu' ),
            'menu_disabled_on'  => $info['menu']['module']['decoration']['disabledOn'] ?? null,
            'mega_menu'         => $first['mega_menu'] ?? null,
        ] );
        break;

    case 'remove':
        mm_admin();
        $hid = mm_header_id();
        $s   = mm_state();
        try {
            $r  = mm_service()->remove( false );
            $r2 = mm_service()->remove( false );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now = mm_raw( $hid );
        mm_out( [
            'changed'        => $r['changed'] ?? null,
            'batch'          => $r['batch']['id'] ?? null,
            'second_changed' => $r2['changed'] ?? null,
            'sha'            => sha1( $now ),
            'same_bytes'     => $now === (string) $s['baseline'],
            'same_blocks'    => mm_blocks_equal( (string) $s['baseline'], $now ),
            'applied'        => HeaderEdit::inspect( $now )['hasMega'],
        ] );
        break;

    case 'undo':
        mm_admin();
        $batch = (int) ( $args[1] ?? 0 );
        $hid   = mm_header_id();
        try {
            $r = Batches::undo()->undo( $batch );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now = mm_raw( $hid );
        mm_out( [
            'batch'      => $batch,
            'ok'         => $r['ok'] ?? null,
            'result'     => $r['items'][0]['result'] ?? ( $r['result'] ?? null ),
            'message'    => $r['message'] ?? null,
            'sha'        => sha1( $now ),
            'applied'    => HeaderEdit::inspect( $now )['hasMega'],
            'raw_result' => $r,
        ] );
        break;

    case 'state':
        mm_admin();
        $hid = mm_header_id();
        $now = mm_raw( $hid );
        mm_out( [ 'sha' => sha1( $now ), 'len' => strlen( $now ), 'applied' => HeaderEdit::inspect( $now )['hasMega'], 'baseline_sha' => (string) mm_state()['baseline_sha'] ] );
        break;

    // ---- refusals ----------------------------------------------------------------------------------------------------
    case 'negative':
        mm_admin();
        $s = mm_state();
        if ( null === $s ) {
            mm_fail( 'no run is set up: run setup first' );
        }
        $hid = (int) $s['header_id'];
        $pre = sha1( mm_raw( $hid ) );
        $out = [];

        // 1. no global header: the service refuses, nothing is read
        try {
            mm_service( static fn () => null )->apply( 'list-plain', true );
            $out['no_header'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['no_header'] = $e->getMessage();
        }
        $out['default_header_id'] = (int) ( mm_service()->globalHeader()['layout_id'] ?? 0 );

        // 2. a throwaway header copy WITHOUT a Menu module, live through a throwaway template that applies to nothing (the same
        //    technique verify-pro-theme-layouts uses: default 0, empty use_on, body and footer 0)
        $copyContent = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/row {"builderVersion":"5.8.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.8.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"AIED-MEGAMENU-NO-MENU"}}},"builderVersion":"5.8.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $copy        = et_theme_builder_insert_layout( [ 'post_type' => 'et_header_layout', 'post_title' => 'aied megamenu copy without menu', 'post_content' => wp_slash( $copyContent ) ] );
        if ( is_wp_error( $copy ) || ! $copy ) {
            mm_fail( 'could not create the header copy' );
        }
        $s['extra_ids'][] = (int) $copy;
        mm_save_state( $s );
        $tb = (int) et_theme_builder_get_theme_builder_post_id( true, false );
        if ( 0 === $tb ) {
            mm_fail( 'no live Theme Builder post' );
        }
        $tpl = et_theme_builder_store_template(
            $tb,
            [
                'title'               => 'aied megamenu template',
                'default'             => '0',
                'enabled'             => '1',
                'autogenerated_title' => '0',
                'layouts'             => [ 'header' => [ 'id' => (int) $copy, 'enabled' => true ], 'body' => [ 'id' => 0, 'enabled' => false ], 'footer' => [ 'id' => 0, 'enabled' => false ] ],
                'use_on'              => [],
                'exclude_from'        => [],
            ],
            true
        );
        if ( ! $tpl ) {
            mm_fail( 'could not create the template for the header copy' );
        }
        $s['tb_id']       = $tb;
        $s['template_id'] = (int) $tpl;
        $s['extra_ids'][] = (int) $tpl;
        mm_save_state( $s );
        add_post_meta( $tb, '_et_template', (int) $tpl );

        try {
            mm_service( static fn () => [ 'layout_id' => (int) $copy, 'title' => 'copy' ] )->apply( 'list-plain', true );
            $out['no_menu'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['no_menu'] = $e->getMessage();
        }
        $out['no_menu_is_no_menu_error'] = $out['no_menu'] === HeaderEdit::NO_MENU;
        try {
            mm_service( static fn () => [ 'layout_id' => (int) $copy, 'title' => 'copy' ] )->apply( 'list-plain', false );
            $out['no_menu_real'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['no_menu_real'] = $e->getMessage();
        }
        $out['copy_untouched'] = mm_raw( (int) $copy ) === $copyContent;

        // 3. a user without the Theme Builder permission (an editor): refused, the header unchanged
        $login = 'aied_megamenu_editor_' . wp_rand( 100000, 999999 );
        $uid   = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.invalid', 'role' => 'editor' ] );
        if ( is_wp_error( $uid ) ) {
            mm_fail( 'could not create the editor fixture: ' . $uid->get_error_message() );
        }
        $s['user_ids'][] = (int) $uid;
        mm_save_state( $s );
        wp_set_current_user( (int) $uid );
        try {
            mm_service()->apply( 'list-plain', true );
            $out['editor_dry'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['editor_dry'] = $e->getMessage();
        }
        try {
            mm_service()->apply( 'list-plain', false );
            $out['editor_real'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['editor_real'] = $e->getMessage();
        }
        try {
            mm_service()->remove( false );
            $out['editor_remove'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['editor_remove'] = $e->getMessage();
        }
        mm_admin();
        $out['header_unchanged'] = sha1( mm_raw( $hid ) ) === $pre;

        // clean the fixtures of this step now (teardown removes them again if this step aborts)
        wp_delete_user( (int) $uid );
        $s['user_ids'] = [];
        delete_post_meta( $tb, '_et_template', (int) $tpl );
        wp_delete_post( (int) $tpl, true );
        wp_delete_post( (int) $copy, true );
        $s['extra_ids'] = [];
        unset( $s['tb_id'], $s['template_id'] );
        mm_save_state( $s );
        $out['fixtures_left'] = (int) ( get_post( (int) $copy ) !== null ) + (int) ( get_post( (int) $tpl ) !== null ) + (int) ( get_userdata( (int) $uid ) !== false );
        mm_out( $out );
        break;

    case 'teardown':
        mm_out( mm_teardown() );
        break;

    default:
        mm_fail( 'unknown step "' . $aiedMmStep . '"' );
}
