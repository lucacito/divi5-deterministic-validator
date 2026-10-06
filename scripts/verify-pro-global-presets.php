<?php
/**
 * Real-site check of the Pro manage_global_presets tool on Divi's own module presets: the evidence gate for that tool.
 * Through the real ToolRegistry it proves: a dry run changes nothing; a create adds exactly one preset (its name and styleAttrs as sent,
 * the module's default unchanged, every other preset byte-identical); a throwaway published page that cites it RENDERS the preset
 * (the class preset--module--divi-heading--<id>, a font-size and a margin-bottom rule); an update with a token changes name and
 * attrs and `updated` only, and the page renders the new size and no longer the old one; a module with NO presets (divi/image) gets
 * the default preset Divi adds, reported by the dry run and removed again by undo (also for two creates of the same module in one
 * call); custom CSS, an unknown id, a legacy preset, a module change, an unknown module and an update of a module's default preset
 * without allow_default_presets are refused and change nothing; the token works once and only for the same arguments; undo walks
 * it all back and the stored presets (the WHOLE data, legacy entries included) are IDENTICAL to the start.
 * IT TEMPORARILY RESTYLES THE SITE'S REAL divi/heading DEFAULT PRESET (section 8: it is updated with allow_default_presets, a probe page
 * proves Divi renders it, then undo_batch puts it back; only that preset's `updated` stamp may differ, and the script restores the
 * snapshot taken at the start if anything else differs). Every heading on the dev site without a preset of its own is restyled in between.
 * The page is fetched through the Docker network (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to change that.
 * Not covered live: Divi's refusal for a user without edit_theme_options (the dev admin always has it); the unit tests cover it.
 *
 * Run: make verify-pro-global-presets   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 * Dev sites only. WARNING: it records 10 batches and deletes them at the end; the batch store keeps only the newest 20, so on a
 * site that already has more than 10 batches the run evicts the oldest real ones (it warns, and the final count check expects it).
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\LayoutGate;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! class_exists( GlobalPresets::PRESET_CLASS ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalPreset class is not loaded: activate the Divi theme on this site first." );
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

/** The whole stored preset data, as Divi's own reader returns it (legacy entries and the group list included). */
$fingerprint = static fn (): string => md5( serialize( GlobalPresets::read() ) );
$total       = static function (): int {
    $n = 0;
    foreach ( (array) ( GlobalPresets::read()['module'] ?? [] ) as $entry ) {
        $n += is_array( $entry ) ? count( (array) ( $entry['items'] ?? [] ) ) : 0;
    }

    return $n;
};
/** The whole data without one preset id (its module entry is kept, even when that leaves it empty). */
$without = static function ( string $id ): string {
    $data = (array) GlobalPresets::read();
    foreach ( array_keys( (array) ( $data['module'] ?? [] ) ) as $m ) {
        unset( $data['module'][ $m ]['items'][ $id ] );
    }

    return md5( serialize( $data ) );
};
$item = static fn ( string $id ): ?array => GlobalPresets::find( (array) GlobalPresets::read(), $id )['item'] ?? null;

// ---- the render helpers (from scripts/spike-global-presets.php): the page's HTML plus its same-site stylesheets --------------------
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
    // Divi marks a cached stylesheet stale only when its mtime is STRICTLY older than the clear time, in whole seconds: a write in the
    // same second as this render would leave this page's stylesheet looking fresh. Waiting two seconds makes the next write count.
    sleep( 2 );

    return $all;
};
/** Does a CSS rule whose selector names this heading preset's class contain the declaration? (Divi minifies: no space after the colon.) */
$ruleHas = static fn ( string $css, string $id, string $decl ): bool => 1 === preg_match( '/preset--module--divi-heading--' . preg_quote( $id, '/' ) . '[^{}<]*\{[^}]*' . preg_quote( $decl, '/' ) . '/', $css ); // no "<" in a selector: the class in the page's HTML must not match
/** Did this fetch really return the probe page (its body class and its marker text)? A negative render check means nothing on an empty fetch. */
$pageRendered = static fn ( string $html, int $pageId, string $marker ): bool => false !== strpos( $html, $marker ) && 1 === preg_match( '/<body[^>]*\bpage-id-' . $pageId . '\b/', $html );
$layout  = static function ( string $presetId ): string {
    $attrs = [ 'modulePreset' => [ $presetId ], 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'AIEDPRESETGATE' ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$probePages = [];
$probeTitle = 'aied verify gp probe';
$probeLeft  = static function () use ( $wpdb ): int {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify gp%'" ); // phpcs:ignore
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
    WP_CLI::error( "a page titled 'aied verify gp...' is already stored (an earlier run was killed?): delete it first" );
}

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 1. baseline --------------------------------------------------------------------------------------------------
$dataStart    = GlobalPresets::read();
$fpStart      = $fingerprint();
$countStart   = $total();
$batchesStart = $batchCount();
$check( GlobalPresets::available(), "baseline: Divi's preset functions are available" );
$check( GlobalPresets::permitted(), 'baseline: this user may manage presets (edit_theme_options)' );
$check( is_array( $dataStart ) && [] === GlobalPresets::unsafe( $dataStart ), 'baseline: the stored presets are in a shape the tool can save back faithfully' );
if ( ! is_array( $dataStart ) ) {
    WP_CLI::error( "Divi's presets could not be read: nothing was done." );
}
WP_CLI::log( "baseline: {$countStart} stored preset(s) in " . count( (array) ( $dataStart['module'] ?? [] ) ) . " module entries, {$batchesStart} stored batch row(s)" );
if ( $batchesStart > 10 ) {
    WP_CLI::warning( "the batch store holds {$batchesStart} rows and keeps only 20: this run will evict the oldest real ones (dev site)" );
}
// Divi's own default presets can be stored as an empty shell (no attrs, no styleAttrs keys): the tool manages those too (section 8).
$headingDefault = GlobalPresets::defaultId( $dataStart, 'divi/heading' );
$check( '' !== $headingDefault && null !== GlobalPresets::find( $dataStart, $headingDefault ), 'baseline: divi/heading has a default preset' . ( is_array( GlobalPresets::find( $dataStart, $headingDefault )['item'] ?? null ) && ! array_key_exists( 'attrs', GlobalPresets::find( $dataStart, $headingDefault )['item'] ) ? ' (an empty shell here: no attrs key)' : '' ) );
$defaultId = '';
$defaultModule = '';
foreach ( (array) ( $dataStart['module'] ?? [] ) as $module => $entry ) {
    $dId = is_array( $entry ) ? GlobalPresets::defaultId( $dataStart, (string) $module ) : '';
    $dIt = '' !== $dId ? ( $entry['items'][ $dId ] ?? null ) : null;
    if ( '' === $defaultId && is_array( $dIt ) && GlobalPresets::validId( $dId ) && GlobalPresets::managed( (string) $module, $dIt ) ) {
        $defaultId     = $dId;
        $defaultModule = (string) $module;
    }
}
$check( '' !== $defaultId, "baseline: a module with a manageable default preset exists ({$defaultModule}); the default-preset refusal checks aim at it and never write to it" );
$imageFree = ! isset( $dataStart['module']['divi/image'] );
if ( ! $imageFree ) {
    WP_CLI::warning( 'this site already has divi/image presets: the default-preset side-effect check was skipped' );
}
$legacyId = '';
foreach ( (array) ( $dataStart['module'] ?? [] ) as $module => $entry ) {
    foreach ( (array) ( is_array( $entry ) ? ( $entry['items'] ?? [] ) : [] ) as $id => $it ) {
        if ( '' === $legacyId && GlobalPresets::validId( (string) $id ) && ( ! is_array( $it ) || ! GlobalPresets::managed( (string) $module, $it ) ) ) {
            $legacyId = (string) $id;
        }
    }
}
if ( '' === $legacyId ) {
    WP_CLI::warning( 'this site holds no legacy-looking preset: the legacy refusal check was skipped' );
}

$ids     = [];
$aborted = false;
$bigName = 'AIED verify big';
$bigAttr = [
    'title'  => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ],
    'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '', 'right' => '', 'bottom' => '39px', 'left' => '', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ] ] ] ],
];
$canon = static fn ( $v ): string => (string) wp_json_encode( $v );
try {
    // ---- 2. (a) a dry run changes nothing ---------------------------------------------------------------------------------
    $new = [ [ 'module' => 'divi/heading', 'name' => $bigName, 'attrs' => $bigAttr ] ];
    $d   = $call( 'manage_global_presets', [ 'presets' => $new, 'dry_run' => true ] );
    $check( null === $d['error'] && true === $d['result']['dry_run'] && false === $d['result']['changed'] && is_string( $d['result']['preview_token'] ?? null ) && null === $d['result']['changes'][0]['id'] && 'create' === $d['result']['changes'][0]['action'], 'dry run: a preview, a token, no id yet, changed=false' );
    $check( $fingerprint() === $fpStart, 'the dry run changed nothing' );
    $r = $call( 'manage_global_presets', [ 'presets' => $new ] );
    $check( $errHas( $r, 'preview_token' ), 'a real run without the token is refused' );
    $check( $fingerprint() === $fpStart, 'the refused run changed nothing' );

    // ---- 3. (b) the real create ------------------------------------------------------------------------------------------
    $c = $call( 'manage_global_presets', [ 'presets' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( null === $c['error'] && true === $c['result']['changed'] && 1 === count( $c['result']['changes'] ) && true === $c['result']['batch']['undoable'] && ! isset( $c['result']['unexpected_changes'] ), 'create: applied, undoable, nothing unexpected' );
    $newId   = (string) ( $c['result']['changes'][0]['id'] ?? '' );
    $ids[]   = $newId;
    $createB = (int) ( $c['result']['batch']['id'] ?? 0 );
    $stored  = $item( $newId );
    $check( '' !== $newId && is_array( $stored ), "the preset is stored through Divi: {$newId}" );
    $stored = (array) $stored;
    $check( $bigName === ( $stored['name'] ?? null ) && 'divi/heading' === ( $stored['moduleName'] ?? null ) && 'module' === ( $stored['type'] ?? null ), 'create: name, module and type are stored' );
    $check( $canon( $bigAttr ) === $canon( $stored['styleAttrs'] ?? null ) && $canon( $bigAttr ) === $canon( $stored['attrs'] ?? null ), 'create: attrs and styleAttrs equal the attrs sent' );
    $check( is_string( $stored['version'] ?? null ) && '' !== $stored['version'], 'create: a non-empty version is stored (' . (string) ( $stored['version'] ?? '' ) . ')' );
    $check( GlobalPresets::defaultId( (array) GlobalPresets::read(), 'divi/heading' ) === $headingDefault, "create: the module's default preset is unchanged" );
    $check( $total() === $countStart + 1, 'exactly one preset was added' );
    $check( $without( $newId ) === $fpStart, 'create: every other preset (and the whole data apart from the new item) is byte-identical to the start' );
    $reuse = $call( 'manage_global_presets', [ 'presets' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( $errHas( $reuse, 'preview_token' ), 'the token works once (g): the same token again is refused' );
    $check( $total() === $countStart + 1, 'the reused token created nothing' );

    // ---- 3b. (c) Divi RENDERS a page that cites the preset --------------------------------------------------------------
    $layoutCited = $layout( $newId );
    $check( LayoutGate::check( $layoutCited )->isValid(), 'the probe layout that cites the preset passes the gate' );
    $newRequest();
    $probeId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $probeTitle, 'post_content' => wp_slash( $layoutCited ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $probeId ) ) {
        throw new RuntimeException( 'could not create the probe page: ' . $probeId->get_error_message() );
    }
    $probePages[] = (int) $probeId;
    $css          = $render( (int) $probeId );
    $check( false !== strpos( $css, 'et_pb_section' ) && 1 === preg_match( '/<body[^>]*\bpage-id-' . (int) $probeId . '\b/', $css ), 'the probe page rendered (if this FAILS: set AIED_LOOPBACK_BASE to a URL the wpcli container can reach)' );
    $check( 1 === preg_match( '/class="[^"]*preset--module--divi-heading--' . preg_quote( $newId, '/' ) . '[^"]*"[^>]*><div class="et_pb_heading_container"><h1[^>]*>AIEDPRESETGATE/', $css ), 'render: the heading element carries the class preset--module--divi-heading--<id>' );
    $check( $ruleHas( $css, $newId, 'font-size:57px' ), "render: a rule for the preset's class sets font-size:57px" );
    $check( $ruleHas( $css, $newId, 'margin-bottom:39px' ), "render: a rule for the preset's class sets margin-bottom:39px" );
    $check( ! $ruleHas( $css, $newId, 'font-size:61px' ), 'render: the preset does not yet set font-size:61px' );

    // ---- 4. (f) refusals change nothing ----------------------------------------------------------------------------------
    $cssAttrs = [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.aiedcss{outline:9px solid red}' ] ] ] ] ] ];
    $refusals = [
        'custom CSS on a create'                             => [ [ [ 'module' => 'divi/heading', 'name' => 'AIED verify css', 'attrs' => $cssAttrs ] ], 'presets[0]' ],
        'custom CSS on an update'                            => [ [ [ 'id' => $newId, 'attrs' => $cssAttrs ] ], 'presets[0]' ],
        'an unknown id'                                      => [ [ [ 'id' => 'aiedmissing-0000-0000', 'name' => 'x' ] ], 'no preset with the id' ],
        'the default preset without allow_default_presets'   => [ [ [ 'id' => $defaultId, 'name' => 'AIED verify default' ] ], 'allow_default_presets' ],
        'a module change on an update'                       => [ [ [ 'id' => $newId, 'module' => 'divi/text', 'name' => 'x' ] ], 'never moves' ],
        'an unknown module'                                  => [ [ [ 'module' => 'divi/not-a-module', 'name' => 'x', 'attrs' => $bigAttr ] ], 'must be a Divi 5 module' ],
        'an update with nothing to change'                   => [ [ [ 'id' => $newId ] ], 'at least one of name, attrs' ],
        'a good create beside a bad entry (atomic)'          => [ [ [ 'module' => 'divi/heading', 'name' => 'AIED verify atomic', 'attrs' => $bigAttr ], [ 'id' => 'aiedmissing-0000-0000', 'name' => 'x' ] ], 'no preset with the id' ],
    ];
    if ( '' !== $legacyId ) {
        $refusals['a legacy preset as the target'] = [ [ [ 'id' => $legacyId, 'name' => 'AIED verify legacy' ] ], 'cannot be changed here' ];
    }
    $fpNow = $fingerprint();
    foreach ( $refusals as $what => [ $entries, $needle ] ) {
        $r = $call( 'manage_global_presets', [ 'presets' => $entries, 'dry_run' => true ] );
        $check( $errHas( $r, $needle ), "refused (dry run): {$what}" );
        $r = $call( 'manage_global_presets', [ 'presets' => $entries ] );
        $check( $errHas( $r, $needle ), "refused (real run): {$what}" );
        $check( $fingerprint() === $fpNow, "the refusals of {$what} changed nothing" );
    }
    // The default preset CAN be targeted on purpose: a dry run with allow_default_presets previews it (and still writes nothing).
    $dd = $call( 'manage_global_presets', [ 'presets' => [ [ 'id' => $defaultId, 'name' => 'AIED verify default' ] ], 'dry_run' => true, 'allow_default_presets' => true ] );
    $check( null === $dd['error'] && true === ( $dd['result']['changes'][0]['is_default'] ?? false ) && is_string( $dd['result']['preview_token'] ?? null ), 'allow_default_presets=true: the default preset is previewed and flagged is_default' );
    $check( $fingerprint() === $fpNow, 'that default-preset preview changed nothing' );

    // ---- 5. (d) update the preset -----------------------------------------------------------------------------------------
    $createdBefore = $stored['created'] ?? null;
    $v2Attr = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '61px' ] ] ] ] ] ] ];
    $upd    = [ [ 'id' => $newId, 'name' => 'AIED verify big v2', 'attrs' => $v2Attr ] ];
    $du     = $call( 'manage_global_presets', [ 'presets' => $upd, 'dry_run' => true ] );
    $check( null === $du['error'] && 'update' === ( $du['result']['changes'][0]['action'] ?? '' ) && $newId === ( $du['result']['changes'][0]['id'] ?? null ) && is_string( $du['result']['preview_token'] ?? null ), 'update: the dry run previews it and gives a token' );
    $check( $fingerprint() === $fpNow, 'the update dry run changed nothing' );
    $wrong = $call( 'manage_global_presets', [ 'presets' => [ [ 'id' => $newId, 'name' => 'AIED verify big other', 'attrs' => $v2Attr ] ], 'preview_token' => (string) ( $du['result']['preview_token'] ?? '' ) ] );
    $check( $errHas( $wrong, 'preview_token' ), 'the token only fits the arguments it was made for' );
    $check( $fingerprint() === $fpNow, 'the mismatched token changed nothing' );
    $u = $call( 'manage_global_presets', [ 'presets' => $upd, 'preview_token' => (string) ( $du['result']['preview_token'] ?? '' ) ] );
    $updateB = (int) ( $u['result']['batch']['id'] ?? 0 );
    $check( null === $u['error'] && true === $u['result']['changed'] && 'update' === ( $u['result']['changes'][0]['action'] ?? '' ) && true === ( $u['result']['batch']['undoable'] ?? false ) && ! isset( $u['result']['unexpected_changes'] ), 'update: applied, undoable, nothing unexpected' );
    $after = (array) $item( $newId );
    $check( 'AIED verify big v2' === ( $after['name'] ?? null ) && $canon( $v2Attr ) === $canon( $after['styleAttrs'] ?? null ) && $canon( $v2Attr ) === $canon( $after['attrs'] ?? null ), 'update: the stored name, attrs and styleAttrs changed (attrs REPLACED the tree)' );
    $check( is_int( $after['updated'] ?? null ) && abs( time() - $after['updated'] ) < 600, 'update: `updated` is a recent integer' );
    $check( null !== $createdBefore && $createdBefore === ( $after['created'] ?? null ) && $stored['version'] === ( $after['version'] ?? null ) && 'module' === ( $after['type'] ?? null ) && $newId === ( $after['id'] ?? null ), 'update: created, version, type and id are unchanged' );
    $check( $without( $newId ) === $fpStart, 'update: every other preset is still byte-identical to the start' );
    $css = $render( (int) $probeId );
    $check( $ruleHas( $css, $newId, 'font-size:61px' ), 'render after the update: the preset class sets font-size:61px' );
    $check( ! $ruleHas( $css, $newId, 'font-size:57px' ), 'render after the update: the preset class no longer sets font-size:57px' );
    $check( ! $ruleHas( $css, $newId, 'margin-bottom:39px' ), 'render after the update: the replaced margin rule is gone' );

    // ---- 6. (h) undo walks it back, newest first --------------------------------------------------------------------------
    $un = $call( 'undo_batch', [ 'batch_id' => $updateB ] );
    $check( true === ( $un['result']['fully_undone'] ?? false ), "undo of the update batch {$updateB}" );
    $back = (array) $item( $newId );
    $check( $bigName === ( $back['name'] ?? null ) && $canon( $bigAttr ) === $canon( $back['styleAttrs'] ?? null ) && $canon( $bigAttr ) === $canon( $back['attrs'] ?? null ), 'undo of the update: the preset is exactly as the create left it' );
    $css = $render( (int) $probeId );
    $check( $ruleHas( $css, $newId, 'font-size:57px' ) && ! $ruleHas( $css, $newId, 'font-size:61px' ), 'render after undoing the update: 57px again, no 61px' );
    // The update would apply again now (undo put the old state back), so only a spent token can refuse this.
    $u2 = $call( 'manage_global_presets', [ 'presets' => $upd, 'preview_token' => (string) ( $du['result']['preview_token'] ?? '' ) ] );
    $check( $errHas( $u2, 'preview_token' ) && 'AIED verify big' === ( $item( $newId )['name'] ?? null ), 'the update token works once too (a second use is refused and changes nothing)' );
    $un = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( true === ( $un['result']['fully_undone'] ?? false ), "undo of the create batch {$createB}" );
    $check( null === $item( $newId ), 'undo of the create: the preset is gone' );
    $check( $fingerprint() === $fpStart, 'after the undo chain every stored preset (the whole data) is identical to the start' );
    $css = $render( (int) $probeId );
    $check( $pageRendered( $css, (int) $probeId, 'AIEDPRESETGATE' ), 'render after undoing the create: the probe page rendered (so the next check is not an empty fetch)' );
    $check( ! $ruleHas( $css, $newId, 'font-size:57px' ), 'render after undoing the create: no rule for the removed preset sets 57px' );

    // ---- 7. (e) a module with NO presets: Divi adds a default preset, the dry run says so, undo removes both ------------------
    if ( $imageFree ) {
        $imgAttr = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '', 'right' => '', 'bottom' => '12px', 'left' => '', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ] ] ] ] ];
        $img     = [ [ 'module' => 'divi/image', 'name' => 'AIED verify image', 'attrs' => $imgAttr ] ];
        $di      = $call( 'manage_global_presets', [ 'presets' => $img, 'dry_run' => true ] );
        $check( null === $di['error'] && true === ( $di['result']['changes'][0]['default_preset_created'] ?? false ) && str_contains( (string) ( $di['result']['message'] ?? '' ), 'default preset' ), 'divi/image dry run: says Divi will also add a default preset' );
        $check( $fingerprint() === $fpStart, 'the divi/image dry run changed nothing' );
        $ci = $call( 'manage_global_presets', [ 'presets' => $img, 'preview_token' => (string) ( $di['result']['preview_token'] ?? '' ) ] );
        $imgB = (int) ( $ci['result']['batch']['id'] ?? 0 );
        foreach ( (array) ( $ci['result']['changes'] ?? [] ) as $ch ) {
            $ids[] = (string) ( $ch['id'] ?? '' );
        }
        $items = (array) ( GlobalPresets::read()['module']['divi/image']['items'] ?? [] );
        $check( null === $ci['error'] && true === ( $ci['result']['changes'][0]['default_preset_created'] ?? false ) && 2 === count( $items ), 'divi/image create: the real run stores 2 items for the module (mine plus the default Divi added)' );
        $check( 1 === count( array_filter( $items, static fn ( $i ): bool => is_array( $i ) && 'AIED verify image' === ( $i['name'] ?? null ) ) ), 'divi/image create: exactly one of them is the preset that was asked for' );
        $check( true === ( $ci['result']['batch']['undoable'] ?? false ) && ! isset( $ci['result']['unexpected_changes'] ), 'divi/image create: undoable, nothing unexpected' );
        $un = $call( 'undo_batch', [ 'batch_id' => $imgB ] );
        $check( true === ( $un['result']['fully_undone'] ?? false ) && ! isset( GlobalPresets::read()['module']['divi/image'] ), 'undo of the divi/image create removes both items and the module entry' );
        $check( $fingerprint() === $fpStart, 'after that undo every stored preset is identical to the start' );

        // Two creates for the same new module in one call (the regression guard for the second create's module-existed read).
        $two = [ [ 'module' => 'divi/image', 'name' => 'AIED verify image A', 'attrs' => $imgAttr ], [ 'module' => 'divi/image', 'name' => 'AIED verify image B', 'attrs' => $imgAttr ] ];
        $dt  = $call( 'manage_global_presets', [ 'presets' => $two, 'dry_run' => true ] );
        $check( null === $dt['error'] && true === ( $dt['result']['changes'][0]['default_preset_created'] ?? false ) && false === ( $dt['result']['changes'][1]['default_preset_created'] ?? true ), 'two divi/image creates: the dry run flags the default preset on the first only' );
        $ct   = $call( 'manage_global_presets', [ 'presets' => $two, 'preview_token' => (string) ( $dt['result']['preview_token'] ?? '' ) ] );
        $twoB = (int) ( $ct['result']['batch']['id'] ?? 0 );
        foreach ( (array) ( $ct['result']['changes'] ?? [] ) as $ch ) {
            $ids[] = (string) ( $ch['id'] ?? '' );
        }
        $items = (array) ( GlobalPresets::read()['module']['divi/image']['items'] ?? [] );
        $names = array_map( static fn ( $i ): string => is_array( $i ) ? (string) ( $i['name'] ?? '' ) : '', $items );
        $check( null === $ct['error'] && 2 === count( (array) ( $ct['result']['changes'] ?? [] ) ) && 3 === count( $items ) && in_array( 'AIED verify image A', $names, true ) && in_array( 'AIED verify image B', $names, true ), 'two divi/image creates: stored as 3 items (A, B and the default)' );
        $un = $call( 'undo_batch', [ 'batch_id' => $twoB ] );
        $check( true === ( $un['result']['fully_undone'] ?? false ) && ! isset( GlobalPresets::read()['module']['divi/image'] ), 'undo of the two creates is fully_undone and the module entry is gone' );
        $check( $fingerprint() === $fpStart, 'after the two-create undo every stored preset is identical to the start' );
    }
    // ---- 8. the real DEFAULT preset of divi/heading (a shell without attrs on a stock site): update it, render it, undo it ----------------
    $defItem0   = (array) GlobalPresets::find( (array) GlobalPresets::read(), $headingDefault )['item'];
    $shell0     = ! array_key_exists( 'attrs', $defItem0 ) && ! array_key_exists( 'styleAttrs', $defItem0 );
    $fpNoDef    = $without( $headingDefault );
    $dataNoStamp = static function () use ( $headingDefault, $defItem0 ): string {
        $data = (array) GlobalPresets::read();
        if ( isset( $data['module']['divi/heading']['items'][ $headingDefault ] ) ) {
            $data['module']['divi/heading']['items'][ $headingDefault ]['updated'] = $defItem0['updated'] ?? null; // undo refreshes this one stamp
        }

        return md5( serialize( $data ) );
    };
    WP_CLI::log( 'shell check: the divi/heading default preset ' . $headingDefault . ( $shell0 ? ' is an empty shell (no attrs, no styleAttrs keys)' : ' has attrs on this site' ) );
    $defAttr = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '53px', 'weight' => '700' ] ] ] ] ] ] ];
    $defUpd  = [ [ 'id' => $headingDefault, 'attrs' => $defAttr ] ];
    $r = $call( 'manage_global_presets', [ 'presets' => $defUpd, 'dry_run' => true ] );
    $check( $errHas( $r, 'allow_default_presets' ), 'default heading: a dry run WITHOUT allow_default_presets is refused (it is the default preset)' );
    $r = $call( 'manage_global_presets', [ 'presets' => $defUpd ] );
    $check( $errHas( $r, 'allow_default_presets' ) && $fingerprint() === $fpStart, 'default heading: a real run without allow_default_presets is refused and changes nothing' );
    $dd = $call( 'manage_global_presets', [ 'presets' => $defUpd, 'dry_run' => true, 'allow_default_presets' => true ] );
    $check( null === $dd['error'] && true === ( $dd['result']['changes'][0]['is_default'] ?? false ) && $headingDefault === ( $dd['result']['changes'][0]['id'] ?? null ) && is_string( $dd['result']['preview_token'] ?? null ), 'default heading: with allow_default_presets the dry run works, is_default is true and a token is given' );
    $check( $fingerprint() === $fpStart, 'default heading: that dry run changed nothing' );
    $noAllow = $call( 'manage_global_presets', [ 'presets' => $defUpd, 'preview_token' => (string) ( $dd['result']['preview_token'] ?? '' ) ] );
    $check( $errHas( $noAllow, 'allow_default_presets' ) && $fingerprint() === $fpStart, 'default heading: that token cannot be used without allow_default_presets' );
    // Divi marks a module's DEFAULT preset in the page with the fixed word "default" (preset--module--divi-heading--default), not with the
    // preset's id (observed live on Divi 5.14), so that class is what the rules are searched by.
    $defCls    = 'default';
    $defLayout = str_replace( 'AIEDPRESETGATE', 'AIEDDEFAULTGATE', (string) preg_replace( '/"modulePreset":\["[^"]*"\],?/', '', $layout( 'x-placeholder' ) ) );
    $check( false === strpos( $defLayout, 'modulePreset' ) && false !== strpos( $defLayout, 'AIEDDEFAULTGATE' ) && LayoutGate::check( $defLayout )->isValid(), 'default heading: the probe layout has a heading WITHOUT modulePreset and passes the gate' );
    $newRequest();
    $defPage = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify gp probe default', 'post_content' => wp_slash( $defLayout ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $defPage ) ) {
        throw new RuntimeException( 'could not create the default-preset probe page: ' . $defPage->get_error_message() );
    }
    $probePages[] = (int) $defPage;
    $css = $render( (int) $defPage );
    $check( false !== strpos( $css, 'AIEDDEFAULTGATE' ) && 1 === preg_match( '/<body[^>]*\bpage-id-' . (int) $defPage . '\b/', $css ), 'default heading: the probe page rendered (before the update)' );
    $check( false === strpos( $css, 'preset--module--divi-heading--' . $defCls ) && ! $ruleHas( $css, $defCls, 'font-size:53px' ), 'default heading: BEFORE the update the page has no default-preset class and no 53px rule (the control for the checks below)' );
    $dr = $call( 'manage_global_presets', [ 'presets' => $defUpd, 'allow_default_presets' => true, 'preview_token' => (string) ( $dd['result']['preview_token'] ?? '' ) ] );
    $defB = (int) ( $dr['result']['batch']['id'] ?? 0 );
    $check( null === $dr['error'] && true === ( $dr['result']['changed'] ?? false ) && true === ( $dr['result']['batch']['undoable'] ?? false ) && ! isset( $dr['result']['unexpected_changes'] ), 'default heading: the real run changed it, undoable, nothing unexpected' );
    $defItem1 = (array) GlobalPresets::find( (array) GlobalPresets::read(), $headingDefault )['item'];
    $check( $canon( $defAttr ) === $canon( $defItem1['attrs'] ?? null ) && $canon( $defAttr ) === $canon( $defItem1['styleAttrs'] ?? null ), 'default heading: the stored default item now has attrs and styleAttrs equal to what was sent' );
    $check( ( $defItem0['name'] ?? null ) === ( $defItem1['name'] ?? 0 ) && ( $defItem0['id'] ?? null ) === ( $defItem1['id'] ?? 0 ) && ( $defItem0['type'] ?? null ) === ( $defItem1['type'] ?? 0 ) && ( $defItem0['created'] ?? null ) === ( $defItem1['created'] ?? 0 ) && $headingDefault === GlobalPresets::defaultId( (array) GlobalPresets::read(), 'divi/heading' ), 'default heading: name, id, type and created are unchanged and it is still the module default' );
    $check( $without( $headingDefault ) === $fpNoDef, 'default heading: every OTHER preset (and the rest of the data) is byte-identical to before' );
    $css = $render( (int) $defPage );
    $check( 1 === preg_match( '/class="[^"]*preset--module--divi-heading--' . $defCls . '[^"]*"[^>]*><div class="et_pb_heading_container"><h1[^>]*>AIEDDEFAULTGATE/', $css ), 'default heading: Divi now puts the default preset class on the heading that has no modulePreset of its own' );
    $check( $ruleHas( $css, $defCls, 'font-size:53px' ), "default heading: a rule for the default preset's class sets font-size:53px" );
    $check( $ruleHas( $css, $defCls, 'font-weight:700' ), "default heading: a rule for the default preset's class sets font-weight:700" );
    $un = $call( 'undo_batch', [ 'batch_id' => $defB ] );
    $defItem2 = (array) GlobalPresets::find( (array) GlobalPresets::read(), $headingDefault )['item'];
    $check( true === ( $un['result']['fully_undone'] ?? false ), "default heading: undo of batch {$defB} is fully_undone" );
    $check( $shell0 === ( ! array_key_exists( 'attrs', $defItem2 ) && ! array_key_exists( 'styleAttrs', $defItem2 ) ), 'default heading: after undo the item is back to its original kind (a shell has NO attrs and NO styleAttrs keys)' );
    $check( $dataNoStamp() === md5( serialize( $dataStart ) ), "default heading: after undo the whole data equals the start, ignoring only that preset's `updated` stamp" );
    $css = $render( (int) $defPage );
    $check( $pageRendered( $css, (int) $defPage, 'AIEDDEFAULTGATE' ), 'default heading: after undo the probe page rendered (so the next check is not an empty fetch)' );
    $check( ! $ruleHas( $css, $defCls, 'font-size:53px' ), 'default heading: after undo no rule for the default preset sets font-size:53px any more' );
} catch ( \Throwable $e ) {
    $aborted    = true;
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    $deleteProbePages(); // an abort must not leave the probe page behind
    if ( serialize( GlobalPresets::read() ) !== serialize( $dataStart ) ) {
        // The data must end BYTE-IDENTICAL to the start. The only difference an undo is allowed to leave is the `updated` stamp of the
        // default heading preset it restored (undo refreshes it); anything else is a failure of the run.
        $cur = (array) GlobalPresets::read();
        if ( isset( $cur['module']['divi/heading']['items'][ $headingDefault ], $dataStart['module']['divi/heading']['items'][ $headingDefault ] ) ) {
            $cur['module']['divi/heading']['items'][ $headingDefault ]['updated'] = $dataStart['module']['divi/heading']['items'][ $headingDefault ]['updated'] ?? null;
        }
        if ( serialize( $cur ) !== serialize( $dataStart ) ) {
            $failures[] = 'the stored presets differed from the start after the run and were restored with GlobalPresets::save()';
            WP_CLI::log( 'FAIL  the stored presets differed from the start after the run: restored from the snapshot taken through Divi\'s own reader' );
        } else {
            WP_CLI::log( "note: only the default heading preset's updated stamp differed (undo refreshes it): restored from the snapshot" );
        }
        GlobalPresets::save( $dataStart );
    }
}

// ---- 9. the site is exactly as it was ---------------------------------------------------------------------------------------
$check( $fingerprint() === $fpStart, 'every stored preset (the whole data, legacy entries included) is identical to the start' );
$check( serialize( GlobalPresets::read() ) === serialize( $dataStart ), 'the final stored presets serialize BYTE-IDENTICALLY to the data read at the start (updated stamps included)' );
$check( $total() === $countStart, 'the preset count equals the baseline' );
$deleteProbePages();
$check( 0 === $probeLeft(), "the probe page is deleted (none titled 'aied verify gp...' is left)" );
$left = 0;
foreach ( array_filter( $ids ) as $leftId ) {
    $left += null === GlobalPresets::find( (array) GlobalPresets::read(), $leftId ) ? 0 : 1;
}
$check( 0 === $left, 'none of the presets this run created is stored any more' );
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
WP_CLI::success( 'Pro manage_global_presets verified on the real Divi presets.' );
