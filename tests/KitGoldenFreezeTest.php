<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The goldens of the earlier adapters are frozen: the rules and chips adapter only ADDED two goldens (kit-rule-patches.json,
 * kit-chip-patches.json), the type adapter only ADDED language-type-patches.json and the rhythm adapter only ADDED
 * language-rhythm-patches.json and the carriers adapter only ADDED kit-carrier-patches.json (its Task 4: the eight earlier hashes
 * above are NOT touched by it); none of them changes a patch an earlier
 * adapter writes. The sha256 of each file is pinned literally (the four kit goldens at 155ff75, rule and chip at 77abc24, the
 * language type golden when the type adapter's Task 4 generated it, the language rhythm golden when the rhythm adapter's Task 4
 * generated it, the carrier golden when the carriers adapter's Task 4 generated it), so a change to any
 * of them, even a whitespace one, fails here and has to be made on purpose, in the open, by changing the pin with the golden.
 */
class KitGoldenFreezeTest extends TestCase
{
    private const FROZEN = [
        'kit-adapter-patches.json' => 'ff4ca3d5b4e6b71163084f0ac6add4d9b45c7b284e4f81c3f8c0aa834459233e',
        'kit-card-patches.json'    => '1ffad3f06f3a22feb94e9c854cbb93f1391d7b800dc032124efbb4a042ee309f',
        'kit-item-patches.json'    => '73605923c31d175ebe5c783954f58f31b03e62e0fc96c9af898d4da6198b8956',
        'kit-form-patches.json'    => '47be308c01424d9241f00dac247381423bf956cb697e34153286721b2bd1acc8',
        'kit-rule-patches.json'    => 'd087813dcc1562b29fd9fec3883828faa6b9833061ae7458d6785ef7b7f2b0a3',
        'kit-chip-patches.json'    => '12dfba28c57715e651ee2e029e3a9c2e0fb3bbe771f0bbdd38fcce88613ebe2d',
        'language-type-patches.json' => '64bf3702f64351d4c8af21758b2fca684b3321f57745ebda7a95b3169c04a9f4',
        'language-rhythm-patches.json' => '4dfde6021b53c1e9a62d0f9d83ac992bec8c440c40ee894c386e2ef84e49f173',
        'kit-carrier-patches.json' => 'aabdd90285dd21d3264ef8d7ca0c477881cda856667b7af7ffe32ba325f10682',
    ];

    public function testTheEarlierGoldensAreByteIdentical(): void
    {
        $this->assertCount( 9, self::FROZEN );
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
