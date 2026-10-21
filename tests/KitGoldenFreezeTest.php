<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The goldens of the earlier kit adapters (button, card, item, form) are frozen: the rules and chips adapter only ADDS two
 * goldens (kit-rule-patches.json, kit-chip-patches.json) and never changes a patch the earlier adapters write. The sha256 of
 * each earlier file is pinned literally (their values at 155ff75, before the rules and chips adapter began), so a change to any
 * of them, even a whitespace one, fails here and has to be made on purpose, in the open, by changing the pin with the golden.
 */
class KitGoldenFreezeTest extends TestCase
{
    private const FROZEN = [
        'kit-adapter-patches.json' => 'ff4ca3d5b4e6b71163084f0ac6add4d9b45c7b284e4f81c3f8c0aa834459233e',
        'kit-card-patches.json'    => '1ffad3f06f3a22feb94e9c854cbb93f1391d7b800dc032124efbb4a042ee309f',
        'kit-item-patches.json'    => '73605923c31d175ebe5c783954f58f31b03e62e0fc96c9af898d4da6198b8956',
        'kit-form-patches.json'    => '47be308c01424d9241f00dac247381423bf956cb697e34153286721b2bd1acc8',
    ];

    public function testTheEarlierKitGoldensAreByteIdentical(): void
    {
        $this->assertCount( 4, self::FROZEN );
        foreach ( self::FROZEN as $file => $sha ) {
            $path = __DIR__ . '/golden/' . $file;
            $this->assertFileExists( $path );
            $this->assertSame( $sha, hash_file( 'sha256', $path ), "$file changed: an earlier adapter's golden may not change when a later adapter is added" );
        }
    }

    public function testTheFreezeCatchesWhatItShould(): void
    {
        // A one-byte change to a golden's content gives another digest, so a planted edit would fail the pin above.
        $path    = __DIR__ . '/golden/kit-form-patches.json';
        $content = (string) file_get_contents( $path );
        $this->assertNotSame( self::FROZEN['kit-form-patches.json'], hash( 'sha256', $content . ' ' ) );
        $this->assertNotSame( self::FROZEN['kit-form-patches.json'], hash( 'sha256', substr( $content, 0, -1 ) ) );
        $this->assertNotSame( self::FROZEN['kit-form-patches.json'], hash( 'sha256', str_replace( '"4px"', '"5px"', $content ) ) );
    }
}
