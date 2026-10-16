<?php

declare(strict_types=1);

/**
 * DEV-ONLY (not part of the plugin zip). Measures how much of an AI assistant's context the plugin's own
 * answers use, and writes docs/token-usage.md. Validation itself runs in PHP and costs no tokens; what costs
 * tokens is what the assistant has to read. Tokens are ESTIMATED as bytes / 4 (a common rule of thumb for
 * English text and JSON; the real count depends on the model).
 *
 * Run: php scripts/measure-tokens.php
 */

require __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../tests/FakeWpdb.php';
require_once __DIR__ . '/../tests/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php'; // the plugin's own loader (every class, in the right order)

use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;

$est  = static fn ( int $bytes ): int => (int) ceil( $bytes / 4 );
$rows = [];
$add  = static function ( string $what, string $detail, string $text ) use ( &$rows, $est ): void {
    $bytes  = strlen( $text );
    $rows[] = [ $what, $detail, $bytes, $est( $bytes ) ];
};

// 1. What the assistant loads once per conversation: the tool definitions.
$list = ( new McpHandler() )->handle( new WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] ) )->get_data();
$tools = (array) $list['result']['tools'];
$add( 'Tool definitions', count( $tools ) . ' tools, loaded once per conversation (free plugin only)', (string) json_encode( $list['result'] ) );

// 2. Reading a page: the layout the assistant gets back from get_page_layout (smallest, median, largest real fixture).
$sizes = [];
foreach ( glob( __DIR__ . '/../fixtures/valid/page-*.json' ) ?: [] as $f ) {
    $content = (string) ( json_decode( (string) file_get_contents( $f ), true )['post_content'] ?? '' );
    if ( '' !== $content ) {
        $sizes[ basename( $f ) ] = $content;
    }
}
uasort( $sizes, static fn ( string $a, string $b ): int => strlen( $a ) <=> strlen( $b ) );
$names  = array_keys( $sizes );
$median = $names[ intdiv( count( $names ), 2 ) ];
foreach ( [ 'smallest' => $names[0], 'median' => $median, 'largest' => $names[ count( $names ) - 1 ] ] as $label => $name ) {
    $add( "Reading a page ({$label})", 'the page\'s layout content (get_page_layout adds a little wrapper), from ' . $name, $sizes[ $name ] );
    $o = \AiEditorDivi5\WP\PageOutline::outline( $sizes[ $name ] );
    $add( "Reading a page's outline ({$label})", 'get_page_layout with view "outline" on the same page: ' . $o['total'] . ' blocks, each with path, name, size and the start of its text', (string) json_encode( $o ) );
}

// 3. A refusal: what the assistant reads when the checker says no.
$small   = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$refusal = static function ( string $content ): array {
    $r = LayoutGate::check( $content );

    return [ count( $r->violations() ), (string) json_encode( [ 'saved' => false, 'valid' => false, 'violations' => \AiEditorDivi5\WP\ViolationReport::compact( $r->violations() ) ] ) ];
};
$refusalFull = static function ( string $content ): string {
    $r = LayoutGate::check( $content );

    return (string) json_encode( [ 'saved' => false, 'valid' => false, 'violations' => array_map( static fn ( $v ) => $v->toArray(), $r->violations() ) ] );
};
[ $n, $body ] = $refusal( str_replace( 'divi/shop', 'divi/not-a-module', $small ) );
$add( 'A refusal: one unknown block', "{$n} " . ( 1 === $n ? 'violation' : 'violations' ) . ' (the second lists the allowed child types)', $body );
[ $n, $body ] = $refusal( str_replace( '"builderVersion"', '"builderVers1on"', $sizes[ $median ] ) );
$add( 'A refusal: every block wrong (worst case)', "{$n} " . ( 1 === $n ? 'violation' : 'violations' ) . ' on the median page, as sent now (the first 25, repeated allowed-type lists by reference)', $body );
$add( 'A refusal: every block wrong (before the compact form)', "the same {$n} violations, each with its own full list (how it was sent until 4.12.0)", $refusalFull( str_replace( '"builderVersion"', '"builderVers1on"', $sizes[ $median ] ) ) );
// A badly nested page: every violation carries the column's long allowed-type list.
$bad = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->' . str_repeat( '<!-- wp:divi/column {"builderVersion":"5.14.0"} --><!-- /wp:divi/column -->', 96 ) . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
[ $n, $body ] = $refusal( $bad );
$add( 'A refusal: 96 badly nested blocks', "{$n} violations, as sent now", $body );
$add( 'A refusal: 96 badly nested blocks (before the compact form)', "the same {$n} violations, each with the full allowed list", $refusalFull( $bad ) );

// 4. A preview (dry run): the diff the assistant reads before a save in approval mode.
$changed = (string) preg_replace( '/"value":"([^"]{3,})"/', '"value":"Changed text"', $sizes[ $median ], 1 );
$add( 'A preview (dry run) of a one-line text change', 'verdict plus a capped diff, on the median page', (string) json_encode( DryRun::preview( $sizes[ $median ], $changed ) ) );

// ---- report ------------------------------------------------------------------------------------------------
$md  = "# Token usage of the AI Editor for Divi 5 (free plugin)\n\n";
$md .= 'Measured ' . date( 'Y-m-d' ) . " by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.\n\n";
$md .= "Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.\n\n";
$md .= "| What | Detail | Bytes | Approx. tokens |\n| --- | --- | ---: | ---: |\n";
foreach ( $rows as [ $what, $detail, $bytes, $tokens ] ) {
    $md .= "| {$what} | {$detail} | " . number_format( $bytes ) . ' | ' . number_format( $tokens ) . " |\n";
}
$md .= "\nA refusal used to repeat the full list of allowed block types in every violation. Now the first violation about a parent keeps the list, the others point back to it, and at most 25 are listed (one entry counts the rest).\n";
$md .= "\nTo read a page cheaply, ask get_page_layout for view \"outline\" (every block's path, name, size and the start of its text), then view \"block\" with a path for one block's exact markup.\n";
$md .= "\nTokens the assistant WRITES also count: sending a whole layout to update_page_layout costs roughly the size of the page read. edit_page_content avoids that (and a dry_run of it sends only the find-and-replace pair).\n";
$md .= "\nThe Pro add-on adds its own tool definitions on top of the first row.\n";
file_put_contents( __DIR__ . '/../docs/token-usage.md', $md );
echo $md;
