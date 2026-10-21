<?php
/**
 * Dev-only: the rules and chips of the section recipes, and the measured quiet-rule floor (rules and chips adapter,
 * plan 2026-10-21, Task 2). Pure PHP, no site, no network.
 *
 *   php scripts/rule-chip-audit.php [revision]            list the rules and chips (default revision 155ff75)
 *   php scripts/rule-chip-audit.php --current             list them from the working-tree recipe file
 *   php scripts/rule-chip-audit.php --floor [revision]    measure the quiet-rule floor over EVERY rule the library shipped
 *                                                         at the revision (default 155ff75) and write
 *                                                         fixtures/shapes/rule-floor.md
 *
 * Reads the old file with `git show` like scripts/kit-migration-report.php. The audit itself is
 * tests/Support/RuleChipAudit.php (it finds a block by its decoration or by its marker, so the lists are the same
 * before and after the migration).
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/Support/RuleChipAudit.php';

use Divi5Validator\Tests\Support\RuleChipAudit;

$args    = array_slice( $argv, 1 );
$floor   = in_array( '--floor', $args, true );
$current = in_array( '--current', $args, true );
$revs    = array_values( array_filter( $args, static fn ( string $a ): bool => '' !== $a && '-' !== $a[0] ) );
$rev     = $revs[0] ?? '155ff75';
if ( ! $current && 1 !== preg_match( '/^[0-9a-f]{7,40}$/', $rev ) ) {
    fwrite( STDERR, "The revision must be a commit id.\n" );
    exit( 2 );
}
$file = 'wp-plugin/data/section-recipes.json';
$raw  = $current
    ? (string) file_get_contents( $root . '/' . $file )
    : (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $rev . ':' . $file ) . ' 2>/dev/null' );
$recipes = json_decode( $raw, true );
if ( ! is_array( $recipes ) ) {
    fwrite( STDERR, 'Could not read the recipes (' . ( $current ? 'the working tree' : "revision $rev" ) . ").\n" );
    exit( 1 );
}

if ( $floor ) {
    if ( $current ) {
        fwrite( STDERR, "The floor is measured on the unmigrated library: give a revision, not --current.\n" );
        exit( 2 );
    }
    $cells = RuleChipAudit::floorCells( $recipes );
    usort( $cells, static fn ( array $a, array $b ): int => $a['ratio'] <=> $b['ratio'] );
    $min   = $cells[0];
    $total = array_sum( array_column( $cells, 'count' ) );
    $md    = "# Quiet-rule floor (measured)\n\n"
        . "Written by `php scripts/rule-chip-audit.php --floor $rev`; do not edit by hand.\n\n"
        . "Every rule the section recipes shipped at revision `$rev` ($total rules, quiet and strong, the two accent dividers that "
        . "Ruling 8 later exempted from the migration included: this is the measurement of what the library shipped) is drawn in each of the 10 design languages: "
        . "the rule colour (alpha included) is composited over its band (light = `bg` over white, surface = `surface` over `bg`, "
        . "accent = `accent` over `bg`, panel = `text` over `bg`, dark = `overlay` over `#808080`) and judged against that band "
        . "(WCAG contrast). One row per (colour, band) cell: its lowest ratio and the language that gives it.\n\n"
        . "| Library rule colour | Band | Lowest ratio | Language | Rules in the cell | Weakest rule |\n|---|---|---|---|---|---|\n";
    foreach ( $cells as $c ) {
        $md .= sprintf( "| `%s` | %s | %.3f | %s | %d | %s |\n", $c['colour'], $c['band'], $c['ratio'], $c['language'], $c['count'], str_replace( '|', ' ', $c['rule'] ) );
    }
    $floorValue = floor( $min['ratio'] * 10 ) / 10;
    $md .= sprintf(
        "\nMinimum: %.3f (%s, `%s` on %s, %s)\n\nFloor: %.1f\n\nThe floor is the minimum rounded DOWN to one decimal. `RuleChipAudit::QUIET_RULE_FLOOR` is written once from it and never lowered; "
        . "a kit x language x band cell that cannot meet it by a role change fails, it is not excused.\n",
        $min['ratio'],
        $min['rule'],
        $min['colour'],
        $min['band'],
        $min['language'],
        $floorValue
    );
    file_put_contents( $root . '/fixtures/shapes/rule-floor.md', $md );
    echo $md;
    exit( 0 );
}

$rules = RuleChipAudit::rules( $recipes );
$chips = RuleChipAudit::chips( $recipes );
printf( "Rules: %d blocks in %d recipes\n", count( $rules ), count( array_unique( array_column( $rules, 'recipe' ) ) ) );
foreach ( $rules as $r ) {
    printf( "  %s|%s|%d  %s  on %s  colour %s%s\n", $r['recipe'], $r['block'], $r['index'], $r['role'], $r['on'], $r['colour'] ?? '-', $r['marked'] ? '  (marked)' : '' );
}
printf( "Chips: %d blocks in %d recipes\n", count( $chips ), count( array_unique( array_column( $chips, 'recipe' ) ) ) );
foreach ( $chips as $c ) {
    printf( "  %s|%s|%d  on %s%s\n", $c['recipe'], $c['block'], $c['index'], $c['on'], $c['marked'] ? '  (marked)' : '' );
}
