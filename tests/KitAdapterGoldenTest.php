<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';

/**
 * The kits shipped before shadows, lift and underlines existed (Task 9a) resolve byte-identically: the golden file holds
 * each of those kits' decisions and its adapter patch for both roles on every surface (rest and hover), captured before
 * the adapter learned the new decisions. Every new decision is optional and defaults to the old behaviour.
 */
class KitAdapterGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-adapter-patches.json';

    public function testEveryKitShippedBeforeTheNewDecisionsIsUnchangedAndResolvesByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $this->assertGreaterThanOrEqual( 12, count( $golden ) );
        foreach ( $golden as $id => $entry ) {
            $kit = StyleKits::get( (string) $id );
            $this->assertIsArray( $kit, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['kit'] ), json_encode( $kit ), "$id: its decisions are unchanged" );
            $this->assertCount( 6, $entry['patches'] );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $role, $on ] = explode( '|', (string) $key );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( KitAdapters::patch( 'divi/button', $role, $on, $kit ), JSON_UNESCAPED_SLASHES ),
                    "$id $role on $on"
                );
            }
        }
    }
}
