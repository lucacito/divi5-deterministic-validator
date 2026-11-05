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
use AiEditorDivi5\Pro\Batch\BatchTable;
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
    if ( ! empty( $s['second_menu_id'] ) && wp_get_nav_menu_object( (int) $s['second_menu_id'] ) ) {
        wp_delete_nav_menu( (int) $s['second_menu_id'] );
    }
    if ( ! empty( $s['directory_menu_id'] ) && wp_get_nav_menu_object( (int) $s['directory_menu_id'] ) ) {
        wp_delete_nav_menu( (int) $s['directory_menu_id'] );
    }
    foreach ( (array) ( $s['directory_posts'] ?? [] ) as $pid ) {
        if ( get_post( (int) $pid ) ) {
            wp_delete_post( (int) $pid, true );
        }
    }
    foreach ( (array) ( $s['directory_terms'] ?? [] ) as $tid ) {
        wp_delete_term( (int) $tid, 'category' );
    }
    $menuLeft = ( wp_get_nav_menu_object( AIED_MM_MENU_NAME ) ? 1 : 0 ) + ( ! empty( $s['second_menu_id'] ) && wp_get_nav_menu_object( (int) $s['second_menu_id'] ) ? 1 : 0 ) + ( ! empty( $s['directory_menu_id'] ) && wp_get_nav_menu_object( (int) $s['directory_menu_id'] ) ? 1 : 0 );

    foreach ( (array) ( $s['item_ids'] ?? [] ) as $itemId ) {
        if ( get_post( (int) $itemId ) ) {
            wp_delete_post( (int) $itemId, true );
        }
    }
    // The throwaway picture (the file goes with it).
    if ( ! empty( $s['attachment_id'] ) && get_post( (int) $s['attachment_id'] ) ) {
        wp_delete_attachment( (int) $s['attachment_id'], true );
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
    // Every write of the header makes a WordPress revision, and WordPress loads a post's revisions into memory when it saves another: a run
    // writes it hundreds of times, so the revisions made during the run are removed again (the owner's own, older ones are not touched).
    $revisionsLeft = 0;
    if ( isset( $s['rev_floor'] ) ) {
        foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID > %d", $hid, (int) $s['rev_floor'] ) ) as $revId ) { // phpcs:ignore
            wp_delete_post_revision( (int) $revId );
        }
        $revisionsLeft = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID > %d", $hid, (int) $s['rev_floor'] ) ); // phpcs:ignore
    }
    // The undo batches this run recorded (an apply, a remove, an undo each write one) must not stay behind: the tab offers the newest one
    // as "Undo last change". The tool filter keeps anything else a person did meanwhile.
    $batchesLeft = 0;
    $batchesBack = true;
    if ( isset( $s['batch_floor'] ) && BatchTable::exists() ) {
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BatchTable::name() . " WHERE id > %d AND tool IN ('apply_mega_menu', 'undo_batch')", (int) $s['batch_floor'] ) ); // phpcs:ignore
        $batchesLeft = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BatchTable::name() . " WHERE id > %d AND tool IN ('apply_mega_menu', 'undo_batch')", (int) $s['batch_floor'] ) ); // phpcs:ignore
        // Rows that were there before the run and were trimmed away by it come back exactly as they were.
        foreach ( (array) ( $s['batches_before'] ?? [] ) as $row ) {
            if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id = %d', (int) $row['id'] ) ) ) { // phpcs:ignore
                $wpdb->insert( BatchTable::name(), $row );
            }
            if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id = %d AND items = %s', (int) $row['id'], (string) $row['items'] ) ) ) { // phpcs:ignore
                $batchesBack = false;
            }
        }
    }
    foreach ( (array) ( $s['options_before'] ?? [] ) as $name => $before ) {
        if ( '__none__' === $before ) {
            delete_option( (string) $name );
        } else {
            update_option( (string) $name, $before, false );
        }
    }
    delete_option( AIED_MM_STATE );

    return [
        'batches_left' => $batchesLeft,
        'revisions_left' => $revisionsLeft,
        'batches_back' => $batchesBack,
        'restored'    => true,
        'same_bytes'  => $sameBytes,
        'fallback'    => $fallback,
        'menu_left'   => $menuLeft,
        'picture_left' => ! empty( $s['attachment_id'] ) && get_post( (int) $s['attachment_id'] ) ? 1 : 0,
        'directory_left' => count( array_filter( (array) ( $s['directory_posts'] ?? [] ), static fn ( $p ): bool => (bool) get_post( (int) $p ) ) ) + count( array_filter( (array) ( $s['directory_terms'] ?? [] ), static fn ( $t ): bool => (bool) get_term( (int) $t, 'category' ) ) ),
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

        $headIds = [];
        $add( $custom( 'Home', home_url( '/' ) ), 0 );
        $services = $add( $custom( 'Services', home_url( '/services/' ) ), 0 );
        foreach ( [
            'Design'  => [ [ 'Branding', 'Logos, colours and type systems' ], [ 'Web design', 'Sites that turn visitors into customers' ], [ 'Print', '' ] ],
            'Build'   => [ [ 'Websites', '' ], [ 'Online shops', 'Catalogues that sell on their own' ], [ 'Landing pages', '' ] ],
            'Grow'    => [ [ 'SEO', '' ], [ 'Ads', '' ], [ 'Email newsletters', 'One message a month, written well' ], [ 'Analytics', '' ] ],
        ] as $heading => $leaves ) {
            $head = $add( $custom( $heading, home_url( '/services/' . strtolower( $heading ) . '/' ) ), $services );
            $headIds[ $heading ] = $head;
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

        // The baseline is the header WITHOUT a mega menu: when the site's own header already carries one (an owner trying the tab), it
        // is taken out for the run (HeaderEdit::remove) and teardown puts the original bytes back.
        $clean = HeaderEdit::remove( $orig );
        if ( 1 !== preg_match_all( '/"menuId":\{"desktop":\{"value":"(\d*)"\}\}/', $clean, $m ) || 1 !== count( $m[0] ) ) {
            mm_fail( 'the header has no single Menu module menuId to point at the throwaway menu' );
        }
        $oldMenuId = (string) $m[1][0];
        $new       = (string) preg_replace( '/"menuId":\{"desktop":\{"value":"\d*"\}\}/', '"menuId":{"desktop":{"value":"' . $menuId . '"}}', $clean, 1 );
        // The owner's own content lives in an option: keep what is there and put it back at the end.
        $optionsBefore = [];
        foreach ( [ \AiEditorDivi5\Pro\MegaMenu\MegaMenuContent::OPTION ] as $name ) {
            $optionsBefore[ $name ] = get_option( $name, '__none__' );
        }
        // A throwaway picture in the Media Library (deleted with the run) and the owner's content for the item "Services" on it.
        $own = [ 'title' => 'Gate headline', 'text' => 'Gate text for the card.', 'button' => 'Gate button', 'url' => home_url( '/gate-own-link/' ), 'alt' => 'Gate picture' ];
        $upload = wp_upload_bits( 'aied-megamenu-gate-' . wp_generate_password( 6, false ) . '.png', null, '' );
        if ( ! empty( $upload['error'] ) ) {
            mm_fail( 'could not write the throwaway picture: ' . $upload['error'] );
        }
        $img = imagecreatetruecolor( 640, 360 );
        $bg  = imagecolorallocate( $img, 29, 78, 216 );
        imagefilledrectangle( $img, 0, 0, 640, 360, $bg );
        imagefilledellipse( $img, 320, 180, 200, 200, imagecolorallocate( $img, 244, 114, 182 ) );
        imagepng( $img, $upload['file'] );
        imagedestroy( $img );
        $attId = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'aied megamenu gate picture', 'post_status' => 'inherit' ], $upload['file'] );
        if ( is_wp_error( $attId ) || ! $attId ) {
            mm_fail( 'could not make the throwaway picture an attachment' );
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( (int) $attId, wp_generate_attachment_metadata( (int) $attId, $upload['file'] ) );
        update_post_meta( (int) $attId, '_wp_attachment_image_alt', $own['alt'] );
        $own['image'] = (int) $attId;
        $own['imageUrl'] = (string) ( wp_get_attachment_image_src( (int) $attId, 'large' )[0] ?? '' );
        global $wpdb;
        $batchFloor = BatchTable::exists() ? (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . BatchTable::name() ) : 0; // phpcs:ignore
        // The table keeps only the newest 20 rows and a run records more than that, so every row that exists now is kept in the state and put back.
        $batchesBefore = BatchTable::exists() ? (array) $wpdb->get_results( 'SELECT * FROM ' . BatchTable::name() . ' ORDER BY id', ARRAY_A ) : []; // phpcs:ignore
        $revFloor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d", $hid ) ); // phpcs:ignore
        mm_save_state( [ 'rev_floor' => $revFloor, 'options_before' => $optionsBefore, 'own' => $own, 'services_id' => $services, 'blog_id' => $blog, 'batches_before' => $batchesBefore, 'batch_floor' => $batchFloor, 'header_id' => $hid, 'original' => $orig, 'original_sha' => sha1( $orig ), 'baseline' => $new, 'baseline_sha' => sha1( $new ), 'menu_id' => $menuId, 'item_ids' => $items, 'old_menu_id' => $oldMenuId, 'extra_ids' => [], 'attachment_id' => (int) $attId, 'user_ids' => [] ] );
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
        // `label`: a second-level item that has a third level is shown as a non-clickable label (not a link).
        $descendants = static function ( int $pid, int $depth = 1 ) use ( &$descendants, $byParent ): array {
            $out = [];
            foreach ( $byParent[ $pid ] ?? [] as $c ) {
                $out[] = [ 'title' => wp_strip_all_tags( (string) $c->title ), 'url' => (string) $c->url, 'label' => 1 === $depth && ! empty( $byParent[ (int) $c->ID ] ) ];
                $out   = array_merge( $out, $descendants( (int) $c->ID, $depth + 1 ) );
            }

            return $out;
        };
        $top = [];
        foreach ( $byParent[0] ?? [] as $t ) {
            $top[] = [ 'title' => wp_strip_all_tags( (string) $t->title ), 'url' => (string) $t->url, 'children' => count( $byParent[ (int) $t->ID ] ?? [] ), 'leaves' => $descendants( (int) $t->ID ) ];
        }
        $postUrls = array_map( static fn ( $pid ): string => (string) get_permalink( (int) $pid ), array_slice( $posts, 0, 5 ) );
        mm_service()->content()->save( $services, $own );
        // Icons for the icon layouts: a rocket for every card of "Services", a house for its card "Build".
        mm_service()->content()->setIcon( $services, 'rocket' );
        mm_service()->content()->setIcon( (int) $headIds['Build'], 'home' );
        mm_out( [
            'own'          => $own,
            'services_id'  => $services,
            'build_id'     => (int) $headIds['Build'],
            'blog_id'      => $blog,
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
        // `flat`: layouts that list every sub-link in one flat row (a posts panel), so a second-level item stays a link there.
        $flat = [];
        foreach ( Layouts::all() as $l ) {
            if ( (int) $l['posts'] > 0 && 'cards' === $l['postStyle'] ) {
                $flat[] = $l['id'];
            }
        }
        $content = [];
        foreach ( Layouts::all() as $l ) {
            if ( ! empty( $l['content'] ) ) {
                $content[] = $l['id'];
            }
        }
        $icons = [];
        foreach ( Layouts::all() as $l ) {
            if ( ! empty( $l['icons'] ) ) {
                $icons[] = $l['id'];
            }
        }
        mm_out( [ 'ids' => Layouts::ids(), 'flat' => $flat, 'content' => $content, 'icons' => $icons ] );
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

    // ---- one item only, and undo last change ---------------------------------------------------------------------------
    case 'item_apply':
        mm_admin();
        $all   = (string) ( $args[1] ?? '' );
        $item  = (string) ( $args[2] ?? '' );
        $title = (string) ( $args[3] ?? '' );
        $hid   = mm_header_id();
        $pre   = mm_raw( $hid );
        $menu  = wp_get_nav_menu_object( AIED_MM_MENU_NAME );
        $itemId = 0;
        foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $it ) {
            if ( 0 === (int) $it->menu_item_parent && wp_strip_all_tags( (string) $it->title ) === $title ) {
                $itemId = (int) $it->ID;
            }
        }
        try {
            $r1      = mm_service()->apply( $all, false );
            $afterAll = mm_raw( $hid );
            $r2      = mm_service()->apply( $item, false, $itemId );
            $r3      = mm_service()->apply( $item, false, $itemId );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now    = mm_raw( $hid );
        $names  = [];
        foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $it ) {
            $names[ (int) $it->ID ] = wp_strip_all_tags( (string) $it->title );
        }
        $byTitle = [];
        foreach ( HeaderEdit::panels( $now ) as $id => $layoutId ) {
            $byTitle[ $names[ $id ] ?? ( '#' . $id ) ] = $layoutId;
        }
        mm_out( [
            'pre_sha'        => sha1( $pre ),
            'after_all_sha'  => sha1( $afterAll ),
            'after_item_sha' => sha1( $now ),
            'batch_all'      => $r1['batch']['id'] ?? null,
            'batch_item'     => $r2['batch']['id'] ?? null,
            'item_changed'   => $r2['changed'] ?? null,
            'item_again'     => $r3['changed'] ?? null,
            'panels'         => $byTitle,
            'wrappers'       => substr_count( $now, 'JHMG Mega Menu"' ),
            'item_label_ok'  => 1 === preg_match( '/"value":"JHMG panel ' . $itemId . ' ' . preg_quote( $item, '/' ) . '( [0-9a-f]{10})?"/', $now ),
        ] );
        break;

    // ---- the menu changed after the mega menu was applied ---------------------------------------------------------------
    // drift_edit adds three items to the throwaway menu (a link under the heading "Design", a sub-item under "Blog", a new top-level
    // item); drift_state reads what the Mega menu tab would say; drift_refresh is the "Update the mega menu" button; drift_cleanup
    // takes the three items away again.
    case 'drift_edit':
        mm_admin();
        $s    = mm_state();
        $menu = wp_get_nav_menu_object( AIED_MM_MENU_NAME );
        $find = static function ( string $title, int $parent ) use ( $menu ): int {
            foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $it ) {
                if ( (int) $it->menu_item_parent === $parent && wp_strip_all_tags( (string) $it->title ) === $title ) {
                    return (int) $it->ID;
                }
            }

            return 0;
        };
        $services = $find( 'Services', 0 );
        $design   = $find( 'Design', $services );
        $blog     = $find( 'Blog', 0 );
        if ( ! $services || ! $design || ! $blog ) {
            mm_fail( 'the throwaway menu is not as the setup built it' );
        }
        $new = [];
        foreach ( [ [ 'Fresh leaf', '/services/design/fresh-leaf/', $design ], [ 'Fresh blog link', '/blog/fresh/', $blog ], [ 'Contact', '/contact/', 0 ] ] as [ $title, $path, $parent ] ) {
            $id = wp_update_nav_menu_item( $menu->term_id, 0, [ 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-title' => $title, 'menu-item-url' => home_url( $path ), 'menu-item-parent-id' => $parent ] );
            if ( is_wp_error( $id ) || ! $id ) {
                mm_fail( 'could not add a menu item' );
            }
            $new[] = (int) $id;
        }
        $s['item_ids']  = array_merge( $s['item_ids'], $new );
        $s['drift_ids'] = $new;
        mm_save_state( $s );
        mm_out( [ 'added' => $new, 'fresh_leaf_url' => home_url( '/services/design/fresh-leaf/' ), 'fresh_blog_url' => home_url( '/blog/fresh/' ), 'contact_url' => home_url( '/contact/' ) ] );
        break;

    case 'drift_state':
        mm_admin();
        $hid    = mm_header_id();
        $status = mm_service()->status();
        $drift  = $status['drift'];
        $panel  = new \AiEditorDivi5\Pro\Admin\MegaMenuPanel( static fn (): bool => true, mm_service(), static function (): void {} );
        ob_start();
        $panel->render( 'megamenu' );
        $html = (string) ob_get_clean();
        $menuNames = [];
        foreach ( $drift['items'] ?? [] as $it ) {
            $menuNames[ $it['title'] ] = [ 'missing' => $it['missing'], 'extra' => $it['extra'] ];
        }
        mm_out( [
            'drifted'       => null !== $drift,
            'bar_missing'   => $drift['bar']['missing'] ?? [],
            'items'         => $menuNames,
            'gone'          => $drift['gone'] ?? [],
            'notice_shown'  => str_contains( $html, 'no longer matches your menu' ),
            'button_shown'  => str_contains( $html, 'value="aied_pro_megamenu_update"' ),
            'sha'           => sha1( mm_raw( $hid ) ),
            'panels'        => HeaderEdit::panels( mm_raw( $hid ) ),
        ] );
        break;

    case 'drift_refresh':
        mm_admin();
        $hid = mm_header_id();
        $pre = mm_raw( $hid );
        try {
            $dry   = mm_service()->refresh( true );
            $mid   = mm_raw( $hid );
            $r1    = mm_service()->refresh( false );
            $after = mm_raw( $hid );
            $r2    = mm_service()->refresh( false );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $status = mm_service()->status();
        mm_out( [
            'pre_sha'       => sha1( $pre ),
            'dry_untouched' => $mid === $pre,
            'dry_valid'     => $dry['valid'] ?? null,
            'batch'         => $r1['batch']['id'] ?? null,
            'changed'       => $r1['changed'] ?? null,
            'again'         => $r2['changed'] ?? null,
            'sha'           => sha1( $after ),
            'drift_after'   => null !== $status['drift'],
            'panels'        => HeaderEdit::panels( $after ),
            'wrappers'      => substr_count( $after, 'JHMG Mega Menu"' ),
        ] );
        break;

    case 'drift_cleanup':
        mm_admin();
        $s = mm_state();
        foreach ( $s['drift_ids'] ?? [] as $id ) {
            wp_delete_post( (int) $id, true );
        }
        $s['item_ids'] = array_values( array_diff( $s['item_ids'], $s['drift_ids'] ?? [] ) );
        unset( $s['drift_ids'] );
        mm_save_state( $s );
        $menu = wp_get_nav_menu_object( AIED_MM_MENU_NAME );
        mm_out( [ 'items_left' => count( wp_get_nav_menu_items( $menu->term_id ) ?: [] ), 'items_expected' => count( $s['item_ids'] ) ] );
        break;

    // ---- the icons offered for the icon layouts: every one must be a glyph of the font file Divi ships ----------------------------------
    case 'icon_font':
        $svg = rtrim( (string) get_template_directory(), '/' ) . '/core/admin/fonts/fontawesome/fa-solid-900.svg';
        if ( ! is_readable( $svg ) ) {
            mm_fail( 'Divi\'s Font Awesome file is not there: ' . $svg );
        }
        $text = (string) file_get_contents( $svg );
        preg_match_all( '/<glyph[^>]*glyph-name="([^"]+)"[^>]*unicode="&#x([0-9a-f]+);"/i', $text, $m, PREG_SET_ORDER );
        $font = [];
        foreach ( $m as $g ) {
            $font[ strtolower( $g[2] ) ] = $g[1];
        }
        $missing = [];
        $names   = [];
        foreach ( \AiEditorDivi5\Pro\MegaMenu\MegaIcons::all() as $i ) {
            if ( ! isset( $font[ $i['code'] ] ) ) {
                $missing[] = $i['id'] . ' (' . $i['code'] . ')';
            } elseif ( $font[ $i['code'] ] !== $i['id'] ) {
                $names[] = $i['id'] . ' is called ' . $font[ $i['code'] ] . ' in the font';
            }
        }
        mm_out( [ 'font_glyphs' => count( $font ), 'icons' => count( \AiEditorDivi5\Pro\MegaMenu\MegaIcons::all() ), 'missing' => $missing, 'renamed' => $names ] );
        break;

    // ---- another menu on the site, and which menu the header shows ---------------------------------------------------------
    case 'second_menu':
        mm_admin();
        $s  = mm_state();
        $id = wp_create_nav_menu( 'aied megamenu second' );
        if ( is_wp_error( $id ) || ! $id ) {
            mm_fail( 'could not create the second throwaway menu' );
        }
        $s['second_menu_id'] = (int) $id;
        mm_save_state( $s );
        $mk = static function ( string $title, string $path, int $parent ) use ( $id ): int {
            $r = wp_update_nav_menu_item( (int) $id, 0, [ 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-title' => $title, 'menu-item-url' => home_url( $path ), 'menu-item-parent-id' => $parent ] );
            if ( is_wp_error( $r ) || ! $r ) {
                mm_fail( 'could not add an item to the second menu' );
            }

            return (int) $r;
        };
        $docs = $mk( 'Docs', '/docs/', 0 );
        $mk( 'Guide', '/docs/guide/', $docs );
        $mk( 'FAQ', '/docs/faq/', $docs );
        $mk( 'Contact', '/contact-second/', 0 );
        mm_out( [ 'menu_id' => (int) $id, 'docs_id' => $docs, 'guide_url' => home_url( '/docs/guide/' ) ] );
        break;

    // ---- the "Grouped list with counts" layout on a menu of its own ------------------------------------------------------
    // directory_menu makes a throwaway menu whose "Library" item holds a group of two throwaway categories with throwaway posts (their post counts are what
    // the panel must show; teardown deletes them), a group of plain links and two loose links with a description (the cards); directory_use points the header at it
    // and applies the layout; directory_back removes the mega menu and points the header at the run's own menu again.
    case 'directory_menu':
        mm_admin();
        $s  = mm_state();
        $id = wp_create_nav_menu( 'aied megamenu directory' );
        if ( is_wp_error( $id ) || ! $id ) {
            mm_fail( 'could not create the directory throwaway menu' );
        }
        $s['directory_menu_id'] = (int) $id;
        mm_save_state( $s );
        // Two throwaway categories and three throwaway published posts (two in the first category, one in the second): the counts the panel must show.
        $cats = [];
        $posts = [];
        foreach ( [ 'aied megamenu cat one' => 2, 'aied megamenu cat two' => 1 ] as $name => $n ) {
            $t = wp_insert_term( $name, 'category' );
            if ( is_wp_error( $t ) ) {
                mm_fail( 'could not create the throwaway category: ' . $t->get_error_message() );
            }
            $cats[] = get_term( (int) $t['term_id'], 'category' );
            for ( $k = 0; $k < $n; $k++ ) {
                $pid = wp_insert_post( [ 'post_title' => 'aied megamenu directory post ' . count( $posts ), 'post_status' => 'publish', 'post_type' => 'post', 'post_category' => [ (int) $t['term_id'] ] ] );
                if ( is_wp_error( $pid ) || ! $pid ) {
                    mm_fail( 'could not create a throwaway post' );
                }
                $posts[] = (int) $pid;
            }
        }
        $s['directory_terms'] = array_map( static fn ( $c ): int => (int) $c->term_id, $cats );
        $s['directory_posts'] = $posts;
        mm_save_state( $s );
        $mk = static function ( array $args, int $parent ) use ( $id ): int {
            $r = wp_update_nav_menu_item( (int) $id, 0, array_merge( [ 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ], $args ) );
            if ( is_wp_error( $r ) || ! $r ) {
                mm_fail( 'could not add an item to the directory menu' );
            }

            return (int) $r;
        };
        $custom = static fn ( string $title, string $path, string $desc = '' ): array => [ 'menu-item-type' => 'custom', 'menu-item-title' => $title, 'menu-item-url' => home_url( $path ), 'menu-item-description' => $desc ];
        $lib   = $mk( $custom( 'Library', '/library/' ), 0 );
        $topic = $mk( $custom( 'By topic', '/library/topic/' ), $lib );
        $counts = [];
        foreach ( $cats as $cat ) {
            $mk( [ 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'category', 'menu-item-object-id' => (int) $cat->term_id, 'menu-item-title' => $cat->name ], $topic );
            $real = new \WP_Query( [ 'post_type' => 'post', 'post_status' => 'publish', 'cat' => (int) $cat->term_id, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false ] );
            $fresh = get_term( (int) $cat->term_id, 'category' );
            $counts[ $cat->name ] = [ 'term' => (int) $fresh->count, 'real' => (int) $real->found_posts, 'url' => get_term_link( $cat ) ];
        }
        $more = $mk( $custom( 'More', '/library/more/' ), $lib );
        $mk( $custom( 'Press kit', '/library/press/' ), $more );
        $mk( $custom( 'Newsletter', '/library/news/' ), $more );
        $mk( $custom( 'Browse everything', '/library/all/', 'The full catalogue in one place.' ), $lib );
        $mk( $custom( 'Talk to us', '/library/contact/', 'A real person answers within a day.' ), $lib );
        $mk( $custom( 'Contact', '/contact-directory/' ), 0 );
        mm_out( [ 'menu_id' => (int) $id, 'lib_id' => $lib, 'counts' => $counts, 'cards' => [ 'Browse everything' => home_url( '/library/all/' ), 'Talk to us' => home_url( '/library/contact/' ) ], 'rows_plain' => [ 'Press kit', 'Newsletter' ] ] );
        break;

    case 'directory_use':
        mm_admin();
        $s = mm_state();
        try {
            mm_service()->useMenu( (int) $s['directory_menu_id'] );
            $r = mm_service()->apply( 'directory-counts', false );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now = mm_raw( mm_header_id() );
        mm_out( [ 'changed' => $r['changed'] ?? null, 'panel_ids' => array_keys( HeaderEdit::panels( $now ) ), 'layouts' => array_values( array_unique( HeaderEdit::panels( $now ) ) ), 'status_menu_id' => mm_service()->status()['menu']['id'] ?? null ] );
        break;

    case 'directory_back':
        mm_admin();
        $s = mm_state();
        try {
            mm_service()->remove();
            mm_service()->useMenu( (int) $s['menu_id'] );
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now = mm_raw( mm_header_id() );
        preg_match( '/"menuId":\{"desktop":\{"value":"(\d*)"/', $now, $m );
        mm_out( [ 'module_menu_id' => (int) ( $m[1] ?? 0 ), 'applied' => false !== strpos( $now, 'JHMG Mega Menu' ), 'sha' => sha1( $now ), 'baseline_sha' => (string) $s['baseline_sha'] ] );
        break;

    case 'menu_state':
        mm_admin();
        $now = mm_raw( mm_header_id() );
        preg_match( '/"menuId":\{"desktop":\{"value":"(\d*)"/', $now, $m );
        $st = mm_service()->status();
        mm_out( [ 'module_menu_id' => (int) ( $m[1] ?? 0 ), 'status_menu' => $st['menu']['name'] ?? null, 'status_menu_id' => $st['menu']['id'] ?? null, 'panel_ids' => array_keys( HeaderEdit::panels( $now ) ), 'layouts' => array_values( array_unique( HeaderEdit::panels( $now ) ) ), 'drift' => null !== $st['drift'], 'sha' => sha1( $now ) ] );
        break;

    // ---- a panel edited in the Divi builder ------------------------------------------------------------------------------
    // hand_edit changes one panel the way an edit in the builder does (a text in it) and writes the header with wp_update_post, not
    // through the add-on; edited_refresh is the "Update" button with an answer ('-' = none was given).
    case 'hand_edit':
        mm_admin();
        $hid   = mm_header_id();
        $s     = mm_state();
        $title = (string) ( $args[1] ?? 'Services' );
        $id    = $title === 'Blog' ? (int) $s['blog_id'] : (int) $s['services_id'];
        $now   = mm_raw( $hid );
        $panel = HeaderEdit::panelBlocks( $now )[ $id ] ?? '';
        if ( '' === $panel ) {
            mm_fail( 'no panel for ' . $title );
        }
        $edited = (string) preg_replace( '/"text":"([^"]+)"/', '"text":"$1 (edited in Divi)"', $panel, 1 );
        wp_update_post( [ 'ID' => $hid, 'post_content' => wp_slash( str_replace( $panel, $edited, $now ) ) ] );
        clean_post_cache( $hid );
        $after = mm_raw( $hid );
        $items = [];
        foreach ( mm_service()->status()['items'] as $it ) {
            $items[ $it['title'] ] = $it['edited'];
        }
        mm_out( [ 'changed' => $after !== $now, 'edited' => $items, 'panel_sha' => sha1( HeaderEdit::panelBlocks( $after )[ $id ] ?? '' ), 'sha' => sha1( $after ) ] );
        break;

    case 'edited_refresh':
        mm_admin();
        $hid    = mm_header_id();
        $s      = mm_state();
        $choice = (string) ( $args[1] ?? '-' );
        $pre    = mm_raw( $hid );
        $out    = [ 'pre_sha' => sha1( $pre ) ];
        try {
            $r = mm_service()->refresh( false, '-' === $choice ? null : $choice );
            $out['asked'] = [];
            $out['changed'] = $r['changed'] ?? null;
            $out['kept'] = $r['mega_menu']['edited_kept'] ?? [];
            $out['replaced'] = $r['mega_menu']['edited_replaced'] ?? [];
        } catch ( \AiEditorDivi5\Pro\MegaMenu\EditedPanelsException $e ) {
            $out['asked'] = array_values( $e->panels );
            $out['message'] = $e->getMessage();
        } catch ( \Throwable $e ) {
            mm_fail( $e->getMessage() );
        }
        $now = mm_raw( $hid );
        $out['sha']        = sha1( $now );
        $out['panel_sha']  = sha1( HeaderEdit::panelBlocks( $now )[ (int) $s['services_id'] ] ?? '' );
        $out['has_edit']   = str_contains( $now, '(edited in Divi)' );
        $out['edited']     = array_map( static fn ( array $it ): bool => $it['edited'], array_column( mm_service()->status()['items'], null, 'title' ) );
        mm_out( $out );
        break;

    case 'set_edits':
        mm_admin();
        $s = mm_state();
        mm_service()->content()->setEdits( ( (string) ( $args[1] ?? '' ) ) === 'Blog' ? (int) $s['blog_id'] : (int) $s['services_id'], (string) ( $args[2] ?? 'ask' ) );
        mm_out( [ 'ok' => true ] );
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
            mm_service( static fn () => null )->apply( 'columns-footer', true );
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
            mm_service( static fn () => [ 'layout_id' => (int) $copy, 'title' => 'copy' ] )->apply( 'columns-footer', true );
            $out['no_menu'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['no_menu'] = $e->getMessage();
        }
        $out['no_menu_is_no_menu_error'] = $out['no_menu'] === HeaderEdit::NO_MENU;
        try {
            mm_service( static fn () => [ 'layout_id' => (int) $copy, 'title' => 'copy' ] )->apply( 'columns-footer', false );
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
            mm_service()->apply( 'columns-footer', true );
            $out['editor_dry'] = 'no error';
        } catch ( \InvalidArgumentException $e ) {
            $out['editor_dry'] = $e->getMessage();
        }
        try {
            mm_service()->apply( 'columns-footer', false );
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
