<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use Divi5Validator\Tests\Support\CarrierAudit;
use Divi5Validator\Tests\Support\RuleChipAudit;
use PHPUnit\Framework\TestCase;

/**
 * The audit of the thin things between modules (rules and chips adapter, plan 2026-10-21, Task 2): which blocks of the
 * shipped recipes are rules and chips, found by their decoration (unmigrated) or by their marker (migrated) so the lists
 * are the same before and after the migration, and the quiet-rule floor measured on what the library shipped (Ruling 5).
 *
 * Ruling 8: the two accent dividers (blog-feed and icon-values, a site preset with an accent line) are brand marks, listed
 * in SHAPE_EXEMPTIONS, so the library has 57 rules to migrate; the floor measurement still covers the 59 it shipped. Since the
 * carriers adapter (plan 2026-10-24, Task 5) they and the four counters carry carrier markers (CarrierMigrationTest's) and are
 * no longer exemptions.
 */
class RuleChipAuditTest extends TestCase
{
    /** key => role, band of every rule (57), from the classifier and the band walk on 155ff75 (the brief's table minus the two accent dividers). */
    private const RULES = [
        'card-grid-4|divi/divider|0' => [ 'rule-strong', 'dark' ], 'card-grid-4|divi/divider|1' => [ 'rule-strong', 'dark' ],
        'hero-image-scrim|divi/divider|0' => [ 'rule-strong', 'dark' ],
        'hero-editorial-minimal|divi/divider|0' => [ 'rule', 'light' ],
        'hero-spec-card|divi/divider|0' => [ 'rule', 'surface' ], 'hero-spec-card|divi/divider|1' => [ 'rule', 'surface' ],
        'hero-spec-card|divi/divider|2' => [ 'rule', 'surface' ], 'hero-spec-card|divi/divider|3' => [ 'rule', 'surface' ],
        'header-divider-intro|divi/divider|0' => [ 'rule-strong', 'panel' ],
        'list-icon-dividers|divi/divider|0' => [ 'rule-strong', 'surface' ],
        'list-icon-dividers|divi/divider|1' => [ 'rule', 'surface' ], 'list-icon-dividers|divi/divider|2' => [ 'rule', 'surface' ],
        'list-icon-dividers|divi/divider|3' => [ 'rule', 'surface' ], 'list-icon-dividers|divi/divider|4' => [ 'rule', 'surface' ],
        'steps-numbered|divi/divider|0' => [ 'rule', 'surface' ], 'steps-numbered|divi/divider|1' => [ 'rule', 'surface' ],
        'steps-horizontal|divi/divider|0' => [ 'rule', 'light' ], 'steps-horizontal|divi/divider|1' => [ 'rule', 'light' ],
        'steps-horizontal|divi/divider|2' => [ 'rule', 'light' ],
        'stats-big-numbers|divi/divider|0' => [ 'rule-strong', 'panel' ], 'stats-big-numbers|divi/divider|1' => [ 'rule-strong', 'panel' ],
        'stats-big-numbers|divi/divider|2' => [ 'rule-strong', 'panel' ],
        'quote-over-image|divi/divider|0' => [ 'rule-strong', 'dark' ],
        'values-list|divi/divider|0' => [ 'rule-strong', 'panel' ], 'values-list|divi/divider|1' => [ 'rule-strong', 'panel' ],
        'values-list|divi/divider|2' => [ 'rule-strong', 'panel' ], 'values-list|divi/divider|3' => [ 'rule-strong', 'panel' ],
        'cta-card-over-image|divi/divider|0' => [ 'rule', 'surface' ],
        'proof-press-quotes|divi/column|1' => [ 'rule-strong', 'surface' ], 'proof-press-quotes|divi/column|2' => [ 'rule-strong', 'surface' ],
        'proof-press-quotes|divi/column|3' => [ 'rule-strong', 'surface' ],
        'pricing-three-tier|divi/group|4' => [ 'rule', 'surface' ], 'pricing-three-tier|divi/group|12' => [ 'rule', 'accent' ],
        'pricing-three-tier|divi/group|18' => [ 'rule', 'surface' ],
        'pricing-two-plan-highlight|divi/group|2' => [ 'rule-strong', 'light' ], 'pricing-two-plan-highlight|divi/group|9' => [ 'rule-strong', 'panel' ],
        'team-grid|divi/group|0' => [ 'rule-strong', 'light' ], 'team-grid|divi/group|1' => [ 'rule-strong', 'light' ],
        'team-grid|divi/group|2' => [ 'rule-strong', 'light' ], 'team-grid|divi/group|3' => [ 'rule-strong', 'light' ],
        'faq-two-column|divi/group|0' => [ 'rule-strong', 'surface' ], 'faq-two-column|divi/group|1' => [ 'rule-strong', 'surface' ],
        'faq-two-column|divi/group|2' => [ 'rule-strong', 'surface' ], 'faq-two-column|divi/group|3' => [ 'rule-strong', 'surface' ],
        'faq-two-column|divi/group|4' => [ 'rule-strong', 'surface' ], 'faq-two-column|divi/group|5' => [ 'rule-strong', 'surface' ],
        'faq-toggle-list|divi/group|2' => [ 'rule-strong', 'light' ],
        'story-timeline|divi/group|3' => [ 'rule-strong', 'dark' ], 'story-timeline|divi/group|4' => [ 'rule-strong', 'dark' ],
        'story-timeline|divi/group|5' => [ 'rule-strong', 'dark' ], 'story-timeline|divi/group|6' => [ 'rule-strong', 'dark' ],
        'before-after-compare|divi/group|1' => [ 'rule-strong', 'surface' ], 'before-after-compare|divi/group|2' => [ 'rule-strong', 'surface' ],
        'contact-details-form|divi/group|1' => [ 'rule', 'light' ], 'contact-details-form|divi/group|4' => [ 'rule', 'light' ],
        'contact-details-form|divi/group|7' => [ 'rule', 'light' ], 'contact-details-form|divi/group|10' => [ 'rule', 'light' ],
    ];

    /** key => band of every chip (14). */
    private const CHIPS = [
        'portfolio-featured-grid|divi/group|5' => 'light', 'portfolio-featured-grid|divi/group|8' => 'light',
        'portfolio-featured-grid|divi/group|11' => 'light', 'portfolio-featured-grid|divi/group|14' => 'light',
        'portfolio-featured-grid|divi/group|17' => 'light',
        'icon-values|divi/icon|0' => 'surface', 'icon-values|divi/icon|1' => 'surface', 'icon-values|divi/icon|2' => 'surface',
        'features-carousel-strip|divi/icon|0' => 'surface', 'features-carousel-strip|divi/icon|1' => 'surface',
        'features-carousel-strip|divi/icon|2' => 'surface', 'features-carousel-strip|divi/icon|3' => 'surface',
        'features-carousel-strip|divi/icon|4' => 'surface', 'features-carousel-strip|divi/icon|5' => 'surface',
    ];

    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $rows = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $rows );

        return $rows;
    }

    /** @return list<array{name:string,markup:string}> */
    private static function one( string $markup ): array
    {
        return [ [ 'name' => 'planted', 'markup' => $markup ] ];
    }

    /** @param array<string,mixed> $attrs */
    private static function block( string $name, array $attrs, string $inner = '' ): string
    {
        $json = json_encode( [] === $attrs ? new \stdClass() : $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return '' === $inner ? "<!-- wp:divi/{$name} {$json} /-->" : "<!-- wp:divi/{$name} {$json} -->{$inner}<!-- /wp:divi/{$name} -->";
    }

    /** @return array<string,mixed> module.decoration.border for a top rule: `all` 0px and a `top` width. */
    private static function topRule( string $role = 'border', string $width = '1px' ): array
    {
        return [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [
            'all' => [ 'width' => '0px', 'color' => '{{aied:color:' . $role . '}}', 'style' => 'solid' ],
            'top' => [ 'width' => $width ],
        ] ] ] ] ];
    }

    /** @return array<string,mixed> */
    private static function fill( string $role ): array
    {
        return [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:' . $role . '}}' ] ] ] ];
    }

    /** @return array<string,mixed> */
    private static function radius( string $px ): array
    {
        return [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => $px, 'topRight' => $px, 'bottomRight' => $px, 'bottomLeft' => $px ] ] ] ] ];
    }

    /** @return array<string,mixed> a divider attribute set with a line colour */
    private static function line( ?string $role, ?string $show = null ): array
    {
        $value = [];
        if ( null !== $role ) {
            $value['color'] = '{{aied:color:' . $role . '}}';
        }
        if ( null !== $show ) {
            $value['show'] = $show;
        }

        return [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => $value ] ] ] ] ];
    }

    /** @param array<string,mixed> $dec */
    private static function group( array $dec, string $inner = '', array $more = [] ): string
    {
        return self::block( 'group', array_merge( [ 'module' => [ 'decoration' => $dec ] ], $more ), $inner );
    }

    /** @return array<string,array{0:string,1:string}> key => role, band of rules() */
    private static function ruleMap( array $recipes, bool $all = false ): array
    {
        $out = [];
        foreach ( RuleChipAudit::rules( $recipes, $all ) as $r ) {
            $out[ RuleChipAudit::key( $r ) ] = [ $r['role'], $r['on'] ];
        }

        return $out;
    }

    /** @return array<string,string> key => band of chips() */
    private static function chipMap( array $recipes ): array
    {
        $out = [];
        foreach ( RuleChipAudit::chips( $recipes ) as $c ) {
            $out[ RuleChipAudit::key( $c ) ] = $c['on'];
        }

        return $out;
    }

    /** @return list<array{colour:string,band:string,ratio:float,language:string}> */
    private static function floorRows(): array
    {
        $md = (string) file_get_contents( __DIR__ . '/../fixtures/shapes/rule-floor.md' );
        self::assertSame( 1, preg_match_all( '/^\| `([^`]+)` \| ([a-z]+) \| ([0-9.]+) \| ([a-z-]+) \|/m', $md, $m, PREG_SET_ORDER ) > 0 ? 1 : 0, 'rule-floor.md has no table rows' );

        return array_map( static fn ( array $r ): array => [ 'colour' => $r[1], 'band' => $r[2], 'ratio' => (float) $r[3], 'language' => $r[4] ], $m );
    }

    public function testTheQuietRuleFloorIsTheMeasuredLibraryMinimum(): void
    {
        $rows = self::floorRows();
        $min  = min( array_column( $rows, 'ratio' ) );
        $this->assertEqualsWithDelta( 1.184, $min, 0.0005, 'the lowest cell of the measured table' );
        $md = (string) file_get_contents( __DIR__ . '/../fixtures/shapes/rule-floor.md' );
        $this->assertSame( 1, preg_match( '/^Minimum: ([0-9.]+) /m', $md, $mm ) );
        $this->assertSame( $min, (float) $mm[1], 'the stated minimum is the table minimum' );
        $this->assertSame( 1, preg_match( '/^Floor: ([0-9.]+)$/m', $md, $fm ) );
        $this->assertSame( 1.1, (float) $fm[1] );
        $this->assertSame( floor( $min * 10 ) / 10, RuleChipAudit::QUIET_RULE_FLOOR, 'the floor is the minimum rounded DOWN to one decimal' );
        // Pinned literally: lowering the constant is a conscious act that has to change this line (and Ruling 5).
        $this->assertSame( 1.1, RuleChipAudit::QUIET_RULE_FLOOR );
        // The minimum cell and the cells the plan recorded.
        $by = [];
        foreach ( $rows as $r ) {
            $by[ $r['colour'] . '|' . $r['band'] ] = $r;
        }
        foreach ( [
            'border|light' => [ 1.184, 'soft-saas' ], 'border|surface' => [ 1.275, 'soft-saas' ],
            'accent-text:40|accent' => [ 1.928, 'playful-pastel' ], 'accent|light' => [ 2.304, 'playful-pastel' ],
            'bg|panel' => [ 11.978, 'organic-warm' ], 'inverse|dark' => [ 9.543, 'playful-pastel' ],
            'text|light' => [ 11.978, 'organic-warm' ], 'text|surface' => [ 13.274, 'organic-warm' ],
        ] as $cell => [ $ratio, $language ] ) {
            $this->assertArrayHasKey( $cell, $by );
            $this->assertEqualsWithDelta( $ratio, $by[ $cell ]['ratio'], 0.0005, $cell );
            $this->assertSame( $language, $by[ $cell ]['language'], $cell );
        }
        $this->assertCount( 8, $rows );
    }

    public function testTheAccentFloorIsTheShippedMinimumRoundedDown(): void
    {
        $min = INF;
        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertIsArray( $lang );
            $min = min( $min, RuleChipAudit::ratio( 'accent', 'light', $lang ) );
        }
        $this->assertEqualsWithDelta( 2.304, $min, 0.0005, 'the weakest accent line on a light band over the 10 languages' );
        $this->assertSame( floor( $min * 10 ) / 10, RuleChipAudit::ACCENT_RULE_FLOOR, 'the floor is the minimum rounded DOWN to one decimal' );
        // Pinned literally: lowering the constant is a conscious act that has to change this line (and Ruling 1).
        $this->assertSame( 2.3, RuleChipAudit::ACCENT_RULE_FLOOR );
        // The measured table has the same cell (playful-pastel), so the floor traces to fixtures/shapes/rule-floor.md.
        $by = [];
        foreach ( self::floorRows() as $r ) {
            $by[ $r['colour'] . '|' . $r['band'] ] = $r;
        }
        $this->assertArrayHasKey( 'accent|light', $by );
        $this->assertEqualsWithDelta( $min, $by['accent|light']['ratio'], 0.0005 );
        $this->assertSame( 'playful-pastel', $by['accent|light']['language'] );
        // The two shipped accent rules are the two accent dividers, both on a light band.
        $dividers = array_values( array_filter( CarrierAudit::blocks( self::library() ), static fn ( array $b ): bool => 'rule-accent' === $b['role'] ) );
        $this->assertCount( 2, $dividers );
        foreach ( $dividers as $d ) {
            $this->assertSame( 'light', $d['on'], CarrierAudit::key( $d ) );
        }
    }

    public function testTheFloorTableIsWhatTheLibraryShippedAndStaysWhileTheRulesAreUnmigrated(): void
    {
        $library = self::library();
        foreach ( RuleChipAudit::rules( $library, true ) as $r ) {
            if ( $r['marked'] ) {
                $this->markTestSkipped( 'The rules are migrated: the floor is measured on the revision before the migration (see rule-floor.md).' );
            }
        }
        $cells = [];
        foreach ( RuleChipAudit::floorCells( $library ) as $c ) {
            $cells[ $c['colour'] . '|' . $c['band'] ] = $c;
        }
        $rows = self::floorRows();
        $this->assertCount( count( $rows ), $cells );
        $total = 0;
        foreach ( $rows as $r ) {
            $cell = $cells[ $r['colour'] . '|' . $r['band'] ] ?? null;
            $this->assertNotNull( $cell, $r['colour'] . ' on ' . $r['band'] );
            $this->assertEqualsWithDelta( $r['ratio'], $cell['ratio'], 0.0005 );
            $this->assertSame( $r['language'], $cell['language'] );
            $total += $cell['count'];
        }
        $this->assertSame( 59, $total, 'the floor covers the 59 rules the library shipped, the two exempt accent dividers included' );
    }

    public function testTheAuditFindsTheFiftySevenRulesOfTheLibrary(): void
    {
        $rules = RuleChipAudit::rules( self::library() );
        $this->assertCount( 57, $rules );
        $this->assertCount( 21, array_unique( array_column( $rules, 'recipe' ) ) );
        $blocks = array_count_values( array_column( $rules, 'block' ) );
        $this->assertEquals( [ 'divi/divider' => 28, 'divi/group' => 26, 'divi/column' => 3 ], $blocks );
        $roles = array_count_values( array_column( $rules, 'role' ) );
        $this->assertSame( [ 'rule' => 22, 'rule-strong' => 35 ], [ 'rule' => $roles['rule'], 'rule-strong' => $roles['rule-strong'] ] );
        $marked = array_unique( array_column( $rules, 'marked' ) );
        $this->assertCount( 1, $marked, 'all rules are either unmigrated or migrated, never a mix' );
        $map = self::ruleMap( self::library() );
        ksort( $map );
        $want = self::RULES;
        ksort( $want );
        $this->assertSame( $want, $map, 'the exact keys, roles and bands of the library' );
        // Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): the two accent dividers were listed here with the exempt
        // blocks (59, as `rule-strong` on light); they now carry a `rule-accent` marker, which is CarrierMigrationTest's, so
        // the exempt-inclusive list is the same 57 and holds neither divider (the floor table still covers the 59 the library
        // shipped: fixtures/shapes/rule-floor.md was measured on the revision before the migration).
        $all = self::ruleMap( self::library(), true );
        $this->assertCount( 57, $all );
        ksort( $all );
        $this->assertSame( $want, $all );
        $this->assertArrayNotHasKey( 'blog-feed|divi/divider|0', $all );
        $this->assertArrayNotHasKey( 'icon-values|divi/divider|0', $all );
    }

    public function testTheAuditFindsTheFourteenChipsOfTheLibrary(): void
    {
        $chips = RuleChipAudit::chips( self::library() );
        $this->assertCount( 14, $chips );
        $this->assertCount( 3, array_unique( array_column( $chips, 'recipe' ) ) );
        $this->assertEquals( [ 'divi/group' => 5, 'divi/icon' => 9 ], array_count_values( array_column( $chips, 'block' ) ) );
        $map  = self::chipMap( self::library() );
        $want = self::CHIPS;
        ksort( $map );
        ksort( $want );
        $this->assertSame( $want, $map );
    }

    public function testTheExemptionsAreFoundAndNamed(): void
    {
        // Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): 15 -> 9 (the four counters and the two accent dividers are
        // kit-driven now; CarrierMigrationTest pins them).
        $this->assertCount( 9, RuleChipAudit::SHAPE_EXEMPTIONS );
        $library = self::library();
        $seen    = RuleChipAudit::exemptionsSeen( $library );
        $rules   = self::ruleMap( $library );
        $all     = self::ruleMap( $library, true );
        $chips   = self::chipMap( $library );
        foreach ( RuleChipAudit::SHAPE_EXEMPTIONS as $key => $reason ) {
            $this->assertNotSame( '', trim( $reason ), "$key has no reason" );
            $this->assertTrue( $seen[ $key ]['exists'], "$key is not a block of the library" );
            $this->assertArrayNotHasKey( $key, $rules, "$key is exempt but listed as a rule" );
            $this->assertArrayNotHasKey( $key, $chips, "$key is exempt but listed as a chip" );
            // An exemption must still be a real decision: the block either looks like a rule (and only the exemption keeps it
            // out) or paints a ground of its own (a badge, a dot, a circle).
            $this->assertContains( $seen[ $key ]['looks'], [ 'rule', 'filled' ], "$key is neither a rule shape nor a filled shape any more: remove the exemption" );
        }
        // Carriers adapter (plan 2026-10-24, Task 5): the accent dividers and the counters left the exemptions; each now
        // carries its carrier marker (rule-accent / rule-strong), and this audit neither lists nor exempts them.
        $carriers = [];
        foreach ( CarrierAudit::blocks( $library ) as $b ) {
            $carriers[ CarrierAudit::key( $b ) ] = [ $b['role'], $b['marked'] ];
        }
        foreach ( [ 'blog-feed|divi/divider|0', 'icon-values|divi/divider|0' ] as $key ) {
            $this->assertArrayNotHasKey( $key, RuleChipAudit::SHAPE_EXEMPTIONS );
            $this->assertArrayNotHasKey( $key, $all );
            $this->assertSame( [ 'rule-accent', true ], $carriers[ $key ] ?? null, $key );
        }
        for ( $i = 0; $i < 4; $i++ ) {
            $key = "stats-counter-band|divi/number-counter|$i";
            $this->assertArrayNotHasKey( $key, RuleChipAudit::SHAPE_EXEMPTIONS );
            $this->assertSame( [ 'rule-strong', true ], $carriers[ $key ] ?? null, $key );
        }
        foreach ( [ 5, 9, 13, 17 ] as $i ) {
            $this->assertSame( 'filled', $seen[ "steps-horizontal|divi/group|$i" ]['looks'] );
        }
        foreach ( [ 3, 8, 13, 18 ] as $i ) {
            $this->assertSame( 'filled', $seen[ "timeline-vertical|divi/group|$i" ]['looks'] );
        }
        $this->assertSame( 'filled', $seen['pricing-three-tier|divi/group|10']['looks'] );
    }

    public function testTheAuditClassifiesPlantedBlocks(): void
    {
        $markup = ''
            . self::group( self::topRule( 'border' ) )                                       // group 0: an unmarked top rule (quiet)
            . self::group( [], '', [ 'aied' => [ 'kit' => 'rule-strong', 'on' => 'light' ] ] ) // group 1: a marked strong rule
            . self::group( self::fill( 'surface' ) + self::radius( '12px' ) )                // group 2: a filled group with a radius (not a tag)
            . self::group( [ 'border' => [ 'desktop' => [ 'value' => [
                'radius' => [ 'topLeft' => '12px', 'topRight' => '12px', 'bottomRight' => '12px', 'bottomLeft' => '12px' ],
                'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}', 'style' => 'solid' ] ],
            ] ] ] ] )                                                                        // group 3: an outline tag (a chip)
            . self::group( [], '', [ 'aied' => [ 'kit' => 'chip', 'on' => 'light' ] ] )       // group 4: a marked chip
            . self::group( self::topRule( 'border' ) + self::fill( 'surface' ) )             // group 5: a top edge on a filled group (not a rule)
            . self::group( [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [
                'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}', 'style' => 'solid' ], 'top' => [ 'width' => '2px' ],
            ] ] ] ] ] )                                                                      // group 6: a framed group (not a rule)
            . self::block( 'divider', self::line( 'text' ) )                                 // divider 0: a strong rule
            . self::block( 'divider', self::line( null, 'off' ) )                            // divider 1: no colour, show off (not a rule)
            . self::block( 'divider', self::line( 'text', 'off' ) )                          // divider 2: hidden although coloured (not a rule)
            . self::block( 'divider', self::line( 'accent-text:40' ) )                       // divider 3: an alpha colour (quiet)
            . self::block( 'icon', [ 'module' => [ 'decoration' => self::fill( 'accent' ) + self::radius( '8px' ) ] ] )  // icon 0: a chip
            . self::block( 'icon', [ 'module' => [ 'decoration' => self::radius( '8px' ) ] ] )                         // icon 1: no background (not a chip)
            . self::block( 'icon', [ 'module' => [ 'decoration' => self::fill( 'accent' ) ] ] )                        // icon 2: no radius (not a chip)
            . self::block( 'icon', [ 'aied' => [ 'kit' => 'chip', 'on' => 'surface' ] ] )                              // icon 3: a marked chip
            . self::block( 'number-counter', [ 'module' => [ 'decoration' => self::topRule( 'border' ) ] ] );          // a counter is never a rule
        $recipes = self::one( $markup );
        $rules   = self::ruleMap( $recipes );
        $this->assertSame( [
            'planted|divi/group|0'   => [ 'rule', 'none' ],
            'planted|divi/group|1'   => [ 'rule-strong', 'none' ],
            'planted|divi/divider|0' => [ 'rule-strong', 'none' ],
            'planted|divi/divider|3' => [ 'rule', 'none' ],
        ], $rules );
        $unmarked = [];
        foreach ( RuleChipAudit::rules( $recipes ) as $r ) {
            $unmarked[ RuleChipAudit::key( $r ) ] = [ $r['marked'], $r['colour'] ];
        }
        $this->assertSame( [ false, 'border' ], $unmarked['planted|divi/group|0'] );
        $this->assertSame( [ true, null ], $unmarked['planted|divi/group|1'] );
        $this->assertSame( [ false, 'accent-text:40' ], $unmarked['planted|divi/divider|3'] );
        $this->assertSame( [
            'planted|divi/group|3' => 'none',
            'planted|divi/group|4' => 'none',
            'planted|divi/icon|0'  => 'none',
            'planted|divi/icon|3'  => 'none',
        ], self::chipMap( $recipes ) );
    }

    public function testTheRoleFollowsTheColourAndTheKindOfBlockDoesNotMatter(): void
    {
        $recipes = self::one(
            self::group( self::topRule( 'border' ) ) . self::group( self::topRule( 'text' ) ) . self::group( self::topRule( 'accent' ) )
            . self::group( self::topRule( 'inverse' ) ) . self::group( self::topRule( 'bg' ) ) . self::group( self::topRule( 'accent-text:40' ) )
            . self::group( self::topRule( 'border:50' ) )
            . self::block( 'column', [ 'module' => [ 'decoration' => self::topRule( 'text' ) ] ] )
        );
        $roles = array_map( static fn ( array $r ): string => $r['role'], RuleChipAudit::rules( $recipes ) );
        $this->assertSame( [ 'rule', 'rule-strong', 'rule-strong', 'rule-strong', 'rule-strong', 'rule', 'rule', 'rule-strong' ], $roles );
        $this->assertSame( [ 'divi/group', 'divi/group', 'divi/group', 'divi/group', 'divi/group', 'divi/group', 'divi/group', 'divi/column' ], array_column( RuleChipAudit::rules( $recipes ), 'block' ) );
    }

    public function testTheBandIsTheNearestGroundAndIndexesCountAllBlocksOfAName(): void
    {
        $inner = self::group( self::topRule( 'text' ) ) . self::block( 'divider', self::line( 'border' ) );
        $markup = self::group( [] ) // group 0: a plain wrapper
            . self::group( self::fill( 'surface' ), $inner )                    // group 1 surface; rule group 2, divider 0
            . self::group( self::fill( 'text' ), $inner )                       // group 3 text (a panel); rule group 4, divider 1
            . self::group( self::fill( 'overlay' ), $inner )                    // group 5 overlay (dark); rule group 6, divider 2
            . self::group( self::fill( 'accent' ), $inner )                     // group 7 accent; rule group 8, divider 3
            . self::group( self::fill( 'bg' ), $inner );                        // group 9 bg (light); rule group 10, divider 4
        $map = self::ruleMap( self::one( $markup ) );
        $this->assertSame( [
            'planted|divi/group|2'   => [ 'rule-strong', 'surface' ], 'planted|divi/divider|0' => [ 'rule', 'surface' ],
            'planted|divi/group|4'   => [ 'rule-strong', 'panel' ],   'planted|divi/divider|1' => [ 'rule', 'panel' ],
            'planted|divi/group|6'   => [ 'rule-strong', 'dark' ],    'planted|divi/divider|2' => [ 'rule', 'dark' ],
            'planted|divi/group|8'   => [ 'rule-strong', 'accent' ],  'planted|divi/divider|3' => [ 'rule', 'accent' ],
            'planted|divi/group|10'  => [ 'rule-strong', 'light' ],   'planted|divi/divider|4' => [ 'rule', 'light' ],
        ], $map );
    }

    public function testTheListsAreTheSameBeforeAndAfterTheMigration(): void
    {
        $surface = static fn ( string $inner ): string => self::group( self::fill( 'surface' ), $inner );
        $before  = $surface(
            self::group( self::topRule( 'border' ) ) . self::group( self::topRule( 'text' ) ) . self::block( 'divider', self::line( 'border' ) )
            . self::block( 'divider', self::line( 'inverse' ) )
            . self::block( 'icon', [ 'module' => [ 'decoration' => self::fill( 'accent' ) + self::radius( '8px' ) ] ] )
            . self::group( [ 'border' => [ 'desktop' => [ 'value' => [
                'radius' => [ 'topLeft' => '6px', 'topRight' => '6px', 'bottomRight' => '6px', 'bottomLeft' => '6px' ],
                'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}', 'style' => 'solid' ] ],
            ] ] ] ] )
        );
        $after = $surface(
            self::group( [], '', [ 'aied' => [ 'kit' => 'rule', 'on' => 'surface' ] ] ) . self::group( [], '', [ 'aied' => [ 'kit' => 'rule-strong', 'on' => 'surface' ] ] )
            . self::block( 'divider', [ 'aied' => [ 'kit' => 'rule', 'on' => 'surface' ] ] ) . self::block( 'divider', [ 'aied' => [ 'kit' => 'rule-strong', 'on' => 'surface' ] ] )
            . self::block( 'icon', [ 'aied' => [ 'kit' => 'chip', 'on' => 'surface' ] ] )
            . self::group( [], '', [ 'aied' => [ 'kit' => 'chip', 'on' => 'surface' ] ] )
        );
        $strip = static fn ( array $rows ): array => array_map( static fn ( array $r ): array => array_diff_key( $r, [ 'colour' => 0, 'marked' => 0 ] ), $rows );
        $this->assertCount( 4, RuleChipAudit::rules( self::one( $before ) ) );
        $this->assertSame( $strip( RuleChipAudit::rules( self::one( $before ) ) ), $strip( RuleChipAudit::rules( self::one( $after ) ) ) );
        $this->assertSame( array_map( static fn ( array $r ): array => array_diff_key( $r, [ 'marked' => 0 ] ), RuleChipAudit::chips( self::one( $before ) ) ),
            array_map( static fn ( array $r ): array => array_diff_key( $r, [ 'marked' => 0 ] ), RuleChipAudit::chips( self::one( $after ) ) ) );
        $this->assertCount( 2, RuleChipAudit::chips( self::one( $before ) ) );
        $this->assertSame( [ false, false, false, false ], array_column( RuleChipAudit::rules( self::one( $before ) ), 'marked' ) );
        $this->assertSame( [ true, true, true, true ], array_column( RuleChipAudit::rules( self::one( $after ) ), 'marked' ) );
    }

    public function testTheFloorMeasurementCompositesTheColourOverItsBand(): void
    {
        $lang = DesignLanguages::get( 'soft-saas' );
        $this->assertIsArray( $lang );
        $this->assertEqualsWithDelta( 1.184, RuleChipAudit::ratio( 'border', 'light', $lang ), 0.0005 );
        // An alpha form is composited, not read as opaque: it is weaker than the opaque colour on the same band.
        $this->assertLessThan( RuleChipAudit::ratio( 'text', 'light', $lang ), RuleChipAudit::ratio( 'text:20', 'light', $lang ) );
        $cells = RuleChipAudit::floorCells( self::one( self::group( self::fill( 'bg' ), self::group( self::topRule( 'border' ) ) ) ) );
        $this->assertCount( 1, $cells );
        $this->assertSame( 'border', $cells[0]['colour'] );
        $this->assertSame( 'light', $cells[0]['band'] );
        $this->assertSame( 1, $cells[0]['count'] );
        $this->assertSame( 'soft-saas', $cells[0]['language'] );
        $this->assertEqualsWithDelta( 1.184, $cells[0]['ratio'], 0.0005 );
    }

    public function testAMarkedRuleCannotBeMeasuredAndAnUnknownBandIsRefused(): void
    {
        $marked = self::one( self::group( self::fill( 'surface' ), self::group( [], '', [ 'aied' => [ 'kit' => 'rule', 'on' => 'surface' ] ] ) ) );
        try {
            RuleChipAudit::floorCells( $marked );
            $this->fail( 'a marked rule has no colour to measure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'unmigrated', $e->getMessage() );
        }
        $lang = DesignLanguages::get( 'soft-saas' );
        $this->assertIsArray( $lang );
        $this->expectException( \RuntimeException::class );
        RuleChipAudit::ratio( 'border', 'none', $lang );
    }
}
