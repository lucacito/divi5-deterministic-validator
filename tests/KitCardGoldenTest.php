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
 * The card patches of the ten design languages' default kits are pinned byte for byte: the golden file holds each kit's
 * decisions and its card adapter patch for both roles (`card`, `panel`) on every band (light, surface, accent, dark),
 * captured when the card adapter was built (Task 3) and reviewed by eye. Both carriers get the same patch. Regenerated
 * once, deliberately, in Task 4: the contrast proof moved the card's hover frame colour from `accent` to `text` (written
 * only on a `border` hairline frame), which changed the `card` patches of glass-edge, heavy-block (hover frame now
 * text), chunky-pill-offset and raw-frame (no hover frame any more: their frame already is text).
 * Kit limits Task 2 ADDED the ground patches (`card|panel` x `light|surface` x `inverted|accent`, keys
 * "<role>|<on>|<ground>") to each entry, reviewed by eye; the eight band patches of every entry stayed byte-identical.
 */
class KitCardGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-card-patches.json';

    public function testEveryLanguageDefaultKitResolvesItsCardsByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $this->assertCount( 10, $golden );

        $defaults = [];
        foreach ( DesignLanguages::ids() as $lang ) {
            $defaults[] = (string) ( DesignLanguages::get( $lang )['kit'] ?? '' );
        }
        $this->assertEqualsCanonicalizing( $defaults, array_keys( $golden ), 'the golden holds exactly the ten language default kits' );

        $patches       = 0;
        $groundPatches = 0;
        foreach ( $golden as $id => $entry ) {
            $kit = StyleKits::get( (string) $id );
            $this->assertIsArray( $kit, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['kit'] ), json_encode( $kit ), "$id: its decisions are unchanged" );
            $this->assertCount( 16, $entry['patches'] );
            $bands   = [];
            $grounds = [];
            foreach ( [ 'card', 'panel' ] as $role ) {
                foreach ( [ 'light', 'surface', 'accent', 'dark' ] as $on ) {
                    $bands[] = "$role|$on";
                }
                foreach ( [ 'light', 'surface' ] as $on ) {
                    foreach ( [ 'inverted', 'accent' ] as $ground ) {
                        $grounds[] = "$role|$on|$ground";
                    }
                }
            }
            $this->assertSame( $bands, array_slice( array_keys( $entry['patches'] ), 0, 8 ), "$id: the eight band patches come first, as captured" );
            $this->assertEqualsCanonicalizing( $grounds, array_slice( array_keys( $entry['patches'] ), 8 ), "$id: the eight ground patches" );
            foreach ( $entry['patches'] as $key => $patch ) {
                $parts  = explode( '|', (string) $key );
                $role   = $parts[0];
                $on     = $parts[1];
                $ground = $parts[2] ?? null;
                foreach ( [ 'divi/group', 'divi/column' ] as $carrier ) {
                    $this->assertSame(
                        json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                        json_encode( null === $ground ? KitAdapters::patch( $carrier, $role, $on, $kit ) : KitAdapters::patch( $carrier, $role, $on, $kit, $ground ), JSON_UNESCAPED_SLASHES ),
                        "$id $role on $on " . ( $ground ?? '' ) . " ($carrier)"
                    );
                }
                null === $ground ? ++$patches : ++$groundPatches;
            }
        }
        $this->assertSame( 80, $patches );
        $this->assertSame( 80, $groundPatches );
    }
}
