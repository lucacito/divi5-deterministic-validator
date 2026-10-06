<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\GlobalStyles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';

class GlobalStylesTest extends TestCase
{
    private const H1 = '1e32dbff-cc61-4cda-b68a-39243c406d98';
    private const H3 = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';

    /** A trimmed copy of the real shape found on the dev site, including its stray "0" entry. */
    private function presets(): array
    {
        return [ 'module' => [
            'divi/heading' => [ 'default' => self::H1, 'items' => [
                self::H3 => [ 'id' => self::H3, 'moduleName' => 'divi/heading', 'name' => 'Heading 3',
                    'attrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '30px' ] ] ] ] ] ] ],
                    'renderAttrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'headingLevel' => 'h3' ] ] ] ] ] ] ] ],
                self::H1 => [ 'id' => self::H1, 'moduleName' => 'divi/heading', 'name' => 'Heading 1',
                    'attrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '72px' ] ] ] ] ] ], 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [] ] ] ] ] ],
            ] ],
            'divi/button' => [ 'default' => 'b-1', 'items' => [ 'b-1' => [ 'id' => 'b-1', 'name' => 'Primary', 'attrs' => [] ] ] ],
            '0'           => [ 'items' => [ [ 'id' => 's2s-test-1', 'name' => 'S2S Test Preset' ] ] ],
        ] ];
    }

    private function colors(): array
    {
        return [
            'gcid-3e93bdc5' => [ 'color' => '#ff4d00', 'label' => '', 'status' => 'active', 'folder' => '' ],
            'gcid-primary-color' => [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ],
            'gcid-old' => [ 'color' => '#000000', 'label' => 'Old', 'status' => 'inactive', 'folder' => '' ],
        ];
    }

    private function catalog( ?array $p = null, ?array $c = null, ?array $v = null, ?string $module = null ): array
    {
        return GlobalStyles::catalogWith(
            null === $p ? null : fn () => $p,
            null === $c ? null : fn () => $c,
            null === $v ? null : fn () => $v,
            $module
        );
    }

    public function testListsPresetsSortedWithDefaultFlagAndSummaries(): void
    {
        $cat = $this->catalog( $this->presets(), [], [] );
        $this->assertSame( [ 'Primary', 'Heading 1', 'Heading 3' ], array_column( $cat['presets'], 'name' ) );
        $h3 = $cat['presets'][2];
        $this->assertSame( [ 'id' => self::H3, 'module' => 'divi/heading', 'name' => 'Heading 3', 'default' => false, 'controls' => [ 'title.decoration.font' ], 'renders' => [ 'headingLevel=h3' ] ], $h3 );
        $this->assertTrue( $cat['presets'][1]['default'] );
        $this->assertSame( [ 'module.decoration.spacing', 'title.decoration.font' ], $cat['presets'][1]['controls'] );
        $this->assertSame( 3, $cat['counts']['presets'] );
    }

    public function testStrayEntriesAreSkippedNeverAnError(): void
    {
        $data = $this->presets();
        $data['module']['divi/text'] = 'not an array';
        $data['module']['divi/blurb'] = [ 'items' => 'nope' ];
        $data['module']['divi/cta'] = [ 'default' => 5, 'items' => [ 'x' => 'str', 'y' => [ 'id' => 7, 'name' => 'n' ], 'z' => [ 'id' => 'z-1' ] ] ];
        $cat = $this->catalog( $data, [], [] );
        $this->assertCount( 3, $cat['presets'] );
    }

    public function testModuleFilterAndEmptyNotes(): void
    {
        $cat = $this->catalog( $this->presets(), [], [], 'divi/button' );
        $this->assertSame( [ 'Primary' ], array_column( $cat['presets'], 'name' ) );
        $none = $this->catalog( $this->presets(), [], [], 'divi/nope' );
        $this->assertSame( [], $none['presets'] );
        $this->assertContains( "This site has no presets for the module 'divi/nope'.", $none['notes'] );
        $empty = $this->catalog( [], [], [] );
        $this->assertContains( 'This site has no module presets yet.', $empty['notes'] );
    }

    public function testColorsAreActiveOnlyThemeFirstAndLabelled(): void
    {
        $cat = $this->catalog( [], $this->colors(), [] );
        $this->assertSame( [
            [ 'id' => 'gcid-primary-color', 'label' => 'Primary Color', 'color' => '#2ea3f2', 'source' => 'theme' ],
            [ 'id' => 'gcid-3e93bdc5', 'label' => '', 'color' => '#ff4d00', 'source' => 'custom' ],
        ], $cat['colors'] );
        $this->assertSame( 2, $cat['counts']['colors'] );
    }

    public function testVariablesAcceptObjectsAndSkipInactiveAndEmptyTypes(): void
    {
        $vars = [
            'numbers' => (object) [ 'gvid-1' => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ], 'gvid-2' => [ 'label' => 'Gone', 'value' => '1', 'status' => 'archived' ] ],
            'strings' => (object) [],
            'fonts'   => [ '--et_global_body_font' => [ 'id' => '--et_global_body_font', 'label' => 'Body', 'value' => 'Open Sans', 'status' => 'active' ] ],
        ];
        $cat = $this->catalog( [], [], $vars );
        $this->assertSame( [ 'fonts', 'numbers' ], array_keys( $cat['variables'] ) );
        $this->assertSame( [ [ 'id' => 'gvid-1', 'label' => 'Radius', 'value' => '12px' ] ], $cat['variables']['numbers'] );
        $this->assertSame( 2, $cat['counts']['variables'] );
    }

    public function testUnavailableReadersGiveEmptySectionsAndNotes(): void
    {
        $cat = $this->catalog( null, null, null );
        $this->assertSame( [], $cat['presets'] );
        $this->assertSame( [], $cat['colors'] );
        $this->assertSame( [], $cat['variables'] );
        $this->assertContains( "Divi's preset data is not available on this site.", $cat['notes'] );
        $this->assertContains( "Divi's global colors are not available on this site.", $cat['notes'] );
        $this->assertContains( "Divi's global variables are not available on this site.", $cat['notes'] );
    }

    public function testAThrowingReaderIsTreatedAsUnavailable(): void
    {
        $cat = GlobalStyles::catalogWith( function () { throw new \RuntimeException( 'boom' ); }, fn () => $this->colors(), null );
        $this->assertSame( [], $cat['presets'] );
        $this->assertCount( 2, $cat['colors'] );
        $this->assertContains( "Divi's preset data is not available on this site.", $cat['notes'] );
    }

    public function testCapsMarkTruncationAndKeepTrueCounts(): void
    {
        $items = [];
        for ( $i = 0; $i < 305; $i++ ) {
            $items[ "p{$i}" ] = [ 'id' => "p{$i}", 'name' => sprintf( 'P%03d', $i ) ];
        }
        $cat = $this->catalog( [ 'module' => [ 'divi/text' => [ 'default' => 'p0', 'items' => $items ] ] ], [], [] );
        $this->assertCount( GlobalStyles::MAX_PRESETS, $cat['presets'] );
        $this->assertSame( 305, $cat['counts']['presets'] );
        $this->assertTrue( $cat['truncated'] );
        $this->assertStringContainsString( 'cut to their limit', implode( ' ', $cat['notes'] ) );
    }

    public function testLongAndInvalidUtf8ValuesNeverBreakJsonEncoding(): void
    {
        $long = str_repeat( 'é', 250 );
        $data = [ 'module' => [ 'divi/text' => [ 'default' => '', 'items' => [
            'a' => [ 'id' => 'a', 'name' => $long ],
            'b' => [ 'id' => 'b', 'name' => "bad\xB1utf8" ],
            "bad\xB1" => [ 'id' => "bad\xB1", 'name' => 'N' ],
        ] ] ] ];
        $colors = [
            'gcid-x'        => [ 'color' => "\xC3\x28", 'label' => 'x', 'status' => 'active' ],
            "gcid-\xB1" => [ 'color' => '#fff', 'label' => 'bad id', 'status' => 'active' ],
        ];
        $cat = $this->catalog( $data, $colors, [] );
        $names = array_column( $cat['presets'], 'name' );
        $this->assertContains( str_repeat( 'é', 200 ) . '…', $names );
        $this->assertContains( '[value is not valid UTF-8]', $names );
        $this->assertSame( '[value is not valid UTF-8]', $cat['colors'][0]['color'] );
        $this->assertNotFalse( json_encode( $cat ) );

        // An id that is not valid UTF-8 is skipped from the listing (never shown garbled) but stays known.
        $this->assertNotContains( "bad\xB1", array_column( $cat['presets'], 'id' ) );
        $this->assertNotContains( "gcid-\xB1", array_column( $cat['colors'], 'id' ) );
        $known = GlobalStyles::knownIdsWith( fn () => $data, fn () => $colors );
        $this->assertArrayHasKey( "bad\xB1", $known['presets'] );
        $this->assertArrayHasKey( "gcid-\xB1", $known['colors'] );

        // The echoed module filter is clipped, so a bad one cannot break the JSON either.
        $filtered = $this->catalog( $data, $colors, [], "mod\xB1" );
        $this->assertStringContainsString( '[value is not valid UTF-8]', implode( ' ', $filtered['notes'] ) );
        $this->assertNotFalse( json_encode( $filtered ) );
    }

    public function testAlwaysEndsWithTheReadOnlyNote(): void
    {
        $cat = $this->catalog( $this->presets(), $this->colors(), [] );
        $this->assertSame( "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.", end( $cat['notes'] ) );
        $this->assertFalse( $cat['truncated'] );
    }

    public function testKnownIdsCoverEveryPresetAndEveryColorIncludingInactive(): void
    {
        $known = GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors() );
        $this->assertArrayHasKey( self::H1, $known['presets'] );
        $this->assertArrayHasKey( 'b-1', $known['presets'] );
        $this->assertArrayHasKey( 'gcid-old', $known['colors'] );
        $this->assertArrayHasKey( 'gcid-primary-color', $known['colors'] );
        $this->assertArrayNotHasKey( 'nope', $known['presets'] );
    }

    public function testKnownIdsAreNullForAnUnavailableReader(): void
    {
        $known = GlobalStyles::knownIdsWith( null, function () { throw new \RuntimeException( 'x' ); } );
        $this->assertSame( [ 'presets' => null, 'colors' => null, 'variables' => null ], $known );
    }

    public function testKnownIdsCoverEveryVariableInEveryGroupIncludingArchivedAndObjectGroups(): void
    {
        $vars = [
            'numbers' => [ 'gvid-active' => [ 'label' => 'R', 'value' => '12px', 'status' => 'active' ], 'gvid-archived' => [ 'label' => 'G', 'value' => '1rem', 'status' => 'archived' ] ],
            'strings' => (object) [ 'gvid-text' => [ 'label' => 'T', 'value' => 'Hi' ] ],
            'fonts'   => [ '--et_global_heading_font' => [ 'label' => 'Heading', 'value' => 'Abel' ] ],
        ];
        $known = GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors(), fn () => $vars );
        $this->assertSame( [ 'gvid-active', 'gvid-archived', 'gvid-text', '--et_global_heading_font' ], array_keys( $known['variables'] ) );
        $this->assertArrayNotHasKey( 'gvid-nope', $known['variables'] );
    }

    public function testKnownVariableIdsAreNullWhenTheReaderIsMissingThrowsOrReturnsNothingUseful(): void
    {
        $this->assertNull( GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors() )['variables'] );
        $this->assertNull( GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors(), null )['variables'] );
        $this->assertNull( GlobalStyles::knownIdsWith( null, null, function () { throw new \RuntimeException( 'x' ); } )['variables'] );
        $this->assertSame( [], GlobalStyles::knownIdsWith( null, null, fn () => [ 'numbers' => 'junk' ] )['variables'], 'a reader that works but holds no usable group means "no variables", not "cannot tell"' );
    }

    public function testRealReadersPointAtDiviClassesOnlyWhenTheyExist(): void
    {
        $expect = static fn ( string $class, string $method ): ?array => is_callable( [ $class, $method ] ) ? [ $class, $method ] : null;
        $this->assertSame(
            [ $expect( GlobalStyles::PRESET_CLASS, 'get_data' ), $expect( GlobalStyles::DATA_CLASS, 'get_global_colors' ), $expect( GlobalStyles::DATA_CLASS, 'get_global_variables' ) ],
            GlobalStyles::readers()
        );
    }

    public function testCatalogAndKnownIdsReadThroughTheDiviClasses(): void
    {
        $GLOBALS['__divi_presets']   = $this->presets();
        $GLOBALS['__divi_colors']    = $this->colors();
        $GLOBALS['__divi_variables'] = [ 'numbers' => [ 'gvid-1' => [ 'label' => 'R', 'value' => '1px' ] ] ];
        try {
            $cat = GlobalStyles::catalog();
            $this->assertSame( 3, $cat['counts']['presets'] );
            $this->assertSame( 2, $cat['counts']['colors'] );
            $this->assertSame( 1, $cat['counts']['variables'] );
            $this->assertCount( 1, GlobalStyles::catalog( 'divi/button' )['presets'] );
            $this->assertArrayHasKey( self::H1, GlobalStyles::knownIds()['presets'] );

            $this->assertArrayHasKey( 'gvid-1', GlobalStyles::knownIds()['variables'] );

            $GLOBALS['__divi_presets'] = null; // the shim now throws
            $this->assertSame( [], GlobalStyles::catalog()['presets'] );
            $this->assertNull( GlobalStyles::knownIds()['presets'] );
            $this->assertIsArray( GlobalStyles::knownIds()['colors'] );
        } finally {
            unset( $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'] );
        }
    }

    public function testANoteSaysNumbersAndStringsVariablesCanBeUsedOnlyWhenThereAreSome(): void
    {
        $with = $this->catalog( $this->presets(), $this->colors(), [ 'numbers' => [ 'gvid-1' => [ 'label' => 'R', 'value' => '12px', 'status' => 'active' ] ] ] );
        $note = "numbers and strings variables can be used in a layout as described in get_style_guide; other kinds of variable are listed for information only.";
        $this->assertContains( $note, $with['notes'] );
        $this->assertSame( "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.", end( $with['notes'] ), 'the read-only note stays last' );

        foreach ( [ [], [ 'fonts' => [ 'gvid-f' => [ 'label' => 'F', 'value' => 'Abel', 'status' => 'active' ] ] ], [ 'numbers' => [ 'gvid-arch' => [ 'label' => 'A', 'value' => '1px', 'status' => 'archived' ] ] ] ] as $vars ) {
            $this->assertNotContains( $note, $this->catalog( $this->presets(), $this->colors(), $vars )['notes'], json_encode( $vars ) );
        }
    }
}
