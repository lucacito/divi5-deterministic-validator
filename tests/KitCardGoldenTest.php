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
 * captured when the card adapter was built (Task 3) and reviewed by eye. Both carriers get the same patch.
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

        $patches = 0;
        foreach ( $golden as $id => $entry ) {
            $kit = StyleKits::get( (string) $id );
            $this->assertIsArray( $kit, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['kit'] ), json_encode( $kit ), "$id: its decisions are unchanged" );
            $this->assertCount( 8, $entry['patches'] );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $role, $on ] = explode( '|', (string) $key );
                foreach ( [ 'divi/group', 'divi/column' ] as $carrier ) {
                    $this->assertSame(
                        json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                        json_encode( KitAdapters::patch( $carrier, $role, $on, $kit ), JSON_UNESCAPED_SLASHES ),
                        "$id $role on $on ($carrier)"
                    );
                }
                ++$patches;
            }
        }
        $this->assertSame( 80, $patches );
    }
}
