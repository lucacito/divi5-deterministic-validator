<?php
/**
 * The server half of the Lab (internal name Studio) live gate. Called by scripts/verify-studio.cjs, one command per call:
 *
 *   wp eval-file /scripts/verify-studio-server.php <command> <base64 JSON parameters> --user=1
 *
 * Prints one line `@@AIED@@<json>`. Group commands (table, preview, kses) run their checks here, on the real MariaDB and the
 * real WordPress, and return [{ok, label, detail}]; the small commands read or change one thing for a check the browser makes.
 * Dev site only (scripts/dev-guard.php). Everything it creates is named `aied studio <run token> ...`, is owned by a throwaway
 * user `aied_studio_<run token>_<x>`, or is a preview draft of such a user; `cleanup` and `orphans` remove it.
 */

use AiEditorDivi5\WP\DraftPage;
use AiEditorDivi5\WP\HistoryCapture;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioPreview;
use AiEditorDivi5\WP\StudioScreen;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;

require __DIR__ . '/dev-guard.php';
aied_require_dev_site();
require_once ABSPATH . 'wp-admin/includes/user.php';

const AIED_GATE_MU = 'aied-studio-gate-errors.php';
const AIED_GATE_LOG = 'aied-studio-gate-errors.log';

$cmd = (string) ( $args[0] ?? '' );
$p   = isset( $args[1] ) ? json_decode( base64_decode( (string) $args[1] ), true ) : [];
$p   = is_array( $p ) ? $p : [];

function aied_out( $value ): void
{
    echo "\n@@AIED@@" . wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) . "\n";
}

/** @param list<array> $checks */
function aied_check( array &$checks, bool $ok, string $label, $detail = null ): void
{
    $checks[] = [ 'ok' => $ok, 'label' => $label, 'detail' => $detail ];
}

function aied_token( array $p ): string
{
    $t = (string) ( $p['token'] ?? '' );
    if ( 1 !== preg_match( '/^[0-9]+-[0-9a-f]{6}$/', $t ) ) {
        WP_CLI::error( 'A run token like 123-abcdef is required.' );
    }

    return $t;
}

function aied_hist_rows( int $page ): array
{
    global $wpdb;
    $t = HistoryTable::name();

    // phpcs:ignore WordPress.DB
    return $wpdb->get_results( $wpdb->prepare( "SELECT id, version_id, tool, actor, bytes, sha256, label, saved_at FROM {$t} WHERE page_id = %d ORDER BY id ASC", $page ), ARRAY_A ) ?: [];
}

function aied_revisions( int $page ): int
{
    global $wpdb;

    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d", $page ) );
}

function aied_post( int $id ): array
{
    clean_post_cache( $id );
    $post = get_post( $id );
    if ( ! $post ) {
        return [ 'exists' => false, 'id' => $id ];
    }

    return [
        'exists'      => true,
        'id'          => $id,
        'type'        => $post->post_type,
        'status'      => $post->post_status,
        'title'       => $post->post_title,
        'author'      => (int) $post->post_author,
        'sha'         => hash( 'sha256', (string) $post->post_content ),
        'len'         => strlen( (string) $post->post_content ),
        'content'     => strlen( (string) $post->post_content ) > 300000 ? null : (string) $post->post_content,
        'edit_url'    => get_edit_post_link( $id, 'raw' ),
        'preview_url' => get_preview_post_link( $id ),
        'history'     => count( aied_hist_rows( $id ) ),
        'revisions'   => aied_revisions( $id ),
    ];
}

/** The compiler result the service would produce for this site (StudioService::compile is private; same arguments). */
function aied_compile( array $brief ): array
{
    $exists = static fn ( string $path ): bool => url_to_postid( home_url( $path ) ) > 0;

    return StudioCompiler::compile( $brief, $exists, (string) home_url() );
}

function aied_first_diff( string $a, string $b ): ?array
{
    if ( $a === $b ) {
        return null;
    }
    $n = min( strlen( $a ), strlen( $b ) );
    $i = 0;
    while ( $i < $n && $a[ $i ] === $b[ $i ] ) {
        ++$i;
    }

    return [ 'at' => $i, 'compiled_len' => strlen( $a ), 'stored_len' => strlen( $b ), 'compiled' => substr( $a, max( 0, $i - 60 ), 200 ), 'stored' => substr( $b, max( 0, $i - 60 ), 200 ) ];
}

/** Every difference between two block-markup strings, as a list of short context pairs (at most $max). */
function aied_all_diffs( string $a, string $b, int $max = 12 ): array
{
    $out = [];
    $guard = 0;
    while ( $a !== $b && count( $out ) < $max && $guard++ < 1000 ) {
        $d = aied_first_diff( $a, $b );
        $out[] = [ 'compiled' => substr( $a, $d['at'], 40 ), 'stored' => substr( $b, $d['at'], 40 ) ];
        // Skip ahead past the differing run: find the next common 12-byte anchor.
        $a = substr( $a, $d['at'] );
        $b = substr( $b, $d['at'] );
        $found = false;
        for ( $i = 1; $i < min( 400, strlen( $a ) ); $i++ ) {
            $anchor = substr( $a, $i, 12 );
            $j      = strpos( $b, $anchor );
            if ( false !== $j && $j < 400 ) {
                $a = substr( $a, $i );
                $b = substr( $b, $j );
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            break;
        }
    }

    return $out;
}

/**
 * True when two layouts are the same blocks with the same decoded attributes, numeric entities compared without leading
 * zeros (kses writes &#36; as &#036;). The bytes may differ: WordPress re-serializes block attributes for a user without
 * unfiltered_html (a backslash becomes \u005c).
 */
function aied_equivalent( string $a, string $b ): bool
{
    $norm = static function ( $v ) use ( &$norm ) {
        if ( is_array( $v ) ) {
            return array_map( $norm, $v );
        }

        return is_string( $v ) ? preg_replace( '/&#0+([0-9]+);/', '&#$1;', $v ) : $v;
    };
    $flat = static function ( array $blocks ) use ( &$flat, $norm ): array {
        $out = [];
        foreach ( $blocks as $bl ) {
            if ( null === $bl['blockName'] && '' === trim( (string) $bl['innerHTML'] ) ) {
                continue;
            }
            $out[] = [ $bl['blockName'], $norm( $bl['attrs'] ), trim( (string) $norm( $bl['innerHTML'] ) ), $flat( $bl['innerBlocks'] ) ];
        }

        return $out;
    };

    return $flat( parse_blocks( $a ) ) === $flat( parse_blocks( $b ) );
}

function aied_preview_drafts( int $user ): array
{
    global $wpdb;

    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'draft' AND post_author = %d AND post_title = %s", $user, StudioPreview::TITLE_PREFIX . $user ) ) );
}

function aied_studio_rows(): array
{
    global $wpdb;
    if ( ! StudioTable::exists() ) {
        return [];
    }
    $t = StudioTable::name();

    // phpcs:ignore WordPress.DB
    return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A ) ?: [];
}

function aied_insert_page( string $title, string $status, int $author, string $content = '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' ): int
{
    $id = wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_author' => $author, 'post_content' => $content ] ), true );
    if ( is_wp_error( $id ) ) {
        WP_CLI::error( 'Could not insert a throwaway page: ' . $id->get_error_message() );
    }

    return (int) $id;
}

function aied_backdate( int $id, int $seconds ): void
{
    global $wpdb;
    $gmt   = gmdate( 'Y-m-d H:i:s', time() - $seconds );
    $local = get_date_from_gmt( $gmt );
    $wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $gmt, 'post_modified' => $local ], [ 'ID' => $id ] );
    clean_post_cache( $id );
}

/**
 * Whether the gate may delete this post: its title starts with `aied studio <run token> ` (any run when $token is null),
 * or it is exactly a gate user's preview draft title, or its author is a gate user. Nothing else is ever deleted, even
 * when a plugin answer handed the gate its id (a product bug returning a wrong id must not delete a real page).
 *
 * @param list<int> $uids the gate users
 */
function aied_may_delete( $post, ?string $token, array $uids ): bool
{
    if ( ! is_object( $post ) ) {
        return false;
    }
    $title = (string) ( $post->post_title ?? '' );
    if ( str_starts_with( $title, 'aied studio ' . ( null === $token ? '' : $token . ' ' ) ) ) {
        return true;
    }
    foreach ( $uids as $uid ) {
        if ( $title === StudioPreview::TITLE_PREFIX . (int) $uid ) {
            return true;
        }
    }

    return in_array( (int) ( $post->post_author ?? 0 ), array_map( 'intval', $uids ), true );
}

/** The gate's users of this run: login => id. */
function aied_gate_users( ?string $token ): array
{
    global $wpdb;
    $like = $wpdb->esc_like( 'aied_studio_' . ( null === $token ? '' : $token . '_' ) ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} WHERE user_login LIKE %s", $like ), ARRAY_A );
    $out  = [];
    foreach ( $rows ?: [] as $r ) {
        $out[ $r['user_login'] ] = (int) $r['ID'];
    }

    return $out;
}

function aied_gate_pages( ?string $token ): array
{
    global $wpdb;
    $like = $wpdb->esc_like( 'aied studio ' . ( null === $token ? '' : $token . ' ' ) ) . '%';

    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title LIKE %s", $like ) ) );
}

/** Brief rows the gate made: owned by a gate user, or by anyone with a title of this run (any run when token is null). */
function aied_gate_rows( ?string $token, array $userIds ): array
{
    $ids = [];
    foreach ( aied_studio_rows() as $row ) {
        $brief = json_decode( (string) $row['brief'], true );
        $title = is_array( $brief ) ? (string) ( $brief['title'] ?? '' ) : '';
        $mine  = str_starts_with( $title, 'aied studio ' . ( null === $token ? '' : $token . ' ' ) );
        if ( $mine || in_array( (int) $row['created_by'], $userIds, true ) || ( null === $token && ! get_userdata( (int) $row['created_by'] ) ) ) {
            $ids[] = (int) $row['id'];
        }
    }

    return $ids;
}

function aied_delete_rows( array $ids ): void
{
    global $wpdb;
    if ( [] === $ids || ! StudioTable::exists() ) {
        return;
    }
    $t = StudioTable::name();
    foreach ( $ids as $id ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB
    }
}

function aied_watched_options(): array
{
    global $wpdb;

    $rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%ai\\_editor\\_divi5%' OR option_name LIKE '%aied%' ORDER BY option_name", ARRAY_A ) ?: [];
    $out  = [];
    foreach ( $rows as $r ) {
        $out[ $r['option_name'] ] = [ 'value' => $r['option_value'], 'autoload' => $r['autoload'] ];
    }

    return $out;
}

function aied_watched_meta( int $user ): array
{
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s ) ORDER BY umeta_id", $user, '%aied%', '%ai\\_editor%' ), ARRAY_A ) ?: [];
    $out  = [];
    foreach ( $rows as $r ) {
        $out[ $r['meta_key'] ][] = $r['meta_value'];
    }

    return $out;
}

switch ( $cmd ) {

    // ------------------------------------------------------------------ setup and teardown

    case 'snapshot':
        $prev = aied_preview_drafts( 1 );
        aied_out( [
            'home'           => home_url(),
            'options'        => aied_watched_options(),
            'meta1'          => aied_watched_meta( 1 ),
            'rows'           => aied_studio_rows(),
            'table'          => StudioTable::exists(),
            'previewOf1'     => array_map( static fn ( $id ) => [ 'id' => $id, 'content' => (string) get_post( $id )->post_content ], $prev ),
            'gatePages'      => aied_gate_pages( null ),
            'gateUsers'      => aied_gate_users( null ),
            'gateRows'       => aied_gate_rows( null, array_values( aied_gate_users( null ) ) ),
            'foreignPreview' => (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='page' AND post_title LIKE %s", $GLOBALS['wpdb']->esc_like( StudioPreview::TITLE_PREFIX ) . '%' ) ),
            'hasCapture'     => false !== has_action( 'pre_post_update', [ HistoryCapture::class, 'onPreUpdate' ] ),
            'pluginVersion'  => defined( 'AI_EDITOR_DIVI5_VERSION' ) ? AI_EDITOR_DIVI5_VERSION : '',
            'divi'           => wp_get_theme( 'Divi' )->get( 'Version' ),
            'wp'             => get_bloginfo( 'version' ),
        ] );
        break;

    case 'users':
        $token = aied_token( $p );
        $made  = [];
        $specs = [
            'a' => [ 'administrator', [] ],
            'b' => [ 'administrator', [] ],
            'n' => [ 'administrator', [ 'unfiltered_html' => false ] ],
            'e' => [ 'editor', [ 'unfiltered_html' => false ] ],
            'r' => [ 'administrator', [ 'edit_others_pages' => false ] ],
        ];
        foreach ( $specs as $k => [ $role, $caps ] ) {
            $login = 'aied_studio_' . $token . '_' . $k;
            $id    = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => $role, 'display_name' => 'aied studio ' . $token . ' ' . $k ] );
            if ( is_wp_error( $id ) ) {
                WP_CLI::error( 'Could not create ' . $login . ': ' . $id->get_error_message() );
            }
            $u = new WP_User( $id );
            foreach ( $caps as $cap => $grant ) {
                $u->add_cap( $cap, $grant );
            }
            $made[ $k ] = (int) $id;
        }
        aied_out( [
            'ids'  => $made,
            'caps' => [
                'n_unfiltered'   => user_can( $made['n'], 'unfiltered_html' ),
                'e_unfiltered'   => user_can( $made['e'], 'unfiltered_html' ),
                'e_edit_pages'   => user_can( $made['e'], 'edit_pages' ),
                'e_manage'       => user_can( $made['e'], 'manage_options' ),
                'r_edit_others'  => user_can( $made['r'], 'edit_others_pages' ),
                'r_manage'       => user_can( $made['r'], 'manage_options' ),
                'a_unfiltered'   => user_can( $made['a'], 'unfiltered_html' ),
            ],
        ] );
        break;

    case 'session':
        $u = (int) ( $p['user'] ?? 0 );
        if ( $u <= 0 || ! get_userdata( $u ) ) {
            WP_CLI::error( 'No such user' );
        }
        $e = time() + 3 * 3600;
        $t = WP_Session_Tokens::get_instance( $u )->create( $e );
        aied_out( [ 'token' => $t, 'cookies' => [ [ AUTH_COOKIE, wp_generate_auth_cookie( $u, $e, 'auth', $t ) ], [ LOGGED_IN_COOKIE, wp_generate_auth_cookie( $u, $e, 'logged_in', $t ) ] ] ] );
        break;

    case 'end-session':
        $u = (int) ( $p['user'] ?? 0 );
        $t = (string) ( $p['token'] ?? '' );
        if ( $u > 0 && 1 === preg_match( '/^[A-Za-z0-9]+$/', $t ) && get_userdata( $u ) ) {
            WP_Session_Tokens::get_instance( $u )->destroy( $t );
        }
        aied_out( [ 'ok' => true ] );
        break;

    case 'errors-on':
        // The mu-plugins folder belongs to root on this site, so the gate writes the file itself (as root); this command
        // only returns its path and code.
        $token = aied_token( $p );
        @unlink( WP_CONTENT_DIR . '/' . AIED_GATE_LOG );
        $log  = WP_CONTENT_DIR . '/' . AIED_GATE_LOG;
        $code = "<?php\n// Temporary: written by the Lab live gate (scripts/verify-studio.cjs, run {$token}) and removed when it ends.\n"
            . "// Logs every PHP warning, notice and deprecation (WP_DEBUG is off on this site) so the gate can show there are none.\n"
            . "set_error_handler( static function ( \$no, \$str, \$file, \$line ) { @file_put_contents( '{$log}', json_encode( [ \$no, \$str, \$file, \$line ] ) . \"\\n\", FILE_APPEND ); return false; } );\n"
            . "register_shutdown_function( static function () { \$e = error_get_last(); if ( \$e && in_array( \$e['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) { @file_put_contents( '{$log}', json_encode( [ \$e['type'], \$e['message'], \$e['file'], \$e['line'] ] ) . \"\\n\", FILE_APPEND ); } } );\n"
            . "if ( isset( \$_GET['aied_gate_selftest'] ) && '{$token}' === \$_GET['aied_gate_selftest'] ) { trigger_error( 'aied gate self-test {$token}', E_USER_NOTICE ); }\n";
        aied_out( [ 'file' => WPMU_PLUGIN_DIR . '/' . AIED_GATE_MU, 'code' => $code ] );
        break;

    case 'errors-read':
        $log = WP_CONTENT_DIR . '/' . AIED_GATE_LOG;
        aied_out( [ 'log' => is_file( $log ) ? (string) file_get_contents( $log ) : '' ] );
        break;

    case 'errors-off':
        @unlink( WPMU_PLUGIN_DIR . '/' . AIED_GATE_MU );
        @unlink( WP_CONTENT_DIR . '/' . AIED_GATE_LOG );
        aied_out( [ 'ok' => ! is_file( WPMU_PLUGIN_DIR . '/' . AIED_GATE_MU ) ] );
        break;

    case 'cleanup':
    case 'orphans':
        global $wpdb;
        $token = 'cleanup' === $cmd ? aied_token( $p ) : null;
        $users = aied_gate_users( $token );
        $uids  = array_values( $users );
        // Pages first (their history goes with them), then the users (with any page they still own), then brief rows.
        $pages = array_unique( array_merge( aied_gate_pages( $token ), array_map( 'intval', (array) ( $p['pages'] ?? [] ) ) ) );
        foreach ( $uids as $uid ) {
            $pages = array_merge( $pages, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ('page','revision')", $uid ) ) ) );
        }
        $deleted = 0;
        $refused = [];
        foreach ( array_unique( $pages ) as $id ) {
            $post = $id > 0 ? get_post( $id ) : null;
            if ( ! $post ) {
                continue;
            }
            if ( ! aied_may_delete( $post, $token, $uids ) ) {
                $refused[] = [ 'id' => $id, 'title' => mb_substr( (string) $post->post_title, 0, 80 ) ];
                WP_CLI::warning( 'Not deleting page ' . $id . ': it is not a page of this gate run.' );
                continue;
            }
            if ( false !== wp_delete_post( $id, true ) ) {
                ++$deleted;
            }
        }
        $attach = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title LIKE %s", $wpdb->esc_like( 'aied studio ' . ( null === $token ? '' : $token . ' ' ) ) . '%' ) ) );
        foreach ( $attach as $aid ) {
            wp_delete_attachment( $aid, true );
        }
        $rows = aied_gate_rows( $token, $uids );
        aied_delete_rows( $rows );
        foreach ( $uids as $uid ) {
            wp_delete_user( $uid );
        }
        // User 1's own preview draft: put back as it was before the run, or removed when the run made it.
        $before = (array) ( $p['previewOf1'] ?? [] );
        $keep   = [];
        foreach ( $before as $b ) {
            $keep[ (int) $b['id'] ] = (string) $b['content'];
        }
        if ( 'cleanup' === $cmd ) {
            foreach ( aied_preview_drafts( 1 ) as $id ) {
                if ( isset( $keep[ $id ] ) ) {
                    if ( (string) get_post( $id )->post_content !== $keep[ $id ] ) {
                        $wpdb->update( $wpdb->posts, [ 'post_content' => $keep[ $id ] ], [ 'ID' => $id ] );
                        clean_post_cache( $id );
                    }
                } else {
                    wp_delete_post( $id, true );
                    ++$deleted;
                }
            }
        }
        // Brief rows that existed before the run and are missing now (the table check drops the table): put back as they were.
        $restored = 0;
        if ( isset( $p['rows'] ) && is_array( $p['rows'] ) && [] !== $p['rows'] ) {
            StudioTable::ensureReady();
            $have = array_map( static fn ( $r ) => (int) $r['id'], aied_studio_rows() );
            foreach ( $p['rows'] as $r ) {
                if ( ! in_array( (int) $r['id'], $have, true ) ) {
                    $wpdb->insert( StudioTable::name(), $r );
                    ++$restored;
                }
            }
        }
        // Options and user meta of user 1 that match the plugin's names: put back what changed, remove what is new.
        $optFixed = [];
        if ( isset( $p['options'] ) && is_array( $p['options'] ) ) {
            $now = aied_watched_options();
            foreach ( $now as $name => $row ) {
                if ( ! array_key_exists( $name, $p['options'] ) ) {
                    $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
                    $optFixed[] = '-' . $name;
                } elseif ( $p['options'][ $name ]['value'] !== $row['value'] ) {
                    $wpdb->update( $wpdb->options, [ 'option_value' => $p['options'][ $name ]['value'] ], [ 'option_name' => $name ] );
                    $optFixed[] = '~' . $name;
                }
            }
            foreach ( $p['options'] as $name => $row ) {
                if ( ! array_key_exists( $name, $now ) ) {
                    $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => $row['value'], 'autoload' => $row['autoload'] ] );
                    $optFixed[] = '+' . $name;
                }
            }
            wp_cache_flush();
        }
        $metaFixed = [];
        if ( isset( $p['meta1'] ) && is_array( $p['meta1'] ) ) {
            $now = aied_watched_meta( 1 );
            foreach ( array_unique( array_merge( array_keys( $now ), array_keys( $p['meta1'] ) ) ) as $key ) {
                $was = $p['meta1'][ $key ] ?? null;
                $is  = $now[ $key ] ?? null;
                if ( $was === $is ) {
                    continue;
                }
                $wpdb->delete( $wpdb->usermeta, [ 'user_id' => 1, 'meta_key' => $key ] );
                foreach ( (array) $was as $v ) {
                    $wpdb->insert( $wpdb->usermeta, [ 'user_id' => 1, 'meta_key' => $key, 'meta_value' => $v ] );
                }
                $metaFixed[] = $key;
            }
            wp_cache_flush();
        }
        @unlink( WPMU_PLUGIN_DIR . '/' . AIED_GATE_MU );
        @unlink( WP_CONTENT_DIR . '/' . AIED_GATE_LOG );
        aied_out( [
            'deletedPages' => $deleted,
            'refused'      => $refused,
            'deletedRows'  => count( $rows ),
            'deletedUsers' => count( $uids ),
            'restoredRows' => $restored,
            'options'      => $optFixed,
            'meta'         => $metaFixed,
        ] );
        break;

    case 'selftest-delete':
        // The cleanup's guard, on made-up posts (nothing is read or written).
        $token = '123-abcdef';
        $mk    = static fn ( string $t, int $a ) => (object) [ 'post_title' => $t, 'post_author' => $a ];
        $cases = [
            [ $mk( 'aied studio 123-abcdef G book', 1 ), $token, [ 50 ], true ],
            [ $mk( 'aied studio 999-zzzzzz G book', 1 ), $token, [ 50 ], false ],
            [ $mk( 'aied studio 999-zzzzzz G book', 1 ), null, [ 50 ], true ],
            [ $mk( 'Lab preview (temporary) 50', 1 ), $token, [ 50 ], true ],
            [ $mk( 'Lab preview (temporary) 1', 1 ), $token, [ 50 ], false ],
            [ $mk( 'About us', 50 ), $token, [ 50 ], true ],
            [ $mk( 'About us', 1 ), $token, [ 50 ], false ],
            [ $mk( 'aied studio', 1 ), $token, [ 50 ], false ],
            [ $mk( 'My aied studio 123-abcdef page', 1 ), $token, [ 50 ], false ],
            [ null, $token, [ 50 ], false ],
        ];
        $bad = [];
        foreach ( $cases as $i => [ $post, $tok, $uids, $want ] ) {
            if ( aied_may_delete( $post, $tok, $uids ) !== $want ) {
                $bad[] = $i;
            }
        }
        aied_out( [ 'cases' => count( $cases ), 'wrong' => $bad ] );
        break;

    case 'leftovers':
        global $wpdb;
        $token = isset( $p['token'] ) ? aied_token( $p ) : null;
        $users = aied_gate_users( $token );
        aied_out( [
            'pages'       => aied_gate_pages( null ),
            'previews'    => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_type='page' AND p.post_title LIKE %s AND ( u.ID IS NULL OR u.user_login LIKE %s )", $wpdb->esc_like( StudioPreview::TITLE_PREFIX ) . '%', $wpdb->esc_like( 'aied_studio_' ) . '%' ) ) ),
            'previewOf1'  => aied_preview_drafts( 1 ),
            'users'       => $users,
            'rows'        => aied_gate_rows( null, array_values( aied_gate_users( null ) ) ),
            'allRows'     => count( aied_studio_rows() ),
            'mu'          => is_file( WPMU_PLUGIN_DIR . '/' . AIED_GATE_MU ),
            'attachments' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title LIKE %s", $wpdb->esc_like( 'aied studio ' ) . '%' ) ) ),
            'table'       => StudioTable::exists(),
        ] );
        break;

    // ------------------------------------------------------------------ A: the table on the real MariaDB (Ruling 8)

    case 'table':
        global $wpdb;
        $x      = (int) $p['a'];
        $y      = (int) $p['b'];
        $checks = [];
        $t      = StudioTable::name();
        $before = aied_studio_rows();
        $brief  = [ 'version' => 1, 'title' => 'aied studio ' . aied_token( $p ) . ' table', 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => 'cta-band', 'slots' => [ 'heading.0' => 'Quote "q" \\ & <b>' ] ] ] ];

        // Created from nothing through the real dbDelta.
        StudioTable::drop();
        aied_check( $checks, ! StudioTable::exists() && false === get_option( StudioTable::DB_VERSION_OPTION ), 'A: drop() removes the table and its version option' );
        $wpdb->last_error = '';
        $ready = StudioTable::ensureReady();
        aied_check( $checks, $ready && StudioTable::exists() && 1 === (int) get_option( StudioTable::DB_VERSION_OPTION ) && '' === $wpdb->last_error, 'A: ensureReady() creates the table on MariaDB through dbDelta', [ 'error' => $wpdb->last_error, 'server' => $wpdb->db_server_info() ] );
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ); // phpcs:ignore WordPress.DB
        aied_check( $checks, [ 'id', 'created_by', 'page_id', 'brief', 'brief_hash', 'content_hash', 'plugin_version', 'created_at', 'updated_at' ] === $cols, 'A: the columns are the schema\'s', $cols );
        $keys = [];
        foreach ( $wpdb->get_results( "SHOW INDEX FROM {$t}", ARRAY_A ) as $ix ) { // phpcs:ignore WordPress.DB
            $keys[ $ix['Key_name'] ][] = $ix['Column_name'];
        }
        aied_check( $checks, [ 'PRIMARY' => [ 'id' ], 'user_idx' => [ 'created_by', 'updated_at' ], 'page_idx' => [ 'created_by', 'page_id' ] ] == $keys, 'A: the primary key and both indexes exist (dbDelta read the two-space PRIMARY KEY line)', $keys );
        $wpdb->last_error = '';
        aied_check( $checks, StudioTable::ensureReady( true ) && '' === $wpdb->last_error && $cols === $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ), 'A: ensureReady(true) again on an existing table is a no-op without an error', $wpdb->last_error ); // phpcs:ignore WordPress.DB

        // save / get / another user's id.
        $s1 = StudioStore::save( $x, $brief );
        $g1 = $s1['ok'] ? StudioStore::get( $x, $s1['id'] ) : null;
        aied_check( $checks, $s1['ok'] && null !== $g1 && $g1['brief'] === $brief && $g1['page_id'] === null && 64 === strlen( $g1['brief_hash'] ), 'A: save() then get() returns the same brief (quotes, backslash, & and a tag kept)', [ 'id' => $s1['id'] ] );
        $other   = StudioStore::get( $y, $s1['id'] );
        $missing = StudioStore::get( $y, 999999999 );
        aied_check( $checks, null === $other && null === $missing, 'A: another user\'s id reads as missing (get)' );
        $su = StudioStore::save( $y, $brief, $s1['id'] );
        $sm = StudioStore::save( $y, $brief, 999999999 );
        aied_check( $checks, $su === $sm && false === $su['ok'] && StudioStore::NOT_FOUND === $su['code'] && StudioStore::get( $x, $s1['id'] )['brief'] === $brief, 'A: another user\'s id reads as missing (save), and the row is untouched', [ $su, $sm ] );
        aied_check( $checks, false === StudioStore::delete( $y, $s1['id'] ) && null !== StudioStore::get( $x, $s1['id'] ), 'A: another user cannot delete the row' );

        // link / forPage / a second brief on the same page unlinks the first (UPDATE ... SET page_id = NULL on MariaDB).
        $page = aied_insert_page( 'aied studio ' . aied_token( $p ) . ' table page', 'draft', $x );
        $l1   = StudioStore::link( $x, $s1['id'], $page, str_repeat( 'a', 64 ) );
        $f1   = StudioStore::forPage( $x, $page );
        aied_check( $checks, $l1 && null !== $f1 && $f1['id'] === $s1['id'] && $f1['content_hash'] === str_repeat( 'a', 64 ) && null === StudioStore::forPage( $y, $page ), 'A: link() and forPage() (only for the owner)' );
        $s2 = StudioStore::save( $x, $brief );
        $l2 = StudioStore::link( $x, $s2['id'], $page, str_repeat( 'b', 64 ) );
        $g1 = StudioStore::get( $x, $s1['id'] );
        aied_check( $checks, $l2 && null === $g1['page_id'] && null === $g1['content_hash'] && StudioStore::forPage( $x, $page )['id'] === $s2['id'], 'A: linking a second brief to the page unlinks the first (page_id and content_hash set to NULL)', $g1 );
        $recent = StudioStore::recentlyLinked( $x, (string) StudioStore::hashOf( $brief ), 60 );
        aied_check( $checks, null !== $recent && $recent['id'] === $s2['id'], 'A: recentlyLinked() finds the brief just linked (the duplicate-create guard)' );

        // prune at 50: 51 more saves leave 50 rows, the oldest unlinked go first, the linked one stays.
        for ( $i = 0; $i < 51; $i++ ) {
            StudioStore::save( $x, $brief );
        }
        $countX = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_by = %d", $x ) ); // phpcs:ignore WordPress.DB
        aied_check( $checks, StudioStore::MAX_PER_USER === $countX && null === StudioStore::get( $x, $s1['id'] ) && null !== StudioStore::get( $x, $s2['id'] ), 'A: prune keeps 50 briefs per user: the oldest unlinked went, the linked one stayed', [ 'rows' => $countX ] );

        // A corrupt row reads as missing and breaks nothing.
        $s3 = StudioStore::save( $x, $brief );
        $wpdb->update( $t, [ 'brief' => '{"version":1,"title": not json' ], [ 'id' => $s3['id'] ] );
        $_GET['brief'] = (string) $s3['id'];
        wp_set_current_user( $x );
        $cfg = StudioScreen::config( $x );
        unset( $_GET['brief'] );
        wp_set_current_user( 1 );
        aied_check( $checks, null === StudioStore::get( $x, $s3['id'] ) && null === $cfg['brief'] && false === StudioService::rebuild( $x, $s3['id'] )['ok'] && StudioService::NOT_FOUND === StudioService::rebuild( $x, $s3['id'] )['code'], 'A: a corrupt row reads as missing (get, the screen\'s ?brief=, rebuild says NOT_FOUND)' );

        // delete
        aied_check( $checks, StudioStore::delete( $x, $s2['id'] ) && null === StudioStore::get( $x, $s2['id'] ), 'A: delete() removes the owner\'s row' );

        // The Studio parts of uninstall.php, through their public methods (never uninstall.php itself: it drops other tables).
        $foreign = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_type='page' AND p.post_status='draft' AND p.post_title LIKE %s AND ( u.user_login IS NULL OR u.user_login NOT LIKE %s )", $wpdb->esc_like( StudioPreview::TITLE_PREFIX ) . '%', $wpdb->esc_like( 'aied_studio_' ) . '%' ) );
        wp_delete_post( $page, true );
        if ( 0 === $foreign ) {
            wp_set_current_user( $x );
            $pv = StudioPreview::url( $x, (string) aied_compile( [ 'version' => 1, 'title' => 'x', 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => 'cta-band', 'slots' => [] ] ] ] )['markup'] );
            wp_set_current_user( 1 );
            $had = aied_preview_drafts( $x );
            StudioPreview::cleanupAll();
            StudioStore::deleteAll();
            StudioTable::drop();
            aied_check( $checks, 'draft' === $pv['mode'] && 1 === count( $had ) && [] === aied_preview_drafts( $x ) && ! StudioTable::exists() && false === get_option( StudioTable::DB_VERSION_OPTION ), 'A: uninstall\'s Studio steps (cleanupAll, deleteAll, drop) remove every preview draft, every row and the table' );
        } else {
            StudioStore::deleteAll();
            StudioTable::drop();
            aied_check( $checks, ! StudioTable::exists(), 'A: uninstall\'s deleteAll + drop remove the table' );
            $checks[] = [ 'ok' => true, 'skip' => true, 'label' => 'A: uninstall\'s cleanupAll() was NOT run live: ' . $foreign . ' preview draft(s) of real users exist on this site and would be deleted', 'detail' => null ];
        }
        aied_check( $checks, StudioTable::ensureReady() && StudioTable::exists() && [] === aied_studio_rows(), 'A: after the drop, ensureReady() brings the table back, empty' );
        // Rows that were there before the run are put back with their ids.
        foreach ( $before as $r ) {
            $wpdb->insert( $t, $r );
        }
        aied_check( $checks, count( aied_studio_rows() ) === count( $before ), 'A: the rows that existed before the run are back (' . count( $before ) . ')' );
        aied_out( $checks );
        break;

    // ------------------------------------------------------------------ B: the preview draft on the real WordPress

    case 'preview':
        global $wpdb;
        $x      = (int) $p['a'];
        $y      = (int) $p['b'];
        $token  = aied_token( $p );
        $checks = [];
        $mk     = static fn ( string $lang, string $h ) => (string) aied_compile( [ 'version' => 1, 'title' => 'p', 'goal' => 'book', 'language' => $lang, 'sections' => [ [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => $h ] ], [ 'recipe' => 'cta-band', 'slots' => [] ] ] ] )['markup'];
        $m1     = $mk( 'editorial', 'One ' . $token );
        $m2     = $mk( 'luxe', 'Two ' . $token );
        $m3     = $mk( 'brutalist-flat', 'Three ' . $token );
        aied_check( $checks, '' !== $m1 && '' !== $m2 && '' !== $m3 && $m1 !== $m2 && $m2 !== $m3, 'B: three different layouts compiled' );
        aied_check( $checks, false !== has_action( 'pre_post_update', [ HistoryCapture::class, 'onPreUpdate' ] ), 'B: the real HistoryCapture hook is registered in this request' );

        // Look-alikes the strict match must never take.
        $title = StudioPreview::TITLE_PREFIX . $x;
        $look  = [
            'other author'     => aied_insert_page( $title, 'draft', $y ),
            'published'        => aied_insert_page( $title, 'publish', $x ),
            'other title'      => aied_insert_page( $title . ' copy', 'draft', $x ),
            'names y, by x'    => aied_insert_page( StudioPreview::TITLE_PREFIX . $y, 'draft', $x ),
        ];
        $lookSha = [];
        foreach ( $look as $k => $id ) {
            $lookSha[ $k ] = aied_post( $id )['sha'];
        }

        wp_set_current_user( $x );
        $u1 = StudioPreview::url( $x, $m1 );
        $id = (int) ( wp_parse_args( (string) wp_parse_url( (string) $u1['url'], PHP_URL_QUERY ) )['page_id'] ?? 0 );
        $p1 = aied_post( $id );
        aied_check( $checks, 'draft' === $u1['mode'] && $id > 0 && ! in_array( $id, $look, true ) && $p1['title'] === $title && $p1['author'] === $x && 'draft' === $p1['status'] && $p1['content'] === $m1, 'B: the first preview inserts the user\'s own draft (exact title, author, status, content), not a look-alike', [ 'id' => $id, 'url' => $u1['url'] ] );
        aied_check( $checks, [ $id ] === aied_preview_drafts( $x ), 'B: exactly one preview draft of the user exists' );
        $u2 = StudioPreview::url( $x, $m2 );
        $u3 = StudioPreview::url( $x, $m3 );
        $p3 = aied_post( $id );
        aied_check( $checks, $u2['url'] === $u1['url'] && $u3['url'] === $u1['url'] && $p3['content'] === $m3 && [ $id ] === aied_preview_drafts( $x ), 'B: two updates keep the same draft id and store the newest layout' );
        aied_check( $checks, 0 === $p3['history'], 'B: the two updates wrote NO history row (real hook registered, save suspended)', [ 'rows' => $p3['history'] ] );
        aied_check( $checks, 0 === $p3['revisions'], 'B: the two updates left no revision', [ 'revisions' => $p3['revisions'] ] );
        $same = true;
        foreach ( $look as $k => $lid ) {
            $same = $same && aied_post( $lid )['sha'] === $lookSha[ $k ];
        }
        aied_check( $checks, $same, 'B: the look-alikes (other author, published, other title, names another user) are untouched' );

        // Control: an ordinary save of the same draft outside the preview path IS captured (so the zero above means something).
        wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => $m1 ] ) );
        $rows = aied_hist_rows( $id );
        aied_check( $checks, 1 === count( $rows ), 'B: control: an ordinary wp_update_post of that draft writes one history row (the hook is live)', $rows );

        // Deleting the draft (Create's cleanup) removes it and its history rows and revisions.
        StudioPreview::cleanup( $x );
        aied_check( $checks, ! aied_post( $id )['exists'] && [] === aied_hist_rows( $id ) && 0 === aied_revisions( $id ), 'B: cleanup() (wp_delete_post) removes the draft, its history rows and its revisions' );
        $same = true;
        foreach ( $look as $k => $lid ) {
            $same = $same && aied_post( $lid )['exists'] && aied_post( $lid )['sha'] === $lookSha[ $k ];
        }
        aied_check( $checks, $same, 'B: cleanup() left every look-alike alone' );

        // cleanupStale(): a back-dated draft goes; back-dated look-alikes and a fresh draft of another user stay.
        $u4  = StudioPreview::url( $x, $m1 );
        $id4 = (int) ( wp_parse_args( (string) wp_parse_url( (string) $u4['url'], PHP_URL_QUERY ) )['page_id'] ?? 0 );
        wp_set_current_user( $y );
        $uy  = StudioPreview::url( $y, $m2 );
        $idy = (int) ( wp_parse_args( (string) wp_parse_url( (string) $uy['url'], PHP_URL_QUERY ) )['page_id'] ?? 0 );
        wp_set_current_user( 1 );
        aied_backdate( $id4, 2 * DAY_IN_SECONDS );
        foreach ( $look as $lid ) {
            aied_backdate( $lid, 2 * DAY_IN_SECONDS );
        }
        $removed = StudioPreview::cleanupStale();
        $lookStay = true;
        foreach ( $look as $lid ) {
            $lookStay = $lookStay && aied_post( $lid )['exists'];
        }
        aied_check( $checks, $id4 > 0 && ! aied_post( $id4 )['exists'] && aied_post( $idy )['exists'] && $lookStay, 'B: cleanupStale() removed the back-dated draft and left the fresh draft of another user and the back-dated look-alikes', [ 'removed' => $removed, 'stale_gone' => ! aied_post( $id4 )['exists'], 'fresh_kept' => aied_post( $idy )['exists'], 'ids' => [ $id4, $idy ], 'looks' => array_map( static fn ( $l ) => aied_post( $l )['exists'], $look ) ] );

        // cleanupAll(): only when no real user's preview draft exists (it is meant for uninstall).
        $foreign = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_type='page' AND p.post_status='draft' AND p.post_title LIKE %s AND ( u.user_login IS NULL OR u.user_login NOT LIKE %s )", $wpdb->esc_like( StudioPreview::TITLE_PREFIX ) . '%', $wpdb->esc_like( 'aied_studio_' ) . '%' ) );
        if ( 0 === $foreign ) {
            StudioPreview::cleanupAll();
            $lookStay = true;
            foreach ( $look as $lid ) {
                $lookStay = $lookStay && aied_post( $lid )['exists'];
            }
            aied_check( $checks, [] === aied_preview_drafts( $x ) && [] === aied_preview_drafts( $y ) && $lookStay, 'B: cleanupAll() leaves no preview draft and keeps the look-alikes' );
        } else {
            StudioPreview::cleanup( $y );
            $checks[] = [ 'ok' => true, 'skip' => true, 'label' => 'B: cleanupAll() was NOT run live: ' . $foreign . ' preview draft(s) of real users exist and would be deleted', 'detail' => null ];
        }
        foreach ( $look as $lid ) {
            wp_delete_post( $lid, true );
        }
        aied_out( $checks );
        break;

    // ------------------------------------------------------------------ D: kses for a user without unfiltered_html (Ruling 13)

    case 'kses':
        $e      = (int) $p['e'];
        $token  = aied_token( $p );
        $brief  = $p['brief'];
        $checks = [];
        $c      = aied_compile( $brief );
        aied_check( $checks, true === $c['ok'], 'D: the brief compiles', $c['problems'] ?? null );
        $markup = (string) $c['markup'];
        wp_set_current_user( $e );
        aied_check( $checks, ! current_user_can( 'unfiltered_html' ) && current_user_can( 'edit_pages' ) && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'D: the Editor has no unfiltered_html and kses is on for its saves' );
        // The preview draft: insert, then update.
        $pv  = StudioPreview::url( $e, $markup );
        $pid = (int) ( wp_parse_args( (string) wp_parse_url( (string) $pv['url'], PHP_URL_QUERY ) )['page_id'] ?? 0 );
        $s1  = $pid > 0 ? (string) get_post( $pid )->post_content : '';
        $c2  = aied_compile( array_replace( $brief, [ 'language' => 'luxe' ] ) );
        StudioPreview::url( $e, (string) $c2['markup'] );
        clean_post_cache( $pid );
        $s2  = $pid > 0 ? (string) get_post( $pid )->post_content : '';
        // A Studio create.
        $made = StudioService::create( $e, $brief );
        $qid  = (int) ( $made['page_id'] ?? 0 );
        $s3   = $qid > 0 ? (string) get_post( $qid )->post_content : '';
        wp_set_current_user( 1 );
        $out = [
            'checks'  => $checks,
            'preview' => [ 'id' => $pid, 'mode' => $pv['mode'], 'insert_equal' => $s1 === $markup, 'update_equal' => $s2 === (string) $c2['markup'], 'insert_equivalent' => aied_equivalent( $markup, $s1 ), 'update_equivalent' => aied_equivalent( (string) $c2['markup'], $s2 ), 'visible_code' => str_contains( $s1, '&lt;!-- wp:' ) || str_contains( $s2, '&lt;!-- wp:' ), 'insert_diffs' => aied_all_diffs( $markup, $s1 ), 'update_diffs' => aied_all_diffs( (string) $c2['markup'], $s2 ) ],
            'create'  => [ 'ok' => $made['ok'], 'code' => $made['code'], 'id' => $qid, 'brief_id' => $made['brief_id'] ?? null, 'equal' => $s3 === $markup, 'equivalent' => aied_equivalent( $markup, $s3 ), 'visible_code' => str_contains( $s3, '&lt;!-- wp:' ), 'diffs' => aied_all_diffs( $markup, $s3 ), 'valid' => '' !== $s3 && LayoutGate::check( $s3 )->isValid(), 'preview_url' => $qid ? get_preview_post_link( $qid ) : null ],
        ];
        aied_out( $out );
        break;

    // ------------------------------------------------------------------ small commands for the browser and ajax checks

    case 'post':
        aied_out( aied_post( (int) $p['id'] ) );
        break;

    case 'history':
        aied_out( aied_hist_rows( (int) $p['id'] ) );
        break;

    case 'restore':
        $r = HistoryService::restore( (int) $p['page'], (int) $p['version'] );
        aied_out( [ 'ok' => $r['ok'], 'error' => $r['error'] ?? null, 'post' => aied_post( (int) $p['page'] ) ] );
        break;

    case 'brief':
        aied_out( StudioStore::get( (int) $p['user'], (int) $p['id'] ) );
        break;

    case 'compare':
        // Compiles the stored brief as the service does and compares the result with the page as stored.
        $row  = StudioStore::get( (int) $p['user'], (int) $p['id'] );
        $post = get_post( (int) $p['page'] );
        if ( null === $row || ! $post ) {
            aied_out( [ 'ok' => false, 'why' => null === $row ? 'no brief' : 'no page' ] );
            break;
        }
        $c = aied_compile( $row['brief'] );
        aied_out( [ 'ok' => true, 'compiled' => (bool) $c['ok'], 'equal' => (string) $c['markup'] === (string) $post->post_content, 'equivalent' => aied_equivalent( (string) $c['markup'], (string) $post->post_content ), 'visible_code' => str_contains( (string) $post->post_content, '&lt;!-- wp:' ), 'diff' => aied_first_diff( (string) $c['markup'], (string) $post->post_content ), 'linked' => $row['page_id'], 'hash_matches' => $row['content_hash'] === hash( 'sha256', (string) $post->post_content ) ] );
        break;

    case 'brief-for-page':
        aied_out( StudioStore::forPage( (int) $p['user'], (int) $p['page'] ) );
        break;

    case 'compare-brief':
        // Compiles a brief that was sent (not stored) and compares it with a page as stored (a preview draft).
        $post = get_post( (int) $p['page'] );
        $c    = aied_compile( (array) $p['brief'] );
        $have = $post ? (string) $post->post_content : '';
        aied_out( [ 'ok' => (bool) $post && true === $c['ok'], 'equal' => (string) $c['markup'] === $have, 'equivalent' => aied_equivalent( (string) $c['markup'], $have ), 'visible_code' => str_contains( $have, '&lt;!-- wp:' ), 'diff' => aied_first_diff( (string) $c['markup'], $have ) ] );
        break;

    case 'mutate':
        global $wpdb;
        $id  = (int) $p['page'];
        $how = (string) $p['how'];
        if ( 'edit' === $how ) {
            // What a Divi builder save does to the stored content, written straight to the table (no hook, no history).
            $content = (string) get_post( $id )->post_content;
            $content .= "\n\n" . '<!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"builderVersion":"5.15.1"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Edited in Divi</p>"}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
            $wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $id ] );
        } elseif ( 'publish' === $how ) {
            wp_publish_post( $id );
        } elseif ( 'trash' === $how ) {
            wp_trash_post( $id );
        } elseif ( 'delete' === $how ) {
            wp_delete_post( $id, true );
        } elseif ( 'author' === $how ) {
            $wpdb->update( $wpdb->posts, [ 'post_author' => (int) $p['author'] ], [ 'ID' => $id ] );
        } elseif ( 'huge' === $how ) {
            // Content over PageHistory::MAX_BYTES that the brief still knows as its own (untouched): only no_undo can stop the overwrite.
            $content = (string) get_post( $id )->post_content . '<!-- aied studio padding ' . str_repeat( 'x', 600000 ) . ' -->';
            $wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $id ] );
            StudioStore::link( (int) $p['user'], (int) $p['brief'], $id, hash( 'sha256', $content ) );
        }
        clean_post_cache( $id );
        aied_out( aied_post( $id ) + [ 'can_edit_as' => isset( $p['as'] ) ? user_can( (int) $p['as'], 'edit_post', $id ) : null ] );
        break;

    case 'gone-recipe':
        global $wpdb;
        $row = StudioStore::get( (int) $p['user'], (int) $p['id'] );
        $b   = $row['brief'];
        $b['sections'][0]['recipe'] = 'aied-gone-layout';
        $wpdb->update( StudioTable::name(), [ 'brief' => wp_json_encode( $b ) ], [ 'id' => (int) $p['id'] ] );
        aied_out( StudioStore::get( (int) $p['user'], (int) $p['id'] ) );
        break;

    case 'pages-titled':
        global $wpdb;
        aied_out( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_title = %s ORDER BY ID", (string) $p['title'] ) ) ) );
        break;

    case 'preview-drafts':
        aied_out( aied_preview_drafts( (int) $p['user'] ) );
        break;

    case 'image':
        // A small PNG in the Media Library, owned by user a of this run, with alt text: the picker's target.
        $token = aied_token( $p );
        $up    = wp_upload_dir();
        $file  = trailingslashit( $up['path'] ) . 'aied-studio-' . $token . '.png';
        $im = imagecreatetruecolor( 64, 48 );
        imagefill( $im, 0, 0, imagecolorallocate( $im, 40, 90, 160 ) );
        imagepng( $im, $file );
        imagedestroy( $im );
        $id = wp_insert_attachment( [ 'post_title' => 'aied studio ' . $token . ' picture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => (int) $p['a'] ], $file );
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
        update_post_meta( $id, '_wp_attachment_image_alt', 'aied studio ' . $token . ' alt text' );
        aied_out( [ 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ), 'alt' => 'aied studio ' . $token . ' alt text' ] );
        break;

    case 'drop-image':
        $id = (int) $p['id'];
        if ( $id > 0 && 'attachment' === get_post_type( $id ) && str_starts_with( (string) get_the_title( $id ), 'aied studio ' ) ) {
            wp_delete_attachment( $id, true );
        }
        aied_out( [ 'gone' => ! get_post( $id ) ] );
        break;

    default:
        WP_CLI::error( 'Unknown command ' . $cmd );
}
