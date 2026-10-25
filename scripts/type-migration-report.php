<?php
/**
 * Dev-only: which headings and labels of the shipped section recipes get a type marker (type adapter, plan
 * 2026-10-24, Task 4). The migration (Task 5) puts a marker on exactly the blocks this report calls display, title or
 * eyebrow.
 *
 *   php scripts/type-migration-report.php [--all]        (make type-report)
 *
 * Reads wp-plugin/data/section-recipes.json raw (no plugin classes, no site, no network) through
 * tests/Support/TypeAudit.php, which classifies every `divi/heading` by its level and font token (h1/h2 + display =
 * display, h3 + display = title, h4 + eyebrow = eyebrow) and every uppercase `divi/text` in the eyebrow font as an
 * eyebrow; a block that already carries `"aied":{"type":...}` is classified by its marker, so the report is the same
 * before and after the migration (only the MARKED column changes).
 *
 * One line per classified block:
 *
 *   recipe | block#index | level | font | role | MARKED or - | exempt reason or -
 *
 * `index` is the block's order among ALL blocks of its name in the recipe, the numbering the migration follows. An
 * `exempt` block (TypeAudit::EXEMPTIONS) stays literal and shows `exempt` as its role. `--all` also lists the texts that
 * are no label (a role of `-`). A heading the rule cannot place and that is not exempt is listed with role `UNCLASSIFIED`
 * and makes the script exit 1. A malformed marker is an error (exit 1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/Support/TypeAudit.php';

use Divi5Validator\Tests\Support\TypeAudit;

final class TypeReport
{
    /**
     * @param list<array{recipe:string,block:string,index:int,level:?string,font:?string,role:?string,marked:bool,exempt:?string}> $rows
     */
    public static function render( array $rows, bool $all ): string
    {
        $out  = "Type classification of the shipped recipes (wp-plugin/data/section-recipes.json)\n";
        $out .= "recipe | block#index | level | font | role | marked | exempt reason\n";
        $display = $title = $eyebrowHeadings = $eyebrowTexts = $exempt = $marked = $unclassified = $plainTexts = 0;
        $lines   = '';
        foreach ( $rows as $r ) {
            $short = substr( $r['block'], strlen( 'divi/' ) );
            $role  = $r['role'];
            if ( null !== $r['exempt'] ) {
                ++$exempt;
                $label = 'exempt';
            } elseif ( null === $role && 'text' === $short ) {
                ++$plainTexts;
                $label = '-';
            } elseif ( null === $role ) {
                ++$unclassified;
                $label = 'UNCLASSIFIED';
            } else {
                $label = $role;
                if ( 'display' === $role ) {
                    ++$display;
                } elseif ( 'title' === $role ) {
                    ++$title;
                } elseif ( 'heading' === $short ) {
                    ++$eyebrowHeadings;
                } else {
                    ++$eyebrowTexts;
                }
                if ( $r['marked'] ) {
                    ++$marked;
                }
            }
            if ( '-' === $label && ! $all ) {
                continue;
            }
            $lines .= sprintf( "  %s | %s#%d | %s | %s | %s | %s | %s\n", $r['recipe'], $short, $r['index'], $r['level'] ?? '-', $r['font'] ?? '-', $label, $r['marked'] ? 'MARKED' : '-', $r['exempt'] ?? '-' );
        }
        $out .= $lines;
        $classified = $display + $title + $eyebrowHeadings + $eyebrowTexts;
        $out .= "\nTotals\n";
        $out .= sprintf( "display %d, title %d, eyebrow %d (%d headings + %d texts), exempt %d\n", $display, $title, $eyebrowHeadings + $eyebrowTexts, $eyebrowHeadings, $eyebrowTexts, $exempt );
        $out .= sprintf( "marked %d of %d\n", $marked, $classified );
        if ( $all ) {
            $out .= sprintf( "unclassified texts (no label): %d\n", $plainTexts );
        }
        if ( $unclassified > 0 ) {
            $out .= sprintf( "UNCLASSIFIED headings or labels: %d (not exempt, not placed by the rule)\n", $unclassified );
        }

        return $out;
    }
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
    $file    = dirname( __DIR__ ) . '/wp-plugin/data/section-recipes.json';
    $recipes = json_decode( (string) file_get_contents( $file ), true );
    if ( ! is_array( $recipes ) ) {
        fwrite( STDERR, "Could not read $file.\n" );
        exit( 1 );
    }
    $all = in_array( '--all', $argv ?? [], true );
    try {
        $rows = TypeAudit::blocks( $recipes, $all );
    } catch ( \RuntimeException $e ) {
        fwrite( STDERR, $e->getMessage() . "\n" );
        exit( 1 );
    }
    echo TypeReport::render( $rows, $all );
    foreach ( $rows as $r ) {
        if ( null === $r['role'] && null === $r['exempt'] && 'divi/heading' === $r['block'] ) {
            exit( 1 );
        }
    }
}
