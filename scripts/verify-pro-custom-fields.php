<?php
/**
 * Real-site check of the Pro tool set_custom_fields: the real tool, the real batch table, WordPress's own meta functions and its REAL
 * wp_kses_post() (the unit tests only use a stand-in for it). Self-cleaning: it deletes every post, batch row, ACF field group and
 * contributor user it created. It records about 13 batches and the batch store keeps only the newest 20, so on a site that already
 * has batches it evicts the oldest real ones: dev sites only.
 *
 * ACF checks run only when the free ACF plugin is active (it is NOT part of the dev environment). To run them, install it for the
 * run, then remove it again exactly as described in the header of scripts/verify-acf-custom-fields.php (deactivate, delete the
 * folder, delete the acf_* options; never `wp plugin delete`). Without ACF those checks are SKIP lines.
 *
 * Run: make verify-pro-custom-fields   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed: the tool
 * is called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Tools\SetCustomFields;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

const AIED_CF_CONTRIB_LOGIN = 'aied_pro_cf_contrib';

/** Delete the contributor this script creates: only that exact login, never user 1. */
$deleteContributor = static function (): void {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $u = get_user_by( 'login', AIED_CF_CONTRIB_LOGIN );
    if ( $u instanceof \WP_User && AIED_CF_CONTRIB_LOGIN === $u->user_login && (int) $u->ID > 1 ) {
        wp_delete_user( (int) $u->ID );
    }
};

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$skip = static function ( string $label ): void {
    WP_CLI::log( 'SKIP  ' . $label );
};

$posts    = [];
$batchIds = [];
$acfGroup = false;
BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
$log  = Batches::log();
$undo = Batches::undo();
$tool = new SetCustomFields( $log );

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
$make = static function ( string $title, array $meta = [] ) use ( &$posts ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '', 'meta_input' => $meta ], true );
    if ( is_wp_error( $id ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create a throwaway page: ' . $id->get_error_message() );
    }
    $posts[] = (int) $id;

    return (int) $id;
};
/** Run the tool; returns [ payload, error message or null ]. */
$run = static function ( array $args ) use ( &$tool, $track ): array {
    try {
        return [ $track( $tool->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    }
};
$meta     = static fn ( int $id, string $key ): mixed => get_post_meta( $id, $key, true );
$newestId = static fn (): int => (int) ( $log->recent( 1 )[0]['id'] ?? 0 );

try {
    // A stale contributor from an interrupted earlier run goes first.
    $deleteContributor();

    // A key becomes "known" because ANOTHER post already has it.
    $seed  = $make( 'AIED Pro cf seed', [ 'aied_pro_known' => 'seed value', 'aied_pro_other' => 'x' ] );
    $page  = $make( 'AIED Pro cf target', [ 'aied_pro_known' => 'Original' ] );
    $fresh = $make( 'AIED Pro cf fresh' );

    // ---- dry run, write, undo ---------------------------------------------------------------
    $newestBefore = $newestId();
    [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'Preview' ] ], 'dry_run' => true ] );
    $check( null === $err && true === ( $r['dry_run'] ?? false ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'a dry run previews and leaves the value alone' );
    $check( ! isset( $r['batch'] ) && $newestBefore === $newestId(), 'a dry run records no batch' );

    $tricky = 'C:\\path "quoted" \'single\' Café ✓ <b>bold</b>';
    [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => $tricky ] ] ] );
    $check( null === $err && true === ( $r['changed'] ?? false ), 'a valid call writes' );
    $check( $tricky === $meta( $page, 'aied_pro_known' ), 'backslashes, quotes, UTF-8 and allowed markup are stored byte-exactly' );
    $check( true === ( $r['batch']['undoable'] ?? false ), 'the call recorded one undoable batch' );
    // the free read tool sees what the Pro tool wrote
    $read = \AiEditorDivi5\WP\CustomFieldReader::read( get_post( $page ), [ 'aied_pro_known' ] );
    $check( $tricky === ( $read['fields'][0]['value'] ?? '' ), 'get_custom_fields (free) reads the value the Pro tool wrote' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === ( $u['fully_undone'] ?? false ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'undo puts the original value back (fully_undone)' );

    // ---- a call that creates a key; undo deletes it -------------------------------------------
    [ $r, $err ] = $run( [ 'id' => $fresh, 'fields' => [ [ 'key' => 'aied_pro_other', 'value' => 'created here' ] ] ] );
    $check( null === $err && 'create' === ( $r['fields'][0]['status'] ?? '' ) && 'created here' === $meta( $fresh, 'aied_pro_other' ), 'a key the site knows is created on a post that did not have it' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === ( $u['fully_undone'] ?? false ) && ! metadata_exists( 'post', $fresh, 'aied_pro_other' ), 'undo of a created key deletes it again' );

    // ---- ampersands: a bare & and an entity are stored exactly as sent ---------------------------
    [ $r, $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'Terms & Conditions' ], [ 'key' => 'aied_pro_other', 'value' => 'Fish &amp; Chips' ] ] ] );
    $check( null === $err && true === ( $r['changed'] ?? false ), 'a bare "&" and an "&amp;" entity are accepted' );
    $check( 'Terms & Conditions' === $meta( $page, 'aied_pro_known' ), 'a bare "&" is stored byte-exactly (the raw value is not the kses output)' );
    $check( 'Fish &amp; Chips' === $meta( $page, 'aied_pro_other' ), 'an "&amp;" entity is stored byte-exactly' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === ( $u['fully_undone'] ?? false ) && 'Original' === $meta( $page, 'aied_pro_known' ) && ! metadata_exists( 'post', $page, 'aied_pro_other' ), 'undo of the ampersand call restores both fields' );

    // ---- undo refuses a value someone else changed ------------------------------------------
    [ $r ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'By the AI' ] ] ] );
    update_post_meta( $page, 'aied_pro_known', wp_slash( 'By a person' ) );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'changed_since' === ( $u['items'][0]['result'] ?? '' ) && false === ( $u['fully_undone'] ?? true ) && 'By a person' === $meta( $page, 'aied_pro_known' ), 'undo does not overwrite a later edit (changed_since)' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );
    $check( true === ( $u['fully_undone'] ?? false ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'a forced undo restores the original value' );

    // ---- refusals change nothing --------------------------------------------------------------
    update_post_meta( $page, '_aied_pro_hidden', 'h' );
    update_post_meta( $page, 'aied_pro_api_token', 't' );
    $before = [ $meta( $page, 'aied_pro_known' ), $meta( $page, '_aied_pro_hidden' ), $meta( $page, 'aied_pro_api_token' ) ];
    $cases  = [
        'an underscore key'          => [ [ 'key' => '_aied_pro_hidden', 'value' => 'x' ], 'protected or private' ],
        'a secret-looking key'       => [ [ 'key' => 'aied_pro_api_token', 'value' => 'x' ], 'password' ],
        'an unknown key'             => [ [ 'key' => 'aied_pro_never_used_anywhere', 'value' => 'x' ], 'knows' ],
        'a case-variant key'         => [ [ 'key' => 'AIED_PRO_KNOWN', 'value' => 'x' ], 'differs only by letter case' ],
        'a non-ASCII key'            => [ [ 'key' => 'aied_pro_knówn', 'value' => 'x' ], 'printable ASCII' ],
        'a script value'             => [ [ 'key' => 'aied_pro_known', 'value' => '<script>alert(1)</script>' ], 'script' ],
        'an event-handler value'     => [ [ 'key' => 'aied_pro_known', 'value' => '<p onclick="x()">hi</p>' ], 'event-handler' ],
        'a javascript: URL'          => [ [ 'key' => 'aied_pro_known', 'value' => '<a href="javascript:alert(1)">x</a>' ], 'javascript' ],
        'markup kses would change'   => [ [ 'key' => 'aied_pro_known', 'value' => '<form action="x">hi</form>' ], 'markup' ],
        'a value over 10,000 chars'  => [ [ 'key' => 'aied_pro_known', 'value' => str_repeat( 'a', 10001 ) ], '10,000' ],
    ];
    foreach ( $cases as $label => [ $field, $needle ] ) {
        $newestBefore = $newestId();
        // The valid companion uses a DIFFERENT key than the refused field (the same key twice is its own refusal). It would
        // create aied_pro_other, so "not written" is proven by the key still being absent. A plain argument error (not-ASCII key)
        // is thrown before any per-field check, so it has no "Nothing was written" prefix.
        $companion    = 'aied_pro_other' === $field['key'] ? 'aied_pro_known' : 'aied_pro_other';
        [ $r, $err ]  = $run( [ 'id' => $page, 'fields' => [ [ 'key' => $companion, 'value' => 'would be written' ], $field ] ] );
        $argError     = 'printable ASCII' === $needle;
        $check(
            null !== $err && ( $argError || str_contains( $err, 'Nothing was written' ) ) && str_contains( $err, $needle )
                && 'Original' === $meta( $page, 'aied_pro_known' ) && ! metadata_exists( 'post', $page, 'aied_pro_other' ) && $newestBefore === $newestId(),
            "refused: {$label} (the valid field beside it was not written, no batch recorded)"
        );
    }
    $check( $before === [ $meta( $page, 'aied_pro_known' ), $meta( $page, '_aied_pro_hidden' ), $meta( $page, 'aied_pro_api_token' ) ], 'no refused call changed any value' );

    $tooMany = [];
    for ( $i = 0; $i < 26; $i++ ) {
        $tooMany[] = [ 'key' => 'aied_pro_known', 'value' => (string) $i ];
    }
    [ , $err ] = $run( [ 'id' => $page, 'fields' => $tooMany ] );
    $check( null !== $err && str_contains( $err, 'at most 25' ), 'refused: 26 fields' );
    [ , $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'a' ], [ 'key' => 'aied_pro_known', 'value' => 'b' ] ] ] );
    $check( null !== $err && str_contains( $err, 'more than once' ), 'refused: a duplicate key' );
    [ , $err ] = $run( [ 'id' => 99999999, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'x' ] ] ] );
    $check( null !== $err && str_contains( $err, 'not found' ), 'refused: a missing post' );

    // a user who cannot edit the post sees it as missing
    $contributor = wp_insert_user( [ 'user_login' => AIED_CF_CONTRIB_LOGIN, 'user_pass' => wp_generate_password(), 'user_email' => 'aied_pro_cf_contrib@example.invalid', 'role' => 'contributor' ] );
    if ( is_wp_error( $contributor ) ) {
        $check( false, 'could not create the contributor user: ' . $contributor->get_error_message() );
    } else {
        wp_set_current_user( (int) $contributor );
        [ , $err ] = $run( [ 'id' => $page, 'fields' => [ [ 'key' => 'aied_pro_known', 'value' => 'x' ] ] ] );
        wp_set_current_user( 1 );
        $check( null !== $err && str_contains( $err, 'not found' ) && 'Original' === $meta( $page, 'aied_pro_known' ), 'a contributor who cannot edit the page gets "not found" and nothing changes' );
    }

    // ---- ACF (only when ACF is active) --------------------------------------------------------
    if ( function_exists( 'acf_import_field_group' ) && function_exists( 'get_field' ) ) {
        acf_import_field_group( [
            'key' => 'group_aied_pro_cf', 'title' => 'AIED Pro cf group', 'active' => true,
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ],
            'fields' => [
                [ 'key' => 'field_aied_pro_text', 'label' => 'T', 'name' => 'aied_pro_acf_text', 'type' => 'text' ],
                [ 'key' => 'field_aied_pro_select', 'label' => 'S', 'name' => 'aied_pro_acf_select', 'type' => 'select', 'choices' => [ 'a' => 'A', 'b' => 'B' ] ],
                [ 'key' => 'field_aied_pro_rep', 'label' => 'R', 'name' => 'aied_pro_acf_rep', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_aied_pro_rep_item', 'label' => 'I', 'name' => 'item', 'type' => 'text' ] ] ],
            ],
        ] );
        $acfGroup  = true;
        // The tool memoizes ACF's field index and the known-keys lookup per instance (one instance per request in production), so
        // the instance used before this group existed would not see it: use a fresh one, as the next real request would.
        $tool      = new SetCustomFields( $log );
        $acfPage   = $make( 'AIED Pro cf acf' );
        $acfTricky = 'ACF C:\\path "q" Café';
        [ $r, $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_text', 'value' => $acfTricky ], [ 'key' => 'aied_pro_acf_select', 'value' => 'b' ] ] ] );
        $check( null === $err && true === ( $r['changed'] ?? false ), 'ACF: a text field and a select are written' );
        $check( $acfTricky === $meta( $acfPage, 'aied_pro_acf_text' ) && 'field_aied_pro_text' === $meta( $acfPage, '_aied_pro_acf_text' ), 'ACF: the raw value is exact (backslash, quote, UTF-8) and ACF\'s field-key reference row exists' );
        $check( $acfTricky === get_field( 'aied_pro_acf_text', $acfPage, false ), 'ACF: get_field returns the same value' );
        [ , $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_select', 'value' => 'zzz' ] ] ] );
        $check( null !== $err && str_contains( $err, 'one of' ) && 'b' === $meta( $acfPage, 'aied_pro_acf_select' ), 'ACF: a value that is not a choice is refused' );
        [ , $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_rep', 'value' => 'x' ] ] ] );
        $check( null !== $err && str_contains( $err, 'ACF editor' ), 'ACF: a repeater is refused' );
        [ , $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'AIED_PRO_ACF_TEXT', 'value' => 'x' ] ] ] );
        $check( null !== $err && str_contains( $err, 'differs only by letter case' ) && $acfTricky === $meta( $acfPage, 'aied_pro_acf_text' ), 'ACF: a case-variant of an ACF field name is refused and nothing changes' );
        // an edit of a value that already holds a backslash, then its undo: the earlier value must come back byte-exactly
        $acfSecond = 'ACF 2 D:\\dir\\sub "z" Ünï \\n';
        [ $r2, $err ] = $run( [ 'id' => $acfPage, 'fields' => [ [ 'key' => 'aied_pro_acf_text', 'value' => $acfSecond ] ] ] );
        $check( null === $err && $acfSecond === $meta( $acfPage, 'aied_pro_acf_text' ), 'ACF: an edit to a second backslash value is stored byte-exactly' );
        $u2 = isset( $r2['batch']['id'] ) ? $trackUndo( $undo->undo( (int) $r2['batch']['id'] ) ) : [];
        $check( true === ( $u2['fully_undone'] ?? false ) && $acfTricky === $meta( $acfPage, 'aied_pro_acf_text' ) && 'field_aied_pro_text' === $meta( $acfPage, '_aied_pro_acf_text' ), 'ACF: undo restores the previous backslash value byte-exactly (and keeps the field-key reference)' );
        $u = isset( $r['batch']['id'] ) ? $trackUndo( $undo->undo( (int) $r['batch']['id'] ) ) : [];
        $check( true === ( $u['fully_undone'] ?? false ) && ! metadata_exists( 'post', $acfPage, 'aied_pro_acf_text' ), 'ACF: undo of a created ACF value removes it' );
    } else {
        $skip( 'ACF checks (the free ACF plugin is not active; see the header of this script)' );
    }
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    global $wpdb;
    wp_set_current_user( 1 );
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    // Mirrors scripts/verify-acf-custom-fields.php: the group and its fields (including nested sub fields) go.
    foreach ( get_posts( [ 'post_type' => 'acf-field-group', 'post_status' => 'any', 'name' => 'group_aied_pro_cf', 'numberposts' => -1 ] ) as $gp ) {
        $stack = [ $gp->ID ];
        while ( [] !== $stack ) {
            $parent = array_pop( $stack );
            foreach ( get_posts( [ 'post_type' => 'acf-field', 'post_status' => 'any', 'post_parent' => $parent, 'numberposts' => -1 ] ) as $f ) {
                $stack[] = $f->ID;
            }
            wp_delete_post( $parent, true );
        }
    }
    $deleteContributor();
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
    }
}

$left = get_posts( [ 'post_type' => [ 'page', 'acf-field-group', 'acf-field' ], 'post_status' => 'any', 's' => 'AIED Pro cf', 'numberposts' => -1 ] );
$check( [] === $left && ! get_user_by( 'login', AIED_CF_CONTRIB_LOGIN ), 'the throwaway pages, the field group and the contributor user are gone' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'set_custom_fields verified on the real site.' );
