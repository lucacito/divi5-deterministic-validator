<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';

/**
 * Recipe-level guards for contact forms driven by a style kit (marker `form` on a `divi/contact-form`), kept apart from
 * RecipeLibraryTest and ItemMigrationTest to keep those files smaller. The form adapter writes the form's field look (fill,
 * ink, placeholder, frame, radius, padding), the submit button (fill, border, radius, label, hover, padding, shadow, lift)
 * and the captcha ink, so a migrated form must carry none of its own:
 *
 * - own ink: a marked form holds no field, button or captcha colour, frame, fill, padding, shadow or lift of its own, and a
 *   `divi/contact-field` child under it carries no colour, frame or fill (an item-level value beats the parent's);
 * - band: the marker's `on` equals the colour the form is really printed on (the shared band walk; a marked panel counts as
 *   its resolved fill: `surface` on a light band, `bg` on a surface band), and the form has no background of its own that the
 *   walk would ignore;
 * - merge trap: after the kit resolves, every field/button/captcha leaf the patch writes holds exactly the patch's value and
 *   every other leaf is one the recipe may keep (the field and captcha font family and size, the button label and its
 *   enable/icon switch, its font family and size, the form module's own frame, radius, width and alignment);
 * - none bespoke: BESPOKE_FORMS is empty (cap 0);
 * - prose: a recipe with a form marker does not describe a field or button look the kit decides.
 */
class FormMigrationTest extends TestCase
{
    /** The bands the form role sits on (KitAdapters' FORM_BANDS). */
    private const BANDS = [ 'light', 'surface', 'accent', 'dark' ];

    /** The leaves of a marked form whose colours, frames, fills, spacing, shadows and lift the form patch decides. */
    private const OWN_INK = [
        '/^field\.decoration\.(background|border|spacing|boxShadow)(\.|$)/',
        '/^field\.decoration\.(font|placeholderFont)\.font\.[^.]+\.[^.]+\.color$/',
        '/^captcha\.decoration\.font\.font\.[^.]+\.[^.]+\.color$/',
        '/^button\.decoration\.(background|border|spacing|boxShadow|transform)(\.|$)/',
        '/^button\.decoration\.font\.font\.[^.]+\.[^.]+\.(color|weight|capitalization|letterSpacing)$/',
    ];

    /** Leaves of a `divi/contact-field` under a marked form that would beat the form's: any colour, frame, fill, spacing or shadow. */
    private const FIELD_CHILD_INK = '/(^|\.)(border|background|boxShadow|spacing)(\.|$)|(^|\.)color$/';

    /**
     * Leaf paths a marked form may keep that the patch does not write (regexes over dotted paths; any breakpoint and state):
     * the block version, preset and Theme Builder area, the form module's own advanced settings, frame, radius, sizing,
     * spacing, layout and position, the field and captcha type scale (family, size, weight, line height, never a colour), the
     * submit label and its enable/icon switch, and the submit's family, size and line height (the kit decides its colour,
     * weight, capitalization, letter spacing, fill, frame, padding, shadow and lift). Anything else fails closed.
     */
    private const FORM_KEEPS = [
        '/^builderVersion$/',
        '/^modulePreset(\.|$)/',
        '/^themeBuilderArea(\.|$)/',
        '/^module\.(advanced|meta)(\.|$)/',
        '/^module\.decoration\.(sizing|border|spacing|layout|zIndex|position|animation|attributes)(\.|$)/',
        '/^field\.decoration\.font\.font\.[^.]+\.[^.]+\.(family|size|weight|lineHeight)$/',
        '/^captcha\.decoration\.font\.font\.[^.]+\.[^.]+\.(family|size|weight|lineHeight)$/',
        '/^button\.innerContent(\.|$)/',
        '/^button\.decoration\.button(\.|$)/',
        '/^button\.decoration\.font\.font\.[^.]+\.[^.]+\.(family|size|lineHeight)$/',
    ];

    /**
     * Contact forms deliberately NOT driven by a style kit: "<recipe>|<block>|<index among that block name>" => why.
     * SHRINK-ONLY and EMPTY since forms adapter Task 4 (cap 0): every contact form in the recipes carries a form marker, and
     * nothing may be added.
     */
    private const BESPOKE_FORMS = [];

    private const BESPOKE_FORMS_MAX = 0;

    /** The file size before the four recipe forms were migrated (forms adapter plan, Task 4): the file must end below it. */
    private const SIZE_BEFORE_FORMS = 675810;

    /**
     * The 4 marker blocks: "<recipe>|<block>|<index among that block name>" => band. Pinned as literals, never read from git
     * or the recipes, so a lost or added marker fails here. The band is what the walk makes it: contact-form is printed on a
     * photo row with an overlay (dark); cta-split-form on a panel in a surface section (the panel's fill is `bg`: light);
     * contact-location-form and contact-details-form on a panel on the page colour (the panel's fill is `surface`).
     */
    private const MIGRATED_FORMS = [
        'contact-form|divi/contact-form|0'          => 'dark',
        'cta-split-form|divi/contact-form|0'        => 'light',
        'contact-location-form|divi/contact-form|0' => 'surface',
        'contact-details-form|divi/contact-form|0'  => 'surface',
    ];

    /**
     * Phrases that describe a field or button look a style kit now decides (corners, frames, fills), banned from the title,
     * description and `when` of a recipe with a form marker: the prose must be true under every kit.
     */
    private const FORM_DECIDED_PHRASES = [
        '/\b(rounded|pill|pill-shaped|square|square-cornered|hairline|hairline-framed|outlined|filled|framed|bordered|underlined)\s+(\w+\s+)?(fields?|inputs?|text boxes|submit|form buttons?)\b/i',
        '/\b(fields?|inputs?|submit buttons?)\s+(with|in|on)\s+(rounded|square|sharp|hairline|pill)\b/i',
    ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    /**
     * [role, on] of a form marker in $attrs, or null (no marker, or a button, card or item role). A form marker without a
     * string `on` throws: the resolver would default it, and a recipe form must say where it sits.
     *
     * @param array<string,mixed> $attrs
     * @return array{0:string,1:string}|null
     */
    public static function formMarker( array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || 'form' !== ( $m['kit'] ?? null ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'a form marker without "on": a recipe form must name its band, never default' );
        }
        return [ 'form', (string) $m['on'] ];
    }

    /** Opening block comments of $markup in document order: list of [name, attrs, index among that name]. @return list<array{0:string,1:array<string,mixed>,2:int}> */
    private static function blocks( string $markup ): array
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
    private static function leaves( array $node, string $prefix = '' ): array
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

    private static function matchesAny( string $path, array $patterns ): bool
    {
        foreach ( $patterns as $re ) {
            if ( 1 === preg_match( $re, $path ) ) {
                return true;
            }
        }
        return false;
    }

    /** How many form markers the block comments of $markup carry (parsed, so key order does not matter). */
    private static function markerCount( string $markup ): int
    {
        return count( array_filter( self::blocks( $markup ), static fn ( array $b ): bool => null !== self::formMarker( $b[1] ) ) );
    }

    /**
     * (a) and (b): what the RAW markup's marked forms still carry of their own ([] = fine): an own-ink leaf on a marked
     * `divi/contact-form`, and any colour, frame, fill, spacing or shadow on a `divi/contact-field` under one.
     *
     * @return list<string>
     */
    public static function ownInkProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out  = [];
        $root = ( new \Divi5Validator\BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' )->root();
        $seen = [];
        $walk = static function ( \Divi5Validator\Block $b, bool $underMarked ) use ( &$walk, &$out, &$seen, &$checked, $label ): void {
            $name          = $b->name();
            $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
            $where         = "$label $name#{$seen[ $name ]}";
            $marker        = self::formMarker( $b->attrs() );
            if ( null !== $marker && 'divi/contact-form' === $name ) {
                ++$checked;
                foreach ( array_keys( self::leaves( $b->attrs() ) ) as $path ) {
                    if ( ! str_starts_with( $path, 'aied.' ) && self::matchesAny( $path, self::OWN_INK ) ) {
                        $out[] = "$where (form on {$marker[1]}): the migrated block must drop its own $path";
                    }
                }
            }
            if ( $underMarked && 'divi/contact-field' === $name ) {
                foreach ( array_keys( self::leaves( $b->attrs() ) ) as $path ) {
                    if ( 1 === preg_match( self::FIELD_CHILD_INK, $path ) ) {
                        $out[] = "$where: a field under a marked form carries $path (a field-level value beats the form's)";
                    }
                }
            }
            $marked = null !== $marker && 'divi/contact-form' === $name;
            foreach ( $b->children() as $child ) {
                $walk( $child, $underMarked || $marked );
            }
        };
        $walk( $root, false );
        return $out;
    }

    /**
     * (c): every form marker's `on` must equal the band its nearest ground makes (RecipeLibraryTest's band walk), that band
     * must be one the role may sit on, and the form must not paint a background of its own (the walk looks at ancestors only,
     * so the form's own ground would make `on` wrong).
     *
     * @return list<string>
     */
    public static function bandProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( RecipeLibraryTest::markerBands( $markup, static fn ( array $a ): ?array => self::formMarker( $a ) ) as $key => [ $marker, $band ] ) {
            ++$checked;
            if ( $marker[1] !== $band ) {
                $out[] = "$label $key: form marker on={$marker[1]}, but the nearest ground makes it $band";
            }
            if ( ! in_array( $band, self::BANDS, true ) ) {
                $out[] = "$label $key: form on a $band band (it sits on " . implode( '|', self::BANDS ) . ' only)';
            }
        }
        foreach ( self::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            if ( 'divi/contact-form' === $name && null !== self::formMarker( $attrs ) && isset( $attrs['module']['decoration']['background'] ) ) {
                $out[] = "$label $name#$i: a marked form with a background of its own (the band walk would not see it)";
            }
        }
        return $out;
    }

    /**
     * (d) the merge trap: for every block that carries a form marker in $raw, the same block (name and index) in $resolved
     * (after the kit $kit and, when given, the language $lang): every leaf the patch writes holds exactly the patch's value
     * (the patch resolved the same way), and every other leaf matches FORM_KEEPS.
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
            $marker = self::formMarker( $attrs );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            $on    = $marker[1];
            $where = "$label $name#$i (form on $on)";
            $got   = $after[ "$name#$i" ] ?? null;
            $bare  = KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => 'form', 'on' => $on ] ] ) . ' /-->', $kit );
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
                } elseif ( ! self::matchesAny( $path, self::FORM_KEEPS ) ) {
                    $out[] = "$where: keeps $path that the kit does not write";
                }
            }
            foreach ( array_diff_key( $want, self::leaves( $got ) ) as $path => $value ) {
                $out[] = "$where: lacks the kit's $path";
            }
        }
        return $out;
    }

    /** The form markers of the recipe FILE: [parsed from block comments, spelled `"kit":"form"`]. @return array{0:int,1:int} */
    private static function markersInFile(): array
    {
        $parsed  = 0;
        $spelled = 0;
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            $markup   = (string) $recipe['markup'];
            $parsed  += self::markerCount( $markup );
            $spelled += substr_count( $markup, '"kit":"form"' );
        }
        return [ $parsed, $spelled ];
    }

    public function testMarkedFormsCarryNoInkOfTheirOwnAndMatchTheirBand(): void
    {
        [ $parsed, $spelled ] = self::markersInFile();
        $this->assertSame( $spelled, $parsed, 'every "kit":"form" in the file is a top-level marker of a block comment' );
        $own      = [];
        $bands    = [];
        $ownSeen  = 0;
        $bandSeen = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $own   = array_merge( $own, self::ownInkProblems( $name, $raw, $ownSeen ) );
            $bands = array_merge( $bands, self::bandProblems( $name, $raw, $bandSeen ) );
        }
        $this->assertSame( 4, $parsed, 'the four migrated recipes carry exactly 4 form markers' );
        $this->assertSame( $parsed, $ownSeen, 'every form marker in the file is checked for its own ink' );
        $this->assertSame( $parsed, $bandSeen, 'every form marker in the file is checked against its band' );
        $this->assertSame( [], $own, 'a migrated form keeps its own field, button or captcha ink, or a field carries a colour, frame or fill' );
        $this->assertSame( [], $bands, 'a form\'s "on" must name the colour it is really printed on, or the kit picks the wrong field fill and ink' );
    }

    public function testMarkedFormsKeepOnlyStructureUnderEveryKitAndLanguage(): void
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
        $this->assertSame( 4, $blocks, 'the merge trap walks all 4 form markers' );
        $this->assertSame( self::markersInFile()[0], $blocks, 'the merge trap checks every form marker in the file' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked form keeps only structure, its label and its type scale: its own ink survives the kit\'s deep merge' );
    }

    /** Every `divi/contact-form` of the recipe file: "<recipe>|<block>|<index among that block name>" => [role, on] or null. @return array<string,?array{0:string,1:string}> */
    private static function formBlocks(): array
    {
        $out = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            foreach ( self::blocks( (string) $recipe['markup'] ) as [ $name, $attrs, $i ] ) {
                if ( 'divi/contact-form' === $name ) {
                    $out[ $recipe['name'] . '|' . $name . '|' . $i ] = self::formMarker( $attrs );
                }
            }
        }
        return $out;
    }

    public function testTheFourFormMarkersArePinnedAndNoFormIsBespoke(): void
    {
        $blocks = self::formBlocks();
        $marked = array_map( static fn ( array $m ): string => $m[1], array_filter( $blocks, static fn ( ?array $m ): bool => null !== $m ) );
        $pinned = self::MIGRATED_FORMS;
        ksort( $pinned );
        ksort( $marked );
        $this->assertSame( $pinned, $marked, 'exactly these blocks carry a form marker, with this band' );
        $this->assertCount( 4, $marked );
        $bespoke = array_keys( array_filter( $blocks, static fn ( ?array $m ): bool => null === $m ) );
        foreach ( $bespoke as $key ) {
            $this->assertArrayHasKey( $key, self::BESPOKE_FORMS, "$key is a contact form with no form marker: give it one (BESPOKE_FORMS never grows)" );
        }
        foreach ( array_keys( self::BESPOKE_FORMS ) as $key ) {
            $this->assertContains( $key, $bespoke, "$key is listed as bespoke but carries a marker or is gone: remove it" );
        }
        $this->assertLessThanOrEqual( self::BESPOKE_FORMS_MAX, count( self::BESPOKE_FORMS ), 'shrink-only: BESPOKE_FORMS can never grow' );
        $this->assertSame( 0, self::BESPOKE_FORMS_MAX, 'cap 0: every contact form in the recipes is kit-driven' );
        $this->assertSame( [], self::BESPOKE_FORMS );
        $this->assertSame( [], $bespoke, 'every contact form in the recipes carries a form marker' );
    }

    public function testRecipesFileIsSmallerThanBeforeTheForms(): void
    {
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_FORMS, filesize( $file ), 'migrating the four forms shrinks the recipe file (forms adapter plan, Task 4)' );
        $this->assertLessThan( 700 * 1024, filesize( $file ), 'the 700 KB bound holds' );
    }

    public function testFormMarkedRecipeProseDoesNotDescribeTheFormLook(): void
    {
        $problems = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            if ( 0 === self::markerCount( (string) $recipe['markup'] ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::FORM_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the style kit decides a form\'s fields and submit button: describe the form\'s role, not its look' );
        // The phrases catch the wording they were written for, and leave the shipped role prose alone.
        foreach ( [ 'a short form with rounded fields and a pill button', 'hairline-framed inputs', 'square-cornered text boxes', 'a contact form with fields in sharp corners' ] as $old ) {
            $hit = false;
            foreach ( self::FORM_DECIDED_PHRASES as $phrase ) {
                $hit = $hit || 1 === preg_match( $phrase, $old );
            }
            $this->assertTrue( $hit, $old );
        }
        foreach ( [ 'a card on the page colour holding a short Divi contact form (name and email) and its button', 'a Divi contact form (name, email, message) on the surface colour', 'an arch-topped photo of the place' ] as $fine ) {
            foreach ( self::FORM_DECIDED_PHRASES as $phrase ) {
                $this->assertSame( 0, preg_match( $phrase, $fine ), $fine );
            }
        }
    }

    // ---- planted cases: each check fails on an unmigrated recipe with a marker added, and passes on the clean shape ----

    /** $markup with a form marker on $on added to every contact form. */
    private static function markEvery( string $markup, string $on ): string
    {
        return (string) preg_replace_callback(
            '/(<!--\s+wp:divi\/contact-form\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static function ( array $m ) use ( $on ): string {
                $attrs         = json_decode( $m[2], true );
                $attrs['aied'] = [ 'kit' => 'form', 'on' => $on ];
                return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[3];
            },
            $markup
        );
    }

    /**
     * $markup with the own ink of every marked form removed: every leaf that is not in FORM_KEEPS and not the marker is
     * dropped, and so is any ink on a contact-field (the migration this task makes by hand).
     */
    private static function migrate( string $markup ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) ) {
                    return $m[0];
                }
                $isForm  = 'divi/contact-form' === $m[1] && null !== self::formMarker( $attrs );
                $isField = 'divi/contact-field' === $m[1];
                if ( ! $isForm && ! $isField ) {
                    return $m[0];
                }
                $clean = [];
                foreach ( self::leaves( $attrs ) as $path => $value ) {
                    $keep = $isForm ? ( str_starts_with( $path, 'aied.' ) || self::matchesAny( $path, self::FORM_KEEPS ) ) : 1 !== preg_match( self::FIELD_CHILD_INK, $path );
                    if ( $keep ) {
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

    /** $markup with the old recipes' own ink put back on every contact form (field, placeholder, captcha, submit) and ink on every contact field. */
    private static function withOldInk( string $markup ): string
    {
        $value = static fn ( array $v ): array => [ 'desktop' => [ 'value' => $v ] ];
        $ink   = [
            'field'   => [ 'decoration' => [
                'background'      => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:surface}}' ] ], 'tablet' => [ 'value' => [ 'color' => '{{aied:color:bg}}' ] ] ],
                'font'            => [ 'font' => $value( [ 'color' => '{{aied:color:text}}' ] ) ],
                'placeholderFont' => [ 'font' => $value( [ 'color' => '{{aied:color:muted}}' ] ) ],
                'border'          => $value( [ 'radius' => [ 'topLeft' => '12px', 'topRight' => '12px', 'bottomRight' => '12px', 'bottomLeft' => '12px' ], 'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:muted}}', 'style' => 'solid' ] ] ] ),
            ] ],
            'captcha' => [ 'decoration' => [ 'font' => [ 'font' => $value( [ 'color' => '{{aied:color:text}}' ] ) ] ] ],
            'button'  => [ 'decoration' => [
                'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ], 'hover' => [ 'color' => '{{aied:color:text}}' ] ] ],
                'border'     => $value( [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:accent}}', 'width' => '2px' ] ], 'radius' => [ 'topLeft' => '25px', 'topRight' => '25px', 'bottomRight' => '25px', 'bottomLeft' => '25px' ] ] ),
                'font'       => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent-text}}', 'weight' => '600' ], 'hover' => [ 'color' => '{{aied:color:bg}}' ] ] ] ],
                'spacing'    => [ 'desktop' => [ 'value' => [ 'padding' => [ 'left' => '40px', 'right' => '40px' ] ] ], 'phone' => [ 'value' => [ 'padding' => [ 'left' => '14px', 'right' => '14px' ] ] ] ],
            ] ],
        ];
        $fieldInk = [ 'module' => [ 'decoration' => [ 'background' => $value( [ 'color' => '{{aied:color:bg}}' ] ), 'border' => $value( [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:muted}}' ] ] ] ) ] ] ];
        $markup   = (string) preg_replace_callback(
            '/(<!--\s+wp:divi\/contact-form\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static fn ( array $m ): string => $m[1] . RecipeTokens::encodeJson( array_replace_recursive( (array) json_decode( $m[2], true ), $ink ) ) . $m[3],
            $markup
        );
        return (string) preg_replace_callback(
            '/(<!--\s+wp:divi\/contact-field\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static fn ( array $m ): string => $m[1] . RecipeTokens::encodeJson( array_replace_recursive( (array) json_decode( $m[2], true ), $fieldInk ) ) . $m[3],
            $markup
        );
    }

    /** The band the planted recipe's form really sits on (by the shared walk, read from a marker that names nothing yet). */
    private static function realBand( string $raw ): string
    {
        $bands = RecipeLibraryTest::markerBands( self::markEvery( $raw, 'x' ), static fn ( array $a ): ?array => self::formMarker( $a ) );
        return (string) $bands['divi/contact-form#0'][1];
    }

    public function testAnUnmigratedRecipeWithAMarkerFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $kits = [ (array) StyleKits::get( 'soft-solid' ), (array) StyleKits::get( 'underline-link' ), (array) StyleKits::get( 'chunky-pill-offset' ) ];
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        foreach ( array_keys( self::MIGRATED_FORMS ) as $key ) {
            $name = explode( '|', $key )[0];
            $raw  = (string) SectionRecipes::rawMarkup( $name );
            $this->assertSame( 1, self::markerCount( $raw ), "$name is migrated (Task 4)" );
            $band = self::realBand( $raw );
            $this->assertSame( self::MIGRATED_FORMS[ $key ], $band, "$name: the walk agrees with the pinned band" );

            // The unmigrated shape: the old ink put back on the form and its fields, then the marker (re-)added.
            $planted = self::markEvery( self::withOldInk( $raw ), $band );
            $count   = self::markerCount( $planted );
            $this->assertSame( 1, $count );
            // (a) own ink: the field fill, frame, radius, placeholder, captcha and submit are all reported; so is ink on a field child.
            $seen = 0;
            $own  = implode( "\n", self::ownInkProblems( $name, $planted, $seen ) );
            $this->assertSame( $count, $seen );
            foreach ( [
                'its own field.decoration.background.desktop.value.color',
                'its own field.decoration.border.desktop.value.radius.topLeft',
                'its own field.decoration.font.font.desktop.value.color',
                'its own field.decoration.placeholderFont.font.desktop.value.color',
                'its own captcha.decoration.font.font.desktop.value.color',
                'its own button.decoration.background.desktop.value.color',
                'its own button.decoration.border.desktop.value.styles.all.color',
                'its own button.decoration.font.font.desktop.hover.color',
                'its own button.decoration.font.font.desktop.value.weight',
                'its own button.decoration.spacing.desktop.value.padding.left',
            ] as $needle ) {
                $this->assertStringContainsString( 'the migrated block must drop ' . $needle, $own, "$name $needle" );
            }
            $this->assertStringContainsString( 'a field under a marked form carries module.decoration.background.desktop.value.color', $own, "$name field child fill" );
            $this->assertStringContainsString( 'a field under a marked form carries module.decoration.border.desktop.value.styles.all.color', $own, "$name field child frame" );
            // (c) the band matches; a wrong band is caught.
            $this->assertSame( [], self::bandProblems( $name, $planted ) );
            foreach ( self::BANDS as $wrong ) {
                if ( $wrong !== $band ) {
                    $this->assertStringContainsString( "form marker on=$wrong, but the nearest ground makes it $band", implode( "\n", self::bandProblems( $name, self::markEvery( $raw, $wrong ) ) ) );
                }
            }
            // (d) the merge trap: ink at a breakpoint the kit does not write (the old phone padding, a tablet fill) survives the merge under every kit; both are reported.
            foreach ( $kits as $kit ) {
                $trap = implode( "\n", self::mergeTrapProblems( $name, $planted, KitResolver::resolve( $planted, $kit ), $kit, null ) );
                $this->assertStringContainsString( 'keeps button.decoration.spacing.phone.value.padding.left that the kit does not write', $trap, "$name {$kit['id']}" );
                $this->assertStringContainsString( 'keeps field.decoration.background.tablet.value.color that the kit does not write', $trap, "$name {$kit['id']}" );
            }

            // The clean shape: the same recipe with its own ink removed passes all four checks, kit-only and in a language.
            $clean = self::migrate( $planted );
            $this->assertSame( $count, self::markerCount( $clean ) );
            $this->assertSame( [], self::ownInkProblems( $name, $clean ) );
            $this->assertSame( [], self::bandProblems( $name, $clean ) );
            foreach ( $kits as $kit ) {
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, KitResolver::resolve( $clean, $kit ), $kit, null ), "$name {$kit['id']} clean" );
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, RecipeTokens::resolve( KitResolver::resolve( $clean, $kit ), $lang ), $kit, $lang ), "$name {$kit['id']} clean in luxe" );
            }
            // The clean shape kept its label, its field type scale and its form frame (only ink was dropped).
            $form = array_values( array_filter( self::blocks( $clean ), static fn ( array $b ): bool => 'divi/contact-form' === $b[0] ) )[0][1];
            $this->assertArrayHasKey( 'builderVersion', $form, "$name keeps its version" );
            $this->assertNotEmpty( $form['button']['innerContent']['desktop']['value']['text'] ?? null, "$name keeps its submit label" );
            $this->assertSame( '{{aied:font:body}}', $form['field']['decoration']['font']['font']['desktop']['value']['family'] ?? null, "$name keeps its field family" );
            $this->assertSame( '{{aied:font:body}}', $form['captcha']['decoration']['font']['font']['desktop']['value']['family'] ?? null, "$name keeps its captcha family" );
            $this->assertNotEmpty( $form['module']['advanced']['uniqueId']['desktop']['value'] ?? null, "$name keeps its unique id" );
        }
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $kit  = (array) StyleKits::get( 'soft-solid' );
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $form = static fn ( array $attrs ): string => '<!-- wp:divi/contact-form ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
        $run  = static fn ( string $raw, ?array $k = null ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $k ?? $kit ), $k ?? $kit, null ) );
        $mark = [ 'kit' => 'form', 'on' => 'light' ];

        // Clean: the label, switch, type scale, version and the form's own frame, radius and width.
        $clean = [
            'button'         => [
                'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Send' ] ] ],
                'decoration'   => [
                    'button' => [ 'desktop' => [ 'value' => [ 'enable' => 'on', 'icon' => [ 'enable' => 'off' ] ] ] ],
                    'font'   => [ 'font' => [ 'desktop' => [ 'value' => [ 'family' => '{{aied:font:body}}' ] ], 'phone' => [ 'value' => [ 'size' => '15px' ] ] ] ],
                ],
            ],
            'field'          => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'family' => '{{aied:font:body}}', 'size' => '16px' ] ] ] ] ] ],
            'captcha'        => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'family' => '{{aied:font:body}}' ] ] ] ] ] ],
            'module'         => [ 'advanced' => [ 'uniqueId' => [ 'desktop' => [ 'value' => 'abc' ] ] ], 'decoration' => [
                'sizing' => [ 'desktop' => [ 'value' => [ 'maxWidth' => '950px', 'alignment' => 'center' ] ] ],
                'border' => [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => '20px' ], 'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}' ] ] ] ] ],
            ] ],
            'builderVersion' => '5.8.0',
        ];
        $this->assertSame( '', $run( $sec( 'bg', $form( $clean + [ 'aied' => $mark ] ) ) ) );

        // Ink at any breakpoint or state, or a path the kit does not write: each is a stray the deep merge would keep.
        $strays = [
            'field.decoration.background.tablet.value.color'                 => [ 'field' => [ 'decoration' => [ 'background' => [ 'tablet' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ],
            'field.decoration.placeholderFont.font.phone.value.color'        => [ 'field' => [ 'decoration' => [ 'placeholderFont' => [ 'font' => [ 'phone' => [ 'value' => [ 'color' => '{{aied:color:muted}}' ] ] ] ] ] ] ],
            'field.decoration.border.tablet.value.styles.all.color'          => [ 'field' => [ 'decoration' => [ 'border' => [ 'tablet' => [ 'value' => [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:muted}}' ] ] ] ] ] ] ] ],
            'field.decoration.font.font.desktop.value.letterSpacing'         => [ 'field' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'letterSpacing' => '1px' ] ] ] ] ] ] ],
            'captcha.decoration.font.font.phone.value.color'                 => [ 'captcha' => [ 'decoration' => [ 'font' => [ 'font' => [ 'phone' => [ 'value' => [ 'color' => '{{aied:color:inverse}}' ] ] ] ] ] ] ],
            'button.decoration.font.font.tablet.value.color'                => [ 'button' => [ 'decoration' => [ 'font' => [ 'font' => [ 'tablet' => [ 'value' => [ 'color' => '{{aied:color:bg}}' ] ] ] ] ] ] ],
            'button.decoration.font.font.phone.value.weight'                 => [ 'button' => [ 'decoration' => [ 'font' => [ 'font' => [ 'phone' => [ 'value' => [ 'weight' => '700' ] ] ] ] ] ] ],
            'button.decoration.spacing.phone.value.padding.left'             => [ 'button' => [ 'decoration' => [ 'spacing' => [ 'phone' => [ 'value' => [ 'padding' => [ 'left' => '14px' ] ] ] ] ] ] ],
            'button.decoration.border.tablet.value.styles.all.color'        => [ 'button' => [ 'decoration' => [ 'border' => [ 'tablet' => [ 'value' => [ 'styles' => [ 'all' => [ 'color' => '{{aied:color:text}}' ] ] ] ] ] ] ] ],
            'button.decoration.boxShadow.tablet.value.blur'                  => [ 'button' => [ 'decoration' => [ 'boxShadow' => [ 'tablet' => [ 'value' => [ 'blur' => '40px' ] ] ] ] ] ],
            'module.decoration.boxShadow.desktop.value.blur'                 => [ 'module' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'blur' => '40px' ] ] ] ] ] ],
        ];
        foreach ( $strays as $path => $attrs ) {
            $got = $run( $sec( 'bg', $form( array_replace_recursive( $clean, $attrs ) + [ 'aied' => $mark ] ) ) );
            $this->assertStringContainsString( "keeps $path that the kit does not write", $got, $path );
        }
        // Ink at a path the kit writes is overwritten by the patch (the merge cannot hide it: the own-ink check catches it in the raw markup).
        // A resolved block that lost the merge and a block that was never resolved.
        $raw      = $sec( 'bg', $form( $clean + [ 'aied' => $mark ] ) );
        $resolved = str_replace( '"captcha":{', '"captcha":{"x":1,', KitResolver::resolve( $raw, $kit ) );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $kit ), $kit, null ) );
        $this->assertStringContainsString( 'keeps captcha.x', implode( "\n", self::mergeTrapProblems( 'p', $raw, $resolved, $kit, null ) ) );
        $changed = preg_replace( '/"captcha":\{"decoration":\{"font":\{"font":\{"desktop":\{"value":\{"family":"[^"]*","color":"[^"]*"/', '"captcha":{"decoration":{"font":{"font":{"desktop":{"value":{"family":"x","color":"#123456"', KitResolver::resolve( $raw, $kit ) );
        $this->assertStringContainsString( 'captcha.decoration.font.font.desktop.value.color is "#123456", not the kit\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) $changed, $kit, null ) ) );
        $this->assertStringContainsString( 'not resolved', implode( "\n", self::mergeTrapProblems( 'p', $raw, $raw, $kit, null ) ) );
        // A language pass compares against the patch resolved in that language.
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, $lang ) );
        $this->assertNotSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, (array) DesignLanguages::get( 'luxe' ) ), 'another language\'s colours are not the kit\'s' );
    }

    public function testAFieldUnderAMarkedFormMayCarryNoInk(): void
    {
        $form  = static fn ( string $attrs, string $fields ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}}} --><!-- wp:divi/contact-form ' . $attrs . ' -->' . $fields . '<!-- /wp:divi/contact-form --><!-- /wp:divi/section -->';
        $plain = '<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Name"}}},"innerContent":{"desktop":{"value":"Name"}}},"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"12_24"}}}}}} /-->';
        $this->assertSame( [], self::ownInkProblems( 'p', $form( '{"aied":{"kit":"form","on":"light"}}', $plain ) ) );
        foreach ( [
            'module.decoration.border.desktop.value.styles.all.color'  => '"module":{"decoration":{"border":{"desktop":{"value":{"styles":{"all":{"color":"#000"}}}}}}}',
            'module.decoration.background.desktop.value.color'         => '"module":{"decoration":{"background":{"desktop":{"value":{"color":"#fff"}}}}}',
            'module.decoration.boxShadow.desktop.value.blur'          => '"module":{"decoration":{"boxShadow":{"desktop":{"value":{"blur":"8px"}}}}}',
            'fieldItem.decoration.font.font.desktop.value.color'      => '"fieldItem":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#000"}}}}}}',
        ] as $path => $json ) {
            $field = '<!-- wp:divi/contact-field {' . $json . '} /-->';
            $this->assertSame(
                [ "p divi/contact-field#1: a field under a marked form carries $path (a field-level value beats the form's)" ],
                self::ownInkProblems( 'p', $form( '{"aied":{"kit":"form","on":"light"}}', $plain . $field ) ),
                $path
            );
            // Under an UNMARKED form a field may keep its own look (nothing styles it from the parent).
            $this->assertSame( [], self::ownInkProblems( 'p', $form( '{}', $field ) ), "$path unmarked" );
        }
    }

    public function testTheFormBandWalkCatchesWhatItShould(): void
    {
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $form = static fn ( string $on, string $extra = '' ): string => '<!-- wp:divi/contact-form {' . $extra . '"aied":{"on":"' . $on . '","kit":"form"}} --><!-- /wp:divi/contact-form -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $form( 'light' ) ) ), 'key order does not matter' );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $form( 'surface' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'accent', $form( 'accent' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'overlay', $form( 'dark' ) ) ) );
        $this->assertSame( [ 'p divi/contact-form#0: form marker on=light, but the nearest ground makes it surface' ], self::bandProblems( 'p', $sec( 'surface', $form( 'light' ) ) ) );
        $this->assertSame( [ 'p divi/contact-form#0: form marker on=dark, but the nearest ground makes it accent' ], self::bandProblems( 'p', $sec( 'accent', $form( 'dark' ) ) ) );
        // A photo row is a dark band (the contact-form recipe); a gradient with an overlay stop too.
        $photo = '<!-- wp:divi/row {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"{{aied:image:x}}"}}}}}}} -->' . $form( 'dark' ) . '<!-- /wp:divi/row -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $photo ) ) );
        $this->assertCount( 1, self::bandProblems( 'p', $sec( 'bg', str_replace( '"on":"dark"', '"on":"light"', $photo ) ) ) );
        // A form inside a marked panel sits on the panel's fill: surface on a light band, bg (light) on a surface band.
        $panel = static fn ( string $on, string $inner ): string => '<!-- wp:divi/group {"aied":{"kit":"panel","on":"' . $on . '"}} -->' . $inner . '<!-- /wp:divi/group -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $panel( 'light', $form( 'surface' ) ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $panel( 'surface', $form( 'light' ) ) ) ) );
        $this->assertCount( 1, self::bandProblems( 'p', $sec( 'bg', $panel( 'light', $form( 'light' ) ) ) ) );
        // No ground anywhere fails loudly (never defaults to light).
        $this->assertSame( [ 'p divi/contact-form#0: form marker on=light, but the nearest ground makes it none', 'p divi/contact-form#0: form on a none band (it sits on light|surface|accent|dark only)' ], self::bandProblems( 'p', $form( 'light' ) ) );
        // A form with a background of its own is caught: the walk looks at ancestors only.
        $own = '"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}},';
        $this->assertSame( [ 'p divi/contact-form#0: a marked form with a background of its own (the band walk would not see it)' ], self::bandProblems( 'p', $sec( 'bg', $form( 'light', $own ) ) ) );
        // A marker without a band throws; a card, item or button marker is no form marker.
        $this->assertNull( self::formMarker( [ 'aied' => [ 'kit' => 'card', 'on' => 'light' ] ] ) );
        $this->assertNull( self::formMarker( [ 'aied' => [ 'kit' => 'item', 'on' => 'light' ] ] ) );
        $this->assertNull( self::formMarker( [ 'aied' => [ 'kit' => 'control' ] ] ) );
        $this->assertSame( 1, self::markerCount( $sec( 'bg', $form( 'light' ) . '<!-- wp:divi/group {"aied":{"kit":"card","on":"light"}} --><!-- /wp:divi/group -->' ) ) );
        foreach ( [ [ 'kit' => 'form' ], [ 'kit' => 'form', 'on' => '' ], [ 'kit' => 'form', 'on' => 1 ] ] as $marker ) {
            try {
                self::formMarker( [ 'aied' => $marker ] );
                $this->fail( 'a form marker without a band must fail: ' . json_encode( $marker ) );
            } catch ( \UnexpectedValueException $e ) {
                $this->assertStringContainsString( 'without "on"', $e->getMessage() );
            }
        }
    }
}
