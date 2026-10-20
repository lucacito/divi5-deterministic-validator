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
use Divi5Validator\Tests\Support\Colour;
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
require_once __DIR__ . '/Support/Colour.php';
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
        $this->assertSame( [ 'button-padding', 'button-shadow', 'button-lift', 'field-underline', 'field-padding', 'button-underline' ], array_keys( $table ), 'six decisions, in the table\'s order' );
        $this->assertSame( $table, KitAdapters::FORM_PROVEN, 'KitAdapters::FORM_PROVEN is exactly the Adapter decisions table of kit-form-vocabulary.md' );
        $this->assertSame( [ 'button-padding' => true, 'button-shadow' => true, 'button-lift' => true, 'field-underline' => true, 'field-padding' => true, 'button-underline' => true ], KitAdapters::FORM_PROVEN, 'all six passed on the real Divi 5.15.1' );
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
                    // button-underline is proven: the submit takes button()'s underline form, but WITHOUT the hover border colour
                    // (kf-button-underline-hover: it recolours only the 0px sides, so the bottom edge never changes on hover).
                    unset( $expected['border']['desktop']['hover'] );
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
                $off = $this->provenPatch( $kit, $on, array_merge( $all, [ 'button-padding' => false, 'button-shadow' => false, 'button-lift' => false, 'button-underline' => false ] ) );
                $alone = $button['button']['decoration'];
                if ( 'bottom' === ( $kit['border']['sides'] ?? 'all' ) ) {
                    // button-underline not proven: the same edge as a full frame (its hover colour, equal to the rest one, stays).
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
            $under  = 'bottom' === ( $border['sides'] ?? 'all' );
            foreach ( self::BANDS as $on ) {
                // Ruling 1, amended by the Task 3 contrast proof: always `text` on an accent band (muted there leaves neither edge at 3:1).
                $colour = '{{aied:color:' . ( 'accent' === $on || in_array( $border['color'], [ 'text', 'accent', 'inverse' ], true ) ? 'text' : 'muted' ) . '}}';
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
    public function testTheSubmitUnderlineIsWrittenBothWays(): void
    {
        $all = KitAdapters::FORM_PROVEN;
        $this->assertTrue( $all['button-underline'] );
        foreach ( [ 'underline-link', 'underlined-label' ] as $id ) {
            $kit = $this->shippedKits()[ $id ];
            $this->assertSame( 'bottom', $kit['border']['sides'] ?? 'all' );
            foreach ( self::BANDS as $on ) {
                $proven   = self::leaves( $this->provenPatch( $kit, $on, $all ) );
                $unproven = self::leaves( $this->provenPatch( $kit, $on, array_merge( $all, [ 'button-underline' => false ] ) ) );
                $p        = 'button.decoration.border.desktop.';
                $this->assertSame( '0px', $proven[ $p . 'value.styles.all.width' ], "$id on $on: proven, the all-sides frame is zero" );
                $this->assertSame( $kit['border']['width'], $proven[ $p . 'value.styles.bottom.width' ], "$id on $on: the bottom edge at the kit width" );
                $this->assertSame( $proven[ $p . 'value.styles.all.color' ], $proven[ $p . 'value.styles.bottom.color'] );
                $this->assertArrayNotHasKey( $p . 'hover.styles.all.color', $proven, "$id on $on: the underline edge does not change on hover, so no hover border colour is written" );
                $this->assertSame( $kit['border']['width'], $unproven[ $p . 'value.styles.all.width' ], "$id on $on: unproven, the full frame at the kit width" );
                $this->assertArrayNotHasKey( $p . 'value.styles.bottom.width', $unproven );
                $this->assertArrayHasKey( $p . 'hover.styles.all.color', $unproven, 'a full frame\'s hover colour is visible, so it stays' );
                // Fill and label hover still follow the kit.
                $this->assertArrayHasKey( 'button.decoration.background.desktop.hover.color', $proven );
                $this->assertArrayHasKey( 'button.decoration.font.font.desktop.hover.color', $proven );
            }
        }
        // A kit that is not an underline kit is untouched by the decision, and so is divi/button.
        $kit = $this->shippedKits()['soft-solid'];
        $this->assertSame(
            json_encode( $this->provenPatch( $kit, 'light', $all ) ),
            json_encode( $this->provenPatch( $kit, 'light', array_merge( $all, [ 'button-underline' => false ] ) ) )
        );
        $button = KitAdapters::patch( 'divi/button', 'control', 'light', $this->shippedKits()['underline-link'] );
        $this->assertArrayHasKey( 'hover', $button['button']['decoration']['border']['desktop'], 'divi/button keeps its hover border colour' );
        $this->assertArrayHasKey( 'bottom', $button['button']['decoration']['border']['desktop']['value']['styles'] );
    }

    /**
     * Every width the submit button's frame can be given, over every kit variant and band, was probed on the form: the
     * candidates cover `styles.all.width` 0px (kf-button-border-width-0px, kf-button-underline), 1px, 2px, 4px and
     * `styles.bottom.width` 3px (kf-button-underline); an unprobed value cannot slip in.
     */
    public function testEverySubmitFrameWidthWasProbedOnTheForm(): void
    {
        $probed = [];
        $results = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $results[ $r['id'] ] = $r;
        }
        foreach ( (array) json_decode( (string) file_get_contents( self::CANDIDATES ), true ) as $candidate ) {
            $r = $results[ $candidate['id'] ] ?? [];
            if ( true !== ( $r['effect'] ?? false ) || true === ( $r['cssOnly'] ?? false ) ) {
                continue;
            }
            foreach ( (array) ( $candidate['set'] ?? [] ) as $path => $value ) {
                if ( preg_match( '/^button\.decoration\.border\.desktop\.value\.styles\.(all|bottom)\.width$/', (string) $path ) ) {
                    $probed[ (string) $value ] = true;
                }
            }
        }
        $probed = array_keys( $probed );
        sort( $probed );
        $this->assertSame( [ '0px', '1px', '2px', '3px', '4px' ], $probed, 'what the candidates cover' );
        $seen = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                foreach ( [ KitAdapters::FORM_PROVEN, array_merge( KitAdapters::FORM_PROVEN, [ 'button-underline' => false ] ) ] as $proven ) {
                    foreach ( self::leaves( KitAdapters::formPatch( $on, $kit, $proven ) ) as $path => $value ) {
                        if ( preg_match( '/^button\.decoration\.border\.desktop\.value\.styles\.(all|bottom)\.width$/', $path ) ) {
                            $this->assertContains( $value, $probed, "$label on $on: $path = $value was not probed on the form" );
                            $seen[ $value ] = true;
                        }
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual( 4, count( $seen ), 'the kits exercise several widths' );
    }

    /** Expands `{a,b}` groups and `{bp}` (desktop) of a path pattern into every concrete dotted path. @return list<string> */
    private static function expandPattern( string $pattern ): array
    {
        $pattern = str_replace( '{bp}', 'desktop', $pattern );
        if ( ! preg_match( '/\{([^{}]+)\}/', $pattern, $m, PREG_OFFSET_CAPTURE ) ) {
            return [ $pattern ];
        }
        $out = [];
        foreach ( explode( ',', $m[1][0] ) as $alt ) {
            $out = array_merge( $out, self::expandPattern( substr( $pattern, 0, $m[0][1] ) . trim( $alt ) . substr( $pattern, $m[0][1] + strlen( $m[0][0] ) ) ) );
        }
        return $out;
    }

    public function testTheSubmitBorderIsWrittenOnlyThroughPathsTheVocabularyLists(): void
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        preg_match_all( '/`(button\.decoration\.border\.[^`\s]+)`/', $text, $m );
        $listed = [];
        foreach ( array_unique( $m[1] ) as $pattern ) {
            foreach ( self::expandPattern( $pattern ) as $path ) {
                $listed[ $path ] = true;
            }
        }
        foreach ( [ 'button.decoration.border.desktop.value.styles.all.color', 'button.decoration.border.desktop.hover.styles.all.color', 'button.decoration.border.desktop.value.styles.bottom.color' ] as $must ) {
            $this->assertArrayHasKey( $must, $listed, "the vocabulary lists $must" );
        }
        $colourPaths = [];
        foreach ( $this->allKitVariants() as $label => $kit ) {
            foreach ( self::BANDS as $on ) {
                foreach ( [ KitAdapters::FORM_PROVEN, array_merge( KitAdapters::FORM_PROVEN, [ 'button-underline' => false ] ) ] as $proven ) {
                    foreach ( array_keys( self::expandTokens( self::leaves( KitAdapters::formPatch( $on, $kit, $proven ) ), $kit ) ) as $path ) {
                        if ( str_starts_with( $path, 'button.decoration.border.' ) ) {
                            $this->assertArrayHasKey( $path, $listed, "$label on $on: $path is not a border path the vocabulary lists" );
                            if ( str_ends_with( $path, '.color' ) ) {
                                $colourPaths[ $path ] = true;
                            }
                        }
                    }
                }
            }
        }
        $colourPaths = array_keys( $colourPaths );
        sort( $colourPaths );
        $this->assertSame(
            [
                'button.decoration.border.desktop.hover.styles.all.color',
                'button.decoration.border.desktop.value.styles.all.color',
                'button.decoration.border.desktop.value.styles.bottom.color',
            ],
            $colourPaths,
            'the submit border colour is written at rest (all, bottom) and on hover (all) only'
        );
    }

    /**
     * The `dark` band (a form over a photo) is modelled exactly as the button proof models it: the language's `overlay`
     * composited over a mid grey. KitAdaptersTest::PHOTO_GREY is private, so its value is asserted equal here (see
     * testThePhotoModelIsTheButtonProofs).
     */
    private const PHOTO_GREY = '#808080';

    public function testThePhotoModelIsTheButtonProofs(): void
    {
        $this->assertSame( ( new \ReflectionClassConstant( KitAdaptersTest::class, 'PHOTO_GREY' ) )->getValue(), self::PHOTO_GREY );
    }

    /** A colour token or value resolved by the real pipeline (alpha forms included) as an opaque hex over $ground. @param array<string,mixed> $lang */
    private static function opaqueOn( string $value, array $lang, string $ground ): ?string
    {
        return Colour::opaque( RecipeTokens::resolve( $value, $lang ), $ground );
    }

    /** The opaque colour a form of band $on is printed on, in $lang. @param array<string,mixed> $lang */
    private static function containerOf( string $on, array $lang ): string
    {
        $c = (array) $lang['colors'];
        return match ( $on ) {
            'light'   => (string) Colour::opaque( (string) $c['bg'], '#ffffff' ),
            'surface' => (string) Colour::opaque( (string) $c['surface'], (string) $c['bg'] ),
            'accent'  => (string) Colour::opaque( (string) $c['accent'], (string) $c['bg'] ),
            default   => (string) Colour::opaque( (string) $c['overlay'], self::PHOTO_GREY ),
        };
    }

    /**
     * Contrast problems of one form patch on band $on in one language and state ([] = fine), keyed "$where|<check>":
     * - fill: the field fill is exactly `surface` (`bg` on a surface band) and, painted, is not the colour of the container;
     * - text / placeholder: the field text and the placeholder at least 4.5 on the field fill (the placeholder is its own
     *   colour: a missing one is 0:1);
     * - frame-<side>-fill: every drawn side (width not 0px) of the field frame at least 3.0 on the field fill (no exemption
     *   for the `border` hairline: a field's frame is its affordance);
     * - boundary-container: the better of the drawn frame sides and the field fill at least 3.0 on the container;
     * - submit-label: the submit label on its fill as painted over the container, at least 4.5, with this state's values;
     * - submit-edge: where the submit's edge carries its shape (a transparent fill and a drawn side), the edge colour at
     *   least 3.0 on the container; a hover state with no hover border colour (the underline form, Ruling 1) is judged with
     *   the REST edge colour;
     * - captcha: the captcha ink at least 4.5 on the container.
     * Fields are identical in both states (no field hover is written); the submit takes this state's values.
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $lang
     * @return array<string,string>
     */
    public static function formContrastProblems( array $patch, string $on, array $lang, string $state, string $where ): array
    {
        $leaves    = self::leaves( $patch );
        $container = self::containerOf( $on, $lang );
        $want      = '{{aied:color:' . ( 'surface' === $on ? 'bg' : 'surface' ) . '}}';
        $token     = (string) ( $leaves['field.decoration.background.desktop.value.color'] ?? '' );
        if ( $want !== $token ) {
            return [ "$where|fill" => "$where: the field fill is '$token', not $want" ];
        }
        $fill = self::opaqueOn( $token, $lang, $container );
        if ( null === $fill ) {
            return [ "$where|fill" => "$where: the field fill does not resolve" ];
        }
        $out = [];
        if ( strtolower( $fill ) === strtolower( $container ) ) {
            $out[ "$where|fill" ] = "$where: the field fill $fill is the colour of its container";
        }
        $judge = static function ( string $check, ?string $value, string $ground, float $need ) use ( &$out, $lang, $where ): void {
            $ink = null === $value ? null : self::opaqueOn( $value, $lang, $ground );
            $r   = null === $ink ? 0.0 : Colour::contrast( $ink, $ground );
            if ( $r < $need ) {
                $out[ "$where|$check" ] = sprintf( '%s: %s (%s) on %s is %.2f:1, needs %.1f', $where, $check, (string) $value, $ground, $r, $need );
            }
        };
        $leaf = static fn ( string $path ): ?string => isset( $leaves[ $path ] ) ? (string) $leaves[ $path ] : null;

        $judge( 'text', $leaf( 'field.decoration.font.font.desktop.value.color' ), $fill, 4.5 );
        $judge( 'placeholder', $leaf( 'field.decoration.placeholderFont.font.desktop.value.color' ), $fill, 4.5 );
        $judge( 'captcha', $leaf( 'captcha.decoration.font.font.desktop.value.color' ), $container, 4.5 );

        // The field's boundary. Against its own fill every drawn side of the frame needs 3:1 (no exemption for the `border`
        // hairline: a field's frame is its affordance). Against the container the boundary needs 3:1 from the better of the
        // frame and the fill: the outer edge of the field is drawn by whichever of the two is stronger (WCAG 1.4.11 asks for
        // the component's boundary, not for both). A strict reading (every drawn frame side AND the fill) is unsatisfiable:
        // a white field on a dark photo band needs a frame between the two, and no colour role sits there in a light language.
        $frame = 'field.decoration.border.desktop.value.styles.';
        $best  = Colour::contrast( $fill, $container );
        foreach ( [ 'all', 'bottom' ] as $side ) {
            $width = $leaf( $frame . $side . '.width' );
            if ( null === $width || '0px' === $width ) {
                continue;
            }
            $colour = $leaf( $frame . $side . '.color' );
            $judge( "frame-$side-fill", $colour, $fill, 3.0 );
            $edge = null === $colour ? null : self::opaqueOn( $colour, $lang, $container );
            $best = max( $best, null === $edge ? 0.0 : Colour::contrast( $edge, $container ) );
        }
        if ( $best < 3.0 ) {
            $out[ "$where|boundary-container" ] = sprintf( '%s: neither the frame nor the fill %s reaches 3.0 on the container %s (best %.2f:1)', $where, $fill, $container, $best );
        }

        // The submit, with this state's values.
        $key    = 'hover' === $state ? 'hover' : 'value';
        $b      = 'button.decoration.';
        $sFill  = $leaf( $b . 'background.desktop.' . $key . '.color' );
        $sLabel = $leaf( $b . 'font.font.desktop.' . $key . '.color' );
        $sFillR = null === $sFill ? null : RecipeTokens::resolve( $sFill, $lang );
        $painted = null === $sFillR ? null : Colour::opaque( $sFillR, $container );
        if ( null === $painted ) {
            return $out + [ "$where|submit-fill" => "$where: the submit fill does not resolve" ];
        }
        $judge( 'submit-label', $sLabel, $painted, 4.5 );
        $alpha = Colour::rgba( (string) $sFillR );
        $side  = isset( $leaves[ $b . 'border.desktop.value.styles.bottom.width' ] ) ? 'bottom' : 'all';
        $width = $leaf( $b . 'border.desktop.value.styles.' . $side . '.width' );
        if ( null !== $alpha && $alpha[3] < 1.0 && null !== $width && '0px' !== $width ) {
            $edge = 'hover' === $state ? ( $leaf( $b . 'border.desktop.hover.styles.all.color' ) ?? $leaf( $b . 'border.desktop.value.styles.' . $side . '.color' ) ) : $leaf( $b . 'border.desktop.value.styles.' . $side . '.color' );
            $judge( 'submit-edge', $edge, $container, 3.0 );
        }
        return $out;
    }

    public function testNoUnreadableFormInAnyKitLanguageBandState(): void
    {
        $problems = [];
        $checked  = 0;
        $minFill  = 99.0;
        foreach ( $this->shippedKits() as $id => $kit ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                foreach ( self::BANDS as $on ) {
                    $patch = $this->patch( $kit, $on );
                    foreach ( [ 'rest', 'hover' ] as $state ) {
                        ++$checked;
                        $problems = array_merge( $problems, self::formContrastProblems( $patch, $on, $lang, $state, "$id|$langId|$on|$state" ) );
                    }
                }
            }
        }
        $this->assertSame( 40 * 10 * 4 * 2, $checked );
        $this->assertSame( 3200, $checked );
        $this->assertSame( [], array_values( $problems ), "unreadable forms (fix the form role table in KitAdapters::formPatch(), never the bound; no exceptions):\n" . implode( "\n", array_slice( $problems, 0, 60 ) ) . "\n(" . count( $problems ) . ' problems)' );
    }

    public function testTheFormContrastCheckCatchesWhatItShould(): void
    {
        $pastel = DesignLanguages::get( 'playful-pastel' );
        $this->assertIsArray( $pastel );
        $kits  = $this->shippedKits();
        $judge = static fn ( array $p, string $on = 'light', string $state = 'rest', ?array $lang = null ): array => self::formContrastProblems( $p, $on, $lang ?? $pastel, $state, 'x' );
        $good  = $this->patch( $kits['steady-solid'], 'light' );
        foreach ( [ 'rest', 'hover' ] as $state ) {
            $this->assertSame( [], $judge( $good, 'light', $state ), "steady-solid on light, $state, is clean" );
        }
        $frame = 'field.decoration.border.desktop.value.styles.all.color';

        // A muted frame swapped for the decorative `border` hairline (about 1.1:1): fails on the fill, and neither it nor the
        // fill (white on the cream band, 1.06:1) reaches the container.
        $bad = $good;
        self::setLeaf( $bad, $frame, '{{aied:color:border}}' );
        $this->assertSame( [ 'x|frame-all-fill', 'x|boundary-container' ], array_keys( $judge( $bad ) ) );

        // The container is judged from the better of frame and fill. playful-pastel's white field is 2.45:1 on its accent
        // band, so a muted frame (2.55:1 there) leaves the field without a boundary; the amended `text` frame is 5.75:1.
        $accentForm = $this->patch( $kits['steady-solid'], 'accent' );
        $this->assertSame( [], $judge( $accentForm, 'accent' ), 'the amended accent-band frame is clean' );
        $this->assertSame( '{{aied:color:text}}', self::leaves( $accentForm )[ $frame ] );
        self::setLeaf( $accentForm, $frame, '{{aied:color:muted}}' );
        $this->assertSame( [ 'x|boundary-container' ], array_keys( $judge( $accentForm, 'accent' ) ), 'muted on the accent band: neither edge reaches 3:1' );
        self::setLeaf( $accentForm, $frame, '{{aied:color:accent}}' );
        $this->assertSame( [ 'x|frame-all-fill', 'x|boundary-container' ], array_keys( $judge( $accentForm, 'accent' ) ) );
        // Where the FILL carries the edge, a frame that is 1.2:1 on the container is fine: a white field on editorial's accent band.
        $editorial = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $editorial );
        self::setLeaf( $accentForm, $frame, '{{aied:color:muted}}' );
        $this->assertSame( [], $judge( $accentForm, 'accent', 'rest', $editorial ), 'the white fill is 6.96:1 on the accent band' );
        // ...but only while the frame still reads on the fill: a hairline there fails frame-fill.
        self::setLeaf( $accentForm, $frame, '{{aied:color:border}}' );
        $this->assertSame( [ 'x|frame-all-fill' ], array_keys( $judge( $accentForm, 'accent', 'rest', $editorial ) ) );
        // A dark-band field in a light language: the light fill carries the edge against the photo.
        $darkForm = $this->patch( $kits['steady-solid'], 'dark' );
        $this->assertSame( [], $judge( $darkForm, 'dark', 'rest', $editorial ) );

        // The placeholder removed (the field colour does not reach it): 0:1.
        $bad = $good;
        self::unsetLeaf( $bad, 'field.decoration.placeholderFont' );
        $this->assertSame( [ 'x|placeholder' ], array_keys( $judge( $bad ) ) );
        $bad = $good;
        self::setLeaf( $bad, 'field.decoration.placeholderFont.font.desktop.value.color', '{{aied:color:border}}' );
        $this->assertSame( [ 'x|placeholder' ], array_keys( $judge( $bad ) ) );

        // A field filled like its container, or with another role, or transparent, or missing.
        foreach ( [ '{{aied:color:bg}}', '{{aied:color:accent}}', KitAdapters::TRANSPARENT, null ] as $fill ) {
            $bad = $good;
            if ( null === $fill ) {
                self::unsetLeaf( $bad, 'field.decoration.background' );
            } else {
                self::setLeaf( $bad, 'field.decoration.background.desktop.value.color', $fill );
            }
            $this->assertArrayHasKey( 'x|fill', $judge( $bad ), 'field fill ' . var_export( $fill, true ) );
        }
        $this->assertArrayHasKey( 'x|fill', $judge( $good, 'surface' ), 'a surface-band form is filled bg, not surface' );
        $same            = $pastel;
        $same['colors']['surface'] = $same['colors']['bg'];
        $this->assertArrayHasKey( 'x|fill', $judge( $good, 'light', 'rest', $same ), 'a language whose surface is its bg paints a field in its container colour' );

        // Captcha ink on the wrong band: the light band\'s `inverse`, the dark band\'s `text`.
        $bad = $good;
        self::setLeaf( $bad, 'captcha.decoration.font.font.desktop.value.color', '{{aied:color:inverse}}' );
        $this->assertSame( [ 'x|captcha' ], array_keys( $judge( $bad ) ) );
        $dark = $this->patch( $kits['steady-solid'], 'dark' );
        $this->assertSame( [], $judge( $dark, 'dark' ) );
        self::setLeaf( $dark, 'captcha.decoration.font.font.desktop.value.color', '{{aied:color:text}}' );
        $this->assertSame( [ 'x|captcha' ], array_keys( $judge( $dark, 'dark' ) ) );
        $bad = $good;
        self::unsetLeaf( $bad, 'captcha' );
        $this->assertSame( [ 'x|captcha' ], array_keys( $judge( $bad ) ), 'a missing captcha ink' );

        // Field text unreadable on the fill.
        $bad = $good;
        self::setLeaf( $bad, 'field.decoration.font.font.desktop.value.color', '{{aied:color:border}}' );
        $this->assertSame( [ 'x|text' ], array_keys( $judge( $bad ) ) );

        // The submit: an unreadable label in each state, and an outline whose edge melts into the container.
        $bad = $good;
        self::setLeaf( $bad, 'button.decoration.font.font.desktop.value.color', '{{aied:color:border}}' );
        $this->assertArrayHasKey( 'x|submit-label', $judge( $bad, 'light', 'rest' ) );
        $this->assertArrayNotHasKey( 'x|submit-label', $judge( $bad, 'light', 'hover' ), 'the rest label is not the hover one' );
        $bad = $good;
        self::setLeaf( $bad, 'button.decoration.font.font.desktop.hover.color', '{{aied:color:border}}' );
        $this->assertArrayHasKey( 'x|submit-label', $judge( $bad, 'light', 'hover' ) );

        $outline = $this->patch( $kits['outline-2'], 'light' );
        foreach ( [ 'rest', 'hover' ] as $state ) {
            $this->assertSame( [], $judge( $outline, 'light', $state ), "outline-2 on light, $state, is clean" );
        }
        $this->assertSame( KitAdapters::TRANSPARENT, $outline['button']['decoration']['background']['desktop']['value']['color'] );
        $bad = $outline;
        self::setLeaf( $bad, 'button.decoration.border.desktop.value.styles.all.color', '{{aied:color:bg}}' );
        $this->assertArrayHasKey( 'x|submit-edge', $judge( $bad, 'light', 'rest' ) );

        // The underline submit has no hover border colour: the hover state judges the REST edge, so a faint rest edge fails
        // in the hover state too, and a hover colour that exists is the one judged.
        $under = $this->patch( $kits['underlined-label'], 'light' );
        $this->assertSame( [], $judge( $under, 'light', 'hover' ), 'underlined-label on light, hover, is clean' );
        $this->assertArrayNotHasKey( 'hover', $under['button']['decoration']['border']['desktop'] );
        $bad = $under;
        self::setLeaf( $bad, 'button.decoration.border.desktop.value.styles.bottom.color', '{{aied:color:bg}}' );
        $this->assertArrayHasKey( 'x|submit-edge', $judge( $bad, 'light', 'hover' ), 'the rest edge is judged in the hover state' );
        $bad = $outline;
        self::setLeaf( $bad, 'button.decoration.background.desktop.hover.color', KitAdapters::TRANSPARENT );
        self::setLeaf( $bad, 'button.decoration.font.font.desktop.hover.color', '{{aied:color:text}}' );
        $this->assertSame( [], $judge( $bad, 'light', 'hover' ), 'a transparent hover with the kit edge is clean' );
        self::setLeaf( $bad, 'button.decoration.border.desktop.hover.styles.all.color', '{{aied:color:bg}}' );
        $this->assertSame( [], $judge( $bad, 'light', 'rest' ), 'a hover colour does not reach the rest state' );
        $this->assertArrayHasKey( 'x|submit-edge', $judge( $bad, 'light', 'hover' ) );
    }

    /** Set the dotted $path of $node to $value. @param array<string,mixed> $node */
    private static function setLeaf( array &$node, string $path, mixed $value ): void
    {
        $ref = &$node;
        foreach ( explode( '.', $path ) as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                $ref[ $k ] = [];
            }
            $ref = &$ref[ $k ];
        }
        $ref = $value;
    }

    /** Remove the dotted $path of $node. @param array<string,mixed> $node */
    private static function unsetLeaf( array &$node, string $path ): void
    {
        $keys = explode( '.', $path );
        $last = array_pop( $keys );
        $ref  = &$node;
        foreach ( $keys as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                return;
            }
            $ref = &$ref[ $k ];
        }
        unset( $ref[ $last ] );
    }

    public function testTheDeadOnModuleGuardCoversTheForm(): void
    {
        // The shipped list has no contact-form line (every form path passed the probe), so a form clean today stays clean...
        $kit = $this->shippedKits()['chunky-pill-offset'];
        $markup = KitResolver::resolve( $this->formMarkup( 'light' ), $kit );
        $this->assertSame( [], DeadOnModule::load()->violations( $markup ) );
        // ...and a planted dead line for the form's own submit shadow (a path the adapter writes) fails, on the form only.
        $file = tempnam( sys_get_temp_dir(), 'dead' );
        $this->assertIsString( $file );
        file_put_contents( $file, "# test list\ndivi/contact-form|button.decoration.boxShadow|planted-evidence|planted for the test\n" );
        try {
            $scoped = DeadOnModule::load( $file );
            $this->assertStringContainsString( '"boxShadow"', $markup );
            $found = $scoped->violations( $markup );
            $this->assertNotSame( [], $found, 'a planted dead divi/contact-form path is reported' );
            foreach ( $found as $v ) {
                $this->assertStringStartsWith( 'divi/contact-form: button.decoration.boxShadow.', $v );
                $this->assertStringEndsWith( '(dead: planted-evidence)', $v );
            }
            $this->assertSame( [], $scoped->violations( str_replace( 'wp:divi/contact-form', 'wp:divi/button', $markup ) ), 'the line is scoped to the contact form' );
        } finally {
            unlink( $file );
        }
    }
}
