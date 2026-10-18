<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\DesignVariety;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';

/**
 * Library-wide invariants of the recipe set that need no Docker (the live half is `make verify-recipes`): unique names,
 * valid tags, contrast pairs, pairwise-distinct layouts, no remote host, no token left under any language.
 *
 * Known gaps are frozen in fixtures/shapes/recipe-baseline.txt. The baseline may only SHRINK: an entry that no longer
 * applies must be removed, and a new gap is never accepted by adding to it.
 */
class RecipeLibraryTest extends TestCase
{
    private const BASELINE = __DIR__ . '/../fixtures/shapes/recipe-baseline.txt';

    /** Colour roles a `pairs` entry may name => key in a language's `colors`. inverse/overlay arrive with plan task 9. */
    private const ROLE_KEYS = [
        'bg'            => 'bg',
        'surface'       => 'surface',
        'surface-glass' => 'surface_glass',
        'text'          => 'text',
        'muted'         => 'muted',
        'accent'        => 'accent',
        'accent-text'   => 'accent_text',
        'border'        => 'border',
        'inverse'       => 'inverse',
        'overlay'       => 'overlay',
    ];

    /** Failure reasons verify-recipes.cjs reports (a render-failures baseline entry names one). */
    private const RENDER_CODES = [ 'php-error', 'empty', 'overflow', 'columns-side-by-side', 'console-error', 'gate' ];

    /** @return array{no-pairs:list<string>,duplicate-layouts:list<string>,render-failures:list<string>} */
    private function baseline(): array
    {
        $this->assertFileExists( self::BASELINE );
        $out     = [ 'no-pairs' => [], 'duplicate-layouts' => [], 'render-failures' => [] ];
        $section = null;
        foreach ( (array) file( self::BASELINE, FILE_IGNORE_NEW_LINES ) as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            if ( 1 === preg_match( '/^# (no-pairs|duplicate-layouts|render-failures)$/', $line, $m ) ) {
                $section = $m[1];
                continue;
            }
            if ( '#' === $line[0] ) {
                continue;
            }
            $this->assertNotNull( $section, "baseline line \"$line\" is outside a section" );
            $out[ $section ][] = $line;
        }
        return $out;
    }

    /** "r,g,b" or "r,g,b,a" of a hex or rgba() colour as [r,g,b,a], or null when unreadable. */
    private static function rgba( string $c ): ?array
    {
        $c = trim( $c );
        if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m ) ) {
            $h = $m[1];
            if ( 3 === strlen( $h ) ) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            return [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), 1.0 ];
        }
        if ( 1 === preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $c, $m ) ) {
            return [ (int) $m[1], (int) $m[2], (int) $m[3], isset( $m[4] ) ? (float) $m[4] : 1.0 ];
        }
        return null;
    }

    /** A language colour as an opaque hex: an rgba() colour is composited over the language's own `bg`. */
    private static function opaque( string $colour, string $bg ): ?string
    {
        $c = self::rgba( $colour );
        $b = self::rgba( $bg );
        if ( null === $c || null === $b ) {
            return null;
        }
        $mix = static fn ( int $f, int $g ): int => (int) round( $f * $c[3] + $g * ( 1 - $c[3] ) );
        return sprintf( '#%02x%02x%02x', $mix( $c[0], $b[0] ), $mix( $c[1], $b[1] ), $mix( $c[2], $b[2] ) );
    }

    /**
     * What is wrong with a recipe's `pairs` for ONE language ([] = fine). A pair is [foregroundRole, backgroundRole] with an
     * optional third element 'large' (large text: 3.0 instead of 4.5). A role the language does not define yet (inverse and
     * overlay until plan task 9) is reported in $skipped, never as a failure.
     *
     * @param mixed        $pairs
     * @param array<string,mixed> $lang
     * @param list<string> $skipped
     * @return list<string>
     */
    private static function pairProblems( $pairs, array $lang, array &$skipped ): array
    {
        if ( ! is_array( $pairs ) || [] === $pairs || ! array_is_list( $pairs ) ) {
            return [ 'pairs must be a non-empty list' ];
        }
        $colors = (array) $lang['colors'];
        $bg     = (string) $colors['bg'];
        $bad    = [];
        foreach ( $pairs as $i => $pair ) {
            if ( ! is_array( $pair ) || ! array_is_list( $pair ) || count( $pair ) < 2 || count( $pair ) > 3
                || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) || ( isset( $pair[2] ) && 'large' !== $pair[2] ) ) {
                $bad[] = "pair $i is not [foregroundRole, backgroundRole] or [foregroundRole, backgroundRole, 'large']";
                continue;
            }
            foreach ( [ $pair[0], $pair[1] ] as $role ) {
                if ( ! isset( self::ROLE_KEYS[ $role ] ) ) {
                    $bad[] = "pair $i names an unknown role \"$role\"";
                    continue 2;
                }
            }
            $fg = $colors[ self::ROLE_KEYS[ $pair[0] ] ] ?? null;
            $bk = $colors[ self::ROLE_KEYS[ $pair[1] ] ] ?? null;
            if ( ! is_string( $fg ) || ! is_string( $bk ) ) {
                $skipped[] = sprintf( '%s: pair %s/%s skipped (the language does not define %s yet)', (string) $lang['id'], $pair[0], $pair[1], is_string( $fg ) ? $pair[1] : $pair[0] );
                continue;
            }
            $f = self::opaque( $fg, $bg );
            $k = self::opaque( $bk, $bg );
            if ( null === $f || null === $k ) {
                $bad[] = "pair $i has an unreadable colour";
                continue;
            }
            $need  = isset( $pair[2] ) ? 3.0 : 4.5;
            $ratio = DesignLanguages::contrast( $f, $k );
            if ( $ratio < $need ) {
                $bad[] = sprintf( '%s on %s is %.2f:1, needs %.1f', $pair[0], $pair[1], $ratio, $need );
            }
        }
        return $bad;
    }

    private function wrap( string $markup ): string
    {
        return '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->';
    }

    public function testRecipeNamesAreUniqueKebabCase(): void
    {
        $names = SectionRecipes::names();
        $this->assertNotEmpty( $names );
        $this->assertSame( array_values( array_unique( $names ) ), $names, 'duplicate recipe names: ' . implode( ', ', array_diff_assoc( $names, array_unique( $names ) ) ) );
        foreach ( $names as $name ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $name );
        }
    }

    public function testTagsAreValidForEveryRecipe(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $tags = SectionRecipes::tags( $name );
            $this->assertIsArray( $tags, "$name has tags" );
            $this->assertContains( $tags['role'] ?? null, SectionRecipes::ROLES, "$name role" );
            $this->assertContains( $tags['layout'] ?? null, SectionRecipes::LAYOUTS, "$name layout" );
            $this->assertNotEmpty( $tags['mood'] ?? [], "$name mood" );
            $this->assertContains( $tags['density'] ?? null, [ 'airy', 'balanced', 'dense' ], "$name density" );
            $this->assertContains( $tags['background'] ?? null, [ 'solid', 'gradient', 'image', 'none' ], "$name background" );
            $this->assertIsBool( $tags['needs_images'] ?? null, "$name needs_images" );
        }
    }

    public function testPairsAreValidAndMeetContrastInEveryLanguage(): void
    {
        $skipped = [];
        foreach ( SectionRecipes::names() as $name ) {
            $tags = (array) SectionRecipes::tags( $name );
            if ( ! array_key_exists( 'pairs', $tags ) ) {
                continue;
            }
            foreach ( DesignLanguages::ids() as $id ) {
                $problems = self::pairProblems( $tags['pairs'], (array) DesignLanguages::get( $id ), $skipped );
                $this->assertSame( [], $problems, "$name under $id: " . implode( '; ', $problems ) );
            }
        }
        // No recipe declares `pairs` yet (plan task 11 adds them); the loop is then empty and the checker itself is proven by
        // testThePairCheckerCatchesWhatItShould.
        $this->addToAssertionCount( 1 );
        if ( [] !== $skipped ) {
            fwrite( STDERR, "\nRecipeLibraryTest: " . count( array_unique( $skipped ) ) . " pair check(s) skipped:\n  " . implode( "\n  ", array_slice( array_unique( $skipped ), 0, 10 ) ) . "\n" );
        }
    }

    public function testThePairCheckerCatchesWhatItShould(): void
    {
        $lang    = (array) DesignLanguages::get( 'editorial' );
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'text', 'surface' ], [ 'accent-text', 'accent' ] ], $lang, $skipped ) );
        $this->assertSame( [], $skipped );
        // muted on bg is a legitimate pair; border on bg is far below AA.
        $low = self::pairProblems( [ [ 'border', 'bg' ] ], $lang, $skipped );
        $this->assertCount( 1, $low );
        $this->assertStringContainsString( 'needs 4.5', $low[0] );
        // The same ratio passes as large text only when it reaches 3.0; a clearly failing pair still fails.
        $this->assertCount( 1, self::pairProblems( [ [ 'border', 'bg', 'large' ] ], $lang, $skipped ) );
        $this->assertNotSame( [], self::pairProblems( [ [ 'text', 'nonsense' ] ], $lang, $skipped ) );
        $this->assertNotSame( [], self::pairProblems( [], $lang, $skipped ) );
        $this->assertNotSame( [], self::pairProblems( 'text/surface', $lang, $skipped ) );
        $this->assertNotSame( [], self::pairProblems( [ [ 'text' ] ], $lang, $skipped ) );
        // A role the language does not define is skipped with a message, not failed.
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'inverse', 'overlay' ] ], $lang, $skipped ) );
        $this->assertCount( 1, $skipped );
        $this->assertStringContainsString( 'skipped', $skipped[0] );
        // A translucent card colour is judged over the language's own background.
        $glass = (array) DesignLanguages::get( 'glass-dark' );
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'text', 'surface-glass' ] ], $glass, $skipped ) );
        $this->assertSame( [], $skipped );
    }

    public function testRecipesWithoutPairsAreOnlyTheFrozenBaselineAndItOnlyShrinks(): void
    {
        $listed = $this->baseline()['no-pairs'];
        $names  = SectionRecipes::names();
        $actual = [];
        foreach ( $names as $name ) {
            if ( ! array_key_exists( 'pairs', (array) SectionRecipes::tags( $name ) ) ) {
                $actual[] = $name;
            }
        }
        $this->assertSame( [], array_values( array_diff( $actual, $listed ) ), 'recipes without `pairs` that are not in the baseline: declare `pairs` (the baseline never grows)' );
        foreach ( $listed as $name ) {
            $this->assertContains( $name, $names, "baseline lists \"$name\", which no longer exists: remove it" );
            $this->assertContains( $name, $actual, "baseline lists \"$name\", which now has `pairs`: remove it" );
        }
    }

    /** @return array<string,string> recipe name => fingerprint layout (default look) */
    private function layouts(): array
    {
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            $fps = DesignVariety::fingerprints( $this->wrap( (string) SectionRecipes::recipe( $name ) ) );
            $this->assertIsArray( $fps, "$name fingerprints" );
            $this->assertCount( 1, $fps, "$name is one section" );
            $out[ $name ] = $fps[0]['layout'];
        }
        return $out;
    }

    public function testFingerprintLayoutsArePairwiseDistinctExceptTheFrozenPairs(): void
    {
        $byLayout = [];
        foreach ( $this->layouts() as $name => $layout ) {
            $byLayout[ $layout ][] = $name;
        }
        $found = [];
        foreach ( $byLayout as $names ) {
            sort( $names );
            for ( $i = 0; $i < count( $names ); ++$i ) {
                for ( $j = $i + 1; $j < count( $names ); ++$j ) {
                    $found[] = $names[ $i ] . '|' . $names[ $j ];
                }
            }
        }
        $listed = $this->baseline()['duplicate-layouts'];
        $this->assertSame( [], array_values( array_diff( $found, $listed ) ), 'recipes with the same layout fingerprint (a new duplicate is never accepted)' );
        foreach ( $listed as $pair ) {
            $this->assertContains( $pair, $found, "baseline lists the duplicate \"$pair\", which no longer is one: remove it" );
        }
    }

    public function testNoRecipeHasARemoteHostOrAnUnresolvedTokenUnderAnyLanguage(): void
    {
        $this->assertNotEmpty( DesignLanguages::ids() );
        foreach ( array_merge( [ null ], DesignLanguages::ids() ) as $lang ) {
            foreach ( SectionRecipes::names() as $name ) {
                $label  = $name . ' under ' . ( $lang ?? 'the default look' );
                $markup = SectionRecipes::recipe( $name, $lang );
                $this->assertNotNull( $markup, $label );
                $this->assertStringNotContainsString( '{{aied:', $markup, "$label leaks a token" );
                $this->assertSame( [], RecipeTokens::found( $markup ), "$label leaks a token" );
                // The only host a served recipe may name is the plugin's own (the test shim's example.com); any other absolute URL is remote.
                preg_match_all( '#(?:https?:)?//([A-Za-z0-9.-]+)#', $markup, $m );
                foreach ( $m[1] as $host ) {
                    $this->assertSame( 'example.com', strtolower( $host ), "$label names the remote host $host" );
                }
            }
        }
    }

    public function testEveryRecipeValidatesUnderEveryLanguage(): void
    {
        foreach ( array_merge( [ null ], DesignLanguages::ids() ) as $lang ) {
            foreach ( SectionRecipes::names() as $name ) {
                $result = ( new Validator() )->validateContent( $this->wrap( (string) SectionRecipes::recipe( $name, $lang ) ) );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$name under " . ( $lang ?? 'default' ) . " must validate, got: $codes" );
            }
        }
    }

    public function testRenderFailureBaselineEntriesNameARealRecipeAndAKnownReason(): void
    {
        $names = SectionRecipes::names();
        foreach ( $this->baseline()['render-failures'] as $line ) {
            $parts = array_map( 'trim', explode( '|', $line, 3 ) );
            $this->assertCount( 3, $parts, "render-failures entry \"$line\" must be recipe|reason-code|explanation" );
            $this->assertContains( $parts[0], $names, "render-failures lists \"{$parts[0]}\", which is not a recipe: remove it" );
            $this->assertContains( $parts[1], self::RENDER_CODES, "render-failures reason code \"{$parts[1]}\" is unknown" );
            $this->assertNotSame( '', $parts[2], 'a render-failures entry needs a written reason' );
        }
    }

    public function testTheRenderAllowanceFileNamesRealRecipes(): void
    {
        $file = __DIR__ . '/../fixtures/shapes/recipe-render-allowances.json';
        $this->assertFileExists( $file );
        $data = json_decode( (string) file_get_contents( $file ), true );
        $this->assertIsArray( $data );
        foreach ( $data as $name => $reason ) {
            $this->assertContains( $name, SectionRecipes::names(), "allowance for \"$name\", which is not a recipe" );
            $this->assertIsString( $reason );
            $this->assertNotSame( '', trim( $reason ), "allowance for $name needs a reason" );
        }
    }
}
