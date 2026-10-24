<?php
/**
 * Dev-only: which sections of the shipped section recipes get a rhythm marker (rhythm adapter, plan 2026-10-24,
 * Task 4). The migration (Task 5) puts a marker on exactly the sections this report calls section, open or tight.
 *
 *   php scripts/rhythm-migration-report.php        (make rhythm-report)
 *
 * Reads wp-plugin/data/section-recipes.json raw (no plugin classes, no site, no network) through
 * tests/Support/RhythmAudit.php, which classifies every `divi/section` whose padding is `Npx` top = bottom (N > 0) at
 * desktop, tablet AND phone by the nearest role factor (section 1, open 1.25, tight 0.6) to its desktop value over 112 px;
 * a section that already carries `"aied":{"rhythm":...}` is classified by its marker, so the report is the same before and
 * after the migration (only the MARKED column and the padding column change).
 *
 * One line per section:
 *
 *   recipe | section#index | desktop/tablet/phone padding | role | MARKED or - | exempt reason or -
 *
 * A breakpoint shows its padding as written: one value when top equals bottom, `top~bottom` when they differ, `-` for
 * none. An `exempt` section (RhythmAudit::EXEMPTIONS) stays literal and shows `exempt` as its role. A section the rule
 * cannot place and that is not exempt is listed with role `UNCLASSIFIED` and makes the script exit 1. A malformed marker
 * is an error (exit 1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/Support/RhythmAudit.php';

use Divi5Validator\Tests\Support\RhythmAudit;

final class RhythmReport
{
    /**
     * @param list<array{recipe:string,index:int,desktop:?int,tablet:?int,phone:?int,role:?string,marked:bool,exempt:?string,raw:array{desktop:string,tablet:string,phone:string}}> $rows
     */
    public static function render( array $rows ): string
    {
        $out  = "Rhythm classification of the shipped recipes (wp-plugin/data/section-recipes.json)\n";
        $out .= "recipe | section#index | desktop/tablet/phone padding | role | marked | exempt reason\n";
        $roles = [ 'section' => 0, 'open' => 0, 'tight' => 0 ];
        $exempt = $marked = $unclassified = 0;
        $classes = [];
        $lines   = '';
        foreach ( $rows as $r ) {
            if ( null !== $r['exempt'] ) {
                ++$exempt;
                $class = RhythmAudit::reasonClass( $r['exempt'] );
                $classes[ $class ] = ( $classes[ $class ] ?? 0 ) + 1;
                $label = 'exempt';
            } elseif ( null === $r['role'] ) {
                ++$unclassified;
                $label = 'UNCLASSIFIED';
            } else {
                ++$roles[ $r['role'] ];
                $marked += $r['marked'] ? 1 : 0;
                $label   = $r['role'];
            }
            $lines .= sprintf(
                "  %s | section#%d | %s | %s | %s | %s\n",
                $r['recipe'],
                $r['index'],
                implode( '/', [ $r['raw']['desktop'], $r['raw']['tablet'], $r['raw']['phone'] ] ),
                $label,
                $r['marked'] ? 'MARKED' : '-',
                $r['exempt'] ?? '-'
            );
        }
        $out .= $lines;
        ksort( $classes );
        $byClass = [];
        foreach ( $classes as $class => $n ) {
            $byClass[] = "$class $n";
        }
        $classified = array_sum( $roles );
        $out .= "\nTotals\n";
        $out .= sprintf( "section %d, open %d, tight %d, exempt %d\n", $roles['section'], $roles['open'], $roles['tight'], $exempt );
        $out .= sprintf( "marked %d of %d\n", $marked, $classified );
        if ( [] !== $byClass ) {
            $out .= 'exempt by reason: ' . implode( ', ', $byClass ) . "\n";
        }
        if ( $unclassified > 0 ) {
            $out .= sprintf( "UNCLASSIFIED sections: %d (not exempt, not placed by the rule)\n", $unclassified );
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
    try {
        $rows = RhythmAudit::sections( $recipes );
    } catch ( \RuntimeException $e ) {
        fwrite( STDERR, $e->getMessage() . "\n" );
        exit( 1 );
    }
    echo RhythmReport::render( $rows );
    foreach ( $rows as $r ) {
        if ( null === $r['role'] && null === $r['exempt'] ) {
            exit( 1 );
        }
    }
}
