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
 * The rule patches of the ten design languages' default kits are pinned byte for byte: the golden file holds each kit's
 * decisions and its rule adapter patch for every carrier (`divi/divider`, `divi/group`, `divi/column`), both roles (`rule`,
 * `rule-strong`) and every band (light, surface, accent, dark, panel), keys "<block>|<role>|<on>" (10 kits x 3 x 2 x 5 = 300
 * patches), captured when the contrast proof of the rules and chips adapter (Task 4) had proven the role table and reviewed
 * by eye. Deliberate changes only: regenerate by writing the patches of KitAdapters::patch() for the ten default kits in this
 * file's format, and review the diff.
 */
class KitRuleGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/kit-rule-patches.json';

    /** @return list<string> the patch keys of one entry, in order */
    private static function keys(): array
    {
        $keys = [];
        foreach ( [ 'divi/divider', 'divi/group', 'divi/column' ] as $block ) {
            foreach ( [ 'rule', 'rule-strong' ] as $role ) {
                foreach ( [ 'light', 'surface', 'accent', 'dark', 'panel' ] as $on ) {
                    $keys[] = "$block|$role|$on";
                }
            }
        }
        return $keys;
    }

    public function testEveryLanguageDefaultKitResolvesItsRulesByteIdentically(): void
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
            $this->assertSame( self::keys(), array_keys( $entry['patches'] ), "$id: the thirty rule patches (divider, group, column x rule, rule-strong x five bands), in order" );
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
        $this->assertSame( 300, $patches );
    }
}
