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
 * The item patches of the ten design languages' default kits are pinned byte for byte: the golden file holds each kit's
 * decisions and its item adapter patch on both carriers (`divi/accordion`, `divi/toggle`) for `item` on every band
 * (light, surface, accent, dark) and `item-rule` on light and surface (keys "<block>|<role>|<on>"), captured when the item
 * adapter was built (accordion plan Task 2) and reviewed by eye. The carriers differ (a toggle's closed title is
 * `closedTitle`, it has an open icon and, for `item`, the card padding), so both are pinned.
 */
class KitItemGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-item-patches.json';

    /** @return list<string> the patch keys of one entry, in order */
    private static function keys(): array
    {
        $keys = [];
        foreach ( [ 'divi/accordion', 'divi/toggle' ] as $block ) {
            foreach ( [ 'item' => [ 'light', 'surface', 'accent', 'dark' ], 'item-rule' => [ 'light', 'surface' ] ] as $role => $bands ) {
                foreach ( $bands as $on ) {
                    $keys[] = "$block|$role|$on";
                }
            }
        }
        return $keys;
    }

    public function testEveryLanguageDefaultKitResolvesItsItemsByteIdentically(): void
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
            $this->assertSame( self::keys(), array_keys( $entry['patches'] ), "$id: the twelve item patches, in order" );
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
        $this->assertSame( 120, $patches );
    }
}
