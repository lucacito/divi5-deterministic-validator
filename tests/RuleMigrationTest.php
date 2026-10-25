<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\RuleChipAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/Support/RuleChipAudit.php';

/**
 * Recipe-level guards for the rules of the section recipes driven by a style kit (markers `rule` and `rule-strong` on a
 * `divi/divider`, a `divi/group` or a `divi/column`; rules and chips adapter, plan 2026-10-21, Task 5), kept apart from
 * RecipeLibraryTest to keep that file smaller. The rule adapter writes the line (a divider's colour, weight and style) or the
 * top edge (a group's or column's `module.decoration.border` styles), so a migrated block must carry none of its own:
 *
 * - own ink: a marked divider holds no line colour, weight or style, a marked group or column no `module.decoration.border`;
 * - band: the marker's `on` equals the band the rule really sits on (the shared walk; `other:text`, an inverted panel, is
 *   `panel`) and the role may sit there; a marked group or column paints no ground of its own (the walk would not see it);
 * - merge trap: after the kit resolves (and, when given, the language), every leaf the patch writes holds exactly the
 *   patch's value and every other leaf is one the recipe may keep (RULE_KEEPS: structure, never the line);
 * - none bespoke: BESPOKE_RULES is empty (cap 0); the only rule-like blocks left literal are the named SHAPE_EXEMPTIONS;
 * - prose: a recipe with a rule marker does not describe the line the kit decides.
 *
 * The audit (tests/Support/RuleChipAudit.php) finds a rule by its decoration or, once migrated, by its marker, so it lists the
 * same 57 blocks before and after; the audit ignores a marker's `on`, which is why bandProblems() compares it here.
 */
class RuleMigrationTest extends TestCase
{
    private const ROLES = [ 'rule', 'rule-strong' ];

    /**
     * The carriers this test was written for. Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): a counter's
     * `rule-strong` marker is CarrierMigrationTest's, so ruleMarker() answers only on these three blocks.
     */
    private const RULE_BLOCKS = [ 'divi/divider', 'divi/group', 'divi/column' ];

    /** The bands a rule may sit on (KitAdapters::RULE_BANDS). */
    private const BANDS = [ 'light', 'surface', 'accent', 'dark', 'panel' ];

    /**
     * The leaves of a marked rule the patch decides: a divider's line colour, weight and style (any breakpoint, any state),
     * and the whole `module.decoration.border` of a group or column.
     */
    private const OWN_INK = [
        'divi/divider' => [ '/^divider\.advanced\.line\.[^.]+\.[^.]+\.(color|weight|style)$/' ],
        'divi/group'   => [ '/^module\.decoration\.border(\.|$)/' ],
        'divi/column'  => [ '/^module\.decoration\.border(\.|$)/' ],
    ];

    /**
     * Leaf paths a marked rule may keep that the patch does not write (regexes over dotted paths; any breakpoint and state):
     * the block version, the preset, the Theme Builder area and the `locked` switch, the module's advanced and meta settings,
     * layout, sizing, spacing, position, z-index, animation and attributes (a rule's gap and padding are layout), and a
     * divider's `position` and `show` (Divi's own default placement and visibility; the kit never writes them). Anything
     * else, a stray line colour at another breakpoint included, fails closed.
     */
    private const RULE_KEEPS = [
        '/^builderVersion$/',
        '/^modulePreset(\.|$)/',
        '/^themeBuilderArea(\.|$)/',
        '/^locked(\.|$)/',
        '/^module\.(advanced|meta)(\.|$)/',
        '/^module\.decoration\.(layout|sizing|spacing|position|zIndex|animation|attributes)(\.|$)/',
        '/^divider\.advanced\.line\.[^.]+\.[^.]+\.(position|show)$/',
    ];

    /**
     * Rule blocks deliberately NOT driven by a style kit: "<recipe>|<block>|<index among that block name>" => why. SHRINK-ONLY
     * and EMPTY since rules and chips Task 5 (cap 0): every rule the audit finds carries a marker, and nothing may be added.
     * (The shape decisions are not bespoke rules: they are SHAPE_EXEMPTIONS of the audit, pinned below.)
     */
    private const BESPOKE_RULES = [];

    private const BESPOKE_RULES_MAX = 0;

    /** The file size before the rules and chips were migrated (rules and chips plan, Task 5): the file must end below it. */
    private const SIZE_BEFORE_RULES = 670981;

    /**
     * The 57 rule markers: "<recipe>|<block>|<index among that block name>" => "<role> on <band>". Pinned as literals, never
     * read from git or the recipes, so a lost, added or re-roled marker fails here. The role is the colour the old rule drew
     * (`border` or an alpha colour = `rule`, every other colour = `rule-strong`, Spec issue 6); the band is what the walk makes
     * it (`panel` = inside an inverted, `text`-filled band or panel). 28 dividers, 26 groups and 3 columns; 22 `rule` and 35
     * `rule-strong`. The two accent dividers (blog-feed 0, icon-values 0) were brand marks left literal (Ruling 8); since the
     * carriers adapter they carry `rule-accent`, which CarrierMigrationTest pins.
     */
    private const MIGRATED_RULES = [
        'card-grid-4|divi/divider|0'                     => 'rule-strong on dark',
        'card-grid-4|divi/divider|1'                     => 'rule-strong on dark',
        'hero-image-scrim|divi/divider|0'                => 'rule-strong on dark',
        'hero-editorial-minimal|divi/divider|0'          => 'rule on light',
        'hero-spec-card|divi/divider|0'                  => 'rule on surface',
        'hero-spec-card|divi/divider|1'                  => 'rule on surface',
        'hero-spec-card|divi/divider|2'                  => 'rule on surface',
        'hero-spec-card|divi/divider|3'                  => 'rule on surface',
        'header-divider-intro|divi/divider|0'            => 'rule-strong on panel',
        'list-icon-dividers|divi/divider|0'              => 'rule-strong on surface',
        'list-icon-dividers|divi/divider|1'              => 'rule on surface',
        'list-icon-dividers|divi/divider|2'              => 'rule on surface',
        'list-icon-dividers|divi/divider|3'              => 'rule on surface',
        'list-icon-dividers|divi/divider|4'              => 'rule on surface',
        'steps-numbered|divi/divider|0'                  => 'rule on surface',
        'steps-numbered|divi/divider|1'                  => 'rule on surface',
        'steps-horizontal|divi/divider|0'                => 'rule on light',
        'steps-horizontal|divi/divider|1'                => 'rule on light',
        'steps-horizontal|divi/divider|2'                => 'rule on light',
        'stats-big-numbers|divi/divider|0'               => 'rule-strong on panel',
        'stats-big-numbers|divi/divider|1'               => 'rule-strong on panel',
        'stats-big-numbers|divi/divider|2'               => 'rule-strong on panel',
        'proof-press-quotes|divi/column|1'               => 'rule-strong on surface',
        'proof-press-quotes|divi/column|2'               => 'rule-strong on surface',
        'proof-press-quotes|divi/column|3'               => 'rule-strong on surface',
        'quote-over-image|divi/divider|0'                => 'rule-strong on dark',
        'pricing-three-tier|divi/group|4'                => 'rule on surface',
        'pricing-three-tier|divi/group|12'               => 'rule on accent',
        'pricing-three-tier|divi/group|18'               => 'rule on surface',
        'pricing-two-plan-highlight|divi/group|2'        => 'rule-strong on light',
        'pricing-two-plan-highlight|divi/group|9'        => 'rule-strong on panel',
        'team-grid|divi/group|0'                         => 'rule-strong on light',
        'team-grid|divi/group|1'                         => 'rule-strong on light',
        'team-grid|divi/group|2'                         => 'rule-strong on light',
        'team-grid|divi/group|3'                         => 'rule-strong on light',
        'values-list|divi/divider|0'                     => 'rule-strong on panel',
        'values-list|divi/divider|1'                     => 'rule-strong on panel',
        'values-list|divi/divider|2'                     => 'rule-strong on panel',
        'values-list|divi/divider|3'                     => 'rule-strong on panel',
        'faq-two-column|divi/group|0'                    => 'rule-strong on surface',
        'faq-two-column|divi/group|1'                    => 'rule-strong on surface',
        'faq-two-column|divi/group|2'                    => 'rule-strong on surface',
        'faq-two-column|divi/group|3'                    => 'rule-strong on surface',
        'faq-two-column|divi/group|4'                    => 'rule-strong on surface',
        'faq-two-column|divi/group|5'                    => 'rule-strong on surface',
        'faq-toggle-list|divi/group|2'                   => 'rule-strong on light',
        'story-timeline|divi/group|3'                    => 'rule-strong on dark',
        'story-timeline|divi/group|4'                    => 'rule-strong on dark',
        'story-timeline|divi/group|5'                    => 'rule-strong on dark',
        'story-timeline|divi/group|6'                    => 'rule-strong on dark',
        'cta-card-over-image|divi/divider|0'             => 'rule on surface',
        'before-after-compare|divi/group|1'              => 'rule-strong on surface',
        'before-after-compare|divi/group|2'              => 'rule-strong on surface',
        'contact-details-form|divi/group|1'              => 'rule on light',
        'contact-details-form|divi/group|4'              => 'rule on light',
        'contact-details-form|divi/group|7'              => 'rule on light',
        'contact-details-form|divi/group|10'             => 'rule on light',
    ];

    /**
     * The exempt blocks: key => [what the raw scan makes of the block, a literal the block must still carry]. Four step
     * badges (48px circles) and four timeline dots (14px squares) are shape decisions (Ruling 6), the filled "[Most popular]"
     * badge is a filled pill, not an outline tag (Spec issue 3). Carriers adapter (plan 2026-10-24, Task 5): the four counters
     * and the two accent dividers left this list (15 -> 9 blocks); they carry carrier markers (CarrierMigrationTest).
     */
    private const EXEMPT_LOOKS = [
        'steps-horizontal|divi/group|5'  => 'filled',
        'steps-horizontal|divi/group|9'  => 'filled',
        'steps-horizontal|divi/group|13' => 'filled',
        'steps-horizontal|divi/group|17' => 'filled',
        'timeline-vertical|divi/group|3'  => 'filled',
        'timeline-vertical|divi/group|8'  => 'filled',
        'timeline-vertical|divi/group|13' => 'filled',
        'timeline-vertical|divi/group|18' => 'filled',
        'pricing-three-tier|divi/group|10' => 'filled',
    ];

    /**
     * Phrases that describe the line a style kit now decides (its weight, style and name), banned from the title, description
     * and `when` of a recipe with a rule marker: the prose must be true under every kit.
     */
    public const RULE_DECIDED_PHRASES = [
        '/\b(hairlines?|hairline-ruled)\b/i',
        '/\b(thin|thick|heavy|fine|slim|bold|1px|2px|3px|4px)\s+(\w+\s+)?(rules?|lines?|dividers?|borders?)\b/i',
        '/\b(solid|dashed|dotted|double)\s+(\w+\s+)?(rules?|lines?|dividers?)\b/i',
    ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    /**
     * [role, on] of a rule marker in $attrs, or null (no marker, or any other role). A rule marker without a string `on`
     * throws: the resolver would default it, and a recipe rule must say where it sits. With $block, only a divider, group
     * or column answers (carriers adapter, Ruling 11: a counter's rule is CarrierMigrationTest's).
     *
     * @param array<string,mixed> $attrs
     * @return array{0:string,1:string}|null
     */
    public static function ruleMarker( array $attrs, ?string $block = null ): ?array
    {
        if ( null !== $block && ! in_array( $block, self::RULE_BLOCKS, true ) ) {
            return null;
        }
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! in_array( $m['kit'] ?? null, self::ROLES, true ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'a rule marker without "on": a recipe rule must name its band, never default' );
        }
        return [ (string) $m['kit'], (string) $m['on'] ];
    }

    /** Opening block comments of $markup in document order: list of [name, attrs, index among that name]. @return list<array{0:string,1:array<string,mixed>,2:int}> */
    public static function blocks( string $markup ): array
    {
        preg_match_all( self::BLOCK, $markup, $all, PREG_SET_ORDER );
        $out  = [];
        $seen = [];
        foreach ( $all as $m ) {
            $seen[ $m[1] ] = ( $seen[ $m[1] ] ?? -1 ) + 1;
            $attrs         = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : [];
            $out[]         = [ $m[1], is_array( $attrs ) ? $attrs : [], $seen[ $m[1] ] ];
        }
        return $out;
    }

    /** Dotted path => value of every leaf of $node (an empty array is a leaf). @return array<string,mixed> */
    public static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leaves( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    /** @param list<string> $patterns */
    public static function matchesAny( string $path, array $patterns ): bool
    {
        foreach ( $patterns as $re ) {
            if ( 1 === preg_match( $re, $path ) ) {
                return true;
            }
        }
        return false;
    }

    /** How many rule markers the block comments of $markup carry (parsed, so key order does not matter). */
    private static function markerCount( string $markup ): int
    {
        return count( array_filter( self::blocks( $markup ), static fn ( array $b ): bool => null !== self::ruleMarker( $b[1], $b[0] ) ) );
    }

    /**
     * (a): what the RAW markup's marked rules still carry of their own ([] = fine): a line colour, weight or style on a
     * divider, a `module.decoration.border` on a group or column.
     *
     * @return list<string>
     */
    public static function ownInkProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( self::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            $marker = self::ruleMarker( $attrs, $name );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            foreach ( array_keys( self::leaves( $attrs ) ) as $path ) {
                if ( ! str_starts_with( $path, 'aied.' ) && self::matchesAny( $path, self::OWN_INK[ $name ] ?? [ '/^$/' ] ) ) {
                    $out[] = "$label $name#$i ({$marker[0]} on {$marker[1]}): the migrated block must drop its own $path";
                }
            }
        }
        return $out;
    }

    /**
     * (c): every rule marker's `on` must equal the band its nearest ground makes (RecipeLibraryTest's band walk; `other:text`,
     * an inverted panel or band, is `panel`), that band must be one the role may sit on, and a marked group or column must
     * not paint a ground of its own (the walk looks at ancestors only, so its own ground would make `on` wrong).
     *
     * @return list<string>
     */
    public static function bandProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( RecipeLibraryTest::markerBands( $markup, static fn ( array $a ): ?array => self::ruleMarker( $a ) ) as $key => [ $marker, $band ] ) {
            if ( null === self::ruleMarker( [ 'aied' => [ 'kit' => $marker[0], 'on' => $marker[1] ] ], substr( $key, 0, (int) strrpos( $key, '#' ) ) ) ) {
                continue; // a counter's rule: CarrierMigrationTest's (carriers adapter, Ruling 11)
            }
            ++$checked;
            $band = 'other:text' === $band ? 'panel' : $band;
            if ( $marker[1] !== $band ) {
                $out[] = "$label $key: {$marker[0]} marker on={$marker[1]}, but the nearest ground makes it $band";
            }
            if ( ! in_array( $band, self::BANDS, true ) ) {
                $out[] = "$label $key: {$marker[0]} on a $band band (it sits on " . implode( '|', self::BANDS ) . ' only)';
            }
        }
        foreach ( self::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            if ( in_array( $name, [ 'divi/group', 'divi/column' ], true ) && null !== self::ruleMarker( $attrs, $name ) && isset( $attrs['module']['decoration']['background'] ) ) {
                $out[] = "$label $name#$i: a marked rule with a background of its own (the band walk would not see it)";
            }
        }
        return $out;
    }

    /**
     * (d) the merge trap: for every block that carries a rule marker in $raw, the same block (name and index) in $resolved
     * (after the kit $kit and, when given, the language $lang): every leaf the patch writes holds exactly the patch's value
     * (the patch resolved the same way), and every other leaf matches RULE_KEEPS.
     *
     * @param array<string,mixed>      $kit
     * @param array<string,mixed>|null $lang
     * @return list<string>
     */
    public static function mergeTrapProblems( string $label, string $raw, string $resolved, array $kit, ?array $lang, int &$checked = 0 ): array
    {
        $out   = [];
        $after = [];
        foreach ( self::blocks( $resolved ) as [ $name, $attrs, $i ] ) {
            $after[ "$name#$i" ] = $attrs;
        }
        foreach ( self::blocks( $raw ) as [ $name, $attrs, $i ] ) {
            $marker = self::ruleMarker( $attrs, $name );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            $where = "$label $name#$i ({$marker[0]} on {$marker[1]})";
            $got   = $after[ "$name#$i" ] ?? null;
            $bare  = KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => $marker[0], 'on' => $marker[1] ] ] ) . ' /-->', $kit );
            if ( null !== $lang ) {
                $bare = RecipeTokens::resolve( $bare, $lang );
            }
            $expect = self::blocks( $bare )[0][1] ?? null;
            if ( ! is_array( $got ) || ! is_array( $expect ) || [] === $expect || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $want = self::leaves( $expect );
            foreach ( self::leaves( $got ) as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value, JSON_UNESCAPED_SLASHES ) . ', not the kit\'s ' . json_encode( $want[ $path ], JSON_UNESCAPED_SLASHES );
                    }
                } elseif ( ! self::matchesAny( $path, self::RULE_KEEPS ) ) {
                    $out[] = "$where: keeps $path that the kit does not write";
                }
            }
            foreach ( array_diff_key( $want, self::leaves( $got ) ) as $path => $value ) {
                $out[] = "$where: lacks the kit's $path";
            }
        }
        return $out;
    }

    /**
     * The rule markers of the recipe FILE: [parsed from block comments, spelled `"kit":"rule"` or `"kit":"rule-strong"` in
     * the block comments of a divider, group or column]. Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): the spelled
     * count was over the whole markup and would count the four counters' `rule-strong`; it is now per block comment of the
     * three carriers this test was written for.
     *
     * @return array{0:int,1:int}
     */
    private static function markersInFile(): array
    {
        $parsed  = 0;
        $spelled = 0;
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            $markup   = (string) $recipe['markup'];
            $parsed  += self::markerCount( $markup );
            preg_match_all( '/<!--\s+wp:(divi\/[a-z0-9-]+)\s.*?-->/s', $markup, $comments, PREG_SET_ORDER );
            foreach ( $comments as $c ) {
                if ( in_array( $c[1], self::RULE_BLOCKS, true ) ) {
                    $spelled += substr_count( $c[0], '"kit":"rule"' ) + substr_count( $c[0], '"kit":"rule-strong"' );
                }
            }
        }
        return [ $parsed, $spelled ];
    }

    public function testMarkedRulesCarryNoInkOfTheirOwnAndMatchTheirBand(): void
    {
        [ $parsed, $spelled ] = self::markersInFile();
        $this->assertSame( $spelled, $parsed, 'every "kit":"rule|rule-strong" in the file is a top-level marker of a block comment' );
        $own      = [];
        $bands    = [];
        $ownSeen  = 0;
        $bandSeen = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $own   = array_merge( $own, self::ownInkProblems( $name, $raw, $ownSeen ) );
            $bands = array_merge( $bands, self::bandProblems( $name, $raw, $bandSeen ) );
        }
        $this->assertSame( 57, $parsed, 'the 57 migrated rules carry exactly 57 markers' );
        $this->assertSame( $parsed, $ownSeen, 'every rule marker in the file is checked for its own line' );
        $this->assertSame( $parsed, $bandSeen, 'every rule marker in the file is checked against its band' );
        $this->assertSame( [], $own, 'a migrated rule keeps its own line colour, weight, style or border' );
        $this->assertSame( [], $bands, 'a rule\'s "on" must name the band it is really drawn on, or the kit picks the wrong colour' );
    }

    public function testMarkedRulesKeepOnlyStructureUnderEveryKitAndLanguage(): void
    {
        $problems = [];
        $blocks   = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( 0 === self::markerCount( $raw ) ) {
                continue;
            }
            foreach ( StyleKits::ids() as $n => $kitId ) {
                $kit      = (array) StyleKits::get( $kitId );
                $seen     = 0;
                $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$kitId", $raw, KitResolver::resolve( $raw, $kit ), $kit, null, $seen ) );
                if ( 0 === $n ) {
                    $blocks += $seen;
                }
                foreach ( DesignLanguages::ids() as $langId ) {
                    $lang = DesignLanguages::get( $langId );
                    $this->assertIsArray( $lang );
                    $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$kitId/$langId", $raw, (string) SectionRecipes::recipe( $name, $langId, $kitId ), $kit, $lang ) );
                }
            }
        }
        $this->assertSame( 57, $blocks, 'the merge trap walks all 57 rule markers' );
        $this->assertSame( self::markersInFile()[0], $blocks, 'the merge trap checks every rule marker in the file' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked rule keeps only structure: its own line survives the kit\'s deep merge at the breakpoints the kit does not write' );
    }

    /** Every rule-like block of the recipe file the audit sees (the exempt ones included): "<recipe>|<block>|<index>" => the marker as "<role> on <band>" or null. @return array<string,?string> */
    private static function ruleBlocks(): array
    {
        $recipes = (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $out     = [];
        foreach ( RuleChipAudit::rules( $recipes, true ) as $r ) {
            $out[ RuleChipAudit::key( $r ) ] = $r['marked'] ? $r['role'] . ' on ' . $r['on'] : null;
        }
        return $out;
    }

    public function testTheFiftySevenRuleMarkersArePinnedAndNoRuleIsBespoke(): void
    {
        $blocks = self::ruleBlocks();
        $marked = array_filter( $blocks, static fn ( ?string $m ): bool => null !== $m );
        // The audit reads the role from the marker and the band from the walk; the marker's own `on` is compared by bandProblems().
        $pinned = self::MIGRATED_RULES;
        ksort( $pinned );
        ksort( $marked );
        $this->assertSame( $pinned, $marked, 'exactly these blocks carry a rule marker, with this role and this band' );
        $this->assertCount( 57, $marked );
        $this->assertCount( 22, array_filter( $marked, static fn ( string $m ): bool => str_starts_with( $m, 'rule on ' ) ) );
        $this->assertCount( 35, array_filter( $marked, static fn ( string $m ): bool => str_starts_with( $m, 'rule-strong on ' ) ) );
        $carriers = array_count_values( array_map( static fn ( string $k ): string => explode( '|', $k )[1], array_keys( $marked ) ) );
        ksort( $carriers );
        $this->assertSame( [ 'divi/column' => 3, 'divi/divider' => 28, 'divi/group' => 26 ], $carriers );
        $this->assertCount( 21, array_unique( array_map( static fn ( string $k ): string => explode( '|', $k )[0], array_keys( $marked ) ) ), 'the 57 rules sit in 21 recipes' );
    }

    public function testNoRuleIsBespokeAndEveryUnmarkedRuleLikeBlockIsAShapeDecision(): void
    {
        $unmarked = array_keys( array_filter( self::ruleBlocks(), static fn ( ?string $m ): bool => null === $m ) );
        foreach ( $unmarked as $key ) {
            $this->assertArrayHasKey( $key, RuleChipAudit::SHAPE_EXEMPTIONS, "$key looks like a rule with no rule marker: give it one (BESPOKE_RULES never grows)" );
            $this->assertArrayNotHasKey( $key, self::BESPOKE_RULES );
        }
        // Carriers adapter (plan 2026-10-24, Task 5): the two accent dividers were the only unmarked rule-like blocks (Ruling 8);
        // they carry `rule-accent` now (CarrierMigrationTest), so none is left.
        $this->assertSame( [], $unmarked, 'every rule-like block of the recipes carries a rule marker' );
        $this->assertLessThanOrEqual( self::BESPOKE_RULES_MAX, count( self::BESPOKE_RULES ), 'shrink-only: BESPOKE_RULES can never grow' );
        $this->assertSame( 0, self::BESPOKE_RULES_MAX, 'cap 0: every rule in the recipes is kit-driven or a named shape decision' );
        $this->assertSame( [], self::BESPOKE_RULES );
    }

    public function testTheShapeExemptionsAreExactlyTheNamedBlocks(): void
    {
        $recipes = (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $this->assertSame( array_keys( self::EXEMPT_LOOKS ), array_keys( RuleChipAudit::SHAPE_EXEMPTIONS ), 'the audit names exactly these 9 blocks, in this order (15 before the carriers adapter)' );
        $seen = RuleChipAudit::exemptionsSeen( $recipes );
        foreach ( self::EXEMPT_LOOKS as $key => $looks ) {
            $this->assertNotSame( '', trim( RuleChipAudit::SHAPE_EXEMPTIONS[ $key ] ), "$key has a reason" );
            $this->assertTrue( $seen[ $key ]['exists'], "$key still exists" );
            $this->assertSame( $looks, $seen[ $key ]['looks'], "$key is still literal: it keeps its own $looks look and carries no marker" );
        }
        // And none of them carries a kit marker of any kind.
        foreach ( $recipes as $recipe ) {
            foreach ( self::blocks( (string) $recipe['markup'] ) as [ $name, $attrs, $i ] ) {
                if ( isset( self::EXEMPT_LOOKS[ $recipe['name'] . '|' . $name . '|' . $i ] ) ) {
                    $this->assertArrayNotHasKey( 'aied', $attrs, $recipe['name'] . "|$name|$i is a shape decision: it stays literal" );
                }
            }
        }
        // Carriers adapter (plan 2026-10-24, Task 5): the two accent dividers no longer draw their own accent line; they carry
        // `rule-accent` on their site preset, and the kit writes the accent line (CarrierMigrationTest pins them).
        foreach ( [ 'blog-feed', 'icon-values' ] as $name ) {
            $divider = array_values( array_filter( self::blocks( (string) SectionRecipes::rawMarkup( $name ) ), static fn ( array $b ): bool => 'divi/divider' === $b[0] ) )[0][1];
            $this->assertSame( [ 'kit' => 'rule-accent', 'on' => 'light' ], $divider['aied'] ?? null, "$name: its accent mark is kit-driven" );
            $this->assertNull( self::ruleMarker( $divider, 'divi/divider' ), "$name: a rule-accent marker is not a rule marker of this test" );
        }
    }

    public function testRecipesFileIsSmallerThanBeforeTheRules(): void
    {
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_RULES, filesize( $file ), 'migrating the rules and chips shrinks the recipe file (rules and chips plan, Task 5)' );
        $this->assertLessThan( 700 * 1024, filesize( $file ), 'the 700 KB bound holds' );
    }

    public function testRuleMarkedRecipeProseDoesNotDescribeTheRuleLook(): void
    {
        $problems = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            if ( 0 === self::markerCount( (string) $recipe['markup'] ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::RULE_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the style kit decides the line\'s weight and style: say a rule, not its look' );
        // The phrases catch the wording they were written for, and leave the shipped role prose alone.
        foreach ( [ 'separated by hairline rules', 'a thin rule', 'each column topped by a heavy rule', 'a 1px rule under the title', 'a dashed line between rows', 'a hairline and a button', 'under a thick divider' ] as $old ) {
            $hit = false;
            foreach ( self::RULE_DECIDED_PHRASES as $phrase ) {
                $hit = $hit || 1 === preg_match( $phrase, $old );
            }
            $this->assertTrue( $hit, $old );
        }
        foreach ( [ 'a ruled list of three ticked features', 'each pair under a rule', 'a short light rule and the name and role', 'a card of ruled label/value lines', 'a rule between steps' ] as $fine ) {
            foreach ( self::RULE_DECIDED_PHRASES as $phrase ) {
                $this->assertSame( 0, preg_match( $phrase, $fine ), $fine );
            }
        }
    }

    // ---- planted cases: each check fails on an unmigrated recipe with a marker added, and passes on the clean shape ----

    /** The old rule ink of a block, as the unmigrated recipes carried it, plus the same at a breakpoint the kit never writes. @return array<string,mixed> */
    private static function oldInk( string $name ): array
    {
        $edge = static fn ( string $colour ): array => [ 'styles' => [ 'all' => [ 'width' => '0px', 'color' => $colour, 'style' => 'solid' ], 'top' => [ 'width' => '2px' ] ] ];
        if ( 'divi/divider' === $name ) {
            $line = static fn ( array $v ): array => [ 'desktop' => [ 'value' => $v ], 'tablet' => [ 'value' => [ 'weight' => '3px' ] ] ];
            return [ 'divider' => [ 'advanced' => [ 'line' => $line( [ 'color' => '{{aied:color:border}}', 'weight' => '1px', 'style' => 'dashed' ] ) ] ] ];
        }
        return [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => $edge( '{{aied:color:text}}' ) ], 'tablet' => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '4px' ] ] ] ] ] ] ] ];
    }

    /** $markup with the old rule ink put back on every rule marker. */
    private static function withOldInk( string $markup ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::ruleMarker( $attrs, $m[1] ) ) {
                    return $m[0];
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( array_replace_recursive( $attrs, self::oldInk( $m[1] ) ) ), $m[0] );
            },
            $markup
        );
    }

    /** $markup with every rule marker's `on` replaced by $on. */
    private static function withBand( string $markup, string $on ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $on ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::ruleMarker( $attrs, $m[1] ) ) {
                    return $m[0];
                }
                $attrs['aied']['on'] = $on;
                return str_replace( $m[2], RecipeTokens::encodeJson( $attrs ), $m[0] );
            },
            $markup
        );
    }

    /** $markup with the own ink of every marked rule removed: every leaf that is not in RULE_KEEPS and not the marker is dropped (the migration this task makes by hand). */
    private static function migrate( string $markup ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::ruleMarker( $attrs, $m[1] ) ) {
                    return $m[0];
                }
                $clean = [];
                foreach ( self::leaves( $attrs ) as $path => $value ) {
                    if ( str_starts_with( $path, 'aied.' ) || self::matchesAny( $path, self::RULE_KEEPS ) ) {
                        $ref = &$clean;
                        foreach ( explode( '.', $path ) as $k ) {
                            $ref = &$ref[ $k ];
                        }
                        $ref = $value;
                        unset( $ref );
                    }
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( $clean ), $m[0] );
            },
            $markup
        );
    }

    /** One recipe per carrier: a divider (with `position` kept), a group, a column. */
    private const PLANTED = [ 'card-grid-4' => 'divi/divider', 'team-grid' => 'divi/group', 'proof-press-quotes' => 'divi/column' ];

    public function testAnUnmigratedRecipeWithAMarkerFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $kits = [ (array) StyleKits::get( 'quiet-solid' ), (array) StyleKits::get( 'hairline-dashed' ), (array) StyleKits::get( 'raw-frame' ) ];
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        foreach ( self::PLANTED as $name => $carrier ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            $this->assertGreaterThan( 0, self::markerCount( $raw ), "$name is migrated (Task 5)" );
            $count   = self::markerCount( $raw );
            $planted = self::withOldInk( $raw );
            $this->assertSame( $count, self::markerCount( $planted ) );
            // (a) own ink: the old line or border is reported on every marked block.
            $seen = 0;
            $own  = implode( "\n", self::ownInkProblems( $name, $planted, $seen ) );
            $this->assertSame( $count, $seen );
            foreach ( 'divi/divider' === $carrier
                ? [ 'divider.advanced.line.desktop.value.color', 'divider.advanced.line.desktop.value.weight', 'divider.advanced.line.desktop.value.style', 'divider.advanced.line.tablet.value.weight' ]
                : [ 'module.decoration.border.desktop.value.styles.all.color', 'module.decoration.border.desktop.value.styles.top.width', 'module.decoration.border.tablet.value.styles.top.width' ] as $path ) {
                $this->assertStringContainsString( "the migrated block must drop its own $path", $own, "$name $path" );
            }
            // (c) the band matches; a wrong band is caught.
            $this->assertSame( [], self::bandProblems( $name, $planted ) );
            $real  = (string) array_values( array_filter( self::blocks( $raw ), static fn ( array $b ): bool => null !== self::ruleMarker( $b[1] ) ) )[0][1]['aied']['on'];
            $wrong = 'light' === $real ? 'dark' : 'light';
            $this->assertStringContainsString( "marker on=$wrong, but the nearest ground makes it $real", implode( "\n", self::bandProblems( $name, self::withBand( $raw, $wrong ) ) ), "$name a wrong band" );
            // (d) the merge trap: the tablet leaf the kit never writes survives the merge under every kit and is reported.
            foreach ( $kits as $kit ) {
                $trap = implode( "\n", self::mergeTrapProblems( $name, $planted, KitResolver::resolve( $planted, $kit ), $kit, null ) );
                $this->assertStringContainsString( 'divi/divider' === $carrier ? 'keeps divider.advanced.line.tablet.value.weight that the kit does not write' : 'keeps module.decoration.border.tablet.value.styles.top.width that the kit does not write', $trap, "$name {$kit['id']}" );
            }

            // The clean shape: the same recipe with its own ink removed passes all three checks, kit-only and in a language.
            $clean = self::migrate( $planted );
            $this->assertSame( $count, self::markerCount( $clean ) );
            $this->assertSame( [], self::ownInkProblems( $name, $clean ) );
            $this->assertSame( [], self::bandProblems( $name, $clean ) );
            foreach ( $kits as $kit ) {
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, KitResolver::resolve( $clean, $kit ), $kit, null ), "$name {$kit['id']} clean" );
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, RecipeTokens::resolve( KitResolver::resolve( $clean, $kit ), $lang ), $kit, $lang ), "$name {$kit['id']} clean in luxe" );
            }
            // The clean shape kept its structure (only the line was dropped).
            $first = array_values( array_filter( self::blocks( $clean ), static fn ( array $b ): bool => null !== self::ruleMarker( $b[1] ) ) )[0][1];
            $this->assertArrayHasKey( 'builderVersion', $first, "$name keeps its version" );
            if ( 'divi/divider' === $carrier ) {
                $this->assertSame( 'center', $first['divider']['advanced']['line']['desktop']['value']['position'] ?? null, "$name keeps the divider's position" );
            } else {
                $this->assertNotEmpty( $first['module']['decoration']['spacing']['desktop']['value']['padding']['top'] ?? null, "$name keeps its rule's top padding" );
            }
        }
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $kit  = (array) StyleKits::get( 'quiet-solid' );
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $blk  = static fn ( string $name, array $attrs ): string => '<!-- wp:divi/' . $name . ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
        $run  = static fn ( string $raw, ?array $k = null ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $k ?? $kit ), $k ?? $kit, null ) );
        $mark = [ 'kit' => 'rule', 'on' => 'light' ];

        // Clean: the divider's position, the group's layout, spacing and sizing, the version and preset.
        $divider = [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'position' => 'center', 'show' => 'on' ] ] ] ] ], 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'bottom' => '0px' ] ] ] ] ] ], 'modulePreset' => [ 'default' ], 'builderVersion' => '5.8.0', 'aied' => $mark ];
        $group   = [ 'module' => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [ 'flexDirection' => 'column' ] ] ], 'sizing' => [ 'desktop' => [ 'value' => [ 'size' => [ 'flexGrow' ] ] ] ], 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '24px' ] ] ] ] ] ], 'builderVersion' => '5.8.0', 'aied' => $mark ];
        $this->assertSame( '', $run( $sec( 'bg', $blk( 'divider', $divider ) ) ) );
        $this->assertSame( '', $run( $sec( 'bg', $blk( 'group', $group ) ) ) );

        // A line or border at a breakpoint or state the kit does not write, or a stray path: each is kept by the deep merge.
        $strays = [
            [ 'divider', $divider, 'divider.advanced.line.tablet.value.color', [ 'divider' => [ 'advanced' => [ 'line' => [ 'tablet' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ] ],
            [ 'divider', $divider, 'divider.advanced.line.phone.value.weight', [ 'divider' => [ 'advanced' => [ 'line' => [ 'phone' => [ 'value' => [ 'weight' => '4px' ] ] ] ] ] ] ],
            [ 'divider', $divider, 'module.decoration.border.desktop.value.radius.topLeft', [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => '9px' ] ] ] ] ] ] ] ],
            [ 'group', $group, 'module.decoration.border.tablet.value.styles.top.width', [ 'module' => [ 'decoration' => [ 'border' => [ 'tablet' => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '4px' ] ] ] ] ] ] ] ] ],
            [ 'group', $group, 'module.decoration.border.desktop.value.radius.topLeft', [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => '9px' ] ] ] ] ] ] ] ],
            [ 'group', $group, 'module.decoration.background.desktop.value.color', [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ] ],
            [ 'group', $group, 'module.decoration.boxShadow.desktop.value.blur', [ 'module' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'blur' => '40px' ] ] ] ] ] ] ],
        ];
        foreach ( $strays as [ $name, $base, $path, $add ] ) {
            $got = $run( $sec( 'bg', $blk( $name, array_replace_recursive( $base, $add ) ) ) );
            $this->assertStringContainsString( "keeps $path that the kit does not write", $got, $path );
        }
        // A resolved block whose edge lost the merge (a value other than the kit's) and a block that was never resolved.
        $raw      = $sec( 'bg', $blk( 'group', $group ) );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $kit ), $kit, null ) );
        $changed = preg_replace( '/"top":\{"width":"[^"]*","style":"[^"]*","color":"[^"]*"\}/', '"top":{"width":"9px","style":"solid","color":"#123456"}', KitResolver::resolve( $raw, $kit ) );
        $this->assertStringContainsString( 'module.decoration.border.desktop.value.styles.top.width is "9px", not the kit\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) $changed, $kit, null ) ) );
        $this->assertStringContainsString( 'not resolved', implode( "\n", self::mergeTrapProblems( 'p', $raw, $raw, $kit, null ) ) );
        // A language pass compares against the patch resolved in that language.
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, $lang ) );
        $this->assertNotSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, (array) DesignLanguages::get( 'luxe' ) ), 'another language\'s colours are not the kit\'s' );
    }

    public function testTheRuleBandWalkCatchesWhatItShould(): void
    {
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $rule = static fn ( string $on, string $role = 'rule', string $extra = '' ): string => '<!-- wp:divi/divider {' . $extra . '"aied":{"on":"' . $on . '","kit":"' . $role . '"}} /-->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $rule( 'light' ) ) ), 'key order does not matter' );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $rule( 'surface', 'rule-strong' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'accent', $rule( 'accent' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'overlay', $rule( 'dark' ) ) ) );
        $this->assertSame( [ 'p divi/divider#0: rule marker on=light, but the nearest ground makes it surface' ], self::bandProblems( 'p', $sec( 'surface', $rule( 'light' ) ) ) );
        $this->assertSame( [ 'p divi/divider#0: rule-strong marker on=dark, but the nearest ground makes it accent' ], self::bandProblems( 'p', $sec( 'accent', $rule( 'dark', 'rule-strong' ) ) ) );
        // An inverted panel (a text-filled band) is `panel`, a photo row is `dark`; a rule inside a marked card sits on the card's fill.
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:text}}"}}}}}} -->' . $rule( 'panel', 'rule-strong' ) . '<!-- /wp:divi/group -->' ) ) );
        $this->assertCount( 1, self::bandProblems( 'p', $sec( 'bg', '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:text}}"}}}}}} -->' . $rule( 'dark', 'rule-strong' ) . '<!-- /wp:divi/group -->' ) ) );
        $photo = '<!-- wp:divi/row {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"{{aied:image:x}}"}}}}}}} -->' . $rule( 'dark', 'rule-strong' ) . '<!-- /wp:divi/row -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $photo ) ) );
        $this->assertCount( 1, self::bandProblems( 'p', $sec( 'bg', str_replace( '"on":"dark"', '"on":"light"', $photo ) ) ) );
        $card = static fn ( string $outerOn, string $inner ): string => '<!-- wp:divi/group {"aied":{"kit":"card","on":"' . $outerOn . '"}} -->' . $inner . '<!-- /wp:divi/group -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $card( 'light', $rule( 'surface' ) ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $card( 'surface', $rule( 'light' ) ) ) ) );
        // No ground anywhere fails loudly (never defaults to light).
        $this->assertSame( [ 'p divi/divider#0: rule marker on=light, but the nearest ground makes it none', 'p divi/divider#0: rule on a none band (it sits on light|surface|accent|dark|panel only)' ], self::bandProblems( 'p', $rule( 'light' ) ) );
        // A marked group or column with a ground of its own is caught: the walk looks at ancestors only.
        $own   = '"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}},';
        $group = '<!-- wp:divi/group {' . $own . '"aied":{"kit":"rule","on":"light"}} --><!-- /wp:divi/group -->';
        $this->assertSame( [ 'p divi/group#0: a marked rule with a background of its own (the band walk would not see it)' ], self::bandProblems( 'p', $sec( 'bg', $group ) ) );
        // A marker without a band throws; any other role is no rule marker.
        $this->assertNull( self::ruleMarker( [ 'aied' => [ 'kit' => 'card', 'on' => 'light' ] ] ) );
        $this->assertNull( self::ruleMarker( [ 'aied' => [ 'kit' => 'chip', 'on' => 'light' ] ] ) );
        $this->assertNull( self::ruleMarker( [ 'aied' => [ 'kit' => 'control' ] ] ) );
        foreach ( [ [ 'kit' => 'rule' ], [ 'kit' => 'rule-strong', 'on' => '' ], [ 'kit' => 'rule', 'on' => 1 ] ] as $marker ) {
            try {
                self::ruleMarker( [ 'aied' => $marker ] );
                $this->fail( 'a rule marker without a band must fail: ' . json_encode( $marker ) );
            } catch ( \UnexpectedValueException $e ) {
                $this->assertStringContainsString( 'without "on"', $e->getMessage() );
            }
        }
    }
}
