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
 * The form patches of the ten design languages' default kits are pinned byte for byte: the golden file holds each kit's
 * decisions and its form adapter patch for `divi/contact-form` role `form` on every band (light, surface, accent, dark;
 * keys "<block>|<role>|<on>"), captured when the contrast proof of the forms adapter (Task 3) settled the role table
 * (the accent-band frame is `text`) and reviewed by eye. Deliberate changes only: regenerate by writing the patches of
 * KitAdapters::patch() for the ten default kits in this file's format, and review the diff.
 * The wording-gaps hover fix (Ruling 7, 2026-10-25) regenerated this golden ON PURPOSE: the glass-edge and heavy-block
 * button patches now keep their rest frame colour on hover (`invert` with a visible `same` frame); the changed entries are
 * listed and proven to be the only moved bytes in KitGoldenFreezeTest::HOVER_FIX_CHANGED.
 */
class KitFormGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-form-patches.json';

    /** @return list<string> the patch keys of one entry, in order */
    private static function keys(): array
    {
        $keys = [];
        foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
            $keys[] = "divi/contact-form|form|$on";
        }
        return $keys;
    }

    public function testEveryLanguageDefaultKitResolvesItsFormByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
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
            $this->assertSame( self::keys(), array_keys( $entry['patches'] ), "$id: the four form patches, in order" );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $block, $role, $on ] = explode( '|', (string) $key );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( KitAdapters::patch( $block, $role, $on, $kit ), JSON_UNESCAPED_SLASHES ),
                    "$id $role on $on ($block)"
                );
                ++$patches;
            }
        }
        $this->assertSame( 40, $patches );
    }
}
