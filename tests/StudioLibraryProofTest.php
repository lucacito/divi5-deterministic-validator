<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioLinks;
use AiEditorDivi5\WP\StudioSlots;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';
require_once __DIR__ . '/../wp-plugin/src/LoopAdvisory.php';
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';
require_once __DIR__ . '/../wp-plugin/src/StudioBrief.php';
require_once __DIR__ . '/../wp-plugin/src/StudioCompiler.php';

/**
 * Studio compiler, plan 1 task 7: the proofs over the whole library. Every recipe compiles under every design language
 * with every supplied word present exactly once and no slot dropped, and hostile text never breaks a page or forms a
 * token, marker, block or link of its own. `AIED_SEED=n` picks the random seed (default 1).
 */
class StudioLibraryProofTest extends TestCase
{
    /** @return array{0:array<string,string|array<string,string>>,1:list<string>} slot values for every text, alt and link slot, and the marker strings that must appear once */
    private static function markers( string $recipe, int $r ): array
    {
        $values  = [];
        $markers = [];
        foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $n => $slot ) {
            if ( 'picture' === $slot['kind'] ) {
                continue;
            }
            if ( 'figure' === $slot['role'] ) {
                // A counter figure must be a number (a circle counter 0 to 100, so it is not checked for being unique).
                $values[ $slot['id'] ] = 'divi/circle-counter' === $slot['block'] ? '42' : sprintf( '9%03d%03d', $r, $n );
                if ( 'divi/circle-counter' !== $slot['block'] ) {
                    $markers[] = $values[ $slot['id'] ];
                }
                continue;
            }
            $marker = 'link' === $slot['kind'] ? "https://example.com/l{$r}x{$n}z" : "Mk{$r}x{$n}z";
            $values[ $slot['id'] ] = $marker;
            $markers[]             = $marker;
        }

        return [ $values, $markers ];
    }

    public function testEveryRecipeUnderEveryLanguage(): void
    {
        $failures = [];
        foreach ( SectionRecipes::names() as $r => $recipe ) {
            [ $values, $markers ] = self::markers( $recipe, $r );
            $rawDynamic           = substr_count( (string) SectionRecipes::rawMarkup( $recipe ), '$variable(' );
            foreach ( DesignLanguages::ids() as $language ) {
                $out = StudioCompiler::compile( [
                    'title'    => 'Proof',
                    'goal'     => 'sell',
                    'language' => $language,
                    'sections' => [ [ 'recipe' => $recipe, 'slots' => $values ] ],
                ] );
                $where = "$recipe / $language";
                if ( ! $out['ok'] ) {
                    $failures[] = "$where not ok: " . json_encode( [ $out['problems'], array_slice( $out['violations'], 0, 2 ) ] );
                    continue;
                }
                $markup = (string) $out['markup'];
                foreach ( $markers as $m ) {
                    if ( 1 !== substr_count( $markup, $m ) ) {
                        $failures[] = "$where: $m appears " . substr_count( $markup, $m ) . ' times';
                    }
                }
                if ( str_contains( $markup, '{{' ) || str_contains( $markup, '"aied"' ) ) {
                    $failures[] = "$where: a token or marker is left";
                }
                if ( substr_count( $markup, '$variable(' ) !== $rawDynamic ) {
                    $failures[] = "$where: dynamic-content tokens changed";
                }
                foreach ( $out['unfilled'] as $u ) {
                    $kind = array_column( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ), 'kind', 'id' )[ $u['slot'] ];
                    if ( 'picture' !== $kind ) {
                        $failures[] = "$where: unfilled non-picture {$u['slot']}";
                    }
                }
            }
        }
        $this->assertSame( [], array_slice( $failures, 0, 15 ), count( $failures ) . ' failures' );
    }

    /** @return list<string> */
    private static function fragments(): array
    {
        return [
            '"', '\\', '-->', '<!--', '<!-- wp:divi/text {} /-->', '{', '}', '{{aied:color:bg}}', '{{aied:image:hero-blue-1}}',
            '"aied":{"kit":"control"}', '$variable({"a":1})$', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '&amp;', '&#123;',
            'emoji 😀👩‍👩‍👧', 'مرحبا بالعالم', "line\nbreak\ttab", str_repeat( 'long ', 380 ), '\\u0022', "caf\u{00e9}", ' ',
        ];
    }

    public function testHostileValuesNeverBreakAPage(): void
    {
        $seed = (int) ( getenv( 'AIED_SEED' ) ?: 1 );
        mt_srand( $seed );
        $names     = SectionRecipes::names();
        $languages = DesignLanguages::ids();
        $fragments = self::fragments();
        $links     = [ 'https://example.com/ok', '/contact/', '#top', 'javascript:alert(1)', 'data:text/html,x', '//evil.com', 'https://u@x.com', '{{aied:color:bg}}', 'mailto:a@b.co', "https://x.com/a\nb" ];
        $accepted  = 0;
        for ( $b = 0; $b < 200; ++$b ) {
            $sections = [];
            for ( $s = mt_rand( 1, 4 ); $s > 0; --$s ) {
                $recipe = $names[ mt_rand( 0, count( $names ) - 1 ) ];
                $slots  = [];
                foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $slot ) {
                    if ( 'picture' === $slot['kind'] || 0 !== mt_rand( 0, 1 ) ) {
                        continue;
                    }
                    if ( 'link' === $slot['kind'] ) {
                        $slots[ $slot['id'] ] = $links[ mt_rand( 0, count( $links ) - 1 ) ];
                        continue;
                    }
                    if ( 'figure' === $slot['role'] ) { // a counter figure is a number or it is refused; NUL and the like are refused too (StudioBriefTest)
                        $slots[ $slot['id'] ] = [ '5', '1,250', '98.6', '$2M' ][ mt_rand( 0, 3 ) ];
                        continue;
                    }
                    $value = '';
                    for ( $f = mt_rand( 1, 4 ); $f > 0; --$f ) {
                        $value .= $fragments[ mt_rand( 0, count( $fragments ) - 1 ) ];
                    }
                    $slots[ $slot['id'] ] = $value;
                }
                $sections[] = [ 'recipe' => $recipe, 'slots' => $slots ];
            }
            $brief = [ 'title' => 'Fuzz ' . $b, 'goal' => 'explain', 'language' => $languages[ mt_rand( 0, count( $languages ) - 1 ) ], 'sections' => $sections ];
            $out   = StudioCompiler::compile( $brief );
            $this->assertIsBool( $out['ok'] );
            if ( ! $out['ok'] ) {
                $this->assertNotSame( [], $out['problems'] + $out['violations'], "brief $b (seed $seed) failed without a reason" );
                continue;
            }
            ++$accepted;
            $markup = (string) $out['markup'];
            $this->assertSame( [], $out['violations'], "brief $b (seed $seed)" );
            $this->assertStringNotContainsStringIgnoringCase( '<script', $markup, "brief $b (seed $seed)" );
            $this->assertStringNotContainsStringIgnoringCase( 'u003cscript', $markup, "brief $b (seed $seed)" );
            $this->assertStringNotContainsString( '{{', $markup, "brief $b (seed $seed)" );
            $this->assertStringNotContainsString( '"aied"', $markup, "brief $b (seed $seed)" );

            // No block of its own: the same brief with no words has exactly the same number of block comments.
            $bare = $brief;
            foreach ( $bare['sections'] as $k => $sec ) {
                $bare['sections'][ $k ] = [ 'recipe' => $sec['recipe'] ];
            }
            $base = StudioCompiler::compile( $bare );
            $this->assertSame( substr_count( (string) $base['markup'], '<!-- wp:' ), substr_count( $markup, '<!-- wp:' ), "brief $b (seed $seed) block count" );
            $this->assertSame( substr_count( (string) $base['markup'], '<!--' ), substr_count( $markup, '<!--' ), "brief $b (seed $seed) comment count" );

            // Every static button link in the output is an allowed link.
            preg_match_all( BlockAttrs::BLOCK, $markup, $m, PREG_SET_ORDER );
            foreach ( $m as $block ) {
                $attrs = BlockAttrs::decode( $block[3] );
                $link  = null === $attrs ? null : StudioSlots::get( $attrs, 'button.innerContent.desktop.value.linkUrl' );
                $own   = is_string( $link ) && str_contains( (string) $base['markup'], '"linkUrl":"' . $link . '"' ); // a link the recipe itself carries
                if ( is_string( $link ) && '' !== $link && ! $own && ! StudioSlots::isDynamic( $link ) ) {
                    $this->assertNull( StudioLinks::problem( $link ), "brief $b (seed $seed) link $link" );
                }
            }
        }
        $this->assertGreaterThan( 100, $accepted, 'most random briefs should compile' );
    }
}
