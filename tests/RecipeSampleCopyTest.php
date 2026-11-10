<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
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
require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';
require_once __DIR__ . '/../wp-plugin/src/StudioSlots.php';

/**
 * The sample copy of a recipe is what an unfilled Lab slot keeps on a draft page, so it must suit a bakery as well as a
 * software firm: no software or lorem ipsum text, and picture descriptions that are placeholders, not a scene.
 */
class RecipeSampleCopyTest extends TestCase
{
    /** The scoping regex of the 2026-11-10 dogfood finding: words that make a sample read as software or filler. */
    private const SOFTWARE = '/\b(dashboard|analytics|security|shield|software|saas|api|cloud|developer|lorem|ipsum|dolor|workflow|integration|startup|platform|deploy|encrypt|chatbot|server|code|app\b|apps\b|data\b)/i';

    /**
     * Shrink-only: "recipe/slot id" => reason. Each entry must still match, so a fixed sample leaves the list.
     *
     * @var array<string,string>
     */
    private const SOFTWARE_EXEMPT = [
        'hero-spec-card/text.5.body.0' => 'The recipe is the hero for software, tools and developer products (its `when` says so); its spec card is a list of "Platform [placeholder]" style rows.',
    ];

    /**
     * Shrink-only picture descriptions that are not in the placeholder register. They name abstract art or an avatar,
     * which is what the built-in pack picture really shows, and no product or scene.
     *
     * @var array<string,string>
     */
    private const ALT_EXEMPT = [
        'image-gallery/image.0.alt'                => 'Describes the abstract night-sea picture of the pack (square-blue-1).',
        'image-gallery/image.1.alt'                => 'Describes the abstract tile pattern of the pack (square-teal-1).',
        'image-gallery/image.2.alt'                => 'Describes the abstract planet picture of the pack (square-purple-1).',
        'testimonial/testimonial.0.portrait.alt'   => 'Describes the avatar picture of the pack (avatar-1), not a real person.',
    ];

    /** @return list<array{recipe:string,slot:array<string,mixed>}> */
    private static function samples(): array
    {
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) as $slot ) {
                $out[] = [ 'recipe' => $name, 'slot' => $slot ];
            }
        }

        return $out;
    }

    public function testNoSlotSampleReadsAsSoftwareOrLorem(): void
    {
        $bad = [];
        foreach ( self::samples() as $s ) {
            $key = $s['recipe'] . '/' . $s['slot']['id'];
            if ( 1 === preg_match( self::SOFTWARE, (string) $s['slot']['sample'] ) && ! isset( self::SOFTWARE_EXEMPT[ $key ] ) ) {
                $bad[] = $key . ': ' . $s['slot']['sample'];
            }
        }
        $this->assertSame( [], $bad, "Software or lorem sample copy:\n" . implode( "\n", $bad ) );
    }

    public function testNoRecipeMarkupContainsLorem(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $this->assertStringNotContainsStringIgnoringCase( 'lorem', (string) SectionRecipes::rawMarkup( $name ), "lorem ipsum in $name" );
        }
    }

    public function testPictureDescriptionsArePlaceholdersOrListedExceptions(): void
    {
        $bad = [];
        foreach ( self::samples() as $s ) {
            if ( 'alt' !== $s['slot']['role'] ) {
                continue;
            }
            $key    = $s['recipe'] . '/' . $s['slot']['id'];
            $sample = (string) $s['slot']['sample'];
            $ok     = '' === $sample || str_starts_with( $sample, 'Placeholder' ) || str_starts_with( $sample, '[' );
            if ( ! $ok && ! isset( self::ALT_EXEMPT[ $key ] ) ) {
                $bad[] = $key . ': ' . $sample;
            }
        }
        $this->assertSame( [], $bad, "Picture description that is a scene, not a placeholder:\n" . implode( "\n", $bad ) );
    }

    public function testExemptionsAreStillNeeded(): void
    {
        $byKey = [];
        foreach ( self::samples() as $s ) {
            $byKey[ $s['recipe'] . '/' . $s['slot']['id'] ] = (string) $s['slot']['sample'];
        }
        foreach ( self::SOFTWARE_EXEMPT as $key => $reason ) {
            $this->assertArrayHasKey( $key, $byKey, "stale exemption $key" );
            $this->assertNotSame( '', $reason );
            $this->assertSame( 1, preg_match( self::SOFTWARE, $byKey[ $key ] ), "$key no longer matches: remove its exemption" );
        }
        foreach ( self::ALT_EXEMPT as $key => $reason ) {
            $this->assertArrayHasKey( $key, $byKey, "stale exemption $key" );
            $this->assertNotSame( '', $reason );
            $this->assertFalse( str_starts_with( $byKey[ $key ], 'Placeholder' ) || str_starts_with( $byKey[ $key ], '[' ) || '' === $byKey[ $key ], "$key is a placeholder now: remove its exemption" );
        }
        $this->assertLessThanOrEqual( 1, count( self::SOFTWARE_EXEMPT ), 'the software exemptions only shrink' );
        $this->assertLessThanOrEqual( 4, count( self::ALT_EXEMPT ), 'the picture exemptions only shrink' );
    }
}
