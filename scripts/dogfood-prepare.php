<?php
/**
 * Dev-only: write the guide files and the task a fresh assistant gets for the dogfood routine (plan 2026-10-22).
 * Pure PHP, no site, no network, no model call.
 *
 *   php scripts/dogfood-prepare.php <kit> <language> [--out <dir>] [--task freehand|edit]
 *
 * The default out dir is dist/dogfood/<kit>-<language> (freehand) or dist/dogfood/<kit>-<language>-edit (edit), relative
 * to the repo root. Exit 2 on an unknown kit, language, task or flag.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/bootstrap.php';
require_once $root . '/wp-plugin/src/autoload.php';

use Divi5Validator\Tests\Support\Dogfood\Prepare;

$usage = 'Usage: php scripts/dogfood-prepare.php <kit> <language> [--out <dir>] [--task freehand|edit]';
$fail  = static function ( string $message ) use ( $usage ): void {
    fwrite( STDERR, $message . "\n" . $usage . "\n" );
    exit( 2 );
};

$positional = [];
$out        = null;
$task       = 'freehand';
$args       = array_slice( $argv, 1 );
for ( $i = 0, $n = count( $args ); $i < $n; ++$i ) {
    $a = $args[ $i ];
    if ( '--out' === $a || '--task' === $a ) {
        if ( ! isset( $args[ $i + 1 ] ) || '' === $args[ $i + 1 ] ) {
            $fail( "$a needs a value." );
        }
        if ( '--out' === $a ) {
            $out = $args[ ++$i ];
        } else {
            $task = $args[ ++$i ];
        }
    } elseif ( '' !== $a && '-' === $a[0] ) {
        $fail( "Unknown option $a." );
    } else {
        $positional[] = $a;
    }
}
if ( 2 !== count( $positional ) ) {
    $fail( 'Give a kit and a language.' );
}
[ $kit, $language ] = $positional;

try {
    $rel = $out ?? Prepare::defaultOut( $kit, $language, $task );
    $dir = rtrim( 0 === strpos( $rel, '/' ) ? $rel : $root . '/' . $rel, '/' );
    $files = Prepare::files( $kit, $language, $task, $dir );
} catch ( \InvalidArgumentException $e ) {
    $fail( $e->getMessage() );
}

Prepare::write( $files, $dir );
foreach ( $files as $name => $content ) {
    echo $name, ' ', strlen( $content ), "\n";
}
