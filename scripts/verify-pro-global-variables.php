<?php
/**
 * Real-site check of the Pro manage_global_variables tool on Divi's own global variables: the evidence gate for that tool.
 * Through the real ToolRegistry it proves: a dry run changes nothing; a create of one numbers and one strings variable changes
 * exactly those two entries and every other stored variable (all groups, with their order) is untouched; an edit and an archive
 * each change exactly one entry; forbidden values and unknown ids are refused and change nothing; the token is required and single
 * use; undo walks it all back and the stored variables are IDENTICAL to the start, and a created variable that changed since its
 * batch is not removed by undoing the create.
 * It first seeds one throwaway images and one links variable (the dev site stores none) to prove the unmanaged groups pass through
 * byte-identical, and removes them at the end (also on an abort), checking the site is exactly as it was before the run.
 * It also proves Divi RENDERS a cited variable: after the create it publishes ONE throwaway page that cites the number (a section's
 * padding-top and a heading's font size) and the string (the heading's title and a text module's content) in Divi's `content` form, and
 * checks the `--gvid-...` definition, the `var(--gvid-...)` use and the shown text (Divi silently ignores a variable whose stored entry
 * lacks the builder's id, lastUpdated and variableType; see docs/global-variables-citing-evidence-5.14.md); it re-renders after the
 * archive (an archived variable still renders) and deletes the page, also on an abort. The page is fetched through the Docker network
 * (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to change that.
 * Not covered live: Divi's refusal for a user without the Variables Manager permission (the dev admin always has it); the unit
 * tests cover that path against a shim that follows Divi's source.
 *
 * Run: make verify-pro-global-variables   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 * Dev sites only. WARNING: it records about 6 batches and deletes them at the end; the batch store keeps only the newest 20, so on a
 * site that already has more than 15 batches the run evicts the oldest real ones (it warns, and the final count check expects it).
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\LayoutGate;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}

global $wpdb;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$batchIds = [];
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
// Real Divi caches its static CSS per second (a timestamp flag per request): reset it so every call and render below is a fresh request.
$newRequest = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) && property_exists( 'ET_Core_PageResource', '_global_timestamp_updated' ) ) {
        $prop = new ReflectionProperty( 'ET_Core_PageResource', '_global_timestamp_updated' );
        $prop->setAccessible( true );
        $prop->setValue( null, false );
    }
};
$call     = static function ( string $name, array $args ) use ( $registry, &$batchIds, $newRequest ): array {
    $newRequest();
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        return [ 'error' => [ 'code' => 0, 'message' => 'not claimed' ], 'result' => null ];
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'result' => null ];
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return [ 'error' => null, 'result' => $result ];
};
$batchCount = static fn (): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ); // phpcs:ignore
$errHas     = static fn ( array $r, string $needle ): bool => null !== $r['error'] && false !== stripos( (string) $r['error']['message'], $needle );

/** Every stored variable of every group (label, value, status, order, every other key), optionally except some ids. @param list<string> $except */
$fingerprint = static function ( array $except = [] ): string {
    $norm = [];
    foreach ( (array) GlobalVariables::read() as $type => $group ) {
        foreach ( $group as $id => $entry ) {
            if ( ! in_array( $id, $except, true ) ) {
                $norm[ $type ][ $id ] = array_map( static fn ( $v ) => is_scalar( $v ) ? (string) $v : $v, $entry );
            }
        }
    }

    return md5( serialize( $norm ) );
};
$total = static function (): int {
    $n = 0;
    foreach ( (array) GlobalVariables::read() as $group ) {
        $n += count( $group );
    }

    return $n;
};

// ---- the render helpers (from scripts/spike-global-variables-citing.php): the page's HTML plus its same-site stylesheets --------------
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
$render = static function ( int $pageId ) use ( $fetch, $newRequest ): string {
    $newRequest();
    $html = $fetch( add_query_arg( 'page_id', $pageId, home_url( '/' ) ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 25 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
/** The citation form Divi's own source documents and the builder stores. */
$cite  = static fn ( string $id ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$';
$build = static function ( string $pad, string $size, string $head, string $text ): string {
    $section = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => $pad, 'bottom' => '20px', 'syncVertical' => 'off' ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $title   = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $head ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $size ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $body    = [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section ' . serialize_block_attributes( $section ) . ' --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $title ) . ' /--><!-- wp:divi/text ' . serialize_block_attributes( $body ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$probePages = [];
$probeTitle = 'aied verify gv probe';
$probeLeft  = static function () use ( $wpdb ): int {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify gv%'" ); // phpcs:ignore
};
$deleteProbePages = static function () use ( &$probePages ): void {
    foreach ( $probePages as $pid ) {
        if ( null !== get_post( $pid ) ) {
            wp_delete_post( $pid, true );
        }
    }
    $probePages = [];
};
if ( $probeLeft() > 0 ) {
    WP_CLI::error( "a page titled 'aied verify gv...' is already stored (an earlier run was killed?): delete it first" );
}

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 0. seed two throwaway UNMANAGED variables (images, links): the dev site stores none, so this is what proves pass-through ---------
$seedIds   = [ 'images' => 'gvid-aiedseed-0000-4000-8000-000000000001', 'links' => 'gvid-aiedseed-0000-4000-8000-000000000002' ];
$seedWant  = [
    'images' => [ 'label' => 'AIED verify image', 'value' => 'https://example.com/aied-verify.png' ],
    'links'  => [ 'label' => 'AIED verify link', 'value' => 'https://example.com/aied-verify' ],
];
$seedsIntact = static function () use ( $seedIds, $seedWant ): bool {
    $stored = (array) GlobalVariables::read();
    foreach ( $seedIds as $type => $id ) {
        $e = $stored[ $type ][ $id ] ?? null;
        if ( ! is_array( $e ) || $seedWant[ $type ]['label'] !== ( $e['label'] ?? null ) || $seedWant[ $type ]['value'] !== ( $e['value'] ?? null ) || 'active' !== ( $e['status'] ?? null ) ) {
            return false;
        }
    }

    return true;
};
/** Removes only the seeded ids that are still stored. */
$removeSeeds = static function () use ( $seedIds ): void {
    $all   = (array) GlobalVariables::read();
    $dirty = false;
    foreach ( $seedIds as $type => $id ) {
        if ( isset( $all[ $type ][ $id ] ) ) {
            unset( $all[ $type ][ $id ] );
            $dirty = true;
        }
    }
    if ( $dirty ) {
        GlobalVariables::write( GlobalVariables::sendMap( $all ) );
    }
};
$fpTrue    = $fingerprint();
$countTrue = $total();
$map       = GlobalVariables::sendMap( (array) GlobalVariables::read() );
$map['images'][ $seedIds['images'] ] = [ 'label' => $seedWant['images']['label'], 'value' => $seedWant['images']['value'], 'order' => (string) GlobalVariables::nextOrder( $map['images'] ?? [] ), 'status' => 'active' ];
$map['links'][ $seedIds['links'] ]   = [ 'label' => $seedWant['links']['label'], 'value' => $seedWant['links']['value'], 'order' => (string) GlobalVariables::nextOrder( $map['links'] ?? [] ), 'status' => 'active' ];
GlobalVariables::write( $map );
$seeded = $seedsIntact();
$check( $seeded, 'seed: an images and a links variable are stored with their exact label, value and status' );
if ( ! $seeded ) {
    $removeSeeds();
    WP_CLI::error( 'could not seed the unmanaged variables; removed any that were stored' );
}

// ---- 1. baseline --------------------------------------------------------------------------------------------------
$fpStart      = $fingerprint();
$countStart   = $total();
$batchesStart = $batchCount();
$check( GlobalVariables::available(), "baseline: Divi's variable functions are available" );
$check( GlobalVariables::permitted(), 'baseline: this user may manage variables (edit_theme_options and the Variables Manager permission)' );
WP_CLI::log( "baseline: {$countStart} stored variable(s), {$batchesStart} stored batch row(s)" );
if ( $batchesStart > 15 ) {
    WP_CLI::warning( "the batch store holds {$batchesStart} rows and keeps only 20: this run will evict the oldest real ones (dev site)" );
}
$ids     = [];
$aborted = false;
try {
    // ---- 2. a dry run changes nothing ---------------------------------------------------------------------------------
    $new = [ [ 'type' => 'numbers', 'label' => 'AIED verify radius', 'value' => '12px' ], [ 'type' => 'strings', 'label' => 'AIED verify tagline', 'value' => 'Built with care' ] ];
    $d   = $call( 'manage_global_variables', [ 'variables' => $new, 'dry_run' => true ] );
    $check( null === $d['error'] && true === $d['result']['dry_run'] && false === $d['result']['changed'] && is_string( $d['result']['preview_token'] ?? null ) && null === $d['result']['changes'][0]['id'], 'dry run: a preview, a token, no id yet, changed=false' );
    $check( $fingerprint() === $fpStart, 'the dry run changed nothing' );
    $r = $call( 'manage_global_variables', [ 'variables' => $new ] );
    $check( $errHas( $r, 'preview_token' ), 'a real run without the token is refused' );
    $check( $fingerprint() === $fpStart, 'the refused run changed nothing' );

    // ---- 3. create one of each type ------------------------------------------------------------------------------------
    $c = $call( 'manage_global_variables', [ 'variables' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( null === $c['error'] && true === $c['result']['changed'] && 2 === count( $c['result']['changes'] ) && true === $c['result']['batch']['undoable'], 'create: applied and undoable' );
    $numId   = (string) ( $c['result']['changes'][0]['id'] ?? '' );
    $strId   = (string) ( $c['result']['changes'][1]['id'] ?? '' );
    $ids     = [ $numId, $strId ];
    $createB = (int) ( $c['result']['batch']['id'] ?? 0 );
    $stored  = (array) GlobalVariables::read();
    $check( str_starts_with( $numId, 'gvid-' ) && '12px' === ( $stored['numbers'][ $numId ]['value'] ?? '' ) && 'AIED verify radius' === ( $stored['numbers'][ $numId ]['label'] ?? '' ) && 'active' === ( $stored['numbers'][ $numId ]['status'] ?? '' ), "the number is stored through Divi: {$numId}" );
    $check( str_starts_with( $strId, 'gvid-' ) && 'Built with care' === ( $stored['strings'][ $strId ]['value'] ?? '' ), "the string is stored through Divi: {$strId}" );
    $check( is_string( $stored['numbers'][ $numId ]['order'] ?? null ) && ctype_digit( $stored['numbers'][ $numId ]['order'] ), 'Divi stores the order as a string of digits' );
    $stampRe = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/';
    foreach ( [ [ 'numbers', $numId ], [ 'strings', $strId ] ] as [ $t, $vid ] ) {
        $e = (array) ( $stored[ $t ][ $vid ] ?? [] );
        $check( $vid === ( $e['id'] ?? null ) && $t === ( $e['variableType'] ?? null ) && 1 === preg_match( $stampRe, (string) ( $e['lastUpdated'] ?? '' ) ), "the {$t} entry is stored in the builder's shape (id = its key, variableType, lastUpdated stamp)" );
    }
    $check( $total() === $countStart + 2, 'exactly two entries were added' );
    $check( $fingerprint( $ids ) === $fpStart, 'create: every other stored variable, in every group, is untouched' );
    $check( $seedsIntact(), 'create: the seeded images and links variables (unmanaged groups) keep their exact label, value and status' );
    $reuse = $call( 'manage_global_variables', [ 'variables' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( $errHas( $reuse, 'preview_token' ), 'the token works once' );

    // ---- 3b. Divi RENDERS a layout that cites them: the content form in padding, font size, heading title and text content -----------
    $layoutCited = $build( $cite( $numId ), $cite( $numId ), $cite( $strId ), $cite( $strId ) );
    $check( LayoutGate::check( $layoutCited )->isValid(), 'the probe layout that cites both variables passes the gate' );
    $newRequest();
    $probeId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $probeTitle, 'post_content' => wp_slash( $layoutCited ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $probeId ) ) {
        throw new RuntimeException( 'could not create the probe page: ' . $probeId->get_error_message() );
    }
    $probePages[] = (int) $probeId;
    $css          = $render( (int) $probeId );
    $check( false !== strpos( $css, 'et_pb_section' ) && 1 === preg_match( '/<body[^>]*\bpage-id-' . (int) $probeId . '\b/', $css ), 'the probe page rendered (if this FAILS: set AIED_LOOPBACK_BASE to a URL the wpcli container can reach)' );
    $check( 1 === preg_match( '/--' . preg_quote( $numId, '/' ) . '\s*:\s*12px/', $css ), 'render: the number variable is defined (--gvid-...: 12px) for the page' );
    $check( 1 === preg_match( '/\.et_pb_section_\d+\.et_pb_section\s*\{[^}]*padding-top:\s*var\(--' . preg_quote( $numId, '/' ) . '\)/', $css ), "render: the section's padding-top uses var(--{$numId})" );
    $check( 1 === preg_match( '/font-size:\s*var\(--' . preg_quote( $numId, '/' ) . '\)/', $css ), "render: the heading's font size uses var(--{$numId})" );
    $check( 1 === preg_match( '/<h1[^>]*class="[^"]*et_pb_module_header[^"]*"[^>]*>[^<]*Built with care/', $css ), 'render: the heading shows the string variable (Built with care)' );
    $check( 1 === preg_match( '/et_pb_text_inner[^>]*>(?:\s|<p[^>]*>)*Built with care/', $css ), 'render: the text module shows the string variable too' );
    $check( 0 === substr_count( $css, '$variable(' ) + substr_count( $css, 'u0024variable(' ), 'render: no raw $variable(...) token leaks into the page' );

    // ---- 4. refusals change nothing ---------------------------------------------------------------------------------------
    $fpNow = $fingerprint();
    foreach ( [
        'a number with a semicolon' => [ [ 'type' => 'numbers', 'label' => 'bad', 'value' => 'calc(1px; x)' ], 'numbers variable' ],
        'a string with braces'      => [ [ 'type' => 'strings', 'label' => 'bad', 'value' => '}body{' ], 'strings variable' ],
        'an unknown id'             => [ [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ], 'no variable with the id' ],
        'a type change'             => [ [ 'id' => $numId, 'type' => 'strings', 'value' => 'x' ], 'never changes type' ],
    ] as $what => [ $entry, $needle ] ) {
        $r = $call( 'manage_global_variables', [ 'variables' => [ $entry ], 'dry_run' => true ] );
        $check( $errHas( $r, $needle ), "refused: {$what}" );
    }
    $check( $fingerprint() === $fpNow, 'the refusals changed nothing' );

    // ---- 5. edit the number --------------------------------------------------------------------------------------------
    $edit = [ [ 'id' => $numId, 'value' => 'clamp(1rem, 2vw + 1rem, 3rem)' ] ];
    $de   = $call( 'manage_global_variables', [ 'variables' => $edit, 'dry_run' => true ] );
    $ed   = $call( 'manage_global_variables', [ 'variables' => $edit, 'preview_token' => (string) ( $de['result']['preview_token'] ?? '' ) ] );
    $editB = (int) ( $ed['result']['batch']['id'] ?? 0 );
    $check( null === $ed['error'] && 'update' === ( $ed['result']['changes'][0]['action'] ?? '' ) && 'clamp(1rem, 2vw + 1rem, 3rem)' === ( GlobalVariables::read()['numbers'][ $numId ]['value'] ?? '' ), 'edit: the value changed and is stored as sent' );
    $check( $fingerprint( $ids ) === $fpStart, 'edit: every other stored variable is untouched' );

    // ---- 6. archive the string -----------------------------------------------------------------------------------------
    $arch = [ [ 'id' => $strId, 'status' => 'archived' ] ];
    $da   = $call( 'manage_global_variables', [ 'variables' => $arch, 'dry_run' => true ] );
    $a    = $call( 'manage_global_variables', [ 'variables' => $arch, 'preview_token' => (string) ( $da['result']['preview_token'] ?? '' ) ] );
    $archB = (int) ( $a['result']['batch']['id'] ?? 0 );
    $check( null === $a['error'] && 'archive' === ( $a['result']['changes'][0]['action'] ?? '' ) && 'archived' === ( GlobalVariables::read()['strings'][ $strId ]['status'] ?? '' ), 'archive: the status is archived (nothing deleted)' );
    $check( $fingerprint( $ids ) === $fpStart, 'archive: every other stored variable is untouched' );
    $css = $render( (int) $probeId );
    $check( 1 === preg_match( '/<h1[^>]*class="[^"]*et_pb_module_header[^"]*"[^>]*>[^<]*Built with care/', $css ), 'render after the archive: the archived string is still shown' );
    $check( 1 === preg_match( '/--' . preg_quote( $numId, '/' ) . '\s*:/', $css ) && 1 === preg_match( '/padding-top:\s*var\(--' . preg_quote( $numId, '/' ) . '\)/', $css ), 'render after the archive: the number is still defined and used (it now holds the edited value)' );
    $e = (array) ( GlobalVariables::read()['strings'][ $strId ] ?? [] );
    $check( $strId === ( $e['id'] ?? null ) && 'strings' === ( $e['variableType'] ?? null ) && 1 === preg_match( $stampRe, (string) ( $e['lastUpdated'] ?? '' ) ), 'archive: the archived entry kept id and variableType and has a refreshed lastUpdated stamp' );
    $e = (array) ( GlobalVariables::read()['numbers'][ $numId ] ?? [] );
    $check( $numId === ( $e['id'] ?? null ) && 'numbers' === ( $e['variableType'] ?? null ), 'edit: the edited number kept id and variableType' );

    // ---- 7. undo walks it back; undo of a create refuses once the entry changed since ----------------------------------
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( null === $u['error'] && false === ( $u['result']['fully_undone'] ?? true ) && isset( GlobalVariables::read()['numbers'][ $numId ] ), 'undoing the create while later batches changed the variable does not remove it' );
    foreach ( [ $archB, $editB ] as $b ) {
        $u = $call( 'undo_batch', [ 'batch_id' => $b ] );
        $check( true === ( $u['result']['fully_undone'] ?? false ), "undo of batch {$b}" );
    }
    $check( '12px' === ( GlobalVariables::read()['numbers'][ $numId ]['value'] ?? '' ) && 'active' === ( GlobalVariables::read()['strings'][ $strId ]['status'] ?? '' ), 'the variables are exactly as the create left them' );
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( true === ( $u['result']['fully_undone'] ?? false ) && ! isset( GlobalVariables::read()['numbers'][ $numId ] ) && ! isset( GlobalVariables::read()['strings'][ $strId ] ), 'now the undo of the create removes both variables' );
} catch ( \Throwable $e ) {
    $aborted    = true;
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    $deleteProbePages(); // before the variables go: an abort must not leave the probe page behind
    $all   = (array) GlobalVariables::read();
    $dirty = false;
    foreach ( array_filter( $ids ) as $id ) { // cleanup after an abort: remove any throwaway variable still stored
        foreach ( array_keys( $all ) as $type ) {
            if ( isset( $all[ $type ][ $id ] ) ) {
                unset( $all[ $type ][ $id ] );
                $dirty = true;
            }
        }
    }
    if ( $dirty ) {
        GlobalVariables::write( GlobalVariables::sendMap( $all ) );
    }
    if ( $aborted ) {
        $removeSeeds(); // an abort must not leave the seeded variables behind
    }
}

// ---- 8. the site is exactly as it was ---------------------------------------------------------------------------------------
$check( $fingerprint() === $fpStart, 'every stored variable (all groups, with their order) is identical to the start' );
$check( $total() === $countStart, 'the variable count equals the baseline' );
$deleteProbePages();
$check( 0 === $probeLeft(), "the probe page is deleted (none titled 'aied verify gv...' is left)" );
$check( $seedsIntact(), 'the seeded images and links variables are still exactly as seeded after the whole chain' );
$removeSeeds();
$check( $fingerprint() === $fpTrue, 'seeds removed: every stored variable is identical to the true state before the run' );
$check( $total() === $countTrue, 'seeds removed: the variable count equals the true count before the run' );
$batchIds = array_values( array_unique( $batchIds ) );
if ( [] !== $batchIds ) {
    $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
}
// The store keeps the newest 20: with N old rows and k new ones, the old rows left are min(N, 20 - k) (N when nothing was evicted).
$expectedBatches = min( $batchesStart, max( 0, 20 - count( $batchIds ) ) );
$batchesEnd      = $batchCount();
$check( $batchesEnd === $expectedBatches, "after deleting this run's own rows the batch table holds {$batchesEnd} row(s), expected {$expectedBatches} (baseline {$batchesStart}, this run recorded " . count( $batchIds ) . ', so no stray row)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro manage_global_variables verified on the real Divi global variables.' );
