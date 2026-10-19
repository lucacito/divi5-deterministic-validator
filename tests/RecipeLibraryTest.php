<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\DesignVariety;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/Colour.php';
require_once __DIR__ . '/../scripts/card-migration-report.php';

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

    /** Colour roles a `pairs` entry may name => key in a language's `colors`. inverse/overlay exist in every language since plan task 9. */
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

    /** Failure reasons verify-recipes.cjs reports that a render-failures baseline entry may name. `render-error` never may. */
    private const RENDER_CODES = [ 'php-error', 'empty', 'overflow', 'columns-side-by-side', 'console-error', 'gate', 'low-contrast' ];

    /**
     * The most `# render-failures` entries the baseline may hold. It may only be LOWERED (to the new count, in the commit that
     * removes entries), never raised: a new render failure is fixed or the recipe retired, not frozen. Plan task 11 sets it to 0.
     */
    private const RENDER_FAILURE_CAP = 0;

    /** The file size before the button migration (plan Task 7): the recipe file must stay below it (spec section 4). */
    private const SIZE_BEFORE_KITS = 702311;

    /**
     * Recipe buttons deliberately NOT driven by a style kit: "recipe#index" (the index counts the recipe's buttons in
     * markup order) => why. Shrink-only. Both sit on a `text`-coloured (inverted) panel, a surface the button adapter
     * does not have: its `light` ink is `text` (invisible on the panel) and its `dark` ink is `inverse`, which is light on
     * a light `text` panel in the dark languages (glass-dark, tech-mono, luxe). They keep their own colours until the
     * adapter learns an inverted surface.
     */
    private const BESPOKE_BUTTONS = [
        'compare-two-panels#0'         => 'solid accent button on the text-coloured "after" panel (no adapter surface for an inverted panel)',
        'pricing-two-plan-highlight#1' => 'bg-filled button on the text-coloured highlighted plan (no adapter surface for an inverted panel)',
    ];

    /**
     * Label roles a style kit's BUTTON may bring that a recipe need not declare: [label, fill], where the fill names the
     * button fill the label sits on. Today the two `invert` hovers of a filled button (KitAdapters' role table): an accent
     * fill turns into label accent on an accent-text fill, and the surface fill on an accent band into label surface on a
     * text fill.
     *
     * What is exempted, precisely: testEveryTextColourRoleIsTheForegroundOfADeclaredPair drops a label ROLE in this list
     * (the first element) when it appears ONLY in the kit-resolved markup, never in the recipe's own markup. It does not
     * read the fill, so the exemption is by label role, not by exact pair. That is safe because every colour a kit button
     * paints (each label on its real fill, every shipped kit x language x role x surface x rest/hover) is proven >= 4.5:1
     * by KitAdaptersTest::testNoInvisibleButtonInAnyKitLanguageRoleSurface; these three pairs are additionally proven AA in
     * every language by testTheKitLabelPairsAreReadableInEveryLanguage. The third, bg on a text fill, is the role table's
     * on(text) (Task 9: rimmed-solid, a text-filled button, on a photo). A recipe's OWN text colours still need a declared
     * pair. Declaring the pairs in every kit-driven recipe instead would grow the recipe file, which the spec's byte rule
     * forbids.
     */
    private const KIT_LABEL_PAIRS = [ [ 'accent', 'accent-text' ], [ 'surface', 'text' ], [ 'bg', 'text' ] ];

    /** The file size before the card migration (card adapter plan, Task 7): the recipe file must end below it (spec section 4). */
    private const SIZE_BEFORE_CARDS = 695877;

    /**
     * Card surfaces deliberately NOT driven by a style kit (card adapter, plan 2026-10-19): every block that
     * scripts/card-migration-report.php classes `card`, `panel` or `inverted` and that carries no card/panel marker.
     * Key "<block>|<recipe>|<index>", where index is the report's: the block's order among ALL blocks of that name in the
     * recipe (document order), so `divi/group|compare-two-panels|2` is the recipe's third group. Value: why.
     *
     * SHRINK-ONLY (testBespokeCardsAreListedAndShrinkOnly): it can never hold more than BESPOKE_CARDS_MAX entries, a listed
     * block that gets a marker fails until its entry is removed, and the migration tasks lower BESPOKE_CARDS_MAX as they
     * remove entries. The inverted surfaces stay (spec "Inverted surfaces": no adapter role for a text-coloured or accent
     * ground in v1); the rest are the column cards Task 9 migrates (Task 8 migrated every group card and panel). An inverted
     * group that sits beside a kit card takes the kit's corner radius through the whole-value token `{{aied:kit:radius-card}}`
     * (testBespokeGroupCornersFollowTheKit), so the pair never has mismatched corners; its fill, frame and padding stay.
     */
    private const BESPOKE_CARDS = [
        'divi/group|compare-two-panels|7'          => 'inverted text-coloured "after" panel: no adapter role for an inverted ground',
        'divi/group|features-bento|1'              => 'accent bento tile: no adapter role for an inverted ground',
        'divi/group|features-bento|3'              => 'inverted bento tile: no adapter role for an inverted ground',
        'divi/group|pricing-two-plan-highlight|8'  => 'inverted text-coloured highlighted plan: no adapter role for an inverted ground',
        'divi/column|icon-values|1'                => 'column card, not migrated yet (plan Task 9)',
        'divi/column|icon-values|2'                => 'column card, not migrated yet (plan Task 9)',
        'divi/column|icon-values|3'                => 'column card, not migrated yet (plan Task 9)',
        'divi/column|pricing-three-tier|1'         => 'column card, not migrated yet (plan Task 9)',
        'divi/column|pricing-three-tier|2'         => 'column card, not migrated yet (plan Task 9)',
        'divi/column|pricing-three-tier|3'         => 'column card, not migrated yet (plan Task 9)',
        'divi/column|testimonials-avatar-grid|1'   => 'column card, not migrated yet (plan Task 9)',
        'divi/column|testimonials-avatar-grid|2'   => 'column card, not migrated yet (plan Task 9)',
        'divi/column|testimonials-avatar-grid|3'   => 'column card, not migrated yet (plan Task 9)',
    ];

    /** The most entries BESPOKE_CARDS may hold. It may only be LOWERED (in the commit that removes entries), never raised. */
    private const BESPOKE_CARDS_MAX = 13;

    /** Every card surface the report found when the card adapter started (Task 2): each one is either marked or listed. */
    private const CARD_SURFACES_KNOWN = 32;

    /**
     * Keys a marked card's `module.decoration` may keep besides the ones the kit's patch writes: structure, never card
     * decoration (background, border, boxShadow, any HOVER transform and, for a `card`, padding belong to the kit; a recipe
     * literal there survives the deep merge and fights the kit). `spacing` stays for margins (and a panel's own padding). A
     * REST transform (`transform.<breakpoint>.value`, e.g. a tilt) is the recipe's composition and also stays
     * (controller amendment to Ruling 2); markedCardProblems() checks that one apart.
     */
    private const CARD_KEEPS = [ 'layout', 'sizing', 'position', 'zIndex', 'overflow', 'animation', 'spacing' ];

    /**
     * Phrases that describe a card look a style kit now decides (corners, frame, shadow), banned from the description and
     * `when` of a recipe with a card or panel marker: the prose must be true under every kit.
     */
    private const CARD_DECIDED_PHRASES = [
        '/\b(rounded|hairline[- ]bordered|bordered|framed|outlined|shadowed|square[- ]cornered)\s+(\w+\s+)?(card|panel|box|tile)s?\b/i',
        '/\brounded (and|or) |, rounded\b/i',
        '/\b(soft|drop|deep|long) shadows?\b/i',
        // Task 8: the corner, frame and elevation words the nine group recipes used for what a kit now decides.
        '/\bsquare[- ]cornered\b|\bwith (square|small|rounded) corners\b|\bheavy[- ]border(s|ed)\b/i',
        '/\b(rounded|square|sharp), |\brounded brands\b|\bframed\b|\braised\b/i',
    ];

    /** A `divi/button` block comment: (json). Same possessive scan as KitResolver::BLOCK. */
    private const BUTTON = '/<!--\s+wp:divi\/button\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})\s+\/?-->/s';

    /**
     * A recipe's stored markup with every style kit's markers resolved and its colour/font tokens left in, one entry
     * per shipped kit (kit id => markup): what the colour checks read, so a kit-driven button's colours are seen.
     *
     * @return array<string,string>
     */
    private static function kitResolved( string $name ): array
    {
        $raw = (string) SectionRecipes::rawMarkup( $name );
        $out = [];
        foreach ( StyleKits::ids() as $kit ) {
            $out[ $kit ] = KitResolver::resolve( $raw, (array) StyleKits::get( $kit ) );
        }
        return $out;
    }

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

    /**
     * What is wrong with a recipe's `pairs` for ONE language ([] = fine). A pair is [foregroundRole, backgroundRole] with an
     * optional third element 'large' (large text: 3.0 instead of 4.5). A role the language does not define (surface-glass in a
     * language without a translucent card colour) is reported in $skipped, never as a failure.
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
                $skipped[] = sprintf( '%s: pair %s/%s skipped (the language does not define %s)', (string) $lang['id'], $pair[0], $pair[1], is_string( $fg ) ? $pair[1] : $pair[0] );
                continue;
            }
            $f = Colour::opaque( $fg, $bg );
            $k = Colour::opaque( $bk, $bg );
            if ( null === $f || null === $k ) {
                $bad[] = "pair $i has an unreadable colour";
                continue;
            }
            $need  = isset( $pair[2] ) ? 3.0 : 4.5;
            $ratio = Colour::contrast( $f, $k );
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

    /** Frozen, shrink-only list of older recipes allowed to name a language that avoids their layout. */
    private const MOOD_AVOIDS_EXEMPTIONS = __DIR__ . '/../fixtures/shapes/mood-avoids-exemptions.txt';

    /** The most entries the mood/avoids exemption list may hold. It may only be LOWERED, never raised. */
    private const MOOD_AVOIDS_EXEMPTION_CAP = 12;

    /**
     * Languages a recipe's mood names although they `avoid` its layout ('any' stands for every language).
     *
     * @param array<string,mixed> $tags
     * @return list<string>
     */
    private static function moodsAvoidingLayout( array $tags ): array
    {
        $moods = (array) ( $tags['mood'] ?? [] );
        $ids   = in_array( 'any', $moods, true ) ? DesignLanguages::ids() : $moods;
        $bad   = [];
        foreach ( $ids as $id ) {
            $lang = DesignLanguages::get( (string) $id );
            if ( null !== $lang && in_array( $tags['layout'] ?? null, (array) ( $lang['avoids'] ?? [] ), true ) ) {
                $bad[] = (string) $id;
            }
        }
        return $bad;
    }

    public function testNoMoodNamesALanguageThatAvoidsTheRecipeLayout(): void
    {
        // Every `avoids` entry is a layout, so the layout check below covers every avoid a language declares.
        foreach ( DesignLanguages::ids() as $id ) {
            foreach ( (array) ( DesignLanguages::get( $id )['avoids'] ?? [] ) as $avoid ) {
                $this->assertContains( $avoid, SectionRecipes::LAYOUTS, "$id avoids `$avoid`, which is not a layout this test can check" );
            }
        }
        $exempt = array_values( array_filter( array_map( 'trim', (array) file( self::MOOD_AVOIDS_EXEMPTIONS, FILE_IGNORE_NEW_LINES ) ), static fn ( string $l ): bool => '' !== $l && '#' !== $l[0] ) );
        $this->assertLessThanOrEqual( self::MOOD_AVOIDS_EXEMPTION_CAP, count( $exempt ), 'the exemption list may only shrink' );
        $this->assertSame( array_values( array_unique( $exempt ) ), $exempt, 'no duplicate exemption' );
        $problems = [];
        foreach ( SectionRecipes::names() as $name ) {
            $bad = self::moodsAvoidingLayout( (array) SectionRecipes::tags( $name ) );
            if ( [] !== $bad && ! in_array( $name, $exempt, true ) ) {
                $problems[] = "$name (" . SectionRecipes::tags( $name )['layout'] . ') names ' . implode( ', ', $bad );
            }
        }
        foreach ( $exempt as $name ) {
            if ( ! in_array( $name, SectionRecipes::names(), true ) ) {
                $problems[] = "exempt $name is not a recipe: remove it";
            } elseif ( [] === self::moodsAvoidingLayout( (array) SectionRecipes::tags( $name ) ) ) {
                $problems[] = "exempt $name no longer names an avoiding language: remove it from the list and lower the cap";
            }
        }
        $this->assertSame( [], $problems );
    }

    public function testTheMoodAvoidsCheckCatchesWhatItShould(): void
    {
        $this->assertSame( [ 'luxe' ], self::moodsAvoidingLayout( [ 'layout' => 'form', 'mood' => [ 'luxe', 'tech-mono' ] ] ) );
        $this->assertSame( [], self::moodsAvoidingLayout( [ 'layout' => 'form', 'mood' => [ 'tech-mono' ] ] ) );
        $this->assertContains( 'brutalist-flat', self::moodsAvoidingLayout( [ 'layout' => 'centered', 'mood' => [ 'any' ] ] ) );
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

    /**
     * Literal colours left in a recipe's markup, minus the documented neutral ones (pure white or black in any opacity, which
     * includes the transparent white Divi's own slider writes) and minus tokens. `$variable(` global-colour references are
     * site-specific ids and never allowed in a recipe.
     *
     * @return list<string>
     */
    private static function literalColours( string $markup ): array
    {
        $bad = [];
        if ( str_contains( $markup, '$variable(' ) ) {
            $bad[] = '$variable(...) (a site-specific global colour id)';
        }
        preg_match_all( '/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/', $markup, $m );
        foreach ( $m[0] as $c ) {
            $rgba = Colour::rgba( strtolower( $c ) );
            $neutral = null !== $rgba && ( ( 255 === $rgba[0] && 255 === $rgba[1] && 255 === $rgba[2] ) || ( 0 === $rgba[0] && 0 === $rgba[1] && 0 === $rgba[2] ) );
            if ( ! $neutral ) {
                $bad[] = $c;
            }
        }
        return array_values( array_unique( $bad ) );
    }

    public function testTokenisedRecipesHaveNoLiteralColoursOrGlobalColourIds(): void
    {
        $checked = 0;
        foreach ( SectionRecipes::names() as $name ) {
            // A recipe that declares `pairs` is a tokenised one (the baseline lists the others and only shrinks).
            if ( ! array_key_exists( 'pairs', (array) SectionRecipes::tags( $name ) ) ) {
                continue;
            }
            ++$checked;
            $this->assertSame( [], self::literalColours( (string) SectionRecipes::rawMarkup( $name ) ), "$name still holds literal colours (use a {{aied:color:<role>}} token)" );
            foreach ( self::kitResolved( $name ) as $kit => $markup ) {
                $this->assertSame( [], self::literalColours( $markup ), "$name under the $kit kit holds literal colours" );
            }
            $this->assertDoesNotMatchRegularExpression( '/"family":"(?!\{\{aied:font:)[^"]*"/', (string) SectionRecipes::rawMarkup( $name ), "$name still holds a literal font family (use {{aied:font:<role>}})" );
        }
        $this->assertGreaterThan( 0, $checked );
        // The checker itself.
        $this->assertSame( [ '#0cf57e', 'rgba(42,42,42,0.85)' ], self::literalColours( '"a":"#0cf57e","b":"#FFFFFF","c":"rgba(42,42,42,0.85)","d":"RGBA(255,255,255,0)","e":"rgba(0,0,0,0.4)","f":"#000"' ) );
        $this->assertNotSame( [], self::literalColours( '"c":"$variable({"type":1})$"' ) );
    }

    /**
     * Colour ROLES used as a TEXT colour token in a markup: a `color` value under a font settings object (a path with a `font` or
     * `bodyFont`/`headingFont` segment ending in `color`: heading and title fonts, body text, links, button labels, hover labels),
     * not shadows, borders or backgrounds.
     *
     * @return list<string>
     */
    private static function textColourRoles( string $markup ): array
    {
        $parsed = ( new \Divi5Validator\BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' );
        $roles  = [];
        $walk   = static function ( $node, string $path ) use ( &$walk, &$roles ): void {
            if ( is_array( $node ) ) {
                foreach ( $node as $k => $v ) {
                    $walk( $v, '' === $path ? (string) $k : $path . '.' . $k );
                }
                return;
            }
            if ( is_string( $node ) && 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $node, $m )
                && 1 === preg_match( '/(^|\.)[a-zA-Z]*[fF]ont(\.|$)/', $path ) && 1 === preg_match( '/\.color$/', $path )
                && ! str_contains( $path, 'textShadow' ) && ! str_contains( $path, 'border' ) ) {
                $roles[ $m[1] ] = true;
            }
        };
        $parsed->root()->walk( static function ( \Divi5Validator\Block $b ) use ( $walk ): void {
            $walk( $b->attrs(), '' );
        } );
        $out = array_keys( $roles );
        sort( $out );
        return $out;
    }

    public function testEveryTextColourRoleIsTheForegroundOfADeclaredPair(): void
    {
        $checked = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $tags = (array) SectionRecipes::tags( $name );
            if ( ! array_key_exists( 'pairs', $tags ) ) {
                continue;
            }
            ++$checked;
            $foregrounds = array_map( static fn ( $p ): string => (string) $p[0], (array) $tags['pairs'] );
            // Read with every kit resolved: a kit-driven button's label colours exist only after resolution.
            $own   = self::textColourRoles( (string) SectionRecipes::rawMarkup( $name ) );
            $roles = $own;
            foreach ( self::kitResolved( $name ) as $markup ) {
                $roles = array_merge( $roles, self::textColourRoles( $markup ) );
            }
            // A label colour only a kit's button brings, whose pair is one of KIT_LABEL_PAIRS (proven below in every language).
            $kitOnly = array_diff( $roles, $own );
            $roles   = array_merge( $own, array_diff( $kitOnly, array_map( static fn ( array $p ): string => $p[0], self::KIT_LABEL_PAIRS ) ) );
            $roles   = array_values( array_unique( $roles ) );
            sort( $roles );
            $this->assertNotSame( [], $roles, "$name uses no text colour token?" );
            $this->assertSame( [], array_values( array_diff( $roles, $foregrounds ) ), "$name colours text with a role no declared pair has as its foreground: declare the pair it sits on" );
        }
        $this->assertGreaterThan( 0, $checked );
        // The guard itself: a heading colour, a body colour and a button label are text; a shadow, a background and a border are not.
        $m = '<!-- wp:divi/heading {"title":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"{{aied:color:text}}"}}},"textShadow":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}}}} /-->'
            . '<!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:muted}}"}}}}}}}} /-->'
            . '<!-- wp:divi/button {"button":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}},"font":{"font":{"desktop":{"value":{"color":"{{aied:color:accent-text}}"},"hover":{"color":"{{aied:color:bg}}"}}}}}}} /-->';
        $this->assertSame( [ 'accent-text', 'bg', 'muted', 'text' ], self::textColourRoles( $m ) );
    }

    public function testTheKitLabelPairsAreReadableInEveryLanguage(): void
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $skipped = [];
            $this->assertSame( [], self::pairProblems( self::KIT_LABEL_PAIRS, (array) DesignLanguages::get( $id ), $skipped ), $id );
            $this->assertSame( [], $skipped, $id );
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
        // A role the language does not define (editorial has no surface_glass) is skipped with a message, not failed.
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'text', 'surface-glass' ] ], $lang, $skipped ) );
        $this->assertCount( 1, $skipped );
        $this->assertStringContainsString( 'skipped', $skipped[0] );
        // inverse and overlay exist in every language since plan task 9, so they are judged, never skipped.
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'inverse', 'overlay' ] ], $lang, $skipped ) );
        $this->assertSame( [], $skipped );
        // A translucent card colour is judged over the language's own background.
        $glass = (array) DesignLanguages::get( 'glass-dark' );
        $skipped = [];
        $this->assertSame( [], self::pairProblems( [ [ 'text', 'surface-glass' ] ], $glass, $skipped ) );
        $this->assertSame( [], $skipped );
    }

    public function testEveryRecipeButtonIsKitDriven(): void
    {
        $total    = 0;
        $migrated = 0;
        $bespoke  = [];
        foreach ( SectionRecipes::names() as $name ) {
            preg_match_all( self::BUTTON, (string) SectionRecipes::rawMarkup( $name ), $m );
            foreach ( $m[1] as $i => $json ) {
                ++$total;
                $key   = $name . '#' . $i;
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs, "$key: button attributes decode" );
                $decoration = (array) ( $attrs['button']['decoration'] ?? [] );
                if ( isset( self::BESPOKE_BUTTONS[ $key ] ) ) {
                    $bespoke[] = $key;
                    $this->assertArrayNotHasKey( 'aied', $attrs, "$key is listed as bespoke but carries a kit marker: remove it from BESPOKE_BUTTONS" );
                    continue;
                }
                ++$migrated;
                $this->assertContains( $attrs['aied']['kit'] ?? null, [ 'control', 'control-secondary' ], "$key: a recipe button asks the style kit for its look (top-level \"aied\":{\"kit\":...})" );
                $this->assertArrayNotHasKey( 'background', $decoration, "$key: the kit writes the button background" );
                $this->assertArrayNotHasKey( 'border', $decoration, "$key: the kit writes the button border" );
                $this->assertArrayNotHasKey( 'spacing', $decoration, "$key: the kit writes the button padding" );
                $font = (array) ( $decoration['font']['font'] ?? [] );
                foreach ( $font as $bp => $states ) {
                    foreach ( (array) $states as $state => $value ) {
                        $this->assertArrayNotHasKey( 'color', (array) $value, "$key: the kit writes the label colour (font.font.$bp.$state.color)" );
                    }
                }
                $this->assertArrayNotHasKey( 'hover', $font['desktop'] ?? [], "$key: the kit writes the hover label" );
            }
        }
        $this->assertLessThanOrEqual( 2, count( self::BESPOKE_BUTTONS ), 'shrink-only by test: the bespoke list can never grow past today\'s 2' );
        $this->assertSame( 28, $total, 'the recipes hold 28 buttons (update this count with the recipes)' );
        $this->assertSame( array_keys( self::BESPOKE_BUTTONS ), $bespoke, 'every bespoke entry names a real button that is still bespoke' );
        $this->assertSame( $total - count( self::BESPOKE_BUTTONS ), $migrated );
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_KITS, filesize( $file ), 'the recipe file ends smaller than before the kits (spec section 4)' );
        $this->assertLessThan( 700 * 1024, filesize( $file ), 'the 700 KB bound holds' );
    }

    /**
     * The surface a block's own background makes, or null when it sets none: `dark` for a background image or a gradient
     * with an overlay stop (a photo under a scrim), `accent` for an accent colour or an enabled gradient with an accent
     * stop, `light` for bg, surface or surface-glass (as a colour or as every stop of an enabled gradient), and
     * `other:<what>` for anything else (an inverted `text` panel, an unknown colour): no adapter surface fits it.
     *
     * @param array<string,mixed> $attrs
     */
    private static function surfaceOf( array $attrs ): ?string
    {
        $v = $attrs['module']['decoration']['background']['desktop']['value'] ?? null;
        if ( ! is_array( $v ) ) {
            return null;
        }
        $roleOf = static fn ( $c ): string => is_string( $c ) && 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $c, $m ) ? $m[1] : 'literal';
        $light  = [ 'bg', 'surface', 'surface-glass' ];
        $image  = $v['image']['url'] ?? '';
        $grad   = is_array( $v['gradient'] ?? null ) && 'on' === ( $v['gradient']['enabled'] ?? null ) ? $v['gradient'] : null;
        $stops  = null === $grad ? [] : array_map( static fn ( $s ) => $roleOf( is_array( $s ) ? ( $s['color'] ?? null ) : null ), (array) ( $grad['stops'] ?? [] ) );
        if ( ( is_string( $image ) && '' !== $image ) || in_array( 'overlay', $stops, true ) ) {
            return 'dark';
        }
        if ( [] !== $stops ) {
            if ( in_array( 'accent', $stops, true ) ) {
                return 'accent';
            }
            return [] === array_diff( $stops, $light ) ? 'light' : 'other:gradient ' . implode( '/', $stops );
        }
        $colour = $v['color'] ?? '';
        if ( ! is_string( $colour ) || '' === $colour ) {
            return null;
        }
        $role = $roleOf( $colour );
        if ( 'overlay' === $role ) {
            return 'dark';
        }
        if ( 'accent' === $role ) {
            return 'accent';
        }
        return in_array( $role, $light, true ) ? 'light' : 'other:' . $role;
    }

    /**
     * Every button of a markup with the surface it really sits on: the surface of its NEAREST ancestor that sets a
     * background colour, image or enabled gradient (`light` when none does: the page). Keyed "#index" in markup order,
     * the value [marker or null, derived surface].
     *
     * @return array<string,array{0:?array<string,mixed>,1:string}>
     */
    private static function buttonSurfaces( string $markup ): array
    {
        $root = ( new \Divi5Validator\BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' )->root();
        $out  = [];
        $walk = static function ( \Divi5Validator\Block $b, array $surfaces ) use ( &$walk, &$out ): void {
            if ( 'divi/button' === $b->name() ) {
                $marker = $b->attrs()['aied'] ?? null;
                $out[ '#' . count( $out ) ] = [ is_array( $marker ) ? $marker : null, [] === $surfaces ? 'light' : (string) end( $surfaces ) ];
            }
            // A card/panel marker's block is filled by the kit with `surface` (or `bg` on a surface band): light for a button.
            $own = null === self::cardMarker( $b->attrs() ) ? self::surfaceOf( $b->attrs() ) : 'light';
            if ( null !== $own ) {
                $surfaces[] = $own;
            }
            foreach ( $b->children() as $child ) {
                $walk( $child, $surfaces );
            }
        };
        $walk( $root, [] );
        return $out;
    }

    public function testEveryMarkedButtonSaysTheSurfaceItReallySitsOn(): void
    {
        $checked  = 0;
        $problems = [];
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( self::buttonSurfaces( (string) SectionRecipes::rawMarkup( $name ) ) as $index => [ $marker, $surface ] ) {
                if ( null === $marker ) {
                    continue;
                }
                ++$checked;
                $on = (string) ( $marker['on'] ?? 'light' );
                if ( $on !== $surface ) {
                    $problems[] = "$name$index: marker on=$on, but the nearest background makes it $surface";
                }
            }
        }
        $this->assertSame( 26, $checked, 'every kit marker is checked (update with the recipes)' );
        $this->assertSame( [], $problems, 'a button\'s "on" must name the band it really sits on, or the kit picks unreadable colours' );
    }

    public function testTheSurfaceWalkCatchesWhatItShould(): void
    {
        $btn = static fn ( string $marker ): string => '<!-- wp:divi/button {' . $marker . '} /-->';
        $bg  = static fn ( string $value ): string => '{"module":{"decoration":{"background":{"desktop":{"value":' . $value . '}}}}}';
        $sec = static fn ( string $attrs, string $inner ): string => '<!-- wp:divi/section ' . $attrs . ' -->' . $inner . '<!-- /wp:divi/section -->';
        $col = static fn ( string $attrs, string $inner ): string => '<!-- wp:divi/column ' . $attrs . ' -->' . $inner . '<!-- /wp:divi/column -->';
        $m   = '"aied":{"kit":"control"}';

        $this->assertSame( [ '#0' => [ [ 'kit' => 'control' ], 'light' ] ], self::buttonSurfaces( $btn( $m ) ), 'no background anywhere: the page' );
        $this->assertSame( 'accent', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:accent}}"}' ), $btn( $m ) ) )['#0'][1] );
        $this->assertSame( 'dark', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:bg}}","image":{"url":"x.jpg"}}' ), $btn( $m ) ) )['#0'][1] );
        $this->assertSame( 'dark', self::buttonSurfaces( $sec( $bg( '{"gradient":{"enabled":"on","stops":[{"color":"{{aied:color:overlay}}"}]}}' ), $btn( $m ) ) )['#0'][1] );
        $this->assertSame( 'light', self::buttonSurfaces( $sec( $bg( '{"gradient":{"enabled":"on","stops":[{"color":"{{aied:color:bg}}"},{"color":"{{aied:color:surface}}"}]}}' ), $btn( $m ) ) )['#0'][1] );
        $this->assertSame( 'light', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:bg}}","gradient":{"enabled":"off","stops":[{"color":"{{aied:color:accent}}"}]}}' ), $btn( $m ) ) )['#0'][1], 'a disabled gradient is not painted' );
        // The NEAREST background wins: a surface column on a photo section is light; an accent column on a bg section is accent.
        $this->assertSame( 'light', self::buttonSurfaces( $sec( $bg( '{"image":{"url":"x.jpg"}}' ), $col( $bg( '{"color":"{{aied:color:surface}}"}' ), $btn( $m ) ) ) )['#0'][1] );
        $this->assertSame( 'accent', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:bg}}"}' ), $col( $bg( '{"color":"{{aied:color:accent}}"}' ), $btn( $m ) ) ) )['#0'][1] );
        // A column without a background is transparent: the section's surface shows through.
        $this->assertSame( 'dark', self::buttonSurfaces( $sec( $bg( '{"image":{"url":"x.jpg"}}' ), $col( '{}', $btn( $m ) ) ) )['#0'][1] );
        // An inverted panel is no adapter surface.
        $this->assertSame( 'other:text', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:text}}"}' ), $btn( $m ) ) )['#0'][1] );
        // A sibling's background never leaks into the next block.
        $this->assertSame( 'light', self::buttonSurfaces( $col( $bg( '{"color":"{{aied:color:accent}}"}' ), '' ) . $btn( $m ) )['#0'][1] );
        // A kit-filled card (its literal background moved to the kit) is light ground for the button inside it.
        $panel = '<!-- wp:divi/group {"aied":{"kit":"panel","on":"accent"}} -->' . $btn( $m ) . '<!-- /wp:divi/group -->';
        $this->assertSame( 'light', self::buttonSurfaces( $sec( $bg( '{"color":"{{aied:color:accent}}"}' ), $panel ) )['#0'][1] );
    }

    /**
     * [role, on] of a card/panel marker in $attrs, or null (a button role is no card marker). A card/panel marker without
     * a string `on` throws: the resolver would default it to `light`, and a recipe card must say where it sits.
     *
     * @return array{0:string,1:string}|null
     */
    private static function cardMarker( array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! in_array( $m['kit'] ?? null, [ 'card', 'panel' ], true ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'a ' . $m['kit'] . ' marker without "on": a recipe card must name its band, never default to light' );
        }
        return [ (string) $m['kit'], $m['on'] ];
    }

    /** How many card/panel markers the block comments of $markup carry (parsed, so key order does not matter). */
    private static function cardMarkerCount( string $markup ): int
    {
        return count( array_filter( self::blocks( $markup ), static fn ( array $b ): bool => null !== self::cardMarker( $b[1] ) ) );
    }

    /**
     * The card/panel markers of the recipe FILE (decoded, not through SectionRecipes): [parsed from the block comments,
     * spelled as `"kit":"card"` / `"kit":"panel"` anywhere in the markups]. Both ignore key order.
     *
     * @return array{0:int,1:int}
     */
    private static function cardMarkersInFile(): array
    {
        $parsed  = 0;
        $spelled = 0;
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            $markup   = (string) $recipe['markup'];
            $parsed  += self::cardMarkerCount( $markup );
            $spelled += substr_count( $markup, '"kit":"card"' ) + substr_count( $markup, '"kit":"panel"' );
        }
        return [ $parsed, $spelled ];
    }

    public function testACardMarkerWithoutABandFailsAndKeyOrderDoesNotMatter(): void
    {
        $this->assertSame( [ 'panel', 'dark' ], self::cardMarker( [ 'aied' => [ 'on' => 'dark', 'kit' => 'panel' ] ] ), 'key order does not matter' );
        $this->assertNull( self::cardMarker( [ 'aied' => [ 'kit' => 'control' ] ] ), 'a button role is no card marker' );
        $this->assertSame( 1, self::cardMarkerCount( '<!-- wp:divi/group {"aied":{"on":"light","kit":"card"}} --><!-- /wp:divi/group --><!-- wp:divi/button {"aied":{"kit":"control"}} /-->' ) );
        foreach ( [ [ 'kit' => 'card' ], [ 'kit' => 'panel', 'on' => '' ], [ 'kit' => 'card', 'on' => 1 ] ] as $marker ) {
            try {
                self::cardMarker( [ 'aied' => $marker ] );
                $this->fail( 'a card marker without a band must fail: ' . json_encode( $marker ) );
            } catch ( \UnexpectedValueException $e ) {
                $this->assertStringContainsString( 'without "on"', $e->getMessage() );
            }
        }
    }

    /**
     * The card band a block's own ground makes, or null when it paints none: a marked card is the kit's fill (`surface`,
     * so a `surface` band; `bg` when it sits on a surface band, so a `light` band); a background image or a gradient with an
     * overlay stop is `dark`; an enabled gradient with an accent stop is `accent`, one whose stops are all `surface` is
     * `surface`, one whose stops are all `bg`/`surface` is `light` (the page colour dominates); a colour `bg` is `light`,
     * `surface` is `surface`, `accent` is `accent`, `overlay` or `inverse` is `dark`; anything else is `other:<what>` (an
     * inverted `text` panel, glass, a literal), which no card band fits.
     *
     * @param array<string,mixed> $attrs
     */
    private static function cardBandOf( array $attrs ): ?string
    {
        $marker = self::cardMarker( $attrs );
        if ( null !== $marker ) {
            return 'surface' === $marker[1] ? 'light' : 'surface';
        }
        $v = $attrs['module']['decoration']['background']['desktop']['value'] ?? null;
        if ( ! is_array( $v ) ) {
            return null;
        }
        $roleOf = static fn ( $c ): string => is_string( $c ) && 1 === preg_match( '/^\{\{aied:color:([a-z-]+)\}\}$/', $c, $m ) ? $m[1] : 'literal';
        $image  = $v['image']['url'] ?? '';
        $grad   = is_array( $v['gradient'] ?? null ) && 'on' === ( $v['gradient']['enabled'] ?? null ) ? $v['gradient'] : null;
        $stops  = null === $grad ? [] : array_map( static fn ( $s ) => $roleOf( is_array( $s ) ? ( $s['color'] ?? null ) : null ), (array) ( $grad['stops'] ?? [] ) );
        if ( ( is_string( $image ) && '' !== $image ) || in_array( 'overlay', $stops, true ) ) {
            return 'dark';
        }
        if ( [] !== $stops ) {
            if ( in_array( 'accent', $stops, true ) ) {
                return 'accent';
            }
            if ( [] === array_diff( $stops, [ 'surface' ] ) ) {
                return 'surface';
            }
            return [] === array_diff( $stops, [ 'bg', 'surface' ] ) ? 'light' : 'other:gradient ' . implode( '/', $stops );
        }
        $colour = $v['color'] ?? '';
        if ( ! is_string( $colour ) || '' === $colour ) {
            return null;
        }
        $role = $roleOf( $colour );
        $map  = [ 'bg' => 'light', 'surface' => 'surface', 'accent' => 'accent', 'overlay' => 'dark', 'inverse' => 'dark' ];
        return $map[ $role ] ?? 'other:' . $role;
    }

    /**
     * Every card/panel marker of a markup with the band it really sits on: the band of its NEAREST ancestor that paints a
     * ground (cardBandOf), or `none` when no ancestor does (never defaulted: a recipe card must say where it sits).
     * Keyed "<block>#<index>", index = the block's order among all blocks of that name (CardReport's numbering).
     *
     * @return array<string,array{0:array{0:string,1:string},1:string}>
     */
    private static function cardBands( string $markup ): array
    {
        $root  = ( new \Divi5Validator\BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' )->root();
        $out   = [];
        $seen  = [];
        $walk  = static function ( \Divi5Validator\Block $b, array $bands ) use ( &$walk, &$out, &$seen ): void {
            $name = $b->name();
            if ( 'divi/placeholder' !== $name ) {
                $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
            }
            $marker = self::cardMarker( $b->attrs() );
            if ( null !== $marker ) {
                $out[ $name . '#' . $seen[ $name ] ] = [ $marker, [] === $bands ? 'none' : (string) end( $bands ) ];
            }
            $own = self::cardBandOf( $b->attrs() );
            if ( null !== $own ) {
                $bands[] = $own;
            }
            foreach ( $b->children() as $child ) {
                $walk( $child, $bands );
            }
        };
        $walk( $root, [] );
        return $out;
    }

    public function testCardMarkersMatchTheirBand(): void
    {
        $checked  = 0;
        $problems = [];
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( self::cardBands( (string) SectionRecipes::rawMarkup( $name ) ) as $key => [ [ , $on ], $band ] ) {
                ++$checked;
                if ( $on !== $band ) {
                    $problems[] = "$name $key: marker on=$on, but the nearest ground makes it $band";
                }
            }
        }
        [ $parsed, $spelled ] = self::cardMarkersInFile();
        $this->assertSame( $spelled, $parsed, 'every "kit":"card|panel" in the file is a top-level marker of a block comment' );
        $this->assertSame( $parsed, $checked, 'every card marker in the file is checked' );
        $this->assertGreaterThan( 0, $checked, 'the recipes carry card markers' );
        $this->assertSame( [], $problems, 'a card\'s "on" must name the band it really sits on, or the kit picks the wrong fill and shadow' );
    }

    public function testTheCardBandWalkCatchesWhatItShould(): void
    {
        $card = static fn ( string $on ): string => '<!-- wp:divi/group {"aied":{"kit":"card","on":"' . $on . '"}} --><!-- /wp:divi/group -->';
        $bg   = static fn ( string $value ): string => '{"module":{"decoration":{"background":{"desktop":{"value":' . $value . '}}}}}';
        $sec  = static fn ( string $attrs, string $inner ): string => '<!-- wp:divi/section ' . $attrs . ' -->' . $inner . '<!-- /wp:divi/section -->';
        $col  = static fn ( string $attrs, string $inner ): string => '<!-- wp:divi/column ' . $attrs . ' -->' . $inner . '<!-- /wp:divi/column -->';
        $band = static fn ( string $markup, string $key = 'divi/group#0' ): string => self::cardBands( $markup )[ $key ][1];

        $this->assertSame( 'none', $band( $card( 'light' ) ), 'no ground anywhere fails loudly, never defaults to light' );
        $this->assertSame( 'light', $band( $sec( $bg( '{"color":"{{aied:color:bg}}"}' ), $card( 'light' ) ) ) );
        $this->assertSame( 'surface', $band( $sec( $bg( '{"color":"{{aied:color:surface}}"}' ), $card( 'surface' ) ) ) );
        $this->assertSame( 'accent', $band( $sec( $bg( '{"color":"{{aied:color:accent}}"}' ), $card( 'accent' ) ) ) );
        $this->assertSame( 'dark', $band( $sec( $bg( '{"image":{"url":"x.jpg"}}' ), $card( 'dark' ) ) ) );
        $this->assertSame( 'dark', $band( $sec( $bg( '{"color":"{{aied:color:overlay}}"}' ), $card( 'dark' ) ) ) );
        $this->assertSame( 'light', $band( $sec( $bg( '{"color":"{{aied:color:bg}}","gradient":{"enabled":"on","stops":[{"color":"{{aied:color:bg}}"},{"color":"{{aied:color:surface}}"}]}}' ), $card( 'light' ) ) ) );
        $this->assertSame( 'other:text', $band( $sec( $bg( '{"color":"{{aied:color:text}}"}' ), $card( 'light' ) ) ), 'an inverted ground is no card band' );
        // The nearest ground wins, a transparent column lets the section through, a sibling never leaks.
        $this->assertSame( 'surface', $band( $sec( $bg( '{"color":"{{aied:color:bg}}"}' ), $col( $bg( '{"color":"{{aied:color:surface}}"}' ), $card( 'surface' ) ) ) ) );
        $this->assertSame( 'accent', $band( $sec( $bg( '{"color":"{{aied:color:accent}}"}' ), $col( '{}', $card( 'accent' ) ) ) ) );
        $this->assertSame( 'none', $band( $col( $bg( '{"color":"{{aied:color:accent}}"}' ), '' ) . $card( 'accent' ) ) );
        // A card inside a marked card sits on the kit's fill: surface, or bg (a light band) when the outer one is on surface.
        $nested = static fn ( string $outerOn ): string => '<!-- wp:divi/group {"aied":{"kit":"panel","on":"' . $outerOn . '"}} -->' . $card( 'x' ) . '<!-- /wp:divi/group -->';
        $this->assertSame( 'surface', $band( $sec( $bg( '{"color":"{{aied:color:bg}}"}' ), $nested( 'light' ) ), 'divi/group#1' ) );
        $this->assertSame( 'light', $band( $sec( $bg( '{"color":"{{aied:color:surface}}"}' ), $nested( 'surface' ) ), 'divi/group#1' ) );
        // A column carrier is keyed by its own count.
        $this->assertSame( 'surface', self::cardBands( $sec( $bg( '{"color":"{{aied:color:surface}}"}' ), $col( '{}', '' ) . '<!-- wp:divi/column {"aied":{"kit":"card","on":"surface"}} --><!-- /wp:divi/column -->' ) )['divi/column#1'][1] );
    }

    public function testBespokeCardsAreListedAndShrinkOnly(): void
    {
        $recipes = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $this->assertIsArray( $recipes );
        $rows = [];
        foreach ( \CardReport::classify( $recipes ) as $r ) {
            $rows[ $r['block'] . '|' . $r['recipe'] . '|' . $r['index'] ] = $r;
        }
        [ $missing, $stale, $decorated, $marked ] = self::bespokeCardProblems( $rows, self::BESPOKE_CARDS );
        $this->assertSame( [], $missing, 'card surfaces with no kit marker that BESPOKE_CARDS does not list: give them a marker (the list never grows)' );
        $this->assertSame( [], $stale, 'BESPOKE_CARDS entries that are no longer bespoke: remove them from BESPOKE_CARDS (and lower BESPOKE_CARDS_MAX)' );
        $this->assertSame( [], $decorated, 'marked blocks that still carry their own card decoration (the deep merge keeps it): remove it' );
        $this->assertLessThanOrEqual( self::BESPOKE_CARDS_MAX, count( self::BESPOKE_CARDS ), 'shrink-only: BESPOKE_CARDS can never grow' );
        $this->assertSame( self::BESPOKE_CARDS_MAX, count( self::BESPOKE_CARDS ), 'lower BESPOKE_CARDS_MAX to the list\'s size when you remove entries' );
        $this->assertSame( self::CARD_SURFACES_KNOWN, count( self::BESPOKE_CARDS ) + $marked, 'every card surface the report knows is either kit-driven or listed' );
    }

    public function testTheBespokeCardCheckCatchesWhatItShould(): void
    {
        $row = static fn ( string $class, ?string $marked ): array => [ 'class' => $class, 'marked' => $marked ];
        $rows = [
            'divi/group|r|0'  => $row( 'card', null ),
            'divi/group|r|1'  => $row( 'wrapper', 'card' ),
            'divi/group|r|2'  => $row( 'panel', 'panel' ),
            'divi/column|r|0' => $row( 'inverted', null ),
            'divi/group|r|3'  => $row( 'rule', null ),
        ];
        [ $missing, $stale, $decorated, $marked ] = self::bespokeCardProblems( $rows, [ 'divi/group|r|1' => 'x', 'divi/group|r|9' => 'y', 'divi/group|r|3' => 'z' ] );
        $this->assertSame( [ 'divi/group|r|0 (card)', 'divi/column|r|0 (inverted)' ], $missing );
        $this->assertSame( [ 'divi/group|r|1 carries a card marker', 'divi/group|r|9 is not a block of the recipes', 'divi/group|r|3 is a rule, not a card surface' ], $stale );
        $this->assertSame( [ 'divi/group|r|2 (panel)' ], $decorated );
        $this->assertSame( 2, $marked );
    }

    public function testBespokeGroupCornersFollowTheKit(): void
    {
        $markups = [];
        foreach ( SectionRecipes::names() as $name ) {
            $markups[ $name ] = (string) SectionRecipes::rawMarkup( $name );
        }
        $problems = [];
        $tokened  = 0;
        foreach ( StyleKits::ids() as $kitId ) {
            [ $p, $t ] = self::bespokeCornerProblems( $markups, self::BESPOKE_CARDS, (array) StyleKits::get( $kitId ) );
            $problems  = array_merge( $problems, array_map( static fn ( string $x ): string => "$kitId: $x", $p ) );
            $tokened   = $t;
        }
        $this->assertSame( [], $problems, 'a bespoke group beside a kit card takes the kit\'s corners ({{aied:kit:radius-card}}), or the pair has mismatched corners' );
        $this->assertSame( 4, $tokened, 'the four inverted/accent groups (compare-two-panels#7, features-bento#1 and #3, pricing-two-plan-highlight#8)' );
    }

    public function testTheBespokeCornerCheckCatchesWhatItShould(): void
    {
        $grp    = static fn ( $radius ): string => '<!-- wp:divi/group ' . json_encode( [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:text}}' ] ] ] ] + ( null === $radius ? [] : [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => $radius ] ] ] ] ) ] ], JSON_UNESCAPED_SLASHES ) . ' --><!-- /wp:divi/group -->';
        $corner = static fn ( string $v ): array => [ 'topLeft' => $v, 'topRight' => $v, 'bottomRight' => $v, 'bottomLeft' => $v ];
        $kit    = (array) StyleKits::get( 'quiet-solid' );
        $listed = [ 'divi/group|r|0' => 'x', 'divi/group|r|1' => 'x', 'divi/group|r|2' => 'x', 'divi/group|r|3' => 'x', 'divi/column|r|0' => 'x', 'divi/group|r|9' => 'x' ];
        $markup = $grp( '{{aied:kit:radius-card}}' ) . $grp( $corner( '0px' ) ) . $grp( $corner( '999px' ) ) . $grp( null );
        [ $p, $t ] = self::bespokeCornerProblems( [ 'r' => $markup ], $listed, $kit );
        $this->assertSame( [ 'r divi/group#1: border.desktop.value.radius is a literal, not {{aied:kit:radius-card}}', 'r divi/group#9: no such block' ], $p );
        $this->assertSame( 1, $t, 'the token, the pill (999px) and no radius pass; columns are not this check\'s' );
        // A token that would not resolve to the kit's card radius (a control radius) fails.
        [ $p ] = self::bespokeCornerProblems( [ 'r' => $grp( '{{aied:kit:radius-control}}' ) ], [ 'divi/group|r|0' => 'x' ], $kit );
        $this->assertSame( [ 'r divi/group#0: border.desktop.value.radius is a literal, not {{aied:kit:radius-card}}' ], $p );
    }

    /**
     * What is wrong with the corners of the listed bespoke GROUPS under $kit: a radius (at any breakpoint or state) must be
     * the whole-value token `{{aied:kit:radius-card}}` (a pill, 999px on every corner, is exempt) and resolve to the kit's
     * `radius.card` on all four corners. A group with no radius is fine. Returns [problems, how many groups carry the token].
     *
     * @param array<string,string> $markups recipe => raw markup
     * @param array<string,string> $listed  the bespoke list
     * @param array<string,mixed>  $kit
     * @return array{0:list<string>,1:int}
     */
    private static function bespokeCornerProblems( array $markups, array $listed, array $kit ): array
    {
        $out     = [];
        $tokened = 0;
        $want    = array_fill_keys( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ], (string) ( $kit['radius']['card'] ?? '' ) );
        foreach ( array_keys( $listed ) as $key ) {
            [ $block, $recipe, $index ] = explode( '|', $key );
            if ( 'divi/group' !== $block ) {
                continue;
            }
            $raw      = $markups[ $recipe ] ?? '';
            $find     = static function ( string $markup ) use ( $block, $index ): ?array {
                foreach ( self::blocks( $markup ) as [ $name, $attrs, $i ] ) {
                    if ( $name === $block && (int) $index === $i ) {
                        return $attrs;
                    }
                }
                return null;
            };
            $attrs = $find( $raw );
            if ( null === $attrs ) {
                $out[] = "$recipe $block#$index: no such block";
                continue;
            }
            $after = (array) $find( KitResolver::resolve( $raw, $kit ) );
            $any   = false;
            foreach ( (array) ( $attrs['module']['decoration']['border'] ?? [] ) as $bp => $states ) {
                foreach ( (array) $states as $state => $value ) {
                    if ( ! is_array( $value ) || ! array_key_exists( 'radius', $value ) ) {
                        continue;
                    }
                    $any    = true;
                    $radius = $value['radius'];
                    $where  = "$recipe $block#$index: border.$bp.$state.radius";
                    if ( is_array( $radius ) && [ '999px' ] === array_values( array_unique( $radius ) ) ) {
                        continue;
                    }
                    if ( '{{aied:kit:radius-card}}' !== $radius ) {
                        $out[] = "$where is a literal, not {{aied:kit:radius-card}}";
                        continue;
                    }
                    if ( ( $after['module']['decoration']['border'][ $bp ][ $state ]['radius'] ?? null ) !== $want ) {
                        $out[] = "$where does not resolve to the kit's radius.card";
                    }
                }
            }
            if ( $any && ! array_filter( $out, static fn ( string $x ): bool => str_starts_with( $x, "$recipe $block#$index:" ) ) ) {
                $tokened += str_contains( (string) json_encode( $attrs['module']['decoration']['border'] ?? [] ), 'aied:kit:radius-card' ) ? 1 : 0;
            }
        }
        return [ $out, $tokened ];
    }

    /**
     * @param array<string,array<string,mixed>> $rows   CardReport rows by "<block>|<recipe>|<index>"
     * @param array<string,string>              $listed the bespoke list
     * @return array{0:list<string>,1:list<string>,2:list<string>,3:int} [missing, stale, marked but decorated, marked count]
     */
    private static function bespokeCardProblems( array $rows, array $listed ): array
    {
        $surface   = [ 'card', 'panel', 'inverted' ];
        $missing   = [];
        $decorated = [];
        $marked    = 0;
        foreach ( $rows as $key => $r ) {
            if ( null !== $r['marked'] ) {
                ++$marked;
                if ( in_array( $r['class'], $surface, true ) ) {
                    $decorated[] = "$key ({$r['class']})";
                }
                continue;
            }
            if ( in_array( $r['class'], $surface, true ) && ! isset( $listed[ $key ] ) ) {
                $missing[] = "$key ({$r['class']})";
            }
        }
        $stale = [];
        foreach ( array_keys( $listed ) as $key ) {
            if ( ! isset( $rows[ $key ] ) ) {
                $stale[] = "$key is not a block of the recipes";
            } elseif ( null !== $rows[ $key ]['marked'] ) {
                $stale[] = "$key carries a card marker";
            } elseif ( ! in_array( $rows[ $key ]['class'], $surface, true ) ) {
                $stale[] = "$key is a {$rows[ $key ]['class']}, not a card surface";
            }
        }
        return [ $missing, $stale, $decorated, $marked ];
    }

    public function testRecipesFileIsSmallerThanBeforeTheCardAdapter(): void
    {
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_CARDS, filesize( $file ), 'the recipe file ends smaller than before the card adapter (spec section 4)' );
    }

    /**
     * The opening block comments of $markup in document order: list of [name, attrs, index among that name].
     *
     * @return list<array{0:string,1:array<string,mixed>,2:int}>
     */
    private static function blocks( string $markup ): array
    {
        preg_match_all( '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s', $markup, $all, PREG_SET_ORDER );
        $out  = [];
        $seen = [];
        foreach ( $all as $m ) {
            $seen[ $m[1] ] = ( $seen[ $m[1] ] ?? -1 ) + 1;
            $attrs         = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : [];
            $out[]         = [ $m[1], is_array( $attrs ) ? $attrs : [], $seen[ $m[1] ] ];
        }
        return $out;
    }

    /** True when $node holds a `hover` key at any depth. */
    private static function hasHover( mixed $node ): bool
    {
        if ( ! is_array( $node ) ) {
            return false;
        }
        foreach ( $node as $k => $v ) {
            if ( 'hover' === $k || self::hasHover( $v ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * What is wrong with the card blocks of one resolved markup ([] = fine): for every block that carries a card marker in
     * $raw, the same block (name and index) in $resolved, after the kit $kit, must hold only the kit's card decoration.
     * - Structure (any language): a `panel` has no `hover` key at all; border `styles` has no side key (top/right/bottom/
     *   left) at any breakpoint or state, except an underline kit's `styles.bottom` at desktop rest; `module.decoration` holds
     *   only the keys the patch writes plus CARD_KEEPS plus `transform`, whose only non-rest state is the kit's own desktop
     *   hover; a `card` has no padding outside the kit's desktop padding.
     * - $exact (markup resolved by the kit only, colour tokens left): background, border and boxShadow equal the patch
     *   exactly (absent where the patch writes none), the transform minus its rest states equals the patch's hover
     *   transform, and a card's spacing minus its margins equals the patch's.
     *
     * @param array<string,mixed> $kit
     * @return list<string>
     */
    private static function markedCardProblems( string $label, string $raw, string $resolved, array $kit, bool $exact, int &$checked = 0 ): array
    {
        $out    = [];
        $after  = [];
        foreach ( self::blocks( $resolved ) as [ $name, $attrs, $i ] ) {
            $after[ "$name#$i" ] = $attrs;
        }
        $underline = 'bottom' === ( $kit['border']['sides'] ?? 'all' );
        foreach ( self::blocks( $raw ) as [ $name, $attrs, $i ] ) {
            $marker = self::cardMarker( $attrs );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            [ $role, $on ] = $marker;
            $where = "$label $name#$i ($role on $on)";
            $got   = $after[ "$name#$i" ] ?? null;
            $patch = KitAdapters::patch( $name, $role, $on, $kit );
            if ( ! is_array( $got ) || ! is_array( $patch ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $dec  = (array) ( $got['module']['decoration'] ?? [] );
            $keys = array_keys( (array) $patch['module']['decoration'] );
            if ( 'panel' === $role && self::hasHover( $got ) ) {
                $out[] = "$where: a panel keeps a hover key (a panel is static)";
            }
            foreach ( (array) ( $dec['border'] ?? [] ) as $bp => $states ) {
                foreach ( (array) $states as $state => $value ) {
                    $sides = array_values( array_intersect( array_keys( (array) ( $value['styles'] ?? [] ) ), [ 'top', 'right', 'bottom', 'left' ] ) );
                    $want  = $underline && 'desktop' === $bp && 'value' === $state ? [ 'bottom' ] : [];
                    if ( $sides !== $want ) {
                        $out[] = "$where: border.$bp.$state.styles has side keys [" . implode( ',', $sides ) . '] (a side style beats the kit\'s `all`)';
                    }
                }
            }
            // A REST transform (a tilt: any breakpoint's `value`) is the recipe's composition and stays; the kit writes only
            // the desktop HOVER transform (lift), so any other transform state is a stray the merge would keep.
            $kitTransform = $patch['module']['decoration']['transform'] ?? [];
            foreach ( (array) ( $dec['transform'] ?? [] ) as $bp => $states ) {
                foreach ( (array) $states as $state => $value ) {
                    if ( 'value' !== $state && $value !== ( $kitTransform[ $bp ][ $state ] ?? null ) ) {
                        $out[] = "$where: transform.$bp.$state is a hover transform the kit does not write";
                    }
                }
            }
            $stray = array_diff( array_keys( $dec ), $keys, self::CARD_KEEPS, [ 'transform' ] );
            if ( [] !== $stray ) {
                $out[] = "$where: module.decoration keeps " . implode( ', ', $stray ) . ' that the kit does not write';
            }
            if ( 'card' === $role ) {
                foreach ( (array) ( $dec['spacing'] ?? [] ) as $bp => $states ) {
                    foreach ( (array) $states as $state => $value ) {
                        if ( isset( $value['padding'] ) && ! ( 'desktop' === $bp && 'value' === $state ) ) {
                            $out[] = "$where: spacing.$bp.$state.padding overrides the kit's card padding";
                        }
                    }
                }
            }
            if ( ! $exact ) {
                continue;
            }
            $expect = self::blocks( KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => $role, 'on' => $on ] ] ) . ' /-->', $kit ) )[0][1]['module']['decoration'] ?? [];
            foreach ( [ 'background', 'border', 'boxShadow' ] as $key ) {
                if ( ( $dec[ $key ] ?? null ) !== ( $expect[ $key ] ?? null ) ) {
                    $out[] = "$where: $key is not exactly the kit's (" . json_encode( $dec[ $key ] ?? null, JSON_UNESCAPED_SLASHES ) . ')';
                }
            }
            // The transform minus its rest states is exactly the kit's hover transform (or nothing).
            $hoverPart = (array) ( $dec['transform'] ?? [] );
            foreach ( $hoverPart as $bp => $states ) {
                unset( $hoverPart[ $bp ]['value'] );
                if ( [] === $hoverPart[ $bp ] ) {
                    unset( $hoverPart[ $bp ] );
                }
            }
            if ( $hoverPart !== ( $expect['transform'] ?? [] ) ) {
                $out[] = "$where: the hover transform is not exactly the kit's (" . json_encode( $hoverPart, JSON_UNESCAPED_SLASHES ) . ')';
            }
            if ( 'card' === $role ) {
                $spacing = (array) ( $dec['spacing'] ?? [] );
                foreach ( $spacing as $bp => $states ) {
                    foreach ( (array) $states as $state => $value ) {
                        unset( $spacing[ $bp ][ $state ]['margin'] );
                        if ( [] === $spacing[ $bp ][ $state ] ) {
                            unset( $spacing[ $bp ][ $state ] );
                        }
                    }
                    if ( [] === $spacing[ $bp ] ) {
                        unset( $spacing[ $bp ] );
                    }
                }
                if ( $spacing !== ( $expect['spacing'] ?? [] ) ) {
                    $out[] = "$where: the padding is not exactly the kit's (" . json_encode( $spacing, JSON_UNESCAPED_SLASHES ) . ')';
                }
            }
        }
        return $out;
    }

    public function testMarkedCardsKeepNoCardDecorationOfTheirOwn(): void
    {
        $problems = [];
        $recipes  = 0;
        $blocks   = 0;
        $walked   = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            $walked += count( self::cardBands( $raw ) );
            if ( 0 === self::cardMarkerCount( $raw ) ) {
                continue;
            }
            ++$recipes;
            foreach ( StyleKits::ids() as $n => $kitId ) {
                $kit      = (array) StyleKits::get( $kitId );
                $seen     = 0;
                $problems = array_merge( $problems, self::markedCardProblems( "$name/$kitId", $raw, KitResolver::resolve( $raw, $kit ), $kit, true, $seen ) );
                if ( 0 === $n ) {
                    $blocks += $seen;
                }
                foreach ( DesignLanguages::ids() as $lang ) {
                    $problems = array_merge( $problems, self::markedCardProblems( "$name/$kitId/$lang", $raw, (string) SectionRecipes::recipe( $name, $lang, $kitId ), $kit, false ) );
                }
            }
        }
        $this->assertGreaterThan( 0, $recipes, 'the recipes carry card markers' );
        $this->assertSame( self::cardMarkersInFile()[0], $blocks, 'the merge trap checks every card marker in the file' );
        $this->assertSame( $walked, $blocks, 'the merge trap checks exactly the markers the band test walks' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked card keeps only structure: its own card decoration survives the kit\'s deep merge' );

        // Under a lift kit, hero-gradient-stats' cards keep their rest tilt AND get the kit's hover lift.
        $tilts = [];
        foreach ( self::blocks( KitResolver::resolve( (string) SectionRecipes::rawMarkup( 'hero-gradient-stats' ), (array) StyleKits::get( 'soft-lift' ) ) ) as [ $name, $attrs, $i ] ) {
            if ( 'divi/group' === $name && in_array( $i, [ 1, 2, 3 ], true ) ) {
                $t = (array) ( $attrs['module']['decoration']['transform'] ?? [] );
                $this->assertSame( [ 'y' => '-4px' ], $t['desktop']['hover']['translate'] ?? null, "hero-gradient-stats group#$i: the kit's hover lift" );
                $this->assertSame( [ 'z' => '0deg' ], $t['phone']['value']['rotate'] ?? null, "hero-gradient-stats group#$i: the phone reset of the tilt stays" );
                $tilts[] = $t['desktop']['value']['rotate']['z'] ?? null;
            }
        }
        $this->assertSame( [ '-3deg', '2deg', '-1deg' ], $tilts, 'the recipe\'s rest tilt survives the kit' );
    }

    public function testTheMarkedCardCheckCatchesStrayDecoration(): void
    {
        $sec  = static fn ( string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $grp  = static fn ( array $attrs ): string => '<!-- wp:divi/group ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><!-- /wp:divi/group -->';
        $kit  = (array) StyleKits::get( 'quiet-solid' );
        $run  = static function ( string $raw, array $kit ): string {
            $p = self::markedCardProblems( 'planted', $raw, KitResolver::resolve( $raw, $kit ), $kit, true );
            return implode( "\n", $p );
        };
        $panel = [ 'kit' => 'panel', 'on' => 'light' ];
        $card  = [ 'kit' => 'card', 'on' => 'light' ];

        // Clean: a marker with only structure and margins left.
        $this->assertSame( '', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'maxWidth' => '320px' ] ] ], 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '-40px' ] ] ] ] ] ], 'aied' => $card ] ) ), $kit ) );
        $this->assertSame( '', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '56px' ] ] ], 'phone' => [ 'value' => [ 'padding' => [ 'top' => '24px' ] ] ] ] ] ], 'aied' => $panel ] ) ), $kit ), 'a panel keeps its own padding' );

        // A stray hover border and a side style on a panel.
        $stray = $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '2px' ] ] ], 'hover' => [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:accent}}' ] ] ] ] ] ] ], 'aied' => $panel ] ) ), $kit );
        $this->assertStringContainsString( 'a panel keeps a hover key', $stray );
        $this->assertStringContainsString( 'border.desktop.value.styles has side keys [top]', $stray );
        $this->assertStringContainsString( 'border is not exactly the kit\'s', $stray );
        // A rest shadow and a rest transform the kit does not write, and a phone padding on a card.
        $stray = $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'blur' => '40px' ] ] ], 'transform' => [ 'desktop' => [ 'value' => [ 'rotate' => [ 'z' => '-3deg' ] ] ] ], 'spacing' => [ 'phone' => [ 'value' => [ 'padding' => [ 'top' => '8px' ] ] ] ] ] ], 'aied' => $card ] ) ), $kit );
        $this->assertStringContainsString( 'module.decoration keeps boxShadow that the kit does not write', $stray );
        $this->assertStringNotContainsString( 'transform', $stray, 'a rest tilt is the recipe\'s composition and stays' );
        $this->assertStringContainsString( 'spacing.phone.value.padding overrides the kit\'s card padding', $stray );
        $this->assertStringContainsString( 'the padding is not exactly the kit\'s', $stray );
        // Rest tilt both ways (controller amendment to Ruling 2): a rest rotate (desktop and a phone reset) is allowed under
        // a kit without lift and under a lift kit; a recipe hover transform is rejected under both.
        $lift = (array) StyleKits::get( 'soft-lift' );
        $this->assertSame( 'lift', $lift['hover'] );
        $tilt = [ 'desktop' => [ 'value' => [ 'rotate' => [ 'z' => '-3deg' ] ] ], 'phone' => [ 'value' => [ 'rotate' => [ 'z' => '0deg' ] ] ] ];
        foreach ( [ $kit, $lift ] as $k ) {
            $this->assertSame( '', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'transform' => $tilt ] ], 'aied' => $card ] ) ), $k ) );
            $tiltAndHover = array_replace_recursive( $tilt, [ 'desktop' => [ 'hover' => [ 'scale' => [ 'x' => '105%' ] ] ] ] );
            $hovered      = $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'transform' => $tiltAndHover ] ], 'aied' => $card ] ) ), $k );
            $this->assertStringContainsString( 'transform.desktop.hover is a hover transform the kit does not write', $hovered );
            $this->assertStringContainsString( 'the hover transform is not exactly the kit\'s', $hovered );
        }
        $this->assertStringContainsString( 'transform.phone.hover is a hover transform', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'transform' => [ 'phone' => [ 'hover' => [ 'translate' => [ 'y' => '-2px' ] ] ] ] ] ], 'aied' => $panel ] ) ), $kit ) );
        // A key outside the structure list.
        $this->assertStringContainsString( 'keeps filters', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'filters' => [ 'desktop' => [ 'value' => [ 'blur' => '2px' ] ] ] ] ], 'aied' => $card ] ) ), $kit ) );
        // An underline kit's bottom side is the kit's own and passes; a recipe's extra top side does not.
        $under = (array) StyleKits::get( 'underline-link' );
        $this->assertSame( 'bottom', $under['border']['sides'] );
        $this->assertSame( '', $run( $sec( $grp( [ 'aied' => $card ] ) ), $under ) );
        $this->assertStringContainsString( 'side keys [top,bottom]', $run( $sec( $grp( [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '1px' ] ] ] ] ] ] ], 'aied' => $card ] ) ), $under ) );
    }

    public function testCardMarkedRecipeProseDoesNotDescribeTheCardLook(): void
    {
        $problems = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            if ( 0 === self::cardMarkerCount( (string) $recipe['markup'] ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::CARD_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the style kit decides a card\'s corners, frame and shadow: describe its role, not its look' );
        // The phrases catch the shipped wording they were written for.
        $this->assertSame( 1, preg_match( self::CARD_DECIDED_PHRASES[0], 'a hairline-bordered card of ruled lines' ) );
        $this->assertSame( 1, preg_match( self::CARD_DECIDED_PHRASES[0], 'a rounded card on the page colour' ) );
        $this->assertSame( 1, preg_match( self::CARD_DECIDED_PHRASES[1], 'modern, rounded and dark-glass brands' ) );
        $this->assertSame( 0, preg_match( self::CARD_DECIDED_PHRASES[0], 'a card of ruled label/value lines' ) );
        // Task 8's wording (the old descriptions and `when` lines of the nine group recipes).
        foreach ( [ 'over two square-cornered panels', 'upscale brands with square corners', 'editorial brands with small corners', 'carry heavy borders', 'inside a heavy-bordered panel' ] as $old ) {
            $this->assertSame( 1, preg_match( self::CARD_DECIDED_PHRASES[3], $old ), $old );
        }
        foreach ( [ 'square, flat and upscale brands', 'rounded, soft and layered brands', 'sharp, technical and upscale brands', 'warm, friendly and rounded brands', 'a framed Divi contact form', 'a raised surface card' ] as $old ) {
            $this->assertSame( 1, preg_match( self::CARD_DECIDED_PHRASES[4], $old ), $old );
        }
        $this->assertSame( 0, preg_match( self::CARD_DECIDED_PHRASES[4], 'a large rounded image on the left' ), 'an image keeps its own corners' );
    }

    /**
     * Phrases that describe a button look a style kit now decides (fill, border, colour), banned from the description and
     * `when` of a recipe whose buttons are kit-driven: the prose must be true under every kit.
     */
    private const KIT_DECIDED_PHRASES = [
        '/ghost button/i',
        '/bordered button/i',
        '/filled button/i',
        '/outlined? button/i',
        '/solid (plus a )?button/i',
        '/button in the surface colou?r/i',
        '/pill(-shaped)? button/i',
        '/heavy borders and square corners/i',
        // A corner shape in `when` that only the old baked button gave: cta-band and faq-two-column have no other element with
        // a corner radius. (pricing-two-plan-highlight's "upscale brands with square corners" went with the card adapter, Task 8:
        // its plans now take the kit's card radius, CARD_DECIDED_PHRASES.)
        '/editorial brands with (small|square) corners/i',
    ];

    public function testKitDrivenRecipeProseDoesNotDescribeTheButtonLook(): void
    {
        $problems = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            if ( ! str_contains( (string) $recipe['markup'], '"aied"' ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::KIT_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the style kit decides how a recipe button looks: describe its role, not its look' );
    }

    public function testEveryKitDrivenRecipeIsServedValidUnderEveryLanguageAndKit(): void
    {
        $checked = 0;
        foreach ( SectionRecipes::names() as $name ) {
            if ( ! str_contains( (string) SectionRecipes::rawMarkup( $name ), '"aied"' ) ) {
                continue;
            }
            ++$checked;
            foreach ( DesignLanguages::ids() as $lang ) {
                foreach ( StyleKits::ids() as $kit ) {
                    $label  = "$name under $lang with the $kit kit";
                    $markup = SectionRecipes::recipe( $name, $lang, $kit );
                    $this->assertIsString( $markup, $label );
                    $this->assertStringNotContainsString( '"aied"', $markup, "$label leaves a kit marker" );
                    $this->assertStringNotContainsString( '{{aied:', $markup, "$label leaves a token" );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), $label );
                    $result = LayoutGate::check( $this->wrap( $markup ) );
                    $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                    $this->assertTrue( $result->isValid(), "$label must pass the layout gate, got: $codes" );
                    $this->assertTrue( ( new Validator() )->validateContent( $this->wrap( $markup ) )->isValid(), "$label must validate" );
                }
            }
        }
        // 22 recipes have a button; compare-two-panels has only a bespoke one (BESPOKE_BUTTONS): 21 with a kit-driven button,
        // plus the recipes whose only kit marker is a card or panel: cta-split-form (card adapter Task 7) and
        // compare-two-panels, features-bento, contact-location-form, contact-details-form, transition-overlap-panel (Task 8).
        $this->assertSame( 27, $checked, 'the recipes with a kit marker (button or card)' );
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

    /**
     * What is wrong with one `# render-failures` line `recipe|language|width|code|explanation` ([] = fine). Mirrors
     * scripts/lib/recipe-checks.cjs parseEntry (the JS side is tested by scripts/test-recipe-baseline-matcher.cjs).
     *
     * @param list<string> $recipes
     * @param list<string> $languages
     * @return list<string>
     */
    private static function renderFailureProblems( string $line, array $recipes, array $languages ): array
    {
        $parts = array_map( 'trim', explode( '|', $line, 5 ) );
        if ( count( $parts ) < 5 ) {
            return [ 'must be recipe|language|width|code|explanation' ];
        }
        [ $recipe, $language, $width, $code, $why ] = $parts;
        $bad = [];
        if ( ! in_array( $recipe, $recipes, true ) ) {
            $bad[] = "\"$recipe\" is not a recipe";
        }
        if ( 'default' !== $language && ! in_array( $language, $languages, true ) ) {
            $bad[] = "\"$language\" is not default or a design language";
        }
        if ( 'render-error' === $code ) {
            $bad[] = 'render-error can never be baselined';
        } elseif ( ! in_array( $code, self::RENDER_CODES, true ) ) {
            $bad[] = "unknown reason code \"$code\"";
        }
        if ( ( 'gate' === $code ) !== ( 'gate' === $width ) ) {
            $bad[] = 'width "gate" goes with code gate, and only with it';
        } elseif ( 'gate' !== $code && ! in_array( $width, [ '1280', '900', '400' ], true ) ) {
            $bad[] = 'width must be exactly 1280, 900 or 400 (no wildcard)';
        }
        if ( '' === $why ) {
            $bad[] = 'an entry needs a written explanation';
        }
        return $bad;
    }

    public function testRenderFailureBaselineIsWellFormedAndWithinTheFrozenCap(): void
    {
        $entries = $this->baseline()['render-failures'];
        $this->assertLessThanOrEqual( self::RENDER_FAILURE_CAP, count( $entries ), 'the render-failures baseline may only shrink: fix or retire the recipe instead of listing it (lower RENDER_FAILURE_CAP when you remove entries)' );
        foreach ( $entries as $line ) {
            $problems = self::renderFailureProblems( $line, SectionRecipes::names(), DesignLanguages::ids() );
            $this->assertSame( [], $problems, "render-failures entry \"$line\": " . implode( '; ', $problems ) );
        }
    }

    public function testTheRenderFailureEntryRulesRejectWhatTheyShould(): void
    {
        $r = SectionRecipes::names();
        $l = DesignLanguages::ids();
        $this->assertSame( [], self::renderFailureProblems( 'hero-cta|default|400|overflow|a documented reason', $r, $l ) );
        $this->assertSame( [], self::renderFailureProblems( 'hero-cta|glass-dark|gate|gate|rejected by the gate', $r, $l ) );
        $bad = [
            'old three-field format'       => 'hero-cta|overflow|why',
            'wildcard width'               => 'hero-cta|default|*|overflow|why',
            'width not one of the three'   => 'hero-cta|default|500|overflow|why',
            'unknown recipe'               => 'no-such-recipe|default|400|overflow|why',
            'unknown language'             => 'hero-cta|no-such-language|400|overflow|why',
            'unknown code'                 => 'hero-cta|default|400|sparkle|why',
            'render-error never baselined' => 'hero-cta|default|400|render-error|why',
            'gate code, non-gate width'    => 'hero-cta|default|400|gate|why',
            'gate width, non-gate code'    => 'hero-cta|default|gate|overflow|why',
            'empty explanation'            => 'hero-cta|default|400|overflow|',
        ];
        foreach ( $bad as $label => $line ) {
            $this->assertNotSame( [], self::renderFailureProblems( $line, $r, $l ), $label );
        }
    }

    public function testTheNodeMatcherOnlyKnowsTheExactCell(): void
    {
        $node = trim( (string) ( function_exists( 'shell_exec' ) ? @shell_exec( 'command -v node 2>/dev/null' ) : '' ) );
        if ( '' === $node ) {
            $this->markTestSkipped( 'node is not available (or shell_exec is disabled): scripts/test-recipe-baseline-matcher.cjs was not run' );
        }
        $out = [];
        $code = 1;
        exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/test-recipe-baseline-matcher.cjs' ) . ' 2>&1', $out, $code );
        $this->assertSame( 0, $code, implode( "\n", $out ) );
        $this->assertStringContainsString( 'OK recipe baseline parser', implode( "\n", $out ) );
    }

    public function testTheRenderAllowanceFileNamesRealRecipes(): void
    {
        $file = __DIR__ . '/../fixtures/shapes/recipe-render-allowances.json';
        $this->assertFileExists( $file );
        $data = json_decode( (string) file_get_contents( $file ), true );
        $this->assertIsArray( $data );
        foreach ( $data as $name => $reason ) {
            $this->assertContains( $name, SectionRecipes::names(), "allowance for \"$name\", which is not a recipe" );
            // A string exempts the columns-side-by-side check (the original meaning); an object names each exempted check
            // ("columns", "overlap", "contrast") with its own written reason.
            $reasons = is_array( $reason ) ? $reason : [ 'columns' => $reason ];
            $this->assertNotSame( [], $reasons, "allowance for $name is empty" );
            foreach ( $reasons as $check => $why ) {
                $this->assertContains( $check, [ 'columns', 'overlap', 'contrast' ], "allowance for $name names an unknown check \"$check\"" );
                $this->assertIsString( $why );
                $this->assertNotSame( '', trim( $why ), "allowance for $name ($check) needs a reason" );
            }
        }
    }
}
