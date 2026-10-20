<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\DeadOnModule;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/DeadOnModule.php';

/**
 * The form adapter: a kit's decisions become the attributes of a `divi/contact-form` (one marker styles every field, the
 * submit button and the captcha), role `form`, on four bands. Only the paths the effect probe passed ON the contact form
 * are written (fixtures/shapes/kit-form-vocabulary.md, results fixtures/shapes/probe-results-kit-form.json); the five
 * conditional decisions (KitAdapters::FORM_PROVEN) are pinned to that file's "Adapter decisions" table; the submit button is
 * the kit's primary `divi/button` control on the band, moved to the form's own `button.decoration.*` paths; and the form
 * container (`module.decoration`) is never touched.
 */
class KitFormAdapterTest extends TestCase
{
    private const BANDS = [ 'light', 'surface', 'accent', 'dark' ];

    private const COLOR_ROLES = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent-text', 'border', 'inverse', 'overlay' ];

    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/kit-form-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/kit-form.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-kit-form.json';

    /** Decision => the leaves it alone writes (the vocabulary's paths). */
    private const DECISION_LEAVES = [
        'button-padding' => [ 'button.decoration.spacing.desktop.value.padding' ],
        'button-shadow'  => [ 'button.decoration.boxShadow.' ],
        'button-lift'    => [ 'button.decoration.transform.' ],
        'field-padding'  => [ 'field.decoration.spacing.desktop.value.padding' ],
    ];

    /** @return array<string,array<string,mixed>> every shipped kit by id */
    private function shippedKits(): array
    {
        $kits = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            $this->assertSame( [], StyleKits::problems( $kit ), "shipped kit $id must be valid" );
            $kits[ $id ] = $kit;
        }
        $this->assertCount( 40, $kits );
        return $kits;
    }

    /**
     * Every shipped kit plus variants of a full-frame and an underline kit over every hover x border colour x shadow pair x
     * width the schema allows, so every branch of the form adapter runs.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allKitVariants(): array
    {
        $out = $this->shippedKits();
        foreach ( [ 'soft-solid', 'underline-link' ] as $base ) {
            $kit = $out[ $base ];
            foreach ( StyleKits::HOVERS as $hover ) {
                foreach ( StyleKits::BORDER_COLORS as $borderColor ) {
                    foreach ( StyleKits::SHADOW_PAIRS as $pair ) {
                        foreach ( [ '0px', '2px' ] as $width ) {
                            [ $rest, $hoverShadow ] = explode( '/', $pair );
                            $v                    = $kit;
                            $v['hover']           = $hover;
                            $v['border']['color'] = $borderColor;
                            $v['border']['width'] = $width;
                            $v['shadow']          = [ 'rest' => $rest, 'hover' => $hoverShadow ];
                            if ( [] === StyleKits::problems( $v ) ) {
                                $out[ "$base/$hover/$borderColor/$pair/$width" ] = $v;
                            }
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $kit @return array<string,mixed> */
    private function patch( array $kit, string $on ): array
    {
        $patch = KitAdapters::patch( 'divi/contact-form', 'form', $on, $kit );
        $this->assertIsArray( $patch, "{$kit['id']} form on $on must have a patch" );
        return $patch;
    }

    /** @param array<string,mixed> $kit @param array<string,bool> $proven @return array<string,mixed> */
    private function provenPatch( array $kit, string $on, array $proven ): array
    {
        return KitAdapters::formPatch( $on, $kit, $proven );
    }

    /** Dotted path => value of every leaf of $node. @return array<string,mixed> */
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

    /** A leaf map with the whole-value kit tokens replaced by the four corners / sides they resolve to for $kit. @param array<string,mixed> $leaves @param array<string,mixed> $kit @return array<string,mixed> */
    private static function expandTokens( array $leaves, array $kit ): array
    {
        $out = [];
        foreach ( $leaves as $path => $value ) {
            if ( is_string( $value ) && preg_match( '/^\{\{aied:kit:([a-z-]+)\}\}$/', $value, $m ) ) {
                $object = StyleKits::valueToken( $kit, $m[1] );
                if ( is_array( $object ) ) {
                    foreach ( $object as $k => $v ) {
                        $out[ $path . '.' . $k ] = $v;
                    }
                    continue;
                }
            }
            $out[ $path ] = $value;
        }
        return $out;
    }

    private function formMarkup( string $on ): string
    {
        $marker = '"aied":{"kit":"form","on":"' . $on . '"}';
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/contact-form {"button":{"innerContent":{"desktop":{"value":{"text":"Send"}}},"decoration":{"button":{"desktop":{"value":{"enable":"on","icon":{"enable":"off"}}}}}},"builderVersion":"5.15.1",' . $marker . '} -->'
            . '<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Name"}}},"innerContent":{"desktop":{"value":"Name"}}},"builderVersion":"5.15.1"} /-->'
            . '<!-- wp:divi/contact-field {"fieldItem":{"advanced":{"id":{"desktop":{"value":"Email"}},"type":{"desktop":{"value":"email"}}},"innerContent":{"desktop":{"value":"Email"}}},"builderVersion":"5.15.1"} /-->'
            . '<!-- /wp:divi/contact-form -->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** @return array<string,bool> decision => proven, from the "Adapter decisions" table of the vocabulary file */
    private function vocabularyDecisions(): array
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        $at   = strpos( $text, '## Adapter decisions' );
        $this->assertNotFalse( $at, 'the vocabulary file has an Adapter decisions table' );
        preg_match_all( '/^\|\s*([a-z-]+)\s*\|\s*(PASS|DEAD)\s*\|/m', substr( $text, (int) $at ), $m, PREG_SET_ORDER );
        $out = [];
        foreach ( $m as $row ) {
            $out[ $row[1] ] = 'PASS' === $row[2];
        }
        return $out;
    }

    /**
     * Every path of a candidate that PASSED on the contact form (it changed the form's computed styles; not css-only): the
     * keys of its `set`, with the breakpoint kept and a numeric position never present. dotted path => true.
     *
     * @return array<string,true>
     */
    private function probedPaths(): array
    {
        $candidates = json_decode( (string) file_get_contents( self::CANDIDATES ), true );
        $results    = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $results[ $r['id'] ] = $r;
        }
        $this->assertIsArray( $candidates );
        $paths = [];
        foreach ( $candidates as $candidate ) {
            $r = $results[ $candidate['id'] ] ?? null;
            $this->assertNotNull( $r, $candidate['id'] . ' has a result' );
            if ( true !== ( $r['effect'] ?? false ) || true === ( $r['cssOnly'] ?? false ) ) {
                continue;
            }
            $this->assertSame( 'contact-form', $candidate['block'], 'every candidate was measured on the contact form' );
            foreach ( array_keys( (array) ( $candidate['set'] ?? [] ) ) as $key ) {
                $paths[ (string) $key ] = true;
            }
        }
        return $paths;
    }

    public function testEveryEmittedFormPathIsInTheRegistryAndNotDeadOnTheForm(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $missing  = [];
        $killed   = [];
        $checked  = 0;
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                $markup = KitResolver::resolve( $this->formMarkup( $on ), $kit );
                $this->assertSame( [], KitResolver::leftovers( $markup ), "$label form on $on" );
                $this->assertStringNotContainsString( '"aied":{', $markup );
                foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                    if ( ! $registry->coversLeaf( $path ) ) {
                        $missing[ $path ] = "$label form on $on";
                    }
                }
                foreach ( $dead->violations( $markup ) as $v ) {
                    $killed[ $v ] = $label;
                }
                $json = (string) json_encode( $this->patch( $kit, $on ) );
                foreach ( [ 'tablet', 'phone', 'tabletWide', 'phoneWide', 'sticky' ] as $state ) {
                    $this->assertStringNotContainsString( "\"$state\"", $json, "only desktop values are written ($label form on $on)" );
                }
                ++$checked;
            }
        }
        $this->assertGreaterThan( 40 * 4, $checked );
        $this->assertSame( [], $missing, 'the form adapter emits paths the registry does not know' );
        $this->assertSame( [], array_keys( $killed ), 'the form adapter emits paths that are dead on the form' );
    }

    public function testEveryLeafIsAProbedPathOnTheForm(): void
    {
        $probed = $this->probedPaths();
        $this->assertGreaterThan( 30, count( $probed ) );
        $unprobed = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                foreach ( array_keys( self::expandTokens( self::leaves( $this->patch( $kit, $on ) ), $kit ) ) as $path ) {
                    if ( ! isset( $probed[ $path ] ) ) {
                        $unprobed[ $path ] = "$label form on $on";
                    }
                }
            }
        }
        $this->assertSame( [], $unprobed, 'every leaf the form adapter writes was measured to PASS on divi/contact-form (a divi/button result proves nothing here)' );
    }

    public function testFormProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame( [ 'button-padding', 'button-shadow', 'button-lift', 'field-underline', 'field-padding' ], array_keys( $table ), 'five decisions, in the table\'s order' );
        $this->assertSame( $table, KitAdapters::FORM_PROVEN, 'KitAdapters::FORM_PROVEN is exactly the Adapter decisions table of kit-form-vocabulary.md' );
        $this->assertSame( [ 'button-padding' => true, 'button-shadow' => true, 'button-lift' => true, 'field-underline' => true, 'field-padding' => true ], KitAdapters::FORM_PROVEN, 'all five passed on the real Divi 5.15.1' );
    }

    public function testUnprovenDecisionsWriteNothing(): void
    {
        $all = KitAdapters::FORM_PROVEN;
        foreach ( [ 'chunky-pill-offset', 'heavy-block', 'soft-lift', 'underline-link', 'glass-edge' ] as $id ) {
            $kit = $this->shippedKits()[ $id ] ?? null;
            if ( null === $kit ) {
                continue;
            }
            foreach ( self::BANDS as $on ) {
                $with = self::leaves( $this->provenPatch( $kit, $on, $all ) );
                foreach ( self::DECISION_LEAVES as $decision => $prefixes ) {
                    $without = self::leaves( $this->provenPatch( $kit, $on, array_merge( $all, [ $decision => false ] ) ) );
                    foreach ( array_keys( $without ) as $path ) {
                        foreach ( $prefixes as $prefix ) {
                            $this->assertStringStartsNotWith( $prefix, $path, "$id on $on: $decision is false, so $path must not be written" );
                        }
                    }
                    // Only that decision's leaves went away.
                    foreach ( array_keys( $with ) as $path ) {
                        $isDecisionLeaf = false;
                        foreach ( $prefixes as $prefix ) {
                            $isDecisionLeaf = $isDecisionLeaf || str_starts_with( $path, $prefix );
                        }
                        if ( ! $isDecisionLeaf ) {
                            $this->assertArrayHasKey( $path, $without, "$id on $on: turning $decision off must not drop $path" );
                        }
                    }
                }
            }
        }

        // What each decision writes when proven: the T1 path, with the kit's token or value.
        $kit = $this->shippedKits()['chunky-pill-offset'];
        $p   = self::leaves( $this->provenPatch( $kit, 'light', $all ) );
        $this->assertSame( '{{aied:kit:space-control}}', $p['button.decoration.spacing.desktop.value.padding'] ?? null, 'button-padding' );
        $this->assertSame( 'preset4', $p['button.decoration.boxShadow.desktop.value.style'] ?? null, 'button-shadow' );
        $this->assertArrayHasKey( 'field.decoration.spacing.desktop.value.padding', $p, 'field-padding' );
        $lift = $this->shippedKits()['soft-lift'];
        $this->assertSame( 'lift', $lift['hover'] );
        $this->assertSame( '-4px', self::leaves( $this->provenPatch( $lift, 'light', $all ) )['button.decoration.transform.desktop.hover.translate.y'] ?? null, 'button-lift' );
    }

    public function testTheUnderlineDecisionDecidesTheFieldFrame(): void
    {
        $underline = $this->shippedKits()['underline-link'];
        $this->assertSame( 'bottom', $underline['border']['sides'] ?? 'all', 'a kit with sides bottom' );
        $on  = self::leaves( $this->provenPatch( $underline, 'light', array_merge( KitAdapters::FORM_PROVEN, [ 'field-underline' => true ] ) ) );
        $off = self::leaves( $this->provenPatch( $underline, 'light', array_merge( KitAdapters::FORM_PROVEN, [ 'field-underline' => false ] ) ) );
        $w   = '0px' === $underline['border']['width'] ? '1px' : $underline['border']['width'];
        $this->assertSame( '0px', $on['field.decoration.border.desktop.value.styles.all.width'], 'proven: the all-sides frame is zero' );
        $this->assertSame( $w, $on['field.decoration.border.desktop.value.styles.bottom.width'], 'proven: the bottom edge at the kit width' );
        $this->assertSame( $on['field.decoration.border.desktop.value.styles.all.color'], $on['field.decoration.border.desktop.value.styles.bottom.color'] );
        $this->assertSame( '1px', $off['field.decoration.border.desktop.value.styles.all.width'], 'not proven: the full frame at 1px (Ruling 2)' );
        $this->assertArrayNotHasKey( 'field.decoration.border.desktop.value.styles.bottom.width', $off );
        $this->assertSame( $on['field.decoration.border.desktop.value.styles.all.style'], $off['field.decoration.border.desktop.value.styles.all.style'] );
    }

    public function testTheSubmitButtonIsTheKitControlOnTheBand(): void
    {
        $all = KitAdapters::FORM_PROVEN;
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                // The button has no `surface` band: a surface band is a light band for it.
                $button = KitAdapters::patch( 'divi/button', 'control', 'surface' === $on ? 'light' : $on, $kit );
                $this->assertIsArray( $button );
                $expected = $button['button']['decoration'];
                $module   = $button['module']['decoration'];
                if ( 'bottom' === ( $kit['border']['sides'] ?? 'all' ) ) {
                    // A bottom-only edge was probed on a field but never on the submit button of a form: the submit takes the
                    // same edge as a full frame, in the same width, style and colour (the divi/button treatment, relocated).
                    $expected['border']['desktop']['value']['styles'] = [ 'all' => $expected['border']['desktop']['value']['styles']['bottom'] ];
                }
                foreach ( [ 'spacing', 'boxShadow', 'transform' ] as $moved ) {
                    if ( isset( $module[ $moved ] ) ) {
                        $expected[ $moved ] = $module[ $moved ];
                    }
                }
                $form = $this->patch( $kit, $on );
                $this->assertEquals( $expected, $form['button']['decoration'], "$label form on $on: the submit is the kit's control on the band, relocated to the form's own paths" );
                $this->assertSame( [ 'decoration' ], array_keys( $form['button'] ), 'the submit label and icon are the recipe\'s' );
                // The relocated shadow, padding and lift never remain on module.decoration.
                $this->assertArrayNotHasKey( 'module', $form );
                // And the same with every decision off: only the relocated three vanish, the treatment stays.
                $off = $this->provenPatch( $kit, $on, array_merge( $all, [ 'button-padding' => false, 'button-shadow' => false, 'button-lift' => false ] ) );
                $alone = $button['button']['decoration'];
                if ( 'bottom' === ( $kit['border']['sides'] ?? 'all' ) ) {
                    $alone['border']['desktop']['value']['styles'] = [ 'all' => $alone['border']['desktop']['value']['styles']['bottom'] ];
                }
                $this->assertEquals( $alone, $off['button']['decoration'], "$label form on $on: the treatment alone" );
            }
        }
    }

    public function testFieldFillNeverMatchesTheContainer(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                $fill = self::leaves( $this->patch( $kit, $on ) )['field.decoration.background.desktop.value.color'] ?? null;
                $this->assertSame( 'surface' === $on ? '{{aied:color:bg}}' : '{{aied:color:surface}}', $fill, "$label form on $on" );
                $this->assertNotSame( '{{aied:color:' . ( 'surface' === $on ? 'surface' : ( 'light' === $on ? 'bg' : $on ) ) . '}}', $fill, 'a field is never printed in the colour of its container' );
            }
        }
    }

    public function testFieldFrameRules(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            $border = $kit['border'];
            $width  = '0px' === $border['width'] ? '1px' : $border['width'];
            $colour = '{{aied:color:' . ( in_array( $border['color'], [ 'text', 'accent', 'inverse' ], true ) ? 'text' : 'muted' ) . '}}';
            $under  = 'bottom' === ( $border['sides'] ?? 'all' );
            foreach ( self::BANDS as $on ) {
                $leaves = self::leaves( $this->patch( $kit, $on ) );
                $p      = 'field.decoration.border.desktop.value.styles.';
                if ( $under ) {
                    $this->assertSame( '0px', $leaves[ $p . 'all.width' ], "$label on $on" );
                    $this->assertSame( $width, $leaves[ $p . 'bottom.width' ], "$label on $on: floor" );
                    $this->assertSame( $border['style'], $leaves[ $p . 'bottom.style' ] );
                    $this->assertSame( $colour, $leaves[ $p . 'bottom.color' ] );
                    $this->assertSame( $colour, $leaves[ $p . 'all.color' ] );
                } else {
                    $this->assertSame( $width, $leaves[ $p . 'all.width' ], "$label on $on: the kit width with a 1px floor" );
                    $this->assertSame( $border['style'], $leaves[ $p . 'all.style' ] );
                    $this->assertSame( $colour, $leaves[ $p . 'all.color' ], "$label on $on: text for text/accent/inverse, else muted (Ruling 1)" );
                    $this->assertArrayNotHasKey( $p . 'bottom.width', $leaves );
                }
                $this->assertSame( '{{aied:kit:radius-control}}', $leaves['field.decoration.border.desktop.value.radius'], 'a field is a control: the control radius' );
                $this->assertSame( '{{aied:kit:space-control}}', $leaves['field.decoration.spacing.desktop.value.padding'], 'the control padding, proven on the field' );
                $this->assertSame( '{{aied:color:text}}', $leaves['field.decoration.font.font.desktop.value.color'] );
                $this->assertArrayNotHasKey( 'field.decoration.font.font.desktop.value.family', $leaves, 'the family is the recipe\'s' );
                foreach ( array_keys( $leaves ) as $path ) {
                    $this->assertDoesNotMatchRegularExpression( '/^field\..*(boxShadow|hover|focus)/', $path, 'no field shadow, hover or focus state (Ruling 3)' );
                }
            }
        }
    }

    public function testPlaceholderAndCaptchaInkAreWrittenOnEveryBand(): void
    {
        $captcha = [ 'light' => 'text', 'surface' => 'text', 'accent' => 'accent-text', 'dark' => 'inverse' ];
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::BANDS as $on ) {
                $leaves = self::leaves( $this->patch( $kit, $on ) );
                $this->assertSame( '{{aied:color:muted}}', $leaves['field.decoration.placeholderFont.font.desktop.value.color'] ?? null, "$id on $on: the field colour does not reach the placeholder" );
                $this->assertSame( '{{aied:color:' . $captcha[ $on ] . '}}', $leaves['captcha.decoration.font.font.desktop.value.color'] ?? null, "$id on $on: captcha ink" );
                $this->assertArrayNotHasKey( 'captcha.decoration.font.font.desktop.value.family', $leaves );
            }
        }
    }

    public function testNoModuleDecorationIsWritten(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                $patch = $this->patch( $kit, $on );
                $this->assertSame( [ 'button', 'captcha', 'field' ], ( static function ( array $keys ): array {
                    sort( $keys );
                    return $keys;
                } )( array_keys( $patch ) ), "$label on $on: field, button and captcha only" );
                $this->assertArrayNotHasKey( 'module', $patch, 'on a contact form module.decoration is the whole form container: its frame, radius, width and alignment stay the recipe\'s' );
                $this->assertStringNotContainsString( 'module.decoration', implode( "\n", array_keys( self::leaves( $patch ) ) ) );
                $this->assertArrayNotHasKey( 'innerContent', $patch['button'], 'the submit label is the recipe\'s' );
            }
        }
    }

    public function testNoHexAndNoUnresolvedTokensBeyondColourAndKit(): void
    {
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                $json = (string) json_encode( $this->patch( $kit, $on ), JSON_UNESCAPED_SLASHES );
                $this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $json, "$label form on $on" );
                preg_match_all( '/\{\{[^}]*\}\}/', $json, $t );
                foreach ( $t[0] as $token ) {
                    $this->assertMatchesRegularExpression(
                        '/^\{\{aied:(?:color:(?:' . implode( '|', self::COLOR_ROLES ) . ')(?::(?:18|25|45))?|kit:(?:radius-control|space-control))\}\}$/',
                        $token,
                        "$label form on $on"
                    );
                }
                // The only literal colour is the transparent default of an unfilled submit.
                preg_match_all( '/rgba?\([^)]*\)/i', $json, $lits );
                foreach ( $lits[0] as $literal ) {
                    $this->assertSame( KitAdapters::TRANSPARENT, $literal, "$label form on $on" );
                }
            }
        }
    }

    public function testPatchValidatesInsideARealContactForm(): void
    {
        $checked = 0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::BANDS as $on ) {
                $markup = KitResolver::resolve( $this->formMarkup( $on ), $kit );
                $this->assertSame( [], KitResolver::leftovers( $markup ), "$id form on $on" );
                foreach ( [ null, 'brutalist-flat' ] as $langId ) {
                    $lang = null === $langId ? RecipeTokens::default() : DesignLanguages::get( $langId );
                    $this->assertIsArray( $lang );
                    $resolved = RecipeTokens::resolve( $markup, $lang );
                    $this->assertSame( [], RecipeTokens::found( $resolved ), "$id form on $on" );
                    $result = LayoutGate::check( $resolved );
                    $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                    $this->assertTrue( $result->isValid(), "$id form on $on must pass the gate, got: $codes" );
                    ++$checked;
                }
            }
        }
        $this->assertSame( 40 * 4 * 2, $checked );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $kit = $this->shippedKits()['soft-solid'];
        foreach ( [ 'panel', 'photo', 'dark ', '', 'LIGHT' ] as $on ) {
            $this->assertNull( KitAdapters::patch( 'divi/contact-form', 'form', $on, $kit ), "a form on '$on'" );
        }
        foreach ( [ 'inverted', 'accent' ] as $ground ) {
            $this->assertNull( KitAdapters::patch( 'divi/contact-form', 'form', 'light', $kit, $ground ), "a form takes no ground ($ground)" );
        }
        foreach ( [ 'card', 'panel', 'control', 'control-secondary', 'item', 'item-rule', 'forms', '' ] as $role ) {
            $this->assertNull( KitAdapters::patch( 'divi/contact-form', $role, 'light', $kit ), "$role on a contact form" );
        }
        foreach ( [ 'divi/contact-field', 'divi/group', 'divi/column', 'divi/button', 'divi/accordion', 'divi/toggle', 'divi/signup', 'divi/blurb' ] as $block ) {
            $this->assertNull( KitAdapters::patch( $block, 'form', 'light', $kit ), "form on $block" );
        }
        $this->assertNull( KitAdapters::patch( 'divi/contact-form', 'form', 'light', [ 'id' => 'broken' ] ), 'an invalid kit' );
        $this->assertNull( KitAdapters::patch( 'divi/contact-form', 'form', 'light', array_merge( $kit, [ 'shadow' => 'x' ] ) ), 'an invalid kit' );
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( self::BANDS as $on ) {
                $this->assertSame( json_encode( $this->patch( $kit, $on ) ), json_encode( $this->patch( $kit, $on ) ), "$id form on $on" );
            }
        }
    }

    public function testAppliedNamesTheForm(): void
    {
        $this->assertSame( [ 'form' ], KitAdapters::APPLIED['divi/contact-form'] ?? null );
        $this->assertArrayNotHasKey( 'divi/contact-field', KitAdapters::APPLIED, 'fields are styled through their form, never one by one' );
    }

    public function testTheFormCheckCatchesAPlantedFault(): void
    {
        // The probed-path check must fail when the patch carries a path the probe did not measure on the form.
        $probed = $this->probedPaths();
        $kit    = $this->shippedKits()['chunky-pill-offset'];
        $patch  = $this->patch( $kit, 'light' );
        $patch['module'] = [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'style' => 'preset4' ] ] ] ] ];
        $bad = array_filter( array_keys( self::expandTokens( self::leaves( $patch ), $kit ) ), static fn ( string $p ): bool => ! isset( $probed[ $p ] ) );
        $this->assertSame( [ 'module.decoration.boxShadow.desktop.value.style' ], array_values( $bad ), 'module.decoration.boxShadow is css-only on the form' );
    }

    public function testTheGuideSentencesFollowTheProvenDecisions(): void
    {
        $yes = KitAdapters::FORM_PROVEN;
        $no  = array_map( static fn ( bool $v ): bool => false, $yes );
        $on  = StyleGuide::kitRules( null, $yes );
        $off = StyleGuide::kitRules( null, $no );
        $this->assertNotSame( $on, $off, 'the paragraph follows FORM_PROVEN' );
        $this->assertSame( $on, StyleGuide::kitRules(), 'the default is the shipped decisions' );
        // The Contact forms paragraph is the last section of kit-rules (the item paragraph above it also says "bottom rule").
        $on  = substr( $on, (int) strpos( $on, '## Contact forms' ) );
        $off = substr( $off, (int) strpos( $off, '## Contact forms' ) );
        $this->assertStringStartsWith( '## Contact forms', $on );
        $this->assertStringStartsWith( '## Contact forms', $off );
        $this->assertStringContainsString( 'bottom rule', $on );
        $this->assertStringNotContainsString( 'bottom rule', $off, 'an unproven underline is not claimed' );
        $this->assertStringContainsString( 'full 1px frame', $off );
    }
}
