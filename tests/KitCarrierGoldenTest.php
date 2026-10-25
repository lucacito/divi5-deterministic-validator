<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';

/**
 * The carrier patches of the ten design languages' default kits are pinned byte for byte: the golden file holds each kit's
 * decisions and the patch of every new carrier of the carriers adapter, keys "<block>|<role>|<on>": `divi/testimonial` card x4
 * bands and panel x4, `divi/blurb` chip x4, `divi/number-counter` rule x5 and rule-strong x5 (light, surface, accent, dark,
 * panel), `divi/divider` rule-accent x2 (light, surface) = 24 per kit, 10 kits = 240 patches. Captured when the contrast proofs
 * of the carriers adapter (Task 4) had proven the role table, and reviewed by eye (raw-frame: a 4px solid accent mark; the
 * blurb chip takes the control radius token, the accent fill and the accent-text glyph). Deliberate changes only: regenerate by
 * writing the patches of KitAdapters::patch() for the ten default kits in this file's format (4-space pretty JSON, unescaped
 * slashes, trailing newline), review the diff, and change the pin in KitGoldenFreezeTest with it. The eight earlier goldens are
 * untouched by this one (KitGoldenFreezeTest).
 */
class KitCarrierGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-carrier-patches.json';

    /** @return list<string> the patch keys of one entry, in order */
    private static function keys(): array
    {
        $keys = [];
        foreach ( [ 'card', 'panel' ] as $role ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                $keys[] = "divi/testimonial|$role|$on";
            }
        }
        foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
            $keys[] = "divi/blurb|chip|$on";
        }
        foreach ( [ 'rule', 'rule-strong' ] as $role ) {
            foreach ( [ 'light', 'surface', 'accent', 'dark', 'panel' ] as $on ) {
                $keys[] = "divi/number-counter|$role|$on";
            }
        }
        foreach ( [ 'light', 'surface' ] as $on ) {
            $keys[] = "divi/divider|rule-accent|$on";
        }
        return $keys;
    }

    public function testEveryLanguageDefaultKitResolvesItsCarrierPatchesByteIdentically(): void
    {
        $raw    = (string) file_get_contents( self::GOLDEN );
        $golden = json_decode( $raw, true );
        $this->assertIsArray( $golden );
        $this->assertSame( "}\n", substr( $raw, -2 ), 'pretty JSON with a trailing newline' );
        $this->assertCount( 10, $golden );

        $defaults = [];
        foreach ( DesignLanguages::ids() as $lang ) {
            $defaults[] = (string) ( DesignLanguages::get( $lang )['kit'] ?? '' );
        }
        $this->assertEqualsCanonicalizing( $defaults, array_keys( $golden ), 'the golden holds exactly the ten language default kits' );

        $patches = 0;
        foreach ( $golden as $id => $entry ) {
            $kit = StyleKits::get( (string) $id );
            $this->assertIsArray( $kit, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['kit'] ), json_encode( $kit ), "$id: its decisions are unchanged" );
            $this->assertSame( self::keys(), array_keys( $entry['patches'] ), "$id: the 24 carrier patches (testimonial card and panel x4, blurb chip x4, counter rule and rule-strong x5, accent rule x2), in order" );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $block, $role, $on ] = explode( '|', (string) $key );
                $this->assertNotSame( [], $patch, "$id $key is a real patch" );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( KitAdapters::patch( $block, $role, $on, $kit ), JSON_UNESCAPED_SLASHES ),
                    "$id $role on $on ($block)"
                );
                ++$patches;
            }
        }
        $this->assertSame( 240, $patches );
    }

    public function testTheGoldenIsTheFormatItWasWrittenIn(): void
    {
        // Rewriting the decoded golden in the generator's format gives the file's bytes: a hand edit that the decoder forgives
        // (whitespace, escaped slashes) still fails here and in the freeze.
        $raw = (string) file_get_contents( self::GOLDEN );
        $this->assertSame( $raw, json_encode( json_decode( $raw, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
    }
}
