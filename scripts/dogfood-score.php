<?php
/**
 * Dev-only: score a Divi 5 page that an assistant wrote against the real style-kit adapters (dogfood plan 2026-10-22).
 * Pure PHP, no site, no network, no model call.
 *
 *   php scripts/dogfood-score.php <page.html> <kit> [--language <id>] [--json] [--min <pct>]
 *
 * Prints the text report (or the JSON with --json). `--language` defaults to the language whose own kit is <kit>, else
 * `editorial`; the output always names the language used. Exit 0 after measuring (any score); 1 only with --min <pct> and an
 * overall score below it (an overall "n/a" counts as 0); 2 on a usage error (unknown kit or language, unreadable file, a page
 * that does not parse as Divi blocks: that is not a measurement).
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/bootstrap.php';
require_once $root . '/wp-plugin/src/autoload.php';

use Divi5Validator\Tests\Support\Dogfood\Scorer;

$usage = 'Usage: php scripts/dogfood-score.php <page.html> <kit> [--language <id>] [--json] [--min <pct>]';
$fail  = static function ( string $message ) use ( $usage ): void {
    fwrite( STDERR, $message . "\n" . $usage . "\n" );
    exit( 2 );
};

$positional = [];
$language   = null;
$json       = false;
$min        = null;
$args       = array_slice( $argv, 1 );
for ( $i = 0, $n = count( $args ); $i < $n; ++$i ) {
    $a = $args[ $i ];
    if ( '--json' === $a ) {
        $json = true;
    } elseif ( '--language' === $a || '--min' === $a ) {
        if ( ! isset( $args[ $i + 1 ] ) || '' === $args[ $i + 1 ] ) {
            $fail( "$a needs a value." );
        }
        if ( '--language' === $a ) {
            $language = $args[ ++$i ];
        } else {
            $value = $args[ ++$i ];
            if ( ! is_numeric( $value ) ) {
                $fail( '--min needs a number (a percentage).' );
            }
            $min = (float) $value;
        }
    } elseif ( '' !== $a && '-' === $a[0] ) {
        $fail( "Unknown option $a." );
    } else {
        $positional[] = $a;
    }
}
if ( 2 !== count( $positional ) ) {
    $fail( 'Give a page file and a kit.' );
}
[ $path, $kit ] = $positional;

if ( ! is_file( $path ) || ! is_readable( $path ) ) {
    $fail( "Cannot read the page file $path." );
}
$markup = (string) file_get_contents( $path );

try {
    $scorer = new Scorer( $kit, $language ?? Scorer::defaultLanguage( $kit ) );
    $report = $scorer->score( $markup, $path );
} catch ( \InvalidArgumentException $e ) {
    $fail( $e->getMessage() );
}

echo $json ? Scorer::json( $report ) : Scorer::text( $report );

if ( null !== $min ) {
    $overall = $report['overall']['score'];
    $value   = is_float( $overall ) || is_int( $overall ) ? (float) $overall : 0.0;
    if ( $value < $min ) {
        exit( 1 );
    }
}
exit( 0 );
