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
 * The kits shipped before shadows, lift and underlines existed (Task 9a) resolve byte-identically: the golden file holds
 * each of those kits' decisions and its adapter patch for both roles on every surface (rest and hover), captured before
 * the adapter learned the new decisions. Every new decision is optional and defaults to the old behaviour.
 * Kit limits Task 2 ADDED the `panel` surface patches (`control|panel`, `control-secondary|panel`) of the ten design
 * languages' default kits, reviewed by eye: to the entries of the defaults already here, plus three entries
 * (warm-pill-soft, hairline-dashed, chunky-pill-offset) that hold only those two patches. Every earlier patch stayed
 * byte-identical.
 */
class KitAdapterGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-adapter-patches.json';

    /** The twelve kits captured before the new decisions (Task 9a): each holds the six band patches. */
    private const OLD_KITS = [
        'quiet-solid', 'glass-edge', 'warm-pill', 'hairline-mono', 'chunky-pill', 'steady-solid',
        'soft-rounded', 'heavy-block', 'raw-frame', 'gilt-hairline', 'soft-solid', 'outline-2',
    ];

    public function testEveryKitShippedBeforeTheNewDecisionsIsUnchangedAndResolvesByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $this->assertCount( 15, $golden );
        $old      = [ 'control|light', 'control|accent', 'control|dark', 'control-secondary|light', 'control-secondary|accent', 'control-secondary|dark' ];
        $panel    = [ 'control|panel', 'control-secondary|panel' ];
        $defaults = [];
        foreach ( DesignLanguages::ids() as $lang ) {
            $defaults[] = (string) ( DesignLanguages::get( $lang )['kit'] ?? '' );
        }
        $counts = [ 'old' => 0, 'panel' => 0, 'oldEntries' => 0 ];
        foreach ( $golden as $id => $entry ) {
            $kit = StyleKits::get( (string) $id );
            $this->assertIsArray( $kit, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['kit'] ), json_encode( $kit ), "$id: its decisions are unchanged" );
            $keys     = array_keys( $entry['patches'] );
            $expected = array_merge(
                in_array( (string) $id, self::OLD_KITS, true ) ? $old : [],
                in_array( (string) $id, $defaults, true ) ? $panel : []
            );
            $this->assertSame( $expected, $keys, "$id: the band patches as captured, then the panel patches of a language default" );
            $counts['oldEntries'] += in_array( 'control|light', $keys, true ) ? 1 : 0;
            foreach ( $entry['patches'] as $key => $patch ) {
                ++$counts[ str_ends_with( (string) $key, '|panel' ) ? 'panel' : 'old' ];
                [ $role, $on ] = explode( '|', (string) $key );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( KitAdapters::patch( 'divi/button', $role, $on, $kit ), JSON_UNESCAPED_SLASHES ),
                    "$id $role on $on"
                );
            }
        }
        $this->assertSame( [ 'old' => 72, 'panel' => 20, 'oldEntries' => 12 ], $counts );
        $this->assertSame( self::OLD_KITS, array_slice( array_keys( $golden ), 0, 12 ), 'the twelve old entries come first, as captured' );
        foreach ( $defaults as $id ) {
            $this->assertArrayHasKey( $id, $golden, "the language default $id has its panel patches pinned" );
        }
    }
}
