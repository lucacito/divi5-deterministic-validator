<?php
/**
 * Dev-only: promotes probe-proven shapes into the registry.
 *
 *   php scripts/promote-shapes.php <probe-results.json> <candidates.json>
 *
 * For every candidate (optional `note` string is copied into its evidence section) whose probe result says `effect: true` and `phoneOverflow: false` (and whose control loads were
 * stable), every attribute path of its `set` values is appended to fixtures/shapes/verified-shapes.json as
 * `how: probe` with `evidence: docs/shape-evidence-5.15.1.md#<id>`, unless that exact path is already registered (an
 * export stays the stronger evidence). A section per promoted candidate is appended to docs/shape-evidence-5.15.1.md.
 * Candidates that rendered nothing (DEAD), overflow a phone, or ended in an ERROR are never added. A result the probe labelled
 * `cssOnly` (the emitted stylesheet changed but no computed style did) is promoted only when the candidate sets
 * `"allowCssOnly": true` AND has a non-empty `note` saying why a stylesheet-only effect is proof enough. A candidate's optional `parent`,
 * `neighbours` and `children` (see scripts/verify-shapes.cjs) are described in its evidence section. Every candidate that is NOT promoted (dead, overflowing a
 * phone, an error, a stylesheet-only result without `allowCssOnly`, an unreliable measurement) is listed with its reason in a
 * `## Not taught (<candidates file name>)` section of the evidence file (followed by the items of the optional hand-written
 * `fixtures/shapes/not-taught-<candidates file name>.json`, [{item, reason}]), which is rewritten on every run. Pure PHP, no WordPress, idempotent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/Support/ShapePaths.php';

use Divi5Validator\Tests\Support\ShapePaths;

if ( $argc < 3 ) {
    fwrite( STDERR, "usage: php scripts/promote-shapes.php <probe-results.json> <candidates.json>\n" );
    exit( 2 );
}

$root         = dirname( __DIR__ );
$registryFile = $root . '/fixtures/shapes/verified-shapes.json';
$evidenceRel  = 'docs/shape-evidence-5.15.1.md';
$evidenceFile = $root . '/' . $evidenceRel;

$read = static function ( string $file ): array {
    $data = json_decode( (string) file_get_contents( $file ), true );
    if ( ! is_array( $data ) ) {
        fwrite( STDERR, "Cannot read JSON from $file\n" );
        exit( 2 );
    }
    return $data;
};

$results    = [];
foreach ( $read( $argv[1] ) as $r ) {
    $results[ (string) ( $r['id'] ?? '' ) ] = $r;
}
$candidates = $read( $argv[2] );
$registry   = $read( $registryFile );
$have       = [];
foreach ( $registry['shapes'] as $e ) {
    $have[ $e['path'] ] = true;
}

/** @param array<string,mixed> $set */
$setPaths = static function ( array $set ): array {
    $attrs = [];
    foreach ( $set as $dotted => $value ) {
        $node =& $attrs;
        $keys = explode( '.', (string) $dotted );
        $last = array_pop( $keys );
        foreach ( $keys as $k ) {
            if ( ! isset( $node[ $k ] ) || ! is_array( $node[ $k ] ) ) {
                $node[ $k ] = [];
            }
            $node =& $node[ $k ];
        }
        $node[ $last ] = $value;
        unset( $node );
    }
    return ShapePaths::fromBlockAttrs( $attrs );
};

$evidence = is_file( $evidenceFile ) ? (string) file_get_contents( $evidenceFile ) : '';
if ( $evidence === '' ) {
    $evidence = "# Probe-proven attribute shapes (Divi 5.15.1)\n\n"
        . "Each section below is one candidate shape that `node scripts/verify-shapes.cjs` rendered on the real Divi of the Docker dev site: two throwaway draft pages identical except for the candidate's values, opened in Chromium at 1280, 900 and 400 px. A shape counts as proven when the emitted page styles or the computed styles of the module changed at some width and the page did not overflow a phone. `php scripts/promote-shapes.php` adds the proven paths to `fixtures/shapes/verified-shapes.json` (`how: probe`).\n";
}

$added     = 0;
$notes     = [];
$notTaught = []; // id => reason, for the evidence file's "Not taught" section
$family    = basename( (string) $argv[2], '.json' );
foreach ( $candidates as $c ) {
    $id = (string) ( $c['id'] ?? '' );
    $r  = $results[ $id ] ?? null;
    if ( $r === null ) {
        $notes[]          = "skip $id: no probe result";
        $notTaught[ $id ] = 'no probe result';
        continue;
    }
    $noisy = false;
    foreach ( (array) ( $r['detail'] ?? [] ) as $d ) {
        if ( is_array( $d ) && ( ! empty( $d['controlNoise'] ) || isset( $d['error'] ) ) ) {
            $noisy = true;
        }
    }
    if ( isset( $r['error'] ) ) {
        $notes[]          = "not promoted $id: the probe reported an error";
        $notTaught[ $id ] = 'the probe reported an error: ' . (string) $r['error'];
        continue;
    }
    if ( ! empty( $r['cssOnly'] ) && ( ( $c['allowCssOnly'] ?? false ) !== true || trim( (string) ( $c['note'] ?? '' ) ) === '' ) ) {
        $notes[]          = "not promoted $id: only the stylesheet changed, no computed style (set allowCssOnly: true and a note to promote it)";
        $notTaught[ $id ] = 'only the emitted stylesheet changed, no computed style (not accepted without allowCssOnly and a note)';
        continue;
    }
    if ( empty( $r['effect'] ) || ! empty( $r['phoneOverflow'] ) || $noisy || isset( $r['detail']['validator'] ) ) {
        $why              = empty( $r['effect'] ) ? 'no effect (dead)' : ( ! empty( $r['phoneOverflow'] ) ? 'overflows a phone at 400 px' : 'unreliable measurement' );
        $notes[]          = "not promoted $id: $why";
        $notTaught[ $id ] = $why;
        continue;
    }
    $new = [];
    foreach ( $setPaths( (array) $c['set'] ) as $path ) {
        if ( isset( $have[ $path ] ) ) {
            $notes[] = "already registered, kept: $path";
            continue;
        }
        $registry['shapes'][] = [ 'path' => $path, 'how' => 'probe', 'evidence' => $evidenceRel . '#' . $id ];
        $have[ $path ]        = true;
        $new[]                = $path;
        ++$added;
    }
    if ( strpos( $evidence, "\n## $id\n" ) === false ) {
        $section  = "\n## $id\n\n";
        $where = '';
        if ( isset( $c['parent'] ) && is_string( $c['parent'] ) && $c['parent'] !== '' ) {
            $where .= ', placed inside a `' . $c['parent'] . '`';
        }
        $kids = [];
        foreach ( (array) ( $c['children'] ?? [] ) as $child ) {
            if ( is_array( $child ) && isset( $child['block'] ) ) {
                $kids[] = '`' . $child['block'] . '`';
            }
        }
        if ( $kids !== [] ) {
            $where .= ', holding ' . implode( ', ', $kids );
        }
        foreach ( [ 'before', 'after' ] as $side ) {
            $count = is_array( $c['neighbours'][ $side ] ?? null ) ? count( $c['neighbours'][ $side ] ) : 0;
            if ( $count > 0 ) {
                $where .= ', with ' . $count . ' plain neighbour section(s) ' . $side . ' it';
            }
        }
        $section .= 'Block: `' . $c['block'] . '`' . $where . ". Set on top of the base block:\n\n";
        foreach ( (array) $c['set'] as $p => $v ) {
            $section .= '- `' . $p . '` = `' . json_encode( $v, JSON_UNESCAPED_SLASHES ) . "`\n";
        }
        $section .= "\nMeasured (page A = base, page B = base + the values above):\n\n| Width | Changed | Computed-style differences | Stylesheet differs | Example |\n|---|---|---|---|---|\n";
        foreach ( [ '1280', '900', '400' ] as $w ) {
            $d        = $r['detail'][ $w ] ?? [];
            $section .= sprintf(
                "| %s px | %s | %d | %s | %s |\n",
                $w,
                ! empty( $r['widths'][ $w ] ) ? 'yes' : 'no',
                (int) ( $d['computedDiffs'] ?? 0 ),
                ! empty( $d['stylesheetDiffers'] ) ? 'yes' : 'no',
                isset( $d['sample'][0] ) ? '`' . str_replace( '|', '/', (string) $d['sample'][0] ) . '`' : ''
            );
        }
        $section .= "\nPhone overflow at 400 px: " . ( ! empty( $r['phoneOverflow'] ) ? 'yes' : 'no' ) . ".\n";
        if ( isset( $c['note'] ) && trim( (string) $c['note'] ) !== '' ) {
            $section .= "\nNote: " . trim( (string) $c['note'] ) . "\n";
        }
        // Every path registered with this section as its evidence (stable when the section is regenerated).
        $mine = [];
        foreach ( $registry['shapes'] as $e ) {
            if ( $e['evidence'] === $evidenceRel . '#' . $id ) {
                $mine[] = $e['path'];
            }
        }
        sort( $mine, SORT_STRING );
        $section .= $mine === []
            ? "\nRegistered paths added: none (every path was already registered by a builder export, which stays the stronger evidence).\n"
            : "\nRegistered paths added:\n\n" . implode( "\n", array_map( static fn ( string $p ): string => '- `' . $p . '`', $mine ) ) . "\n";
        $evidence .= $section;
    }
}

// The "Not taught" section of this candidates file: rewritten each run (replaced when it exists, appended otherwise).
$heading = "## Not taught ($family)";
$evidence = (string) preg_replace( '/\n' . preg_quote( $heading, '/' ) . '\n.*?(?=\n## |\z)/s', '', $evidence );
// Optional hand-written list of what was deliberately NOT measured: fixtures/shapes/not-taught-<family>.json = [{"item","reason"}, ...].
$notMeasured = [];
$notMeasuredFile = $root . '/fixtures/shapes/not-taught-' . $family . '.json';
if ( is_file( $notMeasuredFile ) ) {
    $notMeasured = $read( $notMeasuredFile );
}
if ( $notTaught !== [] || $notMeasured !== [] ) {
    $byId = [];
    foreach ( $candidates as $c ) {
        $byId[ (string) ( $c['id'] ?? '' ) ] = $c;
    }
    $evidence = rtrim( $evidence, "\n" ) . "\n\n" . $heading . "\n\nCandidates from `" . $family . ".json` that were measured and are not taught, with the reason:\n\n";
    foreach ( $notTaught as $id => $why ) {
        $note      = trim( (string) ( $byId[ $id ]['note'] ?? '' ) );
        $evidence .= '- `' . $id . '`: ' . $why . ( $note !== '' ? '. ' . $note : '' ) . "\n";
    }
    if ( $notMeasured !== [] ) {
        $evidence .= "\nNot measured, therefore not taught as proven (from `fixtures/shapes/not-taught-" . $family . ".json`):\n\n";
        foreach ( $notMeasured as $row ) {
            $evidence .= '- ' . trim( (string) ( $row['item'] ?? '' ) ) . ': ' . trim( (string) ( $row['reason'] ?? '' ) ) . "\n";
        }
    }
}

usort( $registry['shapes'], static fn ( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
file_put_contents( $registryFile, json_encode( $registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
file_put_contents( $evidenceFile, $evidence );

foreach ( $notes as $n ) {
    echo $n . "\n";
}
echo "added $added path(s) to fixtures/shapes/verified-shapes.json\n";
