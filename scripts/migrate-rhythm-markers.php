<?php
/**
 * Dev-only: migrates the section padding of the shipped section recipes to the design language's rhythm (rhythm adapter,
 * plan 2026-10-24, Task 5). The ONLY way the rhythm markers are written into wp-plugin/data/section-recipes.json.
 *
 *   php scripts/migrate-rhythm-markers.php [--check]
 *
 * For every `divi/section` tests/Support/RhythmAudit.php classifies as section, open or tight (not exempt, not yet marked):
 *
 *   - the block's attribute JSON must re-encode byte-identically with plain json_encode (JSON_UNESCAPED_SLASHES |
 *     JSON_UNESCAPED_UNICODE); a mismatch stops the script and nothing is written;
 *   - `top`, `bottom` and `syncVertical` are removed from `module.decoration.spacing.<bp>.value.padding` at every
 *     breakpoint (Ruling 4: the language's patch brings them back), then every object that became empty (`padding`,
 *     `value`, the breakpoint, `spacing`); a classified section carries nothing else under `spacing` (RhythmAudit's rule),
 *     so its whole `spacing` object goes;
 *   - `"aied":{"rhythm":"<role>"}` is appended as the last top-level key; nothing else changes.
 *
 * No recipe prose is reworded: measured, no title, description or `when` of a classified recipe names a padding number,
 * and the only "tall padding" belongs to an `open` section (RhythmMigrationTest guards it).
 * The file is written with JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE and a trailing newline (the
 * file round-trips byte-identically with these flags; checked before anything is changed). Idempotent: a second run
 * changes nothing. `--check` writes nothing and exits 1 when anything would change. No plugin class, no site, no network.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/Support/RhythmAudit.php';

use Divi5Validator\Tests\Support\RhythmAudit;

final class RhythmMarkerMigration
{
    public const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    /** The padding keys the language decides (LanguageAdapters::rhythmPatch()). */
    public const DROP = [ 'top', 'bottom', 'syncVertical' ];

    private const BREAKPOINTS = [ 'desktop', 'tablet', 'phone' ];

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** The scanner RhythmAudit uses (opening and closing comments; only openings are counted), so the indices agree. */
    private const BLOCK = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s';

    /**
     * The migrated file content and a summary.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public static function run( string $raw ): array
    {
        $data = json_decode( $raw );
        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'section-recipes.json is not a JSON list' );
        }
        if ( self::encodeFile( $data ) !== $raw ) {
            throw new RuntimeException( 'section-recipes.json does not round-trip with the pretty-print flags: stop' );
        }
        $targets = [];
        foreach ( RhythmAudit::sections( (array) json_decode( $raw, true ) ) as $row ) {
            if ( null !== $row['role'] && null === $row['exempt'] && ! $row['marked'] ) {
                $targets[ RhythmAudit::key( $row ) ] = (string) $row['role'];
            }
        }
        $stats = [ 'migrated' => 0, 'dropped' => 0, 'emptied' => 0 ];
        foreach ( $data as $recipe ) {
            $recipe->markup = self::migrateMarkup( (string) $recipe->name, (string) $recipe->markup, $targets, $stats );
        }
        if ( [] !== $targets ) {
            throw new RuntimeException( 'targets not found in the markup: ' . implode( ', ', array_keys( $targets ) ) );
        }

        return [ self::encodeFile( $data ), $stats ];
    }

    /** @param list<mixed> $data */
    private static function encodeFile( array $data ): string
    {
        return json_encode( $data, JSON_PRETTY_PRINT | self::FLAGS | JSON_THROW_ON_ERROR ) . "\n";
    }

    /**
     * @param array<string,string> $targets  key => role; found keys are removed
     * @param array<string,int>    $stats
     */
    private static function migrateMarkup( string $recipe, string $markup, array &$targets, array &$stats ): string
    {
        $index = -1;
        $out   = preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $recipe, &$index, &$targets, &$stats ): string {
                if ( '/' === $m[1] || 'divi/section' !== $m[2] ) {
                    return $m[0];
                }
                ++$index;
                $key = "$recipe|divi/section|$index";
                if ( ! isset( $targets[ $key ] ) ) {
                    return $m[0];
                }
                $role = $targets[ $key ];
                unset( $targets[ $key ] );
                $json  = (string) ( $m[3] ?? '' );
                $attrs = json_decode( $json );
                if ( ! $attrs instanceof stdClass || json_encode( $attrs, self::FLAGS ) !== $json ) {
                    throw new RuntimeException( "$key: the attributes do not round-trip with plain json_encode: stop" );
                }
                $decoration = $attrs->module->decoration ?? null;
                $spacing    = $decoration instanceof stdClass ? ( $decoration->spacing ?? null ) : null;
                if ( ! $spacing instanceof stdClass ) {
                    throw new RuntimeException( "$key: a classified section without module.decoration.spacing: stop" );
                }
                foreach ( self::BREAKPOINTS as $bp ) {
                    $value   = $spacing->{$bp}->value ?? null;
                    $padding = $value instanceof stdClass ? ( $value->padding ?? null ) : null;
                    if ( ! $padding instanceof stdClass ) {
                        throw new RuntimeException( "$key: no padding at $bp: stop" );
                    }
                    foreach ( self::DROP as $drop ) {
                        if ( property_exists( $padding, $drop ) ) {
                            unset( $padding->{$drop} );
                            ++$stats['dropped'];
                        }
                    }
                    if ( [] === get_object_vars( $padding ) ) {
                        unset( $value->padding );
                        ++$stats['emptied'];
                    }
                    if ( [] === get_object_vars( $value ) ) {
                        unset( $spacing->{$bp}->value );
                        ++$stats['emptied'];
                    }
                    if ( [] === get_object_vars( $spacing->{$bp} ) ) {
                        unset( $spacing->{$bp} );
                        ++$stats['emptied'];
                    }
                }
                if ( [] === get_object_vars( $spacing ) ) {
                    unset( $decoration->spacing );
                    ++$stats['emptied'];
                }
                $attrs->aied = (object) [ 'rhythm' => $role ];
                $new         = json_encode( $attrs, self::FLAGS | JSON_THROW_ON_ERROR );
                if ( str_contains( $new, '--' ) ) {
                    throw new RuntimeException( "$key: the re-encoded attributes contain \"--\": stop" );
                }
                ++$stats['migrated'];

                return str_replace( $json, $new, $m[0] );
            },
            $markup
        );
        if ( ! is_string( $out ) ) {
            throw new RuntimeException( "$recipe: the block scanner failed" );
        }

        return $out;
    }
}

if ( realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === realpath( __FILE__ ) ) {
    $check = in_array( '--check', array_slice( $argv, 1 ), true );
    try {
        $raw             = (string) file_get_contents( RhythmMarkerMigration::FILE );
        [ $new, $stats ] = RhythmMarkerMigration::run( $raw );
    } catch ( Throwable $e ) {
        fwrite( STDERR, 'migrate-rhythm-markers: ' . $e->getMessage() . "\n" );
        exit( 2 );
    }
    $summary = sprintf( "%d sections marked, %d padding literals dropped, %d emptied objects removed; %d -> %d bytes\n", $stats['migrated'], $stats['dropped'], $stats['emptied'], strlen( $raw ), strlen( $new ) );
    if ( $new === $raw ) {
        echo "Nothing to change.\n";
        exit( 0 );
    }
    if ( $check ) {
        echo 'Would change: ' . $summary;
        exit( 1 );
    }
    file_put_contents( RhythmMarkerMigration::FILE, $new );
    echo 'Migrated: ' . $summary;
}
