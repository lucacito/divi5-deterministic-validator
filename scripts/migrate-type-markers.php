<?php
/**
 * Dev-only: migrates the headings and labels of the shipped section recipes to the design language's type (type adapter,
 * plan 2026-10-24, Task 5). The ONLY way the type markers are written into wp-plugin/data/section-recipes.json.
 *
 *   php scripts/migrate-type-markers.php [--check]
 *
 * For every block tests/Support/TypeAudit.php classifies as display, title or eyebrow (not exempt, not yet marked):
 *
 *   - the block's attribute JSON must re-encode byte-identically with plain json_encode (JSON_UNESCAPED_SLASHES |
 *     JSON_UNESCAPED_UNICODE); the library mixes raw `<p>` and `<` encodings across blocks, so RecipeTokens::encodeJson
 *     is NOT used (plan Spec issue 8); a mismatch stops the script and nothing is written;
 *   - `weight`, `letterSpacing` and `capitalization` are removed from every breakpoint and state of the block's font path
 *     (`title.decoration.font.font.<bp>.<state>` on a heading, `content.decoration.bodyFont.body.font.<bp>.<state>` on a
 *     text; Ruling 4), dropping a state or breakpoint object only when it becomes empty;
 *   - `"aied":{"type":"<role>"}` is appended as the last top-level key; nothing else changes.
 *
 * Then the recipe prose that names the old type look is reworded (PROSE: exact replacements in `title` / `description`).
 * The file is written with JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE and a trailing newline (the
 * file round-trips byte-identically with these flags; checked before anything is changed). Idempotent: a second run
 * changes nothing. `--check` writes nothing and exits 1 when anything would change. No plugin class, no site, no network.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/Support/TypeAudit.php';

use Divi5Validator\Tests\Support\TypeAudit;

final class TypeMarkerMigration
{
    public const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    /** Block => the font group whose breakpoints and states lose the three keys. */
    public const FONT_PATHS = [
        'divi/heading' => [ 'title', 'decoration', 'font', 'font' ],
        'divi/text'    => [ 'content', 'decoration', 'bodyFont', 'body', 'font' ],
    ];

    public const DROP = [ 'weight', 'letterSpacing', 'capitalization' ];

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** The scanner TypeAudit uses (opening and closing comments; only openings are counted), so the indices agree. */
    private const BLOCK = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s';

    /**
     * recipe => field => [old phrase, new phrase]: prose that promised a type look the language now decides (plan Task 5
     * Step 3; Spec issue 14). Applied once (the old phrase must be there, or the new one already is).
     */
    public const PROSE = [
        'hero-editorial-minimal'    => [ 'description', 'a small uppercase kicker', 'a small kicker' ],
        'hero-bordered-cells'       => [ 'description', 'a huge uppercase headline', 'a huge headline' ],
        'stats-big-captions'        => [ 'description', 'a small uppercase caption', 'a small caption' ],
        'testimonials-slider-quote' => [ 'description', 'each a small uppercase attribution', 'each a small attribution' ],
        'team-grid'                 => [ 'description', 'the role in small capitals', 'the role as a small label' ],
        'cta-band'                  => [ 'title', 'one bold line', 'one strong line' ],
    ];

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
        foreach ( TypeAudit::blocks( (array) json_decode( $raw, true ) ) as $row ) {
            if ( null !== $row['role'] && null === $row['exempt'] && ! $row['marked'] ) {
                $targets[ TypeAudit::key( $row ) ] = (string) $row['role'];
            }
        }
        $stats = [ 'migrated' => 0, 'dropped' => 0, 'emptied' => 0, 'reworded' => 0 ];
        foreach ( $data as $recipe ) {
            $recipe->markup = self::migrateMarkup( (string) $recipe->name, (string) $recipe->markup, $targets, $stats );
            if ( isset( self::PROSE[ $recipe->name ] ) ) {
                [ $field, $old, $new ] = self::PROSE[ $recipe->name ];
                $text = (string) $recipe->{$field};
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
     * @param array<string,string> $targets  key => role; found keys are removed
     * @param array<string,int>    $stats
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
                $role = $targets[ $key ];
                unset( $targets[ $key ] );
                $json  = (string) ( $m[3] ?? '' );
                $attrs = json_decode( $json );
                if ( ! $attrs instanceof stdClass || json_encode( $attrs, self::FLAGS ) !== $json ) {
                    throw new RuntimeException( "$key: the attributes do not round-trip with plain json_encode: stop" );
                }
                $group = $attrs;
                foreach ( self::FONT_PATHS[ $block ] as $k ) {
                    $group = $group instanceof stdClass && isset( $group->{$k} ) ? $group->{$k} : null;
                }
                if ( $group instanceof stdClass ) {
                    foreach ( get_object_vars( $group ) as $bp => $states ) {
                        if ( ! $states instanceof stdClass ) {
                            continue;
                        }
                        foreach ( get_object_vars( $states ) as $state => $value ) {
                            if ( ! $value instanceof stdClass ) {
                                continue;
                            }
                            foreach ( self::DROP as $drop ) {
                                if ( property_exists( $value, $drop ) ) {
                                    unset( $value->{$drop} );
                                    ++$stats['dropped'];
                                }
                            }
                            if ( [] === get_object_vars( $value ) ) {
                                unset( $states->{$state} );
                                ++$stats['emptied'];
                            }
                        }
                        if ( [] === get_object_vars( $states ) ) {
                            unset( $group->{$bp} );
                            ++$stats['emptied'];
                        }
                    }
                }
                $attrs->aied = (object) [ 'type' => $role ];
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
        $raw            = (string) file_get_contents( TypeMarkerMigration::FILE );
        [ $new, $stats ] = TypeMarkerMigration::run( $raw );
    } catch ( Throwable $e ) {
        fwrite( STDERR, 'migrate-type-markers: ' . $e->getMessage() . "\n" );
        exit( 2 );
    }
    $summary = sprintf( "%d blocks marked, %d type literals dropped, %d emptied objects removed, %d prose fields reworded; %d -> %d bytes\n", $stats['migrated'], $stats['dropped'], $stats['emptied'], $stats['reworded'], strlen( $raw ), strlen( $new ) );
    if ( $new === $raw ) {
        echo "Nothing to change.\n";
        exit( 0 );
    }
    if ( $check ) {
        echo 'Would change: ' . $summary;
        exit( 1 );
    }
    file_put_contents( TypeMarkerMigration::FILE, $new );
    echo 'Migrated: ' . $summary;
}
