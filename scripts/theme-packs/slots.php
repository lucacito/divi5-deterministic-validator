<?php
// Usage: php scripts/theme-packs/slots.php <language> <recipe> [<recipe>...]  -> numbered text slots
require __DIR__ . '/lib.php';
use AiEditorDivi5\WP\SectionRecipes;
array_shift( $argv ); $lang = array_shift( $argv );
foreach ( $argv as $name ) {
    $md = SectionRecipes::recipe( $name, $lang );
    if ( null === $md ) { echo "## $name: NOT FOUND\n"; continue; }
    echo "## $name\n";
    foreach ( tp_slots( $md ) as $i => $s ) {
        printf( "%2d %-12s %-34s %s\n", $i, $s["block"], substr( $s["path"], -34 ), json_encode( $s['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }
}
