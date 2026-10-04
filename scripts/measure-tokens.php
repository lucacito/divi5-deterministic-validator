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
foreach ( glob( __DIR__ . '/../wp-plugin/src/*.php' ) ?: [] as $file ) {
    if ( in_array( basename( $file ), [ 'autoload.php', 'index.php', 'AdminPage.php' ], true ) ) {
        continue; // the loader, the directory guard and the admin screen are not needed to answer tool calls
    }
    require_once $file;
}

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
    $add( "Reading a page ({$label})", 'the layout get_page_layout returns, from ' . $name, $sizes[ $name ] );
}

// 3. A refusal: what the assistant reads when the checker says no.
$small   = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$refusal = static function ( string $content ): array {
    $r = LayoutGate::check( $content );

    return [ count( $r->violations() ), (string) json_encode( [ 'saved' => false, 'valid' => false, 'violations' => array_map( static fn ( $v ) => $v->toArray(), $r->violations() ) ] ) ];
};
[ $n, $body ] = $refusal( str_replace( 'divi/shop', 'divi/not-a-module', $small ) );
$add( 'A refusal: one mistake', "{$n} violation, e.g. an unknown block type", $body );
[ $n, $body ] = $refusal( str_replace( '"builderVersion"', '"builderVers1on"', $sizes[ $median ] ) );
$add( 'A refusal: every block wrong (worst case)', "{$n} violations on the median page", $body );

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
$md .= "\nThe Pro add-on adds its own tool definitions on top of the first row.\n";
file_put_contents( __DIR__ . '/../docs/token-usage.md', $md );
echo $md;
