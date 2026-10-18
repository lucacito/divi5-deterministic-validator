<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $typographyTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $typographyTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * The typography family topics (`typography`: fonts, shadows, text effects; `imagery`: image filters, fit, corners, borders, shadows,
 * overlay; `controls`: button, blurb, icon, number counter, divider): every list they teach is compared, as an exact set, with what the
 * probe rendered on the real Divi 5.15.1 (fixtures/shapes/candidates/typography.json + probe-results-typography.json), and every measured
 * claim is pinned to the result it came from (computed differences per width, changed properties, the sampled before/after values).
 */
class StyleGuideTypographyTopicTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;
    private const MIN_HEADROOM = 500;

    // ---- the evidence ----

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} candidates and probe results, both keyed by id */
    private function evidence(): array
    {
        $dir = __DIR__ . '/../fixtures/shapes/';
        $this->assertFileExists( $dir . 'candidates/typography.json', 'the candidates were mined' );
        $this->assertFileExists( $dir . 'probe-results-typography.json', 'the candidates were probed on the real Divi' );
        $this->assertFileExists( $dir . 'not-taught-typography.json', 'what was not measured is written down' );
        $candidates = json_decode( (string) file_get_contents( $dir . 'candidates/typography.json' ), true );
        $results    = json_decode( (string) file_get_contents( $dir . 'probe-results-typography.json' ), true );
        $this->assertIsArray( $candidates );
        $this->assertIsArray( $results );
        $c = [];
        foreach ( $candidates as $cand ) {
            $c[ $cand['id'] ] = $cand;
        }
        $r = [];
        foreach ( $results as $res ) {
            $r[ $res['id'] ] = $res;
        }
        $this->assertSame( array_keys( $c ), array_keys( $r ), 'every candidate has exactly one probe result, in order' );

        return [ $c, $r ];
    }

    /** A PASS: it changed the page by a measured computed-style difference, did not overflow a phone and did not error. */
    private static function passed( array $r ): bool
    {
        return ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] ) && empty( $r['cssOnly'] );
    }

    /** @return list<string> ids of the passed candidates whose id starts with $prefix */
    private function passedIds( string $prefix ): array
    {
        [ , $results ] = $this->evidence();
        $ids = [];
        foreach ( $results as $id => $r ) {
            if ( str_starts_with( $id, $prefix ) && self::passed( $r ) ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function normalise( string $path ): string
    {
        return implode( '.', array_map( static fn ( string $s ): string => in_array( $s, ShapePaths::BREAKPOINTS, true ) ? '{bp}' : $s, explode( '.', $path ) ) );
    }

    /** @return list<string> the normalised `set` paths of the passed candidates whose id starts with one of the prefixes, sorted and unique */
    private function provenPaths( array $idPrefixes ): array
    {
        [ $cands ] = $this->evidence();
        $paths = [];
        foreach ( $idPrefixes as $prefix ) {
            foreach ( $this->passedIds( $prefix ) as $id ) {
                foreach ( array_keys( $cands[ $id ]['set'] ) as $p ) {
                    $paths[ self::normalise( (string) $p ) ] = true;
                }
            }
        }
        $out = array_keys( $paths );
        sort( $out );

        return $out;
    }

    /** @return list<string> the first value (a list gives its first entry) the single-path passed candidates with the id prefix set at a path ending in `$leaf` */
    private function provenValues( string $idPrefix, string $leaf ): array
    {
        [ $cands ] = $this->evidence();
        $values = [];
        foreach ( $this->passedIds( $idPrefix ) as $id ) {
            foreach ( $cands[ $id ]['set'] as $p => $v ) {
                if ( str_ends_with( (string) $p, '.' . $leaf ) && 1 === count( $cands[ $id ]['set'] ) ) {
                    $values[ is_array( $v ) ? (string) $v[0] : (string) $v ] = true;
                }
            }
        }
        $out = array_map( 'strval', array_keys( $values ) );
        sort( $out );

        return $out;
    }

    /** @param list<string> $a @return list<string> */
    private function sortedCopy( array $a ): array
    {
        sort( $a );

        return $a;
    }

    // ---- the topic text ----

    private function topic( string $name ): string
    {
        return StyleGuide::markdown( $name );
    }

    /** @return string one `## ` section (heading line included) of a topic */
    private function section( string $heading, string $topic ): string
    {
        $text  = $this->topic( $topic );
        $start = strpos( $text, "\n## " . $heading );
        $this->assertNotFalse( $start, '## ' . $heading . ' exists' );
        $end = strpos( $text, "\n## ", $start + 1 );

        return substr( $text, $start, false === $end ? null : $end - $start );
    }

    private function flat( string $topic ): string
    {
        return preg_replace( '/\s+/', ' ', $this->topic( $topic ) ) ?? '';
    }

    /** @return list<string> the alternatives of the first `{a,b,c}` group (not `{bp}`), or the path itself */
    private static function expandBraces( string $token ): array
    {
        if ( preg_match_all( '/\{([A-Za-z0-9_,.]+)\}/', $token, $groups, PREG_SET_ORDER ) < 1 ) {
            return [ $token ];
        }
        foreach ( $groups as $g ) {
            if ( str_contains( $g[1], ',' ) ) {
                $out = [];
                foreach ( explode( ',', $g[1] ) as $alt ) {
                    $out = array_merge( $out, self::expandBraces( preg_replace( '/' . preg_quote( $g[0], '/' ) . '/', $alt, $token, 1 ) ?? $token ) );
                }

                return $out;
            }
        }

        return [ $token ];
    }

    /**
     * The settings a section lists, as full normalised paths. A bullet "- `x`" names a path (or `{a,b}` alternatives); when the
     * section names a prefix (the first backticked string that ends with a dot) every bullet is relative to it, otherwise a bullet is a full path.
     *
     * @return list<string> sorted, unique
     */
    private function taughtPaths( string $section ): array
    {
        $prefix = '';
        if ( 1 === preg_match( '/`([^`]+\.)`/', $section, $m ) ) {
            $prefix = $m[1];
        }
        preg_match_all( '/^- `([^`]+)`/m', $section, $bullets );
        $paths = [];
        foreach ( $bullets[1] as $token ) {
            foreach ( self::expandBraces( $prefix . $token ) as $p ) {
                $paths[] = $p;
            }
        }
        $this->assertSame( $paths, array_values( array_unique( $paths ) ), 'no setting listed twice' );
        sort( $paths );

        return $paths;
    }

    /** @return list<string> the exact tokens of "<label> N proven: a, b, c." in a section, sorted; N must match the list length */
    private function provenLine( string $section, string $label ): array
    {
        $this->assertSame( 1, preg_match( '/' . preg_quote( $label, '/' ) . ' (\d+) proven: ([A-Za-z0-9_, -]+)\./', $section, $m ), 'one exact line "' . $label . ' N proven: ..."' );
        $tokens = array_map( 'trim', explode( ',', $m[2] ) );
        $this->assertCount( (int) $m[1], $tokens, $label . ': the stated count matches the list' );
        $this->assertSame( $tokens, array_values( array_unique( $tokens ) ), $label . ': no token twice' );
        sort( $tokens );

        return $tokens;
    }

    /** @return list<string> the complete worked examples: fenced blocks that start with a Divi section */
    private function examples( string $topic ): array
    {
        preg_match_all( '/```\n(<!-- wp:divi\/section .*?)\n```/s', $this->topic( $topic ), $m );

        return $m[1];
    }

    // ---- claims about measurements ----

    /** @return array{1280:int,900:int,400:int} computed-style differences per width */
    private function diffs( string $id ): array
    {
        [ , $results ] = $this->evidence();
        $d = $results[ $id ]['detail'];

        return [ 1280 => (int) $d['1280']['computedDiffs'], 900 => (int) $d['900']['computedDiffs'], 400 => (int) $d['400']['computedDiffs'] ];
    }

    /** @return list<int> the widths (1280, 900, 400) at which the computed styles differed */
    private function widthsChanged( string $id ): array
    {
        return array_keys( array_filter( $this->diffs( $id ), static fn ( int $n ): bool => $n > 0 ) );
    }

    /** @return list<string> every property name that changed at any width */
    private function changedProps( string $id ): array
    {
        [ , $results ] = $this->evidence();
        $names = [];
        foreach ( [ '1280', '900', '400' ] as $w ) {
            foreach ( (array) ( $results[ $id ]['detail'][ $w ]['changedProps'] ?? [] ) as $n ) {
                $names[ (string) $n ] = true;
            }
        }

        return array_keys( $names );
    }

    /** @return list<string> the sampled "index:tag:property: before -> after" lines at a width */
    private function sample( string $id, int $width = 1280 ): array
    {
        [ , $results ] = $this->evidence();

        return array_map( 'strval', (array) ( $results[ $id ]['detail'][ (string) $width ]['sample'] ?? [] ) );
    }

    // ---- tests ----

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function topics(): array
    {
        return [
            'typography' => [ 'typography', '# Typography', 'layout-sizing' ],
            'imagery'    => [ 'imagery', '# Imagery', 'typography' ],
            'controls'   => [ 'controls', '# Controls', 'imagery' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'topics' )]
    public function testTopicIsListedAndServedWithinBudget( string $name, string $title, string $before ): void
    {
        $topics = StyleGuide::topics();
        $this->assertContains( $name, $topics );
        $this->assertSame( $before, $topics[ array_search( $name, $topics, true ) - 1 ], 'appended after ' . $before );
        $text = $this->topic( $name );
        $this->assertStringStartsWith( $title, $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET - self::MIN_HEADROOM, strlen( $text ), 'at least 500 bytes of headroom under 10,000 (lesson 20)' );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"|<script|@keyframes|\bvideo\b|backdrop/i', $text, 'no custom CSS, JS, video or backdrop blur is ever taught' );
        $flat = $this->flat( $name );
        $this->assertStringContainsString( 'rendered on the real Divi 5.15.1', $flat );
        $this->assertStringContainsString( 'front end', $flat, 'it says front end' );
        $this->assertStringContainsString( 'Visual Builder', $flat, 'it says the Visual Builder was not checked' );
        $this->assertStringContainsString( '(source)', $text, 'source-only rules are labelled' );
        $this->assertStringContainsString( '## Limits', $text );
        $this->assertSame( $text, $this->mcp( [ 'topic' => $name ] ), 'MCP serves the same text' );
        $rest = ( new RestController() )->style_guide( new \WP_REST_Request( [], [ 'topic' => $name ] ) );
        $this->assertSame( [ 'guide' => $text ], $rest->get_data(), 'REST serves the same text' );
    }

    // ---- every list the topics teach is the measured set ----

    public function testEverySettingTaughtIsAProvenPathAndNoProvenPathIsLeftOut(): void
    {
        $registry = ShapeRegistry::load();
        $groups   = [
            'Heading font'          => [ 'typography', [ 'ty-h-weight-', 'ty-h-style-', 'ty-h-cap-', 'ty-h-spacing-', 'ty-h-lineheight-', 'ty-h-family-', 'ty-h-textwrap-', 'ty-h-hyphens-' ] ],
            'Underline and lines'   => [ 'typography', [ 'ty-h-line-' ] ],
            'Text module body'      => [ 'typography', [ 'ty-tb-' ] ],
            'Text shadow'           => [ 'typography', [ 'ty-ts-preset', 'ty-ts-none', 'ty-ts-override-', 'ty-ts-phone-' ] ],
            'Text effects'          => [ 'typography', [ 'ty-te-' ] ],
            'Filters'               => [ 'imagery', [ 'ty-i-filter-', 'ty-i-blend-' ] ],
            'Fit'                   => [ 'imagery', [ 'ty-i-fit-', 'ty-i-position-' ] ],
            'Corners'               => [ 'imagery', [ 'ty-i-radius-' ] ],
            'Border'                => [ 'imagery', [ 'ty-i-border-' ] ],
            'Shadow'                => [ 'imagery', [ 'ty-i-shadow-' ] ],
            'Hover overlay'         => [ 'imagery', [ 'ty-i-overlay-on', 'ty-i-overlay-needs', 'ty-i-overlay-color' ] ],
            'Overlay icon'          => [ 'imagery', [ 'ty-i-overlay-icon-color', 'ty-i-overlay-hover-icon' ] ],
            'Button icon'           => [ 'controls', [ 'ty-b-icon-' ] ],
            'Button label, box and position' => [ 'controls', [ 'ty-b-font-', 'ty-b-padding', 'ty-b-radius', 'ty-b-border', 'ty-b-shadow', 'ty-b-align-' ] ],
            'Blurb icon'            => [ 'controls', [ 'ty-bl-icon', 'ty-bl-placement-' ] ],
            'Icon module'           => [ 'controls', [ 'ty-ic-' ] ],
            'Number counter'        => [ 'controls', [ 'ty-nc-' ] ],
            'Divider line'          => [ 'controls', [ 'ty-dv-' ] ],
        ];
        foreach ( $groups as $heading => [ $topic, $prefixes ] ) {
            $proven = $this->provenPaths( $prefixes );
            // `radius.sync` is proven but is not taught as a setting (it copies nothing); it is pinned below.
            $proven = array_values( array_filter( $proven, static fn ( string $p ): bool => ! str_ends_with( $p, '.radius.sync' ) ) );
            $this->assertNotSame( [], $proven, $heading . ' has proven paths' );
            $this->assertSame( $proven, $this->taughtPaths( $this->section( $heading, $topic ) ), $heading . ': taught settings == probe-proven paths (exact set)' );
            foreach ( $proven as $path ) {
                $this->assertTrue( $registry->covers( $path ), $path . ' is in the shape registry' );
            }
        }
        // Text module heading levels and the body shadow are written as prose lines, pinned here.
        $this->assertSame(
            [ 'content.decoration.headingFont.h1.font.{bp}.value.weight', 'content.decoration.headingFont.h2.font.{bp}.value.capitalization', 'content.decoration.headingFont.h2.font.{bp}.value.family', 'content.decoration.headingFont.h2.font.{bp}.value.letterSpacing', 'content.decoration.headingFont.h2.font.{bp}.value.lineHeight', 'content.decoration.headingFont.h2.font.{bp}.value.style', 'content.decoration.headingFont.h2.font.{bp}.value.weight', 'content.decoration.headingFont.h3.font.{bp}.value.capitalization', 'content.decoration.headingFont.h3.font.{bp}.value.weight', 'content.decoration.headingFont.h4.font.{bp}.value.weight', 'content.decoration.headingFont.h5.font.{bp}.value.weight', 'content.decoration.headingFont.h6.font.{bp}.value.weight' ],
            $this->provenPaths( [ 'ty-th-' ] )
        );
        $this->assertSame( [ 'content.decoration.bodyFont.body.textShadow.{bp}.value.style' ], $this->provenPaths( [ 'ty-ts-body-' ] ) );
        $this->assertSame( [ 'image.decoration.border.{bp}.value.radius.sync', 'image.decoration.border.{bp}.value.radius.topLeft' ], $this->provenPaths( [ 'ty-i-radius-sync-only' ] ) );
        $this->assertStringContainsString( '`sync` "on" with `topLeft` alone did the same (rendered), so `sync` does not copy a value to the other corners', $this->flat( 'imagery' ) );
    }

    public function testEveryEnumeratedValueListIsTheMeasuredSet(): void
    {
        $font = $this->section( 'Heading font', 'typography' );
        $this->assertSame( $this->provenValues( 'ty-h-weight-', 'weight' ), $this->provenLine( $font, 'Weights' ), 'heading weights' );
        $this->assertSame( $this->provenValues( 'ty-h-style-', 'style' ), $this->provenLine( $font, 'Style' ), 'font style list entries' );
        $this->assertSame( $this->provenValues( 'ty-h-cap-', 'capitalization' ), $this->provenLine( $font, 'Capitalization' ), 'capitalization' );
        $this->assertSame( $this->provenValues( 'ty-h-family-', 'family' ), $this->provenLine( $font, 'Families' ), 'font families' );
        $this->assertSame( $this->provenValues( 'ty-h-line-style-', 'lineStyle' ), $this->provenLine( $this->section( 'Underline and lines', 'typography' ), 'Line styles' ), 'line styles' );
        $this->assertSame( $this->provenValues( 'ty-ts-preset', 'style' ), $this->provenLine( $this->section( 'Text shadow', 'typography' ), 'Presets' ), 'text shadow presets' );

        [ $cands ] = $this->evidence();
        $fills = [];
        foreach ( $this->passedIds( 'ty-te-' ) as $id ) {
            $v = $cands[ $id ]['set']['title.decoration.font.textEffects.desktop.value.fillType'] ?? null;
            if ( null !== $v ) {
                $fills[ (string) $v ] = true;
            }
        }
        $this->assertSame( $this->sortedCopy( array_keys( $fills ) ), $this->provenLine( $this->section( 'Text effects', 'typography' ), 'Fill types' ), 'fill types' );

        $levels = [];
        foreach ( $this->passedIds( 'ty-th-h' ) as $id ) {
            if ( 1 === preg_match( '/^ty-th-(h[1-6])-weight$/', $id, $m ) ) {
                $levels[] = $m[1];
            }
        }
        $headings = $this->section( 'Text module headings', 'typography' );
        $this->assertSame( $this->sortedCopy( $levels ), $this->provenLine( $headings, 'Levels' ), 'heading levels' );
        $h2 = [];
        foreach ( $this->passedIds( 'ty-th-h2-' ) as $id ) {
            $h2[] = substr( (string) array_key_first( $cands[ $id ]['set'] ), strlen( 'content.decoration.headingFont.h2.font.desktop.value.' ) );
        }
        $this->assertSame( $this->sortedCopy( $h2 ), $this->provenLine( $headings, 'Keys on h2' ), 'the keys measured on h2' );
        $this->assertStringContainsString( 'On h3: capitalization.', $headings );
        $this->assertSame( [ 'content.decoration.headingFont.h3.font.desktop.value.capitalization' ], array_keys( $cands['ty-th-h3-capitalization']['set'] ) );

        $imagery = $this->section( 'Fit', 'imagery' );
        $this->assertSame( $this->provenValues( 'ty-i-fit-', 'objectFit' ), $this->provenLine( $imagery, 'Fit values' ), 'object-fit values' );
        // A preset counts as proven only where the probe SAW a box-shadow change; inner presets 6 and 7 changed the page structure instead.
        [ , $results ] = $this->evidence();
        $seen = [];
        foreach ( $this->passedIds( 'ty-i-shadow-preset' ) as $id ) {
            if ( in_array( 'box-shadow', $this->changedProps( $id ), true ) ) {
                $seen[] = (string) $cands[ $id ]['set']['image.decoration.boxShadow.desktop.value.style'];
            }
        }
        $this->assertSame( [ 'preset1', 'preset2', 'preset3', 'preset4', 'preset5' ], $this->sortedCopy( $seen ), 'presets with a measured box-shadow' );
        $this->assertSame( $this->sortedCopy( $seen ), $this->provenLine( $this->section( 'Shadow', 'imagery' ), 'Presets' ), 'image shadow presets' );
        foreach ( [ 'ty-i-shadow-preset6', 'ty-i-shadow-preset7' ] as $id ) {
            $this->assertNotContains( 'box-shadow', $this->changedProps( $id ), $id . ': the probe never saw a shadow' );
            $this->assertGreaterThan( 1000, $this->diffs( $id )[1280], $id . ': the page structure changed' );
            $this->assertStringContainsString( 'undefined', implode( ' ', $this->sample( $id ) ), $id . ': an element stopped being what it was' );
        }
        $this->assertStringContainsString( 'Preset6 and preset7 are inner shadows (source); Divi adds an extra element for an inner shadow on an image module (source: ElementClassnames.php 96-100), and the probe saw the page structure change, not a shadow, so they are not proven on an image.', $this->flat( 'imagery' ) );
        $this->assertSame( $this->provenValues( 'ty-i-border-', 'all.style' ), $this->provenLine( $this->section( 'Border', 'imagery' ), 'Styles' ), 'image border styles' );
        $this->assertSame( $this->provenValues( 'ty-dv-style-', 'style' ), $this->provenLine( $this->section( 'Divider line', 'controls' ), 'Styles' ), 'divider styles' );
        $this->assertSame( $this->provenValues( 'ty-dv-position-', 'position' ), $this->provenLine( $this->section( 'Divider line', 'controls' ), 'Positions' ), 'divider positions' );
    }

    public function testFontFamiliesNamedExistInDivisOwnList(): void
    {
        $fixture = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/fonts-5.15.1.json' ), true );
        $this->assertIsArray( $fixture );
        $known = array_keys( $fixture['families'] );
        $named = $this->provenLine( $this->section( 'Heading font', 'typography' ), 'Families' );
        foreach ( $named as $family ) {
            $this->assertContains( $family, $known, $family . ' is in Divi 5.15.1 google-fonts.json (fixtures/shapes/fonts-5.15.1.json)' );
        }
        $this->assertSame( $this->sortedCopy( $known ), $named, 'the fixture holds exactly the families the topic names' );
        // Every family the typography topics name in quotes (examples included) is one of them.
        preg_match_all( '/"(?:family|Family)":"([^"]+)"|family "([^"]+)"/', $this->topic( 'typography' ) . $this->topic( 'controls' ), $m );
        foreach ( array_filter( array_merge( $m[1], $m[2] ) ) as $family ) {
            $this->assertContains( $family, $known, $family );
        }
        // Cross-check with the real list when the (untracked) Divi zip is present.
        $zip = __DIR__ . '/../divi/Divi-5-15-1.zip';
        if ( class_exists( \ZipArchive::class ) && is_readable( $zip ) ) {
            $za = new \ZipArchive();
            if ( true === $za->open( $zip ) ) {
                $json = $za->getFromName( 'Divi/core/json-data/google-fonts.json' );
                $za->close();
                $this->assertIsString( $json );
                $items = json_decode( $json, true )['items'] ?? [];
                $have  = [];
                foreach ( $items as $item ) {
                    $have[ (string) $item['family'] ] = $item;
                }
                foreach ( $fixture['families'] as $family => $info ) {
                    $this->assertArrayHasKey( $family, $have, $family . ' is in the zip' );
                    $this->assertSame( $have[ $family ]['variants'], $info['variants'], $family . ': the fixture copies the zip' );
                }
            }
        }
    }

    // ---- preconditions, dead shapes and css-only results ----

    public function testDeadCssOnlyAndOverflowingCandidatesAreExactlyWhatTheTopicsSay(): void
    {
        [ $cands, $results ] = $this->evidence();
        $dead = [];
        $css  = [];
        $over = [];
        foreach ( $results as $id => $r ) {
            if ( ! empty( $r['phoneOverflow'] ) ) {
                $over[] = $id;
            } elseif ( empty( $r['effect'] ) ) {
                $dead[] = $id;
            } elseif ( ! empty( $r['cssOnly'] ) ) {
                $css[] = $id;
            }
        }
        $this->assertSame(
            $this->sortedCopy( [
                'ty-h-style-uppercase', 'ty-h-line-style-needs-line', 'ty-ts-needs-style', 'ty-ts-phone-none', 'ty-te-stroke-position-default', 'ty-te-gradient-needs-fill',
                'ty-i-shadow-needs-style', 'ty-i-overlay-needs-link', 'ty-b-icon-enable-on', 'ty-b-icon-enable-nested', 'ty-b-icon-off', 'ty-b-font-style-uppercase', 'ty-b-padding-element',
                'ty-b-shadow', 'ty-bl-placement-top', 'ty-bl-icon-size-width', 'ty-ic-align-center', 'ty-nc-percent-on', 'ty-nc-align-center', 'ty-dv-position-top', 'ty-dv-show-on',
            ] ),
            $this->sortedCopy( $dead ),
            'the DEAD candidates are exactly the 21 the topics call dead (or default)'
        );
        $this->assertSame( $this->sortedCopy( [ 'ty-h-lineheight-1em', 'ty-i-fit-fill' ] ), $this->sortedCopy( $css ), 'the css-only candidates' );
        $this->assertSame( [], $over, 'no candidate made a phone page wider than the screen' );
        $this->assertCount( 183, $cands );
        // an expectation and a source on every candidate (lesson 14), and the outcome equals it.
        foreach ( $cands as $id => $c ) {
            $this->assertContains( $c['expect'] ?? null, [ 'PASS', 'DEAD', 'OVERFLOW', 'PASS(css-only)' ], $id . ' carries an expectation' );
            $this->assertStringContainsString( 'includes/builder-5', (string) ( $c['note'] ?? '' ), $id . ' cites the Divi source file' );
            $r       = $results[ $id ];
            $verdict = ! empty( $r['phoneOverflow'] ) ? 'OVERFLOW' : ( empty( $r['effect'] ) ? 'DEAD' : ( ! empty( $r['cssOnly'] ) ? 'PASS(css-only)' : 'PASS' ) );
            $this->assertSame( $c['expect'], $verdict, $id . ': measured outcome equals the expectation' );
            $this->assertArrayNotHasKey( 'error', $r, $id . ' did not error' );
        }
        // Dead preconditions come in pairs with a passing counterpart.
        $this->assertContains( 'ty-h-line-style-dashed', $this->passedIds( 'ty-h-line-' ) );
        $this->assertContains( 'ty-ts-override-color', $this->passedIds( 'ty-ts-' ) );
        $this->assertContains( 'ty-te-gradient', $this->passedIds( 'ty-te-' ) );
        $this->assertContains( 'ty-i-shadow-color', $this->passedIds( 'ty-i-shadow-' ) );
        $this->assertContains( 'ty-i-overlay-on', $this->passedIds( 'ty-i-overlay-' ) );

        $typo = $this->flat( 'typography' );
        $img  = $this->flat( 'imagery' );
        $ctl  = $this->flat( 'controls' );
        foreach ( [
            'A `style` list holding "uppercase", the shape the default guide shows, changed nothing on a heading or on a button label (rendered: dead). Use `capitalization`.',
            '`lineStyle` with no line in `style` changed nothing (rendered: dead)',
            '`color`, `vertical` and `blur` set without any `style` changed nothing (rendered: dead; `horizontal` was not tried without a `style`). `style` "none" removed a preset2 shadow.',
            'a phone `style` "none" does not remove a desktop shadow (rendered: dead, the desktop rule still applies)',
            '"stroke-fill" is what a stroke wider than 1px already has (source: TextEffects.php 278-286; rendered: dead)',
            'the same gradient without `fillType` changed nothing (rendered: dead)',
            '"1em" is a heading\'s own and changed only the stylesheet',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $typo );
        }
        foreach ( [
            '"fill" is the browser default and changed only the stylesheet (rendered: css-only)',
            '`horizontal`, `vertical`, `blur` and `color` set without a `style` changed nothing (rendered: dead; `spread` was not tried without one), so always set `style`.',
            '`use` "on" with no link changed nothing (rendered: dead)',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $img );
        }
        foreach ( [
            '`icon.enable` "on" is the default and changed nothing (rendered: dead)',
            'An `enable` written beside `icon` (`value.enable`) changed nothing, on or off (rendered: dead), so write it inside `icon`.',
            'A label `style` list with "uppercase" in it changed nothing (rendered: dead; use `capitalization`)',
            'written on the `button` element (`button.decoration.spacing`, `button.decoration.boxShadow`) changed nothing (rendered: dead); write them on the module.',
            '("top" is the default and changed nothing (rendered: dead))',
            'The icon size is `iconFontSize`: the sizing `width` of the icon changed nothing (rendered: dead).',
            'align "center" is the default and changed nothing (rendered: dead)',
            '"on" is the default and changed nothing (rendered: dead). `orientation` "left" aligned the module\'s text left; "center" is the default and changed nothing (rendered: dead)',
            'Position "top" is the default and changed nothing (rendered: dead)',
            'The `show` switch could not be measured (source only).',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $ctl );
        }
        $this->assertSame( [ 'color', 'vertical', 'blur' ], array_map( static fn ( string $p ): string => substr( $p, strrpos( $p, '.' ) + 1 ), array_keys( $cands['ty-ts-needs-style']['set'] ) ), 'the keys tried without a style' );
        $this->assertSame( [ 'horizontal', 'vertical', 'blur', 'color' ], array_map( static fn ( string $p ): string => substr( $p, strrpos( $p, '.' ) + 1 ), array_keys( $cands['ty-i-shadow-needs-style']['set'] ) ), 'the image keys tried without a style' );
        // Lesson 18: what was measured is said, not the visual inference.
        $this->assertStringContainsString( 'changed the computed text fill colour to transparent, so the text may be invisible unless a stroke is set', $typo );
        $this->assertStringContainsString( 'set an image background clipped to the text', $typo );
        $this->assertStringContainsString( 'Divi reads no grayscale key (source: Filters.php:117-135)', $img );
        $this->assertStringNotContainsString( 'a circle', $img );
        $this->assertStringContainsString( '`icon.enable` "off" changed the `::after` display from block to none.', $ctl );
        $this->assertStringContainsString( 'by default the icon appears on hover', $ctl );
        $this->assertSame( [ '0:a:::after:opacity: 0 -> 1' ], $this->sample( 'ty-b-icon-always' ), 'the default icon is hidden until hover' );
        $this->assertStringContainsString( 'radius "999px" (a pill: a strong value)', $ctl );
        $this->assertStringContainsString( 'only the title `weight` and the body `lineHeight` were rendered on a blurb; the others are not measured there.', $ctl );
        $this->assertStringContainsString( 'a blurb title took `weight` only, see `controls`', $typo );
        $this->assertStringNotContainsString( 'uses the same keys', $typo );
        // Lesson 4: the topics that measured desktop values only say so.
        $this->assertStringContainsString( 'Desktop values only: no tablet or phone override was rendered for any key here.', $img );
        $this->assertStringContainsString( 'Desktop values only: no tablet or phone override was rendered for any key here.', $ctl );
        $this->assertStringContainsString( 'Tablet and phone overrides were rendered only for `letterSpacing`, the zero text shadow and `columnCount`', $typo );
        foreach ( $cands as $id => $c ) {
            foreach ( array_keys( $c['set'] ) as $path ) {
                if ( ! str_starts_with( $id, 'ty-h-spacing-' ) && ! str_starts_with( $id, 'ty-ts-phone-' ) && ! str_starts_with( $id, 'ty-tb-columns-phone' ) ) {
                    $this->assertDoesNotMatchRegularExpression( '/\.(tablet|phone)\./', (string) $path, $id . ' sets no device override outside the three the typography topic names' );
                }
            }
        }
        // The dead results those sentences name come from these candidates (lesson 21).
        $this->assertSame( [ 'style' ], array_map( static fn ( string $p ): string => substr( $p, strrpos( $p, '.' ) + 1 ), array_keys( $cands['ty-h-style-uppercase']['set'] ) ) );
        $this->assertSame( [ 'uppercase' ], $cands['ty-h-style-uppercase']['set']['title.decoration.font.font.desktop.value.style'] );
        $this->assertSame( [ 'uppercase' ], $cands['ty-b-font-style-uppercase']['set']['button.decoration.font.font.desktop.value.style'] );
        $this->assertSame( 'uppercase', $cands['ty-h-cap-uppercase']['set']['title.decoration.font.font.desktop.value.capitalization'] );
        $this->assertContains( 'text-transform', $this->changedProps( 'ty-h-cap-uppercase' ) );
        $this->assertArrayNotHasKey( 'style', $cands['ty-ts-needs-style']['set'] );
        $this->assertSame( [ 'color', 'vertical', 'blur' ], array_map( static fn ( string $p ): string => substr( $p, strrpos( $p, '.' ) + 1 ), array_keys( $cands['ty-ts-needs-style']['set'] ) ) );
        $this->assertSame( 'none', $cands['ty-ts-phone-none']['set']['title.decoration.font.textShadow.phone.value.style'] );
        $this->assertSame( 'preset1', $cands['ty-ts-phone-none']['base']['title']['decoration']['font']['textShadow']['desktop']['value']['style'] );
        $this->assertArrayNotHasKey( 'linkUrl', $cands['ty-i-overlay-needs-link']['base']['image']['innerContent']['desktop']['value'] ?? [] );
        $this->assertSame( '#', $cands['ty-i-overlay-on']['base']['image']['innerContent']['desktop']['value']['linkUrl'] );
        $this->assertSame( 'hover', $cands['ty-i-overlay-on']['state'] );
        $this->assertArrayNotHasKey( 'enable', $cands['ty-b-icon-default-on']['set'] );
        $this->assertArrayNotHasKey( 'base', $cands['ty-b-icon-default-on'], 'no icon settings in the base of the default-icon candidate' );
        $this->assertSame( 'off', $cands['ty-b-icon-off']['set']['button.decoration.button.desktop.value.enable'] );
        $this->assertSame( 'off', $cands['ty-b-icon-off-nested']['set']['button.decoration.button.desktop.value.icon.enable'] );
        $this->assertContains( '0:a:::after:display: block -> none', $this->sample( 'ty-b-icon-off-nested' ) );
        $this->assertSame( 'iconFontSize', substr( (string) array_key_first( $cands['ty-bl-icon-size']['set'] ), -12 ) );
        $this->assertSame( 'width', substr( (string) array_key_first( $cands['ty-bl-icon-size-width']['set'] ), -5 ) );
        $this->assertArrayHasKey( 'module.decoration.spacing.desktop.value.padding.top', $cands['ty-b-padding']['set'] );
        $this->assertArrayHasKey( 'button.decoration.spacing.desktop.value.padding.top', $cands['ty-b-padding-element']['set'] );
        $this->assertArrayHasKey( 'module.decoration.boxShadow.desktop.value.style', $cands['ty-b-shadow-module']['set'] );
        $this->assertArrayHasKey( 'button.decoration.boxShadow.desktop.value.style', $cands['ty-b-shadow']['set'] );
        $this->assertSame( 'off', $cands['ty-nc-percent-off']['set']['number.advanced.enablePercentSign.desktop.value'] );
        $this->assertTrue( true === $cands['ty-nc-percent-off']['text'] );
        $this->assertSame( [ 'text:innerText' ], $this->changedProps( 'ty-nc-percent-off' ) );
        $this->assertSame( 'left', $cands['ty-nc-align-left']['set']['module.advanced.text.text.desktop.value.orientation'] );
        $this->assertSame( 'center', $cands['ty-nc-align-center']['set']['module.advanced.text.text.desktop.value.orientation'] );
        $this->assertSame( 'top', $cands['ty-dv-position-top']['set']['divider.advanced.line.desktop.value.position'] );
        $this->assertSame( 'center', $cands['ty-ic-align-center']['set']['icon.advanced.align.desktop.value'] );
        $this->assertSame( 'top', $cands['ty-bl-placement-top']['set']['imageIcon.advanced.placement.desktop.value'] );
    }

    // ---- claims that name a width, a size or a value: pinned to the measurement ----

    public function testResponsiveClaimsAreMeasuredAtTheWidthsTheyNameAndNoWider(): void
    {
        [ $cands ] = $this->evidence();
        $this->assertSame( [ 400 ], $this->widthsChanged( 'ty-h-spacing-phone-reset' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'ty-h-spacing-tablet-reset' ) );
        $this->assertSame( '4px', $cands['ty-h-spacing-phone-reset']['base']['title']['decoration']['font']['font']['desktop']['value']['letterSpacing'] );
        $this->assertSame( '0px', $cands['ty-h-spacing-phone-reset']['set']['title.decoration.font.font.phone.value.letterSpacing'] );
        $this->assertSame( '0px', $cands['ty-h-spacing-tablet-reset']['set']['title.decoration.font.font.tablet.value.letterSpacing'] );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'ty-ts-phone-zero' ) );
        $this->assertSame( [ '0em', '0em', '0em' ], array_values( $cands['ty-ts-phone-zero']['set'] ) );
        $this->assertSame( [ 1280, 900, 400 ], $this->widthsChanged( 'ty-tb-columns' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'ty-tb-columns-phone-one' ) );
        $this->assertSame( '1', $cands['ty-tb-columns-phone-one']['set']['content.decoration.bodyFont.body.font.phone.value.columnCount'] );
        $this->assertSame( '2', $cands['ty-tb-columns']['set']['content.decoration.bodyFont.body.font.desktop.value.columnCount'] );
        $this->assertSame( '48px', $cands['ty-tb-columns']['set']['content.decoration.bodyFont.body.font.desktop.value.columnGap'] );
        $this->assertContains( 'column-count', $this->changedProps( 'ty-tb-columns' ) );
        $this->assertContains( 'column-gap', $this->changedProps( 'ty-tb-columns' ) );
        $typo = $this->flat( 'typography' );
        foreach ( [
            'Responsive: `letterSpacing` "4px" on desktop with "0px" on the phone changed 400 px only; "0px" on the tablet changed 900 and 400 px.',
            '`columnCount` "2" with `columnGap` "48px" (set together) printed two columns at 1280, 900 and 400 px; a phone `columnCount` "1" changed 400 px only. `columnGap` alone was not rendered.',
            'Set `horizontal`, `vertical` and `blur` to "0em" on the phone instead (rendered: 400 px only).',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $typo );
        }
        // the cases without a tablet/phone sentence say nothing about devices beyond the three rendered widths
        $this->assertStringNotContainsString( 'tablet and phone', $typo );
    }

    public function testEachEffectChangedTheComputedPropertyTheTopicNames(): void
    {
        [ $cands ] = $this->evidence();
        // default heading weight 500, one computed difference in the heading tag
        $this->assertSame( [ 1, 1, 1 ], array_values( $this->diffs( 'ty-h-weight-300' ) ) );
        $this->assertSame( [ '2:h1:font-weight: 500 -> 300' ], $this->sample( 'ty-h-weight-300' ) );
        $this->assertContains( 'font-style', $this->changedProps( 'ty-h-style-italic' ) );
        $this->assertContains( 'text-decoration-line', $this->changedProps( 'ty-h-style-underline' ) );
        $this->assertContains( 'text-decoration-line', $this->changedProps( 'ty-h-style-strikethrough' ) );
        $this->assertContains( 'text-decoration-line', $this->changedProps( 'ty-h-style-overline' ) );
        $this->assertContains( 'font-variant-caps', $this->changedProps( 'ty-h-cap-smallCaps' ) );
        $this->assertContains( 'font-variant-caps', $this->changedProps( 'ty-h-cap-allSmallCaps' ) );
        $this->assertSame( [ 'letter-spacing' ], $this->changedProps( 'ty-h-spacing-2px' ) );
        $this->assertSame( [ '2:h1:letter-spacing: normal -> 3px' ], $this->sample( 'ty-h-spacing-em' ), '0.1em at the heading size of 30px' );
        $this->assertContains( 'line-height', $this->changedProps( 'ty-h-lineheight-1-6em' ) );
        $this->assertSame( [ 0, 0, 0 ], array_values( $this->diffs( 'ty-h-lineheight-1em' ) ), '1em is the heading default: no computed difference' );
        $this->assertContains( 'text-wrap-style', $this->changedProps( 'ty-h-textwrap-balance' ) );
        $this->assertContains( 'hyphens', $this->changedProps( 'ty-h-hyphens-on' ) );
        foreach ( [ 'dashed', 'dotted', 'double', 'wavy' ] as $ls ) {
            $this->assertContains( 'text-decoration-style', $this->changedProps( 'ty-h-line-style-' . $ls ) );
        }
        $this->assertContains( 'text-decoration-color', $this->changedProps( 'ty-h-line-color' ) );
        $this->assertContains( 'text-decoration-thickness', $this->changedProps( 'ty-h-line-thickness' ) );
        $this->assertSame( [ 'text-underline-offset' ], $this->changedProps( 'ty-h-line-offset' ) );
        $this->assertSame( [ 'underline' ], $cands['ty-h-line-color']['base']['title']['decoration']['font']['font']['desktop']['value']['style'] );
        // each family named is what the computed font-family printed
        $this->assertStringContainsString( 'font-family: Abel -> "Playfair Display"', implode( ' ', $this->sample( 'ty-h-family-playfair-display' ) ) );
        // heading levels: one computed difference, in the tag of that level
        for ( $n = 1; $n <= 6; $n++ ) {
            $this->assertSame( [ 1, 1, 1 ], array_values( $this->diffs( 'ty-th-h' . $n . '-weight' ) ) );
            $this->assertMatchesRegularExpression( '/^\d+:h' . $n . ':font-weight: 500 -> 300$/', $this->sample( 'ty-th-h' . $n . '-weight' )[0] );
            $this->assertCount( 1, $this->sample( 'ty-th-h' . $n . '-weight' ) );
        }
        $this->assertMatchesRegularExpression( '/^\d+:h2:text-transform: none -> uppercase$/', $this->sample( 'ty-th-h2-capitalization' )[0] );
        $this->assertMatchesRegularExpression( '/^\d+:h3:text-transform: none -> uppercase$/', $this->sample( 'ty-th-h3-capitalization' )[0] );
        // text shadow
        foreach ( [ 'ty-ts-preset1', 'ty-ts-preset2', 'ty-ts-preset3', 'ty-ts-preset4', 'ty-ts-preset5', 'ty-ts-none', 'ty-ts-override-color', 'ty-ts-override-vertical', 'ty-ts-override-horizontal', 'ty-ts-override-blur', 'ty-ts-body-preset1' ] as $id ) {
            $this->assertContains( 'text-shadow', $this->changedProps( $id ), $id );
        }
        $this->assertSame( '2:h1:text-shadow: rgba(0, 0, 0, 0.4) 2.4px 2.4px 2.4px -> none', $this->sample( 'ty-ts-none' )[0], 'style none removed the preset2 shadow' );
        $this->assertSame( 'preset2', $cands['ty-ts-none']['base']['title']['decoration']['font']['textShadow']['desktop']['value']['style'] );
        foreach ( [ 'color' => 'rgb(220, 38, 38) 0px 3px 3px', 'vertical' => '0px 6px 3px', 'horizontal' => '3px 3px 3px', 'blur' => '0px 3px 12px' ] as $key => $after ) {
            $this->assertStringEndsWith( $after, $this->sample( 'ty-ts-override-' . $key )[0], $key . ' over preset1' );
        }
        $this->assertSame( '#dc2626', $cands['ty-ts-override-color']['set']['title.decoration.font.textShadow.desktop.value.color'] );
        $this->assertSame( '0.4em', $cands['ty-ts-override-blur']['set']['title.decoration.font.textShadow.desktop.value.blur'] );
        // text effects
        $this->assertContains( '-webkit-text-stroke-width', $this->changedProps( 'ty-te-stroke' ) );
        $this->assertSame( [ 'paint-order' ], $this->changedProps( 'ty-te-stroke-position' ) );
        $this->assertSame( '2:h1:paint-order: stroke -> fill', $this->sample( 'ty-te-stroke-position' )[0] );
        $this->assertSame( 'fill-stroke', $cands['ty-te-stroke-position']['set']['title.decoration.font.textEffects.desktop.value.strokePosition'] );
        $this->assertSame( 'stroke-fill', $cands['ty-te-stroke-position-default']['set']['title.decoration.font.textEffects.desktop.value.strokePosition'] );
        $this->assertSame( [ '-webkit-text-fill-color' ], $this->changedProps( 'ty-te-fill-transparent' ) );
        $this->assertSame( '2:h1:-webkit-text-fill-color: rgb(51, 51, 51) -> rgba(0, 0, 0, 0)', $this->sample( 'ty-te-fill-transparent' )[0] );
        $this->assertContains( 'background-clip', $this->changedProps( 'ty-te-gradient' ) );
        $this->assertContains( 'background-image', $this->changedProps( 'ty-te-image-fill' ) );
        $this->assertCount( 2, $cands['ty-te-gradient']['set']['title.decoration.font.textEffects.desktop.value.gradient.stops'] );
        $this->assertSame( [ '0', '100' ], array_column( $cands['ty-te-gradient']['set']['title.decoration.font.textEffects.desktop.value.gradient.stops'], 'position' ) );
        $this->assertSame( [ '-webkit-text-fill-color' => true ], array_fill_keys( array_intersect( [ '-webkit-text-fill-color' ], $this->changedProps( 'ty-te-outline' ) ), true ) );
        // text module body
        $this->assertContains( 'font-weight', $this->changedProps( 'ty-tb-weight-300' ) );
        $this->assertContains( 'text-transform', $this->changedProps( 'ty-tb-uppercase' ) );
        $this->assertContains( 'text-wrap-style', $this->changedProps( 'ty-tb-textwrap' ) );
        $this->assertContains( 'hyphens', $this->changedProps( 'ty-tb-hyphens' ) );
    }

    public function testImageryClaimsAreMeasured(): void
    {
        [ $cands ] = $this->evidence();
        $this->assertSame( [ 'filter' ], $this->changedProps( 'ty-i-filter-saturate-0' ) );
        $this->assertSame( [ '0:div:filter: none -> saturate(0)' ], $this->sample( 'ty-i-filter-saturate-0' ) );
        $this->assertSame( '0%', $cands['ty-i-filter-saturate-0']['set']['module.decoration.filters.desktop.value.saturate'] );
        foreach ( [ 'brightness' => 'brightness(1.3)', 'contrast' => 'contrast(1.3)', 'sepia' => 'sepia(1)', 'invert' => 'invert(1)', 'hue' => 'hue-rotate(90deg)', 'blur' => 'blur(4px)', 'opacity' => 'opacity(0.6)' ] as $key => $after ) {
            $this->assertSame( [ '0:div:filter: none -> ' . $after ], $this->sample( 'ty-i-filter-' . $key ), $key );
        }
        $this->assertSame( [ '0:div:mix-blend-mode: normal -> multiply' ], $this->sample( 'ty-i-blend-multiply' ) );
        // fit: measured in a 1:1 box, on the img
        $this->assertSame( '1', $cands['ty-i-fit-cover']['base']['module']['advanced']['sizing']['desktop']['value']['aspectRatio']['width'] );
        $this->assertSame( '1', $cands['ty-i-fit-cover']['base']['module']['advanced']['sizing']['desktop']['value']['aspectRatio']['height'] );
        $this->assertSame( [ '2:img:object-fit: fill -> cover' ], $this->sample( 'ty-i-fit-cover' ) );
        $this->assertSame( [ '2:img:object-position: 50% 50% -> 0% 0%' ], $this->sample( 'ty-i-position-left-top' ) );
        $this->assertSame( [ '2:img:object-position: 50% 50% -> 100% 100%' ], $this->sample( 'ty-i-position-right-bottom' ) );
        $this->assertSame( 'cover', $cands['ty-i-position-left-top']['base']['image']['decoration']['fit']['desktop']['value']['objectFit'] );
        // corners
        foreach ( [ 'ty-i-radius-16', 'ty-i-radius-pill' ] as $id ) {
            $this->assertCount( 4, $cands[ $id ]['set'] );
        }
        $this->assertSame( [ '16px', '16px', '16px', '16px' ], array_values( $cands['ty-i-radius-16']['set'] ) );
        $this->assertSame( [ '50%', '50%', '50%', '50%' ], array_values( $cands['ty-i-radius-pill']['set'] ) );
        $this->assertContains( 'border-top-left-radius', $this->changedProps( 'ty-i-radius-one-corner' ) );
        $this->assertNotContains( 'border-top-right-radius', $this->changedProps( 'ty-i-radius-one-corner' ) );
        $this->assertNotContains( 'border-top-right-radius', $this->changedProps( 'ty-i-radius-sync-only' ) );
        $this->assertContains( 'overflow-x', $this->changedProps( 'ty-i-radius-one-corner' ) );
        $this->assertContains( '1:span:overflow-x: visible -> hidden', $this->sample( 'ty-i-radius-one-corner' ) );
        $this->assertSame( 'on', $cands['ty-i-radius-sync-only']['set']['image.decoration.border.desktop.value.radius.sync'] );
        // border
        $this->assertContains( '1:span:border-block-end-color: rgb(102, 102, 102) -> rgb(51, 51, 51)', $this->sample( 'ty-i-border-width-only' ) );
        $this->assertSame( [ 'image.decoration.border.desktop.value.styles.all.width' ], array_keys( $cands['ty-i-border-width-only']['set'] ) );
        $this->assertSame( '4px', $cands['ty-i-border-solid']['set']['image.decoration.border.desktop.value.styles.all.width'] );
        $this->assertSame( '#1e3a8a', $cands['ty-i-border-solid']['set']['image.decoration.border.desktop.value.styles.all.color'] );
        $this->assertSame( 'solid', $cands['ty-i-border-solid']['set']['image.decoration.border.desktop.value.styles.all.style'] );
        $this->assertSame( [ '4px', '#1e3a8a' ], array_values( $cands['ty-i-border-dashed']['base']['image']['decoration']['border']['desktop']['value']['styles']['all'] ) );
        $this->assertContains( 'border-bottom-width', $this->changedProps( 'ty-i-border-bottom' ) );
        $this->assertNotContains( 'border-top-width', $this->changedProps( 'ty-i-border-bottom' ) );
        // shadow: on the wrapper span, not the picture
        $this->assertStringStartsWith( '1:span:box-shadow: none -> rgba(0, 0, 0, 0.3) 0px 2px 18px 0px', $this->sample( 'ty-i-shadow-preset1' )[0] );
        $this->assertSame( 'preset1', $cands['ty-i-shadow-color']['base']['image']['decoration']['boxShadow']['desktop']['value']['style'] );
        $this->assertSame( 'rgba(30,58,138,0.35)', $cands['ty-i-shadow-color']['set']['image.decoration.boxShadow.desktop.value.color'] );
        $this->assertSame( '40px', $cands['ty-i-shadow-blur']['set']['image.decoration.boxShadow.desktop.value.blur'] );
        $this->assertSame( '24px', $cands['ty-i-shadow-vertical']['set']['image.decoration.boxShadow.desktop.value.vertical'] );
        $this->assertSame( '8px', $cands['ty-i-shadow-spread']['set']['image.decoration.boxShadow.desktop.value.spread'] );
        $this->assertGreaterThan( 100, $this->diffs( 'ty-i-shadow-preset6' )[1280], 'an inner shadow changed the page structure, not one property' );
        // overlay
        $this->assertSame( 'rgba(30,58,138,0.6)', $cands['ty-i-overlay-color']['set']['image.advanced.overlay.desktop.value.backgroundColor'] );
        $this->assertSame( [ 'background-color' ], $this->changedProps( 'ty-i-overlay-color' ) );
        $this->assertSame( [ '::before:content', '::before:font-family', '::before:font-weight' ], $this->changedProps( 'ty-i-overlay-hover-icon' ) );
        $this->assertSame( [ '&#xf002;', 'fa', '900' ], array_values( $cands['ty-i-overlay-hover-icon']['set'] ) );
        $this->assertSame( '#ffffff', $cands['ty-i-overlay-icon-color']['set']['image.advanced.overlayIcon.desktop.value.iconColor'] );
        $this->assertContains( '::before:-webkit-text-fill-color', $this->changedProps( 'ty-i-overlay-icon-color' ) );
    }

    public function testControlsClaimsAreMeasured(): void
    {
        [ $cands ] = $this->evidence();
        // button icon: pseudo-elements compared; defaults
        foreach ( [ 'ty-b-icon-on', 'ty-b-icon-placement-left', 'ty-b-icon-always', 'ty-b-icon-color', 'ty-b-icon-default-on', 'ty-b-icon-off-nested' ] as $id ) {
            $this->assertSame( [ '::before', '::after' ], $cands[ $id ]['pseudo'], $id . ' compares the pseudo-elements' );
        }
        $this->assertContains( '::after:content', $this->changedProps( 'ty-b-icon-on' ) );
        $this->assertContains( '0:a:::after:content: "$" -> ""', $this->sample( 'ty-b-icon-on' ) );
        $this->assertSame( [ '&#xf054;', 'fa', '900' ], array_values( $cands['ty-b-icon-on']['set'] ) );
        $this->assertSame( [ '0:a:::after:opacity: 0 -> 1' ], $this->sample( 'ty-b-icon-always' ) );
        $this->assertSame( 'off', $cands['ty-b-icon-always']['set']['button.decoration.button.desktop.value.icon.onHover'] );
        $this->assertSame( 'left', $cands['ty-b-icon-placement-left']['set']['button.decoration.button.desktop.value.icon.placement'] );
        $this->assertSame( '#f59e0b', $cands['ty-b-icon-color']['set']['button.decoration.button.desktop.value.icon.color'] );
        $this->assertContains( '::before:border-block-end-color', $this->changedProps( 'ty-b-icon-default-on' ), 'a button with no icon settings still has an icon to restyle' );
        // button label, box
        foreach ( [ 'ty-b-font-weight' => 'font-weight', 'ty-b-font-spacing' => 'letter-spacing', 'ty-b-font-cap-uppercase' => 'text-transform', 'ty-b-font-family' => 'font-family', 'ty-b-padding' => 'padding-top', 'ty-b-radius' => 'border-top-left-radius', 'ty-b-border' => 'border-top-width', 'ty-b-shadow-module' => 'box-shadow', 'ty-b-align-center' => 'text-align', 'ty-b-align-right' => 'text-align' ] as $id => $prop ) {
            $this->assertContains( $prop, $this->changedProps( $id ), $id );
        }
        $this->assertSame( [ '16px', '40px' ], [ $cands['ty-b-padding']['set']['module.decoration.spacing.desktop.value.padding.top'], $cands['ty-b-padding']['set']['module.decoration.spacing.desktop.value.padding.left'] ] );
        $this->assertSame( '999px', $cands['ty-b-radius']['set']['button.decoration.border.desktop.value.radius.topLeft'] );
        $this->assertSame( [ '2px', '#1e3a8a', 'solid' ], array_values( $cands['ty-b-border']['set'] ) );
        $this->assertSame( 'preset1', $cands['ty-b-shadow-module']['set']['module.decoration.boxShadow.desktop.value.style'] );
        $this->assertSame( [ '0:a:text-align: start -> center' ], $this->sample( 'ty-b-align-center' ) );
        // blurb
        $this->assertSame( [ 'on', '&#xf054;', 'fa', '900' ], array_values( $cands['ty-bl-icon-on']['set'] ) );
        $this->assertSame( '64px', $cands['ty-bl-icon-size']['set']['imageIcon.decoration.sizing.desktop.value.iconFontSize'] );
        $this->assertContains( 'font-size', $this->changedProps( 'ty-bl-icon-size' ) );
        $this->assertSame( '#dc2626', $cands['ty-bl-icon-color']['set']['imageIcon.advanced.color.desktop.value'] );
        $this->assertSame( '#e0e7ff', $cands['ty-bl-icon-background']['set']['imageIcon.decoration.background.desktop.value.color'] );
        $this->assertSame( [ 'left', 'right' ], [ $cands['ty-bl-placement-left']['set']['imageIcon.advanced.placement.desktop.value'], $cands['ty-bl-placement-right']['set']['imageIcon.advanced.placement.desktop.value'] ] );
        $this->assertSame( [ '16px', '16px', '16px', '16px' ], array_values( $cands['ty-bl-icon-padding']['set'] ) );
        $this->assertSame( [ '3:h4:font-weight: 500 -> 800' ], $this->sample( 'ty-bl-title-weight' ) );
        $this->assertContains( 'line-height', $this->changedProps( 'ty-bl-body-lineheight' ) );
        $this->assertSame( 'on', $cands['ty-bl-icon-color']['base']['imageIcon']['innerContent']['desktop']['value']['useIcon'] );
        // icon module
        foreach ( [ 'left', 'right' ] as $a ) {
            $this->assertContains( 'justify-content', $this->changedProps( 'ty-ic-align-' . $a ) );
        }
        $this->assertSame( '64px', $cands['ty-ic-size']['set']['icon.advanced.size.desktop.value'] );
        $this->assertSame( '#dc2626', $cands['ty-ic-color']['set']['icon.advanced.color.desktop.value'] );
        // number counter
        $this->assertSame( [ '2:p:font-size: 72px -> 48px', '3:span:font-size: 72px -> 48px', '4:span:font-size: 72px -> 48px' ], $this->sample( 'ty-nc-number-size' ), 'its own size is 72px' );
        $this->assertSame( '48px', $cands['ty-nc-number-size']['set']['number.decoration.font.font.desktop.value.size'] );
        $this->assertContains( 'font-weight', $this->changedProps( 'ty-nc-number-weight' ) );
        $this->assertContains( 'font-family', $this->changedProps( 'ty-nc-number-family' ) );
        $this->assertMatchesRegularExpression( '/^\d+:h3:text-transform: none -> uppercase$/', $this->sample( 'ty-nc-title-upper' )[0] );
        $this->assertMatchesRegularExpression( '/^\d+:h3:letter-spacing: normal -> 2px$/', $this->sample( 'ty-nc-title-spacing' )[0] );
        $this->assertContains( 'text-align', $this->changedProps( 'ty-nc-align-left' ) );
        $this->assertStringContainsString( 'text:innerText: 42%', $this->sample( 'ty-nc-percent-off' )[0] );
        $this->assertStringContainsString( '-> 42', $this->sample( 'ty-nc-percent-off' )[0] );
        // divider: the line is on ::before
        foreach ( [ 'ty-dv-color', 'ty-dv-weight', 'ty-dv-style-dashed', 'ty-dv-style-dotted', 'ty-dv-style-double', 'ty-dv-position-center', 'ty-dv-position-bottom' ] as $id ) {
            $this->assertSame( [ '::before' ], $cands[ $id ]['pseudo'], $id );
            foreach ( $this->changedProps( $id ) as $prop ) {
                $this->assertStringStartsWith( '::before:', $prop, $id . ': only the pseudo-element changed' );
            }
        }
        $this->assertContains( '::before:border-top-style', $this->changedProps( 'ty-dv-style-double' ) );
        $this->assertContains( '::before:border-top-width', $this->changedProps( 'ty-dv-weight' ) );
        $this->assertContains( '::before:border-top-color', $this->changedProps( 'ty-dv-color' ) );
        $this->assertArrayNotHasKey( 'ty-dv-show-off', $cands, 'the show switch could not be measured and is not a candidate' );
        $this->assertContains( 'show', array_map( static fn ( array $i ): string => preg_match( '/`show`/', $i['item'] ) ? 'show' : '', json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/not-taught-typography.json' ), true ) ) );
    }

    public function testTheProbeExtensionsTheCandidatesUseAreDocumented(): void
    {
        $header = (string) file_get_contents( __DIR__ . '/../scripts/verify-shapes.cjs' );
        [ $cands ] = $this->evidence();
        foreach ( [ 'pseudo', 'text' ] as $field ) {
            $this->assertStringContainsString( ' *   ' . $field . ' ', $header, 'the probe header documents ' . $field );
            $used = array_filter( $cands, static fn ( array $c ): bool => isset( $c[ $field ] ) );
            $this->assertNotSame( [], $used, $field . ' is used by a candidate' );
        }
        $promote = (string) file_get_contents( __DIR__ . '/../scripts/promote-shapes.php' );
        $this->assertStringContainsString( "\$c['pseudo']", $promote );
        $this->assertStringContainsString( "\$c['text']", $promote );
    }

    // ---- worked examples ----

    /** @return array<string,array{0:string,1:int,2:list<string>}> */
    public static function exampleTopics(): array
    {
        return [
            'typography' => [ 'typography', 1, [ 'capitalization', 'letterSpacing', 'lineHeight', 'family', 'textWrap', 'bodyFont' ] ],
            'imagery'    => [ 'imagery', 2, [ 'radius', 'boxShadow', 'fit', 'overlay', 'overlayIcon', 'aspectRatio' ] ],
            'controls'   => [ 'controls', 1, [ 'enablePercentSign', 'line', 'icon', 'padding', 'radius' ] ],
        ];
    }

    /**
     * @param list<string> $kinds every kind of setting that the examples of the topic must together use
     */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'exampleTopics' )]
    public function testWorkedExamplesValidateAndUseOnlyProvenShapes( string $topic, int $minimum, array $kinds ): void
    {
        $examples = $this->examples( $topic );
        $this->assertGreaterThanOrEqual( $minimum, count( $examples ) );
        $registry = ShapeRegistry::load();
        $used     = [];
        foreach ( $examples as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "example $i passes the plugin gate" );
            $this->assertStringNotContainsString( 'http', $example );
            $this->assertStringNotContainsString( '{{aied:', $example );
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "example $i uses an unregistered path $path" );
                }
            }
            foreach ( $kinds as $kind ) {
                if ( str_contains( $example, '"' . $kind . '":' ) ) {
                    $used[ $kind ] = true;
                }
            }
        }
        $this->assertSame( $this->sortedCopy( $kinds ), $this->sortedCopy( array_keys( $used ) ), 'the examples together use exactly the kinds ' . implode( ', ', $kinds ) );
    }

    /** An AI copies the numbers it is shown: the examples use gentle values (lesson 13). */
    public function testExamplesUseGentleValues(): void
    {
        $all = implode( "\n", array_merge( $this->examples( 'typography' ), $this->examples( 'imagery' ), $this->examples( 'controls' ) ) );
        $this->assertStringNotContainsString( '"blur":"40px"', $all, 'the strong 40px shadow blur is not in an example' );
        $this->assertStringNotContainsString( '"letterSpacing":"4px"', $all );
        $this->assertStringNotContainsString( '999px', $all, 'the pill radius is not in an example' );
        $this->assertStringNotContainsString( '"fillType"', $all, 'gradient, transparent and image text are not in an example' );
        $this->assertStringContainsString( '"letterSpacing":"2px"', $all );
        $this->assertStringContainsString( '"style":"preset1"', $all );
        foreach ( [ 'typography', 'imagery', 'controls' ] as $topic ) {
            $this->assertStringContainsString( 'the worked example', preg_replace( '/\s+/', ' ', $this->topic( $topic ) ) ?? '' );
        }
    }

    private function mcp( array $args ): string
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_style_guide', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();

        return (string) ( $data['result']['content'][0]['text'] ?? '' );
    }
}
