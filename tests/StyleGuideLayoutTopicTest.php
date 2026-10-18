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
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $layoutTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $layoutTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * The layout topics (`layout-advanced`: overlap, stacking, order, hiding, transforms; `layout-sizing`: sizing, overflow, flex, grid,
 * spacing): every list they teach is compared, as an exact set, with what the probe rendered on the real Divi 5.15.1
 * (fixtures/shapes/candidates/layout.json + probe-results-layout.json), and every measured claim is pinned to the result it came from.
 */
class StyleGuideLayoutTopicTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;

    // ---- the evidence ----

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} candidates and probe results, both keyed by id */
    private function evidence(): array
    {
        $dir = __DIR__ . '/../fixtures/shapes/';
        $this->assertFileExists( $dir . 'candidates/layout.json', 'the candidates were mined' );
        $this->assertFileExists( $dir . 'probe-results-layout.json', 'the candidates were probed on the real Divi' );
        $this->assertFileExists( $dir . 'not-taught-layout.json', 'what was not measured is written down' );
        $candidates = json_decode( (string) file_get_contents( $dir . 'candidates/layout.json' ), true );
        $results    = json_decode( (string) file_get_contents( $dir . 'probe-results-layout.json' ), true );
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

    /**
     * @return list<string> the values the single-path passed candidates with the given id prefix set at a path ending in `$leaf`, sorted and unique
     */
    private function provenValues( string $idPrefix, string $leaf ): array
    {
        [ $cands ] = $this->evidence();
        $values = [];
        foreach ( $this->passedIds( $idPrefix ) as $id ) {
            foreach ( $cands[ $id ]['set'] as $p => $v ) {
                if ( str_ends_with( (string) $p, '.' . $leaf ) && 1 === count( $cands[ $id ]['set'] ) ) {
                    $values[ (string) $v ] = true;
                }
            }
        }
        $out = array_keys( $values );
        sort( $out );

        return $out;
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

    /**
     * The settings a section lists, as full normalised paths. A bullet "- `x`" names a path; one that contains `{bp}` is already a
     * full path, otherwise it is relative to the section's prefix: the first backticked string in the section that ends with a dot.
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
            $paths[] = str_contains( $token, '{bp}' ) ? $token : $prefix . $token;
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

    /** @return array<string,mixed> the geometry record of a candidate at a width: control and candidate */
    private function geo( string $id, int $width ): array
    {
        [ , $results ] = $this->evidence();
        $g = $results[ $id ]['detail'][ (string) $width ]['geometry'] ?? null;
        $this->assertIsArray( $g, $id . ' has geometry at ' . $width );

        return $g;
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

    /** @param list<string> $a @return list<string> */
    private function sortedCopy( array $a ): array
    {
        sort( $a );

        return $a;
    }

    // ---- tests ----

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function topics(): array
    {
        return [
            'layout-advanced' => [ 'layout-advanced', '# Layout', 'hover-states' ],
            'layout-sizing'   => [ 'layout-sizing', '# Layout', 'layout-advanced' ],
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
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( '{{aied:', $text );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"|<script|@keyframes|\bvideo\b/i', $text, 'no custom CSS, JS or video is ever taught' );
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
            'Position'            => [ 'layout-advanced', [ 'lay-pos-' ] ],
            'Order'               => [ 'layout-advanced', [ 'lay-order-' ] ],
            'Transforms'          => [ 'layout-advanced', [ 'lay-tf-' ] ],
            'Sizing'              => [ 'layout-sizing', [ 'lay-siz-' ] ],
            'Column widths'       => [ 'layout-sizing', [ 'lay-flx-type-' ] ],
            'Overflow'            => [ 'layout-sizing', [ 'lay-ovf-x-', 'lay-ovf-y-', 'lay-ovf-radius-then', 'lay-ovf-radius-x' ] ],
            'Flex and grid layout' => [ 'layout-sizing', [ 'lay-flx-dir', 'lay-flx-justify', 'lay-flx-align', 'lay-flx-wrap', 'lay-flx-gap', 'lay-flx-display', 'lay-flx-item', 'lay-grd-' ] ],
            'Spacing'             => [ 'layout-sizing', [ 'lay-spc-' ] ],
        ];
        foreach ( $groups as $heading => [ $topic, $prefixes ] ) {
            $proven = $this->provenPaths( $prefixes );
            // `size` is a list, written in prose as ["flexGrow"]; its candidate is pinned below.
            $proven = array_values( array_filter( $proven, static fn ( string $p ): bool => ! str_ends_with( $p, '.value.size' ) && ! str_ends_with( $p, '.padding.syncVertical' ) ) );
            $this->assertNotSame( [], $proven, $heading . ' has proven paths' );
            $this->assertSame( $proven, $this->taughtPaths( $this->section( $heading, $topic ) ), $heading . ': taught settings == probe-proven paths (exact set)' );
            foreach ( $proven as $path ) {
                $this->assertTrue( $registry->covers( $path ), $path . ' is in the shape registry' );
            }
        }
        $stacking = $this->taughtPaths( $this->section( 'Stacking and overlap', 'layout-advanced' ) );
        $this->assertSame( $this->provenPaths( [ 'lay-z-', 'lay-neg-' ] ), $stacking, 'Stacking and overlap: z-index plus the margins that passed' );
        $this->assertSame(
            [ 'module.decoration.spacing.{bp}.value.margin.bottom', 'module.decoration.spacing.{bp}.value.margin.left', 'module.decoration.spacing.{bp}.value.margin.right', 'module.decoration.spacing.{bp}.value.margin.top', 'module.decoration.zIndex.{bp}.value' ],
            $stacking
        );
        $this->assertSame( [ 'module.decoration.disabledOn.{bp}.value' ], $this->taughtPaths( $this->section( 'Hide on a device', 'layout-advanced' ) ) );
        $this->assertSame( [ 'module.decoration.disabledOn.{bp}.value' ], $this->provenPaths( [ 'lay-dis-' ] ) );
        [ $cands ] = $this->evidence();
        $this->assertSame( [ 'flexGrow' ], $cands['lay-flx-item-grow']['set']['module.decoration.sizing.desktop.value.size'], 'the size list the topic shows' );
    }

    public function testEveryEnumeratedValueListIsTheMeasuredSet(): void
    {
        $pos = $this->section( 'Position', 'layout-advanced' );
        $this->assertSame( $this->provenValues( 'lay-pos-origin-', 'origin.absolute' ), $this->provenLine( $pos, 'Absolute origins' ), 'position origins' );
        $hosts = [ 'group' ];
        foreach ( $this->passedIds( 'lay-pos-host-' ) as $id ) {
            $hosts[] = substr( $id, strlen( 'lay-pos-host-' ) );
        }
        $this->assertSame( $this->sortedCopy( $hosts ), $this->provenLine( $pos, 'Hosts' ), 'position hosts' );

        $hide = $this->section( 'Hide on a device', 'layout-advanced' );
        $devices = [];
        [ $cands ] = $this->evidence();
        foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
            $this->assertContains( 'lay-dis-' . $bp, $this->passedIds( 'lay-dis-' ) );
            $this->assertSame( [ 'module.decoration.disabledOn.' . $bp . '.value' ], array_keys( $cands[ 'lay-dis-' . $bp ]['set'] ) );
            $devices[] = $bp;
        }
        $this->assertSame( $this->sortedCopy( $devices ), $this->provenLine( $hide, 'Devices' ), 'hide devices' );
        $hostsHidden = [ 'group' ];
        foreach ( $this->passedIds( 'lay-dis-host-' ) as $id ) {
            $hostsHidden[] = substr( $id, strlen( 'lay-dis-host-' ) );
        }
        $this->assertSame( $this->sortedCopy( $hostsHidden ), $this->provenLine( $hide, 'Hosts' ), 'hidden hosts' );

        $this->assertSame( $this->provenValues( 'lay-flx-type-', 'flexType' ), $this->provenLine( $this->section( 'Column widths', 'layout-sizing' ), 'flexType' ), 'flexType ratios' );
        $this->assertSame( $this->provenValues( 'lay-ovf-x-', 'x' ), $this->provenLine( $this->section( 'Overflow', 'layout-sizing' ), 'Values' ), 'overflow values' );
        $flex = $this->section( 'Flex and grid layout', 'layout-sizing' );
        $this->assertSame( $this->provenValues( 'lay-flx-dir-', 'flexDirection' ), $this->provenLine( $flex, 'Directions:' ), 'flex directions' );
        $this->assertSame( $this->provenValues( 'lay-flx-justify-', 'justifyContent' ), $this->provenLine( $flex, 'Justify:' ), 'justify' );
        $this->assertSame( $this->provenValues( 'lay-flx-align-', 'alignItems' ), $this->provenLine( $flex, 'Align items:' ), 'align items' );
        $this->assertSame( $this->provenValues( 'lay-flx-wrap-', 'flexWrap' ), $this->provenLine( $flex, 'Wrap:' ), 'flex wrap' );
    }

    // ---- preconditions, dead shapes and unsafe overlaps ----

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
            $this->sortedCopy( [ 'lay-pos-mode-default', 'lay-pos-origin-top-left', 'lay-pos-rel-origin-needs-relative-mode', 'lay-order-direct-value', 'lay-dis-wrong-value', 'lay-ovf-wrong-value', 'lay-siz-align-center-needs-width', 'lay-siz-aspect-auto', 'lay-flx-display-flex-default', 'lay-flx-align-content-nowrap', 'lay-flx-justify-needs-flex', 'lay-flx-item-align-self-no-flex', 'lay-grd-min-width-needs-mode', 'lay-grd-item-span-no-grid', 'lay-spc-sync-only' ] ),
            $this->sortedCopy( $dead ),
            'the DEAD candidates are exactly the 15 the topics call dead'
        );
        $this->assertSame( $this->sortedCopy( [ 'lay-pos-mode-relative', 'lay-pos-origin-needs-mode', 'lay-ovf-x-visible', 'lay-flx-wrap-nowrap' ] ), $this->sortedCopy( $css ), 'the css-only candidates' );
        $this->assertSame( $this->sortedCopy( [ 'lay-pos-abs-outside-right', 'lay-siz-minwidth-overflow', 'lay-tf-translate-x-wide' ] ), $this->sortedCopy( $over ), 'the overflowing candidates' );
        // every outcome other than a plain PASS is announced in the candidate (lesson 14): an expectation on every candidate.
        foreach ( $cands as $id => $c ) {
            $this->assertContains( $c['expect'] ?? null, [ 'PASS', 'DEAD', 'OVERFLOW', 'PASS(css-only)' ], $id . ' carries an expectation' );
        }
        $this->assertSame( 'OVERFLOW', $cands['lay-pos-abs-outside-right']['expect'] );
        $this->assertSame( 20, $results['lay-pos-abs-outside-right']['detail']['400']['overflowPx'] );
        $this->assertSame( 240, $results['lay-siz-minwidth-overflow']['detail']['400']['overflowPx'] );
        $this->assertSame( 24, $results['lay-tf-translate-x-wide']['detail']['400']['overflowPx'] );
        $this->assertSame( 0, $results['lay-pos-abs-outside-right-clipped']['detail']['400']['overflowPx'], 'the clipped version fits' );
        $this->assertSame( 'hidden', $cands['lay-pos-abs-outside-right-clipped']['wrapperBase']['section']['module']['decoration']['overflow']['desktop']['value']['x'] );

        $adv = $this->flat( 'layout-advanced' );
        $siz = $this->flat( 'layout-sizing' );
        $this->assertStringContainsString( '`mode` "default" did nothing', $adv );
        $this->assertStringContainsString( 'so `origin.absolute` on a relative module did nothing', $adv );
        $this->assertStringContainsString( '"top left" is the default and changed nothing (rendered: dead)', $adv );
        $this->assertStringContainsString( 'made a 400px page 20px wider than the screen (not safe)', $adv );
        $this->assertStringContainsString( 'did not overflow (rendered)', $adv );
        $this->assertStringContainsString( 'made a 400px page 24px wider than the screen (not safe; 54px at 900 and 77px at 1280 too)', $adv );
        $this->assertStringContainsString( 'A bare `value` (value: 1) did nothing (dead)', $adv );
        $this->assertStringContainsString( 'any other value ("true") did nothing (dead)', $adv );
        $this->assertStringContainsString( '"auto" on both did nothing (dead)', $siz );
        $this->assertStringContainsString( '`alignment` "center" with no width setting did nothing (dead)', $siz );
        $this->assertStringContainsString( 'made a 400px page 240px wider than the screen (not safe)', $siz );
        $this->assertStringContainsString( '"clip" did nothing (dead)', $siz );
        $this->assertStringContainsString( 'so `display` "flex" on it did nothing (dead)', $siz );
        $this->assertStringContainsString( '`justifyContent` on a group whose `display` is "block" did nothing (dead)', $siz );
        $this->assertStringContainsString( '`alignContent` on a non-wrapping group did nothing (dead)', $siz );
        $this->assertStringContainsString( 'did nothing when the parent was "block"', $siz );
        $this->assertStringContainsString( '`gridColumnMinWidth` without "equalMinimum" did nothing (dead)', $siz );
        $this->assertStringContainsString( 'nothing when the parent was not a grid (dead)', $siz );
        $this->assertStringContainsString( '`padding.syncVertical` "on" is only a builder toggle: alone it did nothing (dead)', $siz );
        $this->assertStringContainsString( '"nowrap" changed only the stylesheet (rendered: css-only)', $siz );
        $this->assertStringContainsString( '"visible" is the default and changed only the stylesheet (rendered: css-only)', $siz );
        $this->assertStringContainsString( '`mode` "relative" alone changed only the stylesheet (rendered: css-only)', $adv );
    }

    // ---- claims that name a width, a size or a position: pinned to the measurement ----

    public function testResponsiveClaimsAreMeasuredAtTheWidthsTheyNameAndNoWider(): void
    {
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-pos-abs-phone-relative' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'lay-pos-abs-tablet-offset' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-neg-module-top-phone-reset' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'lay-neg-module-top-tablet-only' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-order-phone-only' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'lay-order-tablet' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-order-phone-reset' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-tf-phone-reset' ) );
        $this->assertSame( [ 1280 ], $this->widthsChanged( 'lay-dis-desktop' ) );
        $this->assertSame( [ 900 ], $this->widthsChanged( 'lay-dis-tablet' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-dis-phone' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'lay-dis-tablet-phone' ) );
        $this->assertSame( [ 1280 ], $this->widthsChanged( 'lay-dis-off-value' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-siz-width-phone' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-flx-dir-phone-column' ) );
        $this->assertSame( [ 400 ], $this->widthsChanged( 'lay-grd-count-phone-1' ) );
        $this->assertSame( [ 900, 400 ], $this->widthsChanged( 'lay-grd-count-tablet-2' ) );
        foreach ( [ 'section', 'row', 'column', 'group', 'heading', 'text', 'image', 'button', 'blurb' ] as $host ) {
            $this->assertSame( [ 400 ], $this->widthsChanged( 'group' === $host ? 'lay-dis-phone' : 'lay-dis-host-' . $host ), $host );
        }
        $adv = $this->flat( 'layout-advanced' );
        $siz = $this->flat( 'layout-sizing' );
        $this->assertStringContainsString( '`mode` "relative" on the phone only changed 400 px only', $adv );
        $this->assertStringContainsString( 'A phone `margin.top` of "0px" reset the pull at 400 px only; a tablet value reached 900 and 400 px', $adv );
        $this->assertStringContainsString( 'put it above the first at 400 px only. A tablet "-1" also reached 400 px; a phone "0" cancelled it', $adv );
        $this->assertStringContainsString( 'Each hid the module at its own width only: desktop at 1280, tablet at 900, phone at 400 px; tablet and phone together hid it at 900 and 400', $adv );
        $this->assertStringContainsString( 'tablet and phone together hid it at 900 and 400', $adv );
        $this->assertStringContainsString( 'reset a lifted, tilted module at 400 px only', $adv );
        $this->assertStringContainsString( 'A phone `width` "100%" over a desktop "50%" changed 400 px only', $siz );
        $this->assertStringContainsString( 'A phone `flexDirection` "column" changed 400 px only', $siz );
        $this->assertStringContainsString( 'a tablet "2" changed 900 and 400 px (180px high); a phone "1" changed 400 px only (110 to 390px)', $siz );
        // The base of every responsive candidate carries the desktop value it overrides (lesson 12).
        [ $cands ] = $this->evidence();
        $this->assertSame( 'absolute', $cands['lay-pos-abs-phone-relative']['base']['module']['decoration']['position']['desktop']['value']['mode'] );
        $this->assertSame( '-40px', $cands['lay-neg-module-top-phone-reset']['base']['module']['decoration']['spacing']['desktop']['value']['margin']['top'] );
        $this->assertSame( 'on', $cands['lay-dis-off-value']['base']['module']['decoration']['disabledOn']['desktop']['value'] );
        $this->assertSame( '3', $cands['lay-grd-count-phone-1']['base']['module']['decoration']['layout']['desktop']['value']['gridColumnCount'] );
    }

    public function testGeometryClaimsMatchTheRenderedRectangles(): void
    {
        // absolute group: the card moved up under it and covered all 160 x 80; the card stayed on top.
        foreach ( [ 1280, 900, 400 ] as $w ) {
            $g = $this->geo( 'lay-pos-mode-absolute', $w );
            $this->assertSame( 0, $g['control']['overlap']['area'] );
            $this->assertSame( 12800, $g['candidate']['overlap']['area'] );
            $this->assertSame( 'sibling', $g['candidate']['overlap']['top'] );
        }
        // fixed: 24px from the bottom right of a 900px-high screen.
        foreach ( [ 1280 => 1096, 900 => 716, 400 => 216 ] as $w => $left ) {
            $r = $this->geo( 'lay-pos-fixed-bottom-right', $w )['candidate']['rect'];
            $this->assertSame( [ $left, 796 ], [ $r['left'], $r['top'] ], 'fixed at ' . $w );
        }
        $this->assertSame( 560, $this->geo( 'lay-pos-origin-center-center', 1280 )['candidate']['rect']['left'], 'centred in the column' );
        // relative offset from the bottom moved the module 48px higher than the same offset from the top.
        $g = $this->geo( 'lay-pos-rel-origin-bottom-left', 1280 );
        $this->assertSame( 48, $g['control']['rect']['top'] - $g['candidate']['rect']['top'] );
        // an offset alone moved a module 24px down.
        $g = $this->geo( 'lay-pos-offset-without-mode', 1280 );
        $this->assertSame( 24, $g['candidate']['rect']['top'] - $g['control']['rect']['top'] );
        // phone relative keeps the offsets: 24px right and down of the natural place (left 40, top 547).
        $r = $this->geo( 'lay-pos-abs-phone-relative', 400 )['candidate']['rect'];
        $this->assertSame( [ 64, 571 ], [ $r['left'], $r['top'] ] );
        // stacking: who is on top at the centre of the overlap.
        foreach ( [ 1280, 900, 400 ] as $w ) {
            $this->assertSame( [ 'sibling', 'measured' ], [ $this->geo( 'lay-z-over-card', $w )['control']['overlap']['top'], $this->geo( 'lay-z-over-card', $w )['candidate']['overlap']['top'] ], 'z-index 5 at ' . $w );
            $this->assertSame( [ 'measured', 'sibling' ], [ $this->geo( 'lay-z-behind-card', $w )['control']['overlap']['top'], $this->geo( 'lay-z-behind-card', $w )['candidate']['overlap']['top'] ], 'z-index -1 at ' . $w );
            $this->assertSame( [ 'sibling', 'measured' ], [ $this->geo( 'lay-z-static-module', $w )['control']['overlap']['top'], $this->geo( 'lay-z-static-module', $w )['candidate']['overlap']['top'] ], 'z-index on a module without position at ' . $w );
            $this->assertSame( 'measured', $this->geo( 'lay-neg-section-top-zindex', $w )['control']['overlap']['top'] );
            $this->assertSame( 'measured', $this->geo( 'lay-neg-section-top-zindex', $w )['candidate']['overlap']['top'] );
        }
        // negative margins: 30px between modules, so -40px overlapped 10px; -25% moved 256 / 180 / 80px.
        $g = $this->geo( 'lay-neg-module-top', 1280 );
        $this->assertSame( 30, $g['control']['rect']['top'] - ( $g['control']['prev']['top'] + $g['control']['prev']['height'] ), 'the gap between modules' );
        $this->assertSame( 40, $g['control']['rect']['top'] - $g['candidate']['rect']['top'] );
        $this->assertSame( 1600, $g['candidate']['overlap']['area'] );
        foreach ( [ 1280 => 256, 900 => 180, 400 => 80 ] as $w => $moved ) {
            $g = $this->geo( 'lay-neg-module-top-percent', $w );
            $this->assertSame( $moved, $g['control']['rect']['top'] - $g['candidate']['rect']['top'], '-25% at ' . $w );
        }
        $g = $this->geo( 'lay-neg-module-left', 1280 );
        $this->assertSame( 40, $g['control']['rect']['left'] - $g['candidate']['rect']['left'] );
        foreach ( [ 'lay-neg-module-right', 'lay-neg-module-bottom' ] as $id ) {
            $this->assertSame( $this->geo( $id, 1280 )['control']['rect'], $this->geo( $id, 1280 )['candidate']['rect'], $id . ' moved nothing of the module' );
        }
        $g = $this->geo( 'lay-neg-row-top', 1280 );
        $this->assertSame( [ 80, 1024 * 20 ], [ $g['control']['rect']['top'] - $g['candidate']['rect']['top'], $g['candidate']['overlap']['area'] ] );
        $g = $this->geo( 'lay-neg-section-top', 1280 );
        $this->assertSame( [ 80, 1280 * 80, 'measured' ], [ $g['control']['rect']['top'] - $g['candidate']['rect']['top'], $g['candidate']['overlap']['area'], $g['candidate']['overlap']['top'] ] );
        // order.
        $g = $this->geo( 'lay-order-col-first-to-last', 1280 );
        $this->assertGreaterThan( $g['control']['rect']['left'], $g['candidate']['rect']['left'] );
        $g = $this->geo( 'lay-order-phone-only', 400 );
        $this->assertGreaterThan( $g['candidate']['rect']['top'], $g['control']['rect']['top'], 'on a phone the second column moved above the first' );
        // transforms.
        $this->assertSame( 24, $this->geo( 'lay-tf-translate-x', 1280 )['candidate']['rect']['left'] - $this->geo( 'lay-tf-translate-x', 1280 )['control']['rect']['left'] );
        $this->assertSame( 2880, $this->geo( 'lay-tf-translate-y-down', 1280 )['candidate']['overlap']['area'], '48px down covered 18px of the card' );
        $this->assertSame( 20, $this->geo( 'lay-tf-translate-y-percent', 1280 )['control']['rect']['top'] - $this->geo( 'lay-tf-translate-y-percent', 1280 )['candidate']['rect']['top'] );
        $r = $this->geo( 'lay-tf-rotate-z-3', 1280 )['candidate']['rect'];
        $this->assertSame( [ 164, 88 ], [ $r['width'], $r['height'] ] );
        $g = $this->geo( 'lay-tf-origin-top-left', 1280 );
        $this->assertSame( [ 121, 445, 111, 461 ], [ $g['control']['rect']['left'], $g['control']['rect']['top'], $g['candidate']['rect']['left'], $g['candidate']['rect']['top'] ] );
        $this->assertSame( $this->geo( 'lay-tf-origin-without-transform', 1280 )['control']['rect'], $this->geo( 'lay-tf-origin-without-transform', 1280 )['candidate']['rect'] );
        $r = $this->geo( 'lay-tf-phone-reset', 400 );
        $this->assertSame( [ 160, 80, 40, 547 ], [ $r['candidate']['rect']['width'], $r['candidate']['rect']['height'], $r['candidate']['rect']['left'], $r['candidate']['rect']['top'] ] );

        $adv = $this->flat( 'layout-advanced' );
        foreach ( [
            'covered all 160 x 80 of it (12,800 square pixels)',
            'sat 24px from the bottom right corner of a 900px-high screen at 1280, 900 and 400 px',
            '(left 560 at 1280 px)',
            'moved the module 48px higher than the same offset from the top',
            'the module sat 24px right and down of its natural place',
            'overlapped the card above by only 10px',
            'moved the module 256, 180 and 80px at 1280, 900 and 400 px',
            'Divi left 30px between the two groups',
            'overlapped the row above by 20px',
            'covered 80px of the dark section before it and was on top',
            '`translate.y` "48px" covered the card below by 18px',
            'from left 121, top 445 to left 111, top 461 at 1280 px',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $adv );
        }
    }

    /** Fix round 1: claims that used to rest on prose pins are backed by candidate data. */
    public function testFixRoundClaimsAreBackedByCandidateData(): void
    {
        [ $cands, $results ] = $this->evidence();
        $adv = $this->flat( 'layout-advanced' );
        $siz = $this->flat( 'layout-sizing' );

        // 1. equalMinimum: the measured grid-template-columns and rows, not a "row" count.
        $samples = static fn ( string $w ): array => $results['lay-grd-min-width']['detail'][ $w ]['sample'];
        $this->assertContains( '0:div:grid-template-columns: 1024px -> 233.5px 233.5px 233.5px 233.5px', $samples( '1280' ), '4 columns at 1280' );
        $this->assertContains( '0:div:grid-template-columns: 720px -> 220px 220px 220px', $samples( '900' ), '3 columns at 900' );
        $this->assertContains( '0:div:grid-template-rows: 40px 40px 40px 40px 40px 40px -> 40px 40px', $samples( '1280' ), 'two rows of six items' );
        $this->assertContains( '0:div:grid-template-rows: 40px 40px 40px 40px 40px 40px -> 40px 40px', $samples( '900' ) );
        $this->assertNotContains( 'grid-template-columns', $results['lay-grd-min-width']['detail']['400']['changedProps'], 'nothing changed in the columns at 400' );
        $this->assertSame( 390, $this->geo( 'lay-grd-min-width', 400 )['control']['rect']['height'] );
        $this->assertSame( 390, $this->geo( 'lay-grd-min-width', 400 )['candidate']['rect']['height'], 'identical to the one-column control' );
        $this->assertStringNotContainsString( 'one row at 1280', $siz );

        // 2. auto margins without a width: the candidate exists and measured 51px; with width 100% the group keeps 480px.
        $this->assertArrayNotHasKey( 'width', $cands['lay-siz-center-auto-margins-no-width']['base']['module']['decoration']['sizing']['desktop']['value'] );
        $this->assertSame( '480px', $cands['lay-siz-center-auto-margins-no-width']['base']['module']['decoration']['sizing']['desktop']['value']['maxWidth'] );
        foreach ( [ 1280, 900, 400 ] as $w ) {
            $this->assertSame( 51, $this->geo( 'lay-siz-center-auto-margins-no-width', $w )['candidate']['rect']['width'], 'shrunk at ' . $w );
        }
        $this->assertSame( 480, $this->geo( 'lay-siz-center-auto-margins', 1280 )['candidate']['rect']['width'], 'with width 100% it keeps 480px' );

        // 3. z-index -1: the condition of the behind-the-card result and what the coloured section and column measured.
        $this->assertArrayNotHasKey( 'wrapperBase', $cands['lay-z-behind-card'], 'the card result was measured in a section with no background colour' );
        foreach ( [ 'lay-z-behind-coloured-section' => 'section', 'lay-z-behind-coloured-column' => 'column' ] as $id => $wrapper ) {
            $this->assertSame( '#0f172a', $cands[ $id ]['wrapperBase'][ $wrapper ]['module']['decoration']['background']['desktop']['value']['color'] );
            $this->assertEmpty( $cands[ $id ]['siblings'] ?? [], $id . ' has no card: it asks about the section or column background' );
            foreach ( [ 1280, 900, 400 ] as $w ) {
                $g = $this->geo( $id, $w );
                $this->assertSame( [ 'measured', 'measured' ], [ $g['control']['hitCentre'], $g['candidate']['hitCentre'] ], $id . ' at ' . $w . ': the decor stayed on top at its own centre' );
            }
        }
        $this->assertStringContainsString( 'behind the card (a section with no background colour). In a section, and in a column, with a dark background colour, -1 left the decor on top at its own centre at all three widths, so do not count on -1 to tuck a decor behind a coloured block', $adv );

        // 4. the image host label.
        $this->assertContains( 'position', $this->changedProps( 'lay-pos-host-image' ) );
        $this->assertSame( 16, $this->geo( 'lay-pos-host-image', 1280 )['control']['rect']['height'], 'the image box was not laid out when measured' );
        $this->assertStringContainsString( '(image: a computed position change only; its box was not laid out when measured)', $adv );

        // 5. the corrected candidates record the surprise in their notes.
        foreach ( [
            'lay-pos-mode-relative'        => 'modules are already position relative',
            'lay-pos-origin-needs-mode'    => 'css-only',
            'lay-flx-display-flex-default' => 'already display: flex',
            'lay-ovf-x-visible'            => 'browser default',
            'lay-pos-offset-without-mode'  => 'an offset needs no mode',
        ] as $id => $phrase ) {
            $this->assertStringContainsString( ' Measured: ', $cands[ $id ]['note'], $id );
            $this->assertStringContainsString( $phrase, $cands[ $id ]['note'], $id );
        }

        // 6. syncVertical is not a taught bullet, it is a rule.
        $this->assertDoesNotMatchRegularExpression( '/^- `padding\.syncVertical`/m', $this->section( 'Spacing', 'layout-sizing' ) );
        $this->assertSame(
            [ 'module.decoration.spacing.{bp}.value.padding.bottom', 'module.decoration.spacing.{bp}.value.padding.left', 'module.decoration.spacing.{bp}.value.padding.right', 'module.decoration.spacing.{bp}.value.padding.top' ],
            $this->taughtPaths( $this->section( 'Spacing', 'layout-sizing' ) )
        );

        // 7. strong values are flagged and the overflow figures cover every width.
        $this->assertSame( [ 77, 54, 24 ], [ $results['lay-tf-translate-x-wide']['detail']['1280']['overflowPx'], $results['lay-tf-translate-x-wide']['detail']['900']['overflowPx'], $results['lay-tf-translate-x-wide']['detail']['400']['overflowPx'] ] );
        $this->assertStringContainsString( '"-25%" (a share of the column width; a strong value) moved the module 256, 180 and 80px', $adv );
        $this->assertStringContainsString( 'a section with "-80px" (a strong pull) covered 80px', $adv );
        $this->assertStringContainsString( 'A light section that overlaps the dark section before it (-80px is a strong pull):', $adv );

        // 8. the clip recommendation carries its side effect.
        $this->assertContains( 'overflow-y', $this->changedProps( 'lay-ovf-x-hidden' ) );
        $this->assertStringContainsString( 'that also computes the other axis as auto (see `layout-sizing`, Overflow), so it clips vertical bleed (sticky children were not measured)', $adv );

        // 9. containing block.
        $this->assertStringContainsString( 'a two-column row: every position candidate sat in a one-column row, so a badge in the corner of a card depends on the column being the positioned ancestor', $adv );

        // 10. limits are consistent with the measured clamp and the row flexWrap.
        $this->assertSame( 245, $this->geo( 'lay-spc-clamp', 1280 )['candidate']['rect']['height'] );
        $this->assertStringContainsString( 'spacing in calc() or vw other than the one clamp() shown', $siz );
        $this->assertStringContainsString( 'the row `flexWrap` of example 1 is only the base of the measured column-width candidates', $siz );
        $this->assertStringNotContainsString( 'spacing in calc() or vw, and the Visual Builder', $siz );
    }

    public function testSizingNumbersMatchTheRenderedRectangles(): void
    {
        $widths = [ '4_24' => [ 124, 87 ], '10_24' => [ 394, 277 ], '16_24' => [ 664, 467 ], '18_24' => [ 754, 530 ], '20_24' => [ 844, 593 ] ];
        foreach ( $widths as $value => [ $w1280, $w900 ] ) {
            $id = 'lay-flx-type-' . substr( $value, 0, strpos( $value, '_' ) );
            $this->assertSame( $w1280, $this->geo( $id, 1280 )['candidate']['rect']['width'], $id . ' at 1280' );
            $this->assertSame( $w900, $this->geo( $id, 900 )['candidate']['rect']['width'], $id . ' at 900' );
            $this->assertSame( 320, $this->geo( $id, 400 )['candidate']['rect']['width'], $id . ' stacks at 400' );
            $this->assertSame( 1024, $this->geo( $id, 1280 )['candidate']['parent']['width'] );
        }
        $this->assertSame( [ 576, 405, 180 ], [ $this->geo( 'lay-siz-aspect-16-9', 1280 )['candidate']['rect']['height'], $this->geo( 'lay-siz-aspect-16-9', 900 )['candidate']['rect']['height'], $this->geo( 'lay-siz-aspect-16-9', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 320, 320 ], [ $this->geo( 'lay-siz-aspect-square', 400 )['candidate']['rect']['width'], $this->geo( 'lay-siz-aspect-square', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( 540, $this->geo( 'lay-siz-height-vh', 1280 )['candidate']['rect']['height'], '60vh of the 900px screen' );
        $this->assertSame( 120, $this->geo( 'lay-siz-maxheight', 1280 )['candidate']['rect']['height'] );
        $this->assertSame( 480, $this->geo( 'lay-siz-maxwidth', 1280 )['candidate']['rect']['width'] );
        $this->assertSame( 480, $this->geo( 'lay-siz-maxwidth', 900 )['candidate']['rect']['width'] );
        $this->assertSame( 320, $this->geo( 'lay-siz-maxwidth', 400 )['candidate']['rect']['width'] );
        $this->assertSame( [ 128, 400, 672 ], [ $this->geo( 'lay-siz-align-left', 1280 )['candidate']['rect']['left'], $this->geo( 'lay-siz-align-center', 1280 )['candidate']['rect']['left'], $this->geo( 'lay-siz-align-right', 1280 )['candidate']['rect']['left'] ] );
        $this->assertSame( 480, $this->geo( 'lay-siz-align-center', 1280 )['candidate']['rect']['width'], 'with width 100% and maxWidth the group keeps its width' );
        $this->assertSame( $this->geo( 'lay-siz-center-auto-margins', 1280 )['candidate']['rect'], $this->geo( 'lay-siz-align-center', 1280 )['candidate']['rect'], 'the margin pair centres the same way' );
        $this->assertSame( '100%', $this->evidence()[0]['lay-siz-align-center']['base']['module']['decoration']['sizing']['desktop']['value']['width'] );
        $this->assertSame( [ 280, 200, 136 ], [ $this->geo( 'lay-spc-section-padding-scale', 1280 )['candidate']['rect']['height'], $this->geo( 'lay-spc-section-padding-scale', 900 )['candidate']['rect']['height'], $this->geo( 'lay-spc-section-padding-scale', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 245, 184, 136 ], [ $this->geo( 'lay-spc-clamp', 1280 )['candidate']['rect']['height'], $this->geo( 'lay-spc-clamp', 900 )['candidate']['rect']['height'], $this->geo( 'lay-spc-clamp', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 80, 120 ], [ $this->geo( 'lay-spc-sync-top-only', 1280 )['candidate']['rect']['height'], $this->geo( 'lay-spc-padding-four-sides', 1280 )['candidate']['rect']['height'] ], 'top 40px + sync grew the group by 40, not 80' );
        $this->assertSame( [ 40, 80 ], [ $this->geo( 'lay-spc-sync-top-only', 1280 )['control']['rect']['height'], $this->geo( 'lay-spc-sync-top-only', 1280 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 390, 110 ], [ $this->geo( 'lay-grd-count-3', 1280 )['control']['rect']['height'], $this->geo( 'lay-grd-count-3', 1280 )['candidate']['rect']['height'] ] );
        $this->assertSame( 180, $this->geo( 'lay-grd-count-tablet-2', 900 )['candidate']['rect']['height'] );
        $this->assertSame( [ 110, 390 ], [ $this->geo( 'lay-grd-count-phone-1', 400 )['control']['rect']['height'], $this->geo( 'lay-grd-count-phone-1', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 110, 110, 390 ], [ $this->geo( 'lay-grd-min-width', 1280 )['candidate']['rect']['height'], $this->geo( 'lay-grd-min-width', 900 )['candidate']['rect']['height'], $this->geo( 'lay-grd-min-width', 400 )['candidate']['rect']['height'] ] );
        $this->assertSame( [ 321, 673 ], [ $this->geo( 'lay-grd-item-span', 1280 )['control']['rect']['width'], $this->geo( 'lay-grd-item-span', 1280 )['candidate']['rect']['width'] ] );
        $this->assertSame( [ 874, 570, 170 ], [ $this->geo( 'lay-flx-item-grow', 1280 )['candidate']['rect']['width'], $this->geo( 'lay-flx-item-grow', 900 )['candidate']['rect']['width'], $this->geo( 'lay-flx-item-grow', 400 )['candidate']['rect']['width'] ] );
        $this->assertSame( 120, $this->geo( 'lay-flx-item-grow', 1280 )['control']['rect']['width'] );
        $g = $this->geo( 'lay-flx-item-align-self', 1280 );
        $this->assertSame( [ 200, 40, 160 ], [ $g['control']['rect']['height'], $g['candidate']['rect']['height'], $g['candidate']['rect']['top'] - $g['control']['rect']['top'] ] );

        $siz = $this->flat( 'layout-sizing' );
        foreach ( [
            'gave 540px', 'made it 576, 405 and 180px high at 1024, 720 and 320px wide', 'made a square of 320px (the group had `maxWidth` "320px")',
            'capped a group set to 300px', '(left 400 for "center", 672 for "right", against 128 at 1280 px)',
            'Margin "auto" on both sides without `width` "100%" shrank a `maxWidth` "480px" group to its content (51px wide at 1280, 900 and 400 px), so set both; with `width` "100%" the margin pair centred it the same way',
            'at 1280 px (row 1024px) 4_24 gave 124px, 10_24 394, 16_24 664, 18_24 754 and 20_24 844px; at 900 px 87, 277, 467, 530 and 593px',
            'the phone value "24_24" kept the columns stacked',
            'widened a 120px child to 874, 570 and 170px at 1280, 900 and 400 px in a flex row',
            '`alignSelf` "flex-end" dropped a stretched 200px child to the bottom of the row',
            'made six headings 110px high instead of 390px', '"equalMinimum" with "200px" gave 4 columns at 1280 px and 3 at 900 px (two rows of six items) and changed nothing at 400 px, where one column already fits',
            '`gridColumnSpan` "2" widened a child from 321 to 673px',
            'with only `padding.top` "40px" the group grew by 40px, not 80, so set every side you want',
            'gave sections of 280, 200 and 136px (content 40px)', 'gave 245, 184 and 136px',
        ] as $sentence ) {
            $this->assertStringContainsString( $sentence, $siz );
        }
    }

    public function testEachEffectChangedTheComputedPropertyTheTopicNames(): void
    {
        $this->assertContains( 'z-index', $this->changedProps( 'lay-z-over-card' ) );
        $this->assertContains( 'order', $this->changedProps( 'lay-order-col-first-to-last' ) );
        $this->assertContains( 'display', $this->changedProps( 'lay-dis-phone' ) );
        $this->assertContains( 'transform', $this->changedProps( 'lay-tf-rotate-z-3' ) );
        $this->assertContains( 'transform-origin', $this->changedProps( 'lay-tf-origin-top-left' ) );
        $this->assertContains( 'position', $this->changedProps( 'lay-pos-mode-fixed' ) );
        $this->assertContains( 'justify-content', $this->changedProps( 'lay-flx-justify-center' ) );
        $this->assertContains( 'align-items', $this->changedProps( 'lay-flx-align-center' ) );
        $this->assertContains( 'column-gap', $this->changedProps( 'lay-flx-gap-column' ) );
        $this->assertContains( 'flex-wrap', $this->changedProps( 'lay-flx-wrap-wrap' ) );
        $this->assertContains( 'grid-template-columns', $this->changedProps( 'lay-grd-count-3' ) );
        $this->assertContains( 'flex-grow', $this->changedProps( 'lay-flx-item-grow' ) );
        $this->assertContains( 'aspect-ratio', $this->changedProps( 'lay-siz-aspect-16-9' ) );
        // overflow: one axis changed both computed axes; the radius made the group clip (computed overflow).
        $this->assertContains( 'overflow-y', $this->changedProps( 'lay-ovf-x-hidden' ) );
        $this->assertContains( 'overflow-x', $this->changedProps( 'lay-ovf-radius-clips' ) );
        $this->assertNotContains( 'overflow-y', $this->changedProps( 'lay-ovf-radius-x-auto' ), 'x auto with a radius left overflow-y hidden' );
        $this->assertContains( 'overflow-x', $this->changedProps( 'lay-ovf-radius-x-auto' ) );
        $this->assertContains( 'overflow-y', $this->changedProps( 'lay-ovf-radius-then-visible' ) );
    }

    public function testCandidatesAreMeasuredWhereTheTopicsSayThey(): void
    {
        [ $cands ] = $this->evidence();
        foreach ( $cands as $id => $c ) {
            $this->assertStringContainsString( 'includes/builder-5', (string) ( $c['note'] ?? '' ), $id . ' cites the Divi source file' );
            $this->assertTrue( true === ( $c['geometry'] ?? false ), $id . ' measures geometry' );
        }
        // Overlaps are placed against a neighbour: the scene is documented in the note.
        foreach ( [ 'lay-pos-mode-absolute', 'lay-neg-module-top', 'lay-z-over-card', 'lay-tf-translate-y-down' ] as $id ) {
            $this->assertStringContainsString( 'card', $cands[ $id ]['note'], $id . ' says what it was placed against' );
            $this->assertNotEmpty( $cands[ $id ]['siblings'], $id . ' has the neighbour it overlaps' );
        }
        foreach ( [ 'lay-neg-section-top', 'lay-neg-section-top-zindex', 'lay-neg-section-top-phone-reset' ] as $id ) {
            $this->assertNotEmpty( $cands[ $id ]['neighbours']['before'], $id . ' has the section it overlaps' );
        }
    }

    // ---- worked examples ----

    /** @return array<string,array{0:string,1:int,2:list<string>}> */
    public static function exampleTopics(): array
    {
        return [
            'layout-advanced' => [ 'layout-advanced', 2, [ 'margin', 'position', 'zIndex', 'disabledOn' ] ],
            'layout-sizing'   => [ 'layout-sizing', 2, [ 'flexType', 'gridColumnCount', 'padding' ] ],
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
