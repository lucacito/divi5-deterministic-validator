<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColorPlanner;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';

class GlobalColorPlannerTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;
    private const NOW = '2026-10-05T10:00:00.000Z';

    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $this->seq = 0;
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    /** @param list<mixed> $entries @return array<string,mixed> */
    private function plan( array $entries, bool $allowTheme = false ): array
    {
        return GlobalColorPlanner::plan( (array) GlobalColors::read(), GlobalColorPlanner::normalize( $entries ), $allowTheme, self::NOW, function (): string {
            return 'gcid-new-' . ++$this->seq;
        } );
    }

    private function refused( array $entries, string $needle, bool $allowTheme = false ): void
    {
        try {
            $this->plan( $entries, $allowTheme );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Nothing was changed.', $e->getMessage() );
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
    }

    public function testNormalizeKeepsTheFourKeysCleansTheLabelAndTrimsTheColor(): void
    {
        $n = GlobalColorPlanner::normalize( [ [ 'label' => '  Brand  ', 'color' => ' #ABCDEF ' ], [ 'id' => self::A, 'status' => 'inactive' ] ] );
        $this->assertSame( [
            [ 'id' => null, 'label' => 'Brand', 'color' => '#ABCDEF', 'status' => null ],
            [ 'id' => self::A, 'label' => null, 'color' => null, 'status' => 'inactive' ],
        ], $n );
    }

    public function testNormalizeRefusesBadShapesAndReportsEveryProblemAtOnce(): void
    {
        $bad = [
            'a string'        => [ [ 'x' ], 'colors[0] must be an object' ],
            'a list'          => [ [ [ 'a', 'b' ] ], 'colors[0] must be an object' ],
            'unknown field'   => [ [ [ 'label' => 'x', 'color' => '#fff', 'delete' => true ] ], 'unknown field "delete"' ],
            'id not a string' => [ [ [ 'id' => 5, 'color' => '#fff' ] ], '"id" must be a string' ],
            'bad id'          => [ [ [ 'id' => 'primary', 'color' => '#fff' ] ], '"id" must look like gcid-' ],
            'create no color' => [ [ [ 'label' => 'x' ] ], 'needs "label" and "color"' ],
            'create no label' => [ [ [ 'color' => '#fff' ] ], 'needs "label" and "color"' ],
            'update nothing'  => [ [ [ 'id' => self::A ] ], 'at least one of label, color, status' ],
            'bad status'      => [ [ [ 'id' => self::A, 'status' => 'temporary' ] ], '"status" must be active or inactive' ],
            'bad color'       => [ [ [ 'label' => 'x', 'color' => 'red' ] ], '"color" must be a hex value' ],
            'bad label'       => [ [ [ 'label' => '<b>', 'color' => '#fff' ] ], '"label" must be 1 to 60 characters' ],
            'empty object'    => [ [ [] ], 'needs "label" and "color"' ],
        ];
        foreach ( $bad as $name => [ $entries, $needle ] ) {
            try {
                GlobalColorPlanner::normalize( $entries );
                $this->fail( "expected a refusal for: {$name}" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( $needle, $e->getMessage(), $name );
            }
        }
        try {
            GlobalColorPlanner::normalize( [ [ 'label' => 'x', 'color' => 'red' ], [ 'id' => 'primary', 'color' => '#fff' ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'colors[0]', $e->getMessage() );
            $this->assertStringContainsString( 'colors[1]', $e->getMessage(), 'both problems are in the one message' );
        }
    }

    public function testACreateBuildsAFullDiviEntryAndTouchesNothingElse(): void
    {
        $p = $this->plan( [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] );
        $this->assertSame( [], $p['unchanged'] );
        $this->assertFalse( $p['theme_touched'] );
        $c = $p['changes'][0];
        $this->assertSame( [ 'index' => 0, 'id' => 'gcid-new-1', 'action' => 'create', 'fields' => [ 'color', 'label', 'status' ], 'theme' => false ], array_diff_key( $c, [ 'before' => 1, 'after' => 1, 'write' => 1 ] ) );
        $this->assertNull( $c['before'] );
        $this->assertSame( [ 'color' => '#00aaff', 'label' => 'Sky', 'status' => 'active', 'folder' => '' ], $c['after'] );
        $this->assertSame( [ 'color' => '#00aaff', 'folder' => '', 'label' => 'Sky', 'lastUpdated' => self::NOW, 'status' => 'active', 'usedInPosts' => [] ], $c['write'] );
    }

    public function testAnUpdateChangesOnlyTheFieldsGivenAndKeepsUsageAndFolder(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#abcdef' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( [ 'color' ], $c['fields'] );
        $this->assertSame( [ 'color' => '#112233', 'label' => 'Brand', 'status' => 'active', 'folder' => '' ], $c['before'] );
        $this->assertSame( [ 'color' => '#abcdef', 'label' => 'Brand', 'status' => 'active', 'folder' => '' ], $c['after'] );
        $expected                = GlobalColorsFixture::custom()[ self::A ];
        $expected['color']       = '#abcdef';
        $expected['lastUpdated'] = self::NOW;
        $this->assertSame( $expected, $c['write'], 'usedInPosts and folder are exactly as stored; only the changed field and lastUpdated differ' );
    }

    public function testStatusOnlyChangesAreNamedDeactivateAndReactivate(): void
    {
        $this->assertSame( 'deactivate', $this->plan( [ [ 'id' => self::A, 'status' => 'inactive' ] ] )['changes'][0]['action'] );
        $this->assertSame( 'reactivate', $this->plan( [ [ 'id' => self::B, 'status' => 'active' ] ] )['changes'][0]['action'] );
        $this->assertSame( 'update', $this->plan( [ [ 'id' => self::A, 'status' => 'inactive', 'label' => 'Old brand' ] ] )['changes'][0]['action'] );
    }

    public function testAnUpdateThatChangesNothingIsReportedAsUnchangedNotPlanned(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#112233', 'label' => 'Brand', 'status' => 'active' ] ] );
        $this->assertSame( [], $p['changes'] );
        $this->assertSame( [ self::A ], $p['unchanged'] );
    }

    public function testTheIndexOfEachChangeIsItsPositionInTheRequest(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#112233' ], [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => self::B, 'color' => '#000000' ] ] );
        $this->assertSame( [ 1, 2 ], array_column( $p['changes'], 'index' ) );
        $this->assertSame( [ self::A ], $p['unchanged'] );
    }

    public function testBadReferencesRefuseTheWholeCallAndSayWhy(): void
    {
        $this->refused( [ [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'colors[0]: no color with the id gcid-unknown-0000' );
        $this->refused( [ [ 'id' => self::A, 'color' => '#fff' ], [ 'id' => self::A, 'label' => 'again' ] ], 'colors[1]: the id ' . self::A . ' is already in this call' );
        $this->refused( [ [ 'id' => self::A, 'color' => '#fff' ], [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'no color with the id', false );
    }

    public function testATemporaryColorIsLeftAlone(): void
    {
        GlobalColorsFixture::reset( [ 'gcid-temp-0001' => [ 'color' => '#010101', 'folder' => '', 'label' => 'Tmp', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'temporary', 'usedInPosts' => [] ] ] );
        $this->refused( [ [ 'id' => 'gcid-temp-0001', 'color' => '#fff' ] ], 'temporary' );
    }

    public function testTwoNewColorsWithTheSameNameAreRefusedWhateverTheCase(): void
    {
        $this->refused( [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'label' => 'sKY', 'color' => '#00bbff' ] ], 'colors[1]: another new color in this call is already named "sKY"' );
    }

    public function testANewColorMayShareANameWithAnExistingOne(): void
    {
        $p = $this->plan( [ [ 'label' => 'Brand', 'color' => '#00aaff' ] ] );
        $this->assertSame( 'create', $p['changes'][0]['action'] );
    }

    public function testThemeColorsNeedTheFlagAndOnlyChangeTheirColor(): void
    {
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ], 'allow_theme_colors' );
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000', 'label' => 'Mine' ] ], 'theme colors can only change their color', true );
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'status' => 'inactive' ] ], 'theme colors can only change their color', true );

        $p = $this->plan( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ], true );
        $this->assertTrue( $p['theme_touched'] );
        $c = $p['changes'][0];
        $this->assertTrue( $c['theme'] );
        $this->assertSame( [ 'color' ], $c['fields'] );
        $this->assertSame( [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ], $c['before'] );
        $this->assertSame( GlobalColors::themeEntry( '#ff0000', 'Primary Color', self::NOW ), $c['write'] );
    }

    public function testOneBadEntryRefusesTheWholePalette(): void
    {
        $this->refused( [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'colors[1]' );
    }
}
