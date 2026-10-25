<?php
/**
 * Dev-only: migrates the 11 carrier blocks of the shipped section recipes to the style kit (carriers adapter, plan
 * 2026-10-24, Task 5). The ONLY way the carrier markers are written into wp-plugin/data/section-recipes.json.
 *
 *   php scripts/migrate-carrier-markers.php [--check]
 *
 * For every block tests/Support/CarrierAudit.php finds and that is not yet marked:
 *
 *   - the block's attribute JSON must re-encode byte-identically with plain json_encode (JSON_UNESCAPED_SLASHES |
 *     JSON_UNESCAPED_UNICODE), followed, when the original spells no raw `&`, by every `&` -> `\u0026` (ESCAPED_AMP; the four
 *     blurbs spell the glyph code `\u0026#x...;` and the paragraph `<p>` raw, so neither plain json_encode nor RecipeTokens::encodeJson
 *     round-trips them; plan Spec issue 7). A mismatch stops the script and nothing is written;
 *   - the literals the kit now writes are removed (DROP; an object is dropped only when it becomes empty): a divider's
 *     whole `divider.advanced.line`; a testimonial's `module.decoration.background` and `module.decoration.border`; a
 *     blurb's `imageIcon.decoration.background` and `imageIcon.advanced.color`; a counter's `module.decoration.border`.
 *     Everything else stays (the divider preset and alignSelf, the testimonial's overflow and layout, the blurb's padding,
 *     glyph size and glyph, the counter's padding, width, text orientation, title and number);
 *   - `"aied":{"kit":"<role>","on":"<band>"}` (the audit's role and band) is appended as the last top-level key; a result
 *     containing `--` is refused.
 *
 * Then the one prose fix (PROSE): stats-counter-band "each under a thin rule" -> "each under a rule" (Spec issue 11).
 * The file is written with JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE and a trailing newline (the
 * file round-trips byte-identically with these flags; checked before anything is changed). Idempotent: a second run changes
 * nothing. `--check` writes nothing and exits 1 when anything would change. No plugin class, no site, no network.
 */

declare(strict_types=1);

// CarrierAudit walks the bands with RecipeLibraryTest::markerBands (the shared walk), autoloaded from tests/ (dev only).
require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../tests/Support/CarrierAudit.php';

use Divi5Validator\Tests\Support\CarrierAudit;

final class CarrierMarkerMigration
{
    public const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    /** Block => the attribute paths (dotted) the kit now writes, each removed whole. */
    public const DROP = [
        'divi/divider'        => [ 'divider.advanced.line' ],
        'divi/testimonial'    => [ 'module.decoration.background', 'module.decoration.border' ],
        'divi/blurb'          => [ 'imageIcon.decoration.background', 'imageIcon.advanced.color' ],
        'divi/number-counter' => [ 'module.decoration.border' ],
    ];

    /** recipe => [field, old phrase, new phrase]: prose that promised a look the kit now decides (plan Task 5 Step 3). */
    public const PROSE = [
        'stats-counter-band' => [ 'description', 'each under a thin rule', 'each under a rule' ],
    ];

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** The JSON escape of `&` the blurbs spell (backslash, u, 0026), written by its byte codes so no editor turns it into `&`. */
    private const ESCAPED_AMP = "\x5Cu0026";

    /** The scanner CarrierAudit's walk agrees with (opening and closing comments; only openings are counted). */
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
        foreach ( CarrierAudit::blocks( (array) json_decode( $raw, true ) ) as $row ) {
            if ( ! $row['marked'] ) {
                $targets[ CarrierAudit::key( $row ) ] = [ (string) $row['role'], (string) $row['on'] ];
            }
        }
        $stats = [ 'migrated' => 0, 'dropped' => 0, 'emptied' => 0, 'reworded' => 0, 'restored' => 0 ];
        foreach ( $data as $recipe ) {
            $recipe->markup = self::migrateMarkup( (string) $recipe->name, (string) $recipe->markup, $targets, $stats );
            if ( isset( self::PROSE[ $recipe->name ] ) ) {
                [ $field, $old, $new ] = self::PROSE[ $recipe->name ];
                $text                  = (string) $recipe->{$field};
                if ( str_contains( $text, $old ) ) {
                    $recipe->{$field} = str_replace( $old, $new, $text );
                    ++$stats['reworded'];
                } elseif ( ! str_contains( $text, $new ) ) {
                    throw new RuntimeException( "{$recipe->name}.$field has neither \"$old\" nor \"$new\": stop" );
                }
            }
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
     * The attribute JSON in the block's own encoding: plain json_encode, then `&` -> `&` when the original spelled no
     * raw `&` (and so spelled every ampersand escaped).
     */
    private static function encodeAttrs( stdClass $attrs, bool $escapeAmp ): string
    {
        $json = json_encode( $attrs, self::FLAGS | JSON_THROW_ON_ERROR );

        return $escapeAmp ? str_replace( '&', self::ESCAPED_AMP, $json ) : $json;
    }

    /**
     * @param array<string,array{0:string,1:string}> $targets key => [role, on]; found keys are removed
     * @param array<string,int>                      $stats
     */
    private static function migrateMarkup( string $recipe, string $markup, array &$targets, array &$stats ): string
    {
        $seen = [];
        $out  = preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $recipe, &$seen, &$targets, &$stats ): string {
                if ( '/' === $m[1] ) {
                    return $m[0];
                }
                $block          = $m[2];
                $seen[ $block ] = ( $seen[ $block ] ?? -1 ) + 1;
                $key            = "$recipe|$block|{$seen[ $block ]}";
                if ( ! isset( $targets[ $key ] ) ) {
                    return $m[0];
                }
                [ $role, $on ] = $targets[ $key ];
                unset( $targets[ $key ] );
                if ( ! isset( self::DROP[ $block ] ) ) {
                    throw new RuntimeException( "$key: not a carrier this script migrates: stop" );
                }
                $json      = (string) ( $m[3] ?? '' );
                $attrs     = json_decode( $json );
                $escapeAmp = ! str_contains( $json, '&' );
                if ( ! $attrs instanceof stdClass ) {
                    throw new RuntimeException( "$key: the attributes are not a JSON object: stop" );
                }
                $plain = json_encode( $attrs, self::FLAGS );
                if ( $plain !== $json ) {
                    if ( ! $escapeAmp || self::encodeAttrs( $attrs, true ) !== $json ) {
                        throw new RuntimeException( "$key: the attributes do not round-trip with plain json_encode (nor with \\u0026 restored): stop" );
                    }
                    ++$stats['restored'];
                } else {
                    $escapeAmp = false; // round-trips plainly: no restore (an unescaped `&` would be spelled raw already)
                }
                foreach ( self::DROP[ $block ] as $path ) {
                    if ( self::drop( $attrs, explode( '.', $path ), $stats ) ) {
                        ++$stats['dropped'];
                    }
                }
                $attrs->aied = (object) [ 'kit' => $role, 'on' => $on ];
                $new         = self::encodeAttrs( $attrs, $escapeAmp );
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

    /**
     * Removes the property at $path of $node; then removes each parent object that became empty. True when something was
     * removed.
     *
     * @param list<string>      $path
     * @param array<string,int> $stats
     */
    private static function drop( stdClass $node, array $path, array &$stats ): bool
    {
        $key = array_shift( $path );
        if ( ! property_exists( $node, (string) $key ) ) {
            return false;
        }
        if ( [] === $path ) {
            unset( $node->{$key} );
            return true;
        }
        $child = $node->{$key};
        if ( ! $child instanceof stdClass || ! self::drop( $child, $path, $stats ) ) {
            return false;
        }
        if ( [] === get_object_vars( $child ) ) {
            unset( $node->{$key} );
            ++$stats['emptied'];
        }
        return true;
    }
}

if ( realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === realpath( __FILE__ ) ) {
    $check = in_array( '--check', array_slice( $argv, 1 ), true );
    try {
        $raw             = (string) file_get_contents( CarrierMarkerMigration::FILE );
        [ $new, $stats ] = CarrierMarkerMigration::run( $raw );
    } catch ( Throwable $e ) {
        fwrite( STDERR, 'migrate-carrier-markers: ' . $e->getMessage() . "\n" );
        exit( 2 );
    }
    $summary = sprintf( "%d blocks marked (%d with \\u0026 restored), %d literals dropped, %d emptied objects removed, %d prose fields reworded; %d -> %d bytes\n", $stats['migrated'], $stats['restored'], $stats['dropped'], $stats['emptied'], $stats['reworded'], strlen( $raw ), strlen( $new ) );
    if ( $new === $raw ) {
        echo "Nothing to change.\n";
        exit( 0 );
    }
    if ( $check ) {
        echo 'Would change: ' . $summary;
        exit( 1 );
    }
    file_put_contents( CarrierMarkerMigration::FILE, $new );
    echo 'Migrated: ' . $summary;
}
