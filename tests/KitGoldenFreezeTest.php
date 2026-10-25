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
 *
 * Deliberate re-freeze: the wording-gaps hover fix (Ruling 7, plan 2026-10-25 Task 3a: `invert` with a `same` frame wider than
 * 0px keeps the REST frame colour on hover) re-froze kit-adapter-patches.json and kit-form-patches.json, because glass-edge and
 * heavy-block are in both. Exactly the patches in HOVER_FIX_CHANGED moved, each only in its hover frame colour (the button's
 * `border.desktop.hover.styles.all.color`, which used to follow the swapped fill and is now the rest frame); every other byte
 * is identical, proven by testTheHoverFixMovedOnlyTheListedHoverFrames, which puts the old value back and finds the old digest.
 * The other seven hashes are untouched.
 */
class KitGoldenFreezeTest extends TestCase
{
    private const FROZEN = [
        'kit-adapter-patches.json' => '8590a60756f7e17d583d87c8427b88aee5a7de8d5bd82057fc660437a6fea361',
        'kit-card-patches.json'    => '1ffad3f06f3a22feb94e9c854cbb93f1391d7b800dc032124efbb4a042ee309f',
        'kit-item-patches.json'    => '73605923c31d175ebe5c783954f58f31b03e62e0fc96c9af898d4da6198b8956',
        'kit-form-patches.json'    => '83a5565dbc2da48f2eaf11b78e0d0515c721dd23055b61e99b0d38b339214730',
        'kit-rule-patches.json'    => 'd087813dcc1562b29fd9fec3883828faa6b9833061ae7458d6785ef7b7f2b0a3',
        'kit-chip-patches.json'    => '12dfba28c57715e651ee2e029e3a9c2e0fb3bbe771f0bbdd38fcce88613ebe2d',
        'language-type-patches.json' => '64bf3702f64351d4c8af21758b2fca684b3321f57745ebda7a95b3169c04a9f4',
        'language-rhythm-patches.json' => '4dfde6021b53c1e9a62d0f9d83ac992bec8c440c40ee894c386e2ef84e49f173',
        'kit-carrier-patches.json' => 'aabdd90285dd21d3264ef8d7ca0c477881cda856667b7af7ffe32ba325f10682',
    ];

    /** The digests of the two goldens before the Ruling 7 hover fix (as pinned at 155ff75 and kept through 1d51dc9). */
    private const BEFORE_THE_HOVER_FIX = [
        'kit-adapter-patches.json' => 'ff4ca3d5b4e6b71163084f0ac6add4d9b45c7b284e4f81c3f8c0aa834459233e',
        'kit-form-patches.json'    => '47be308c01424d9241f00dac247381423bf956cb697e34153286721b2bd1acc8',
    ];

    /** Every golden patch the hover fix changed: file => list of "kit|patch key". */
    private const HOVER_FIX_CHANGED = [
        'kit-adapter-patches.json' => [
            'glass-edge|control|light', 'glass-edge|control|accent', 'glass-edge|control|dark',
            'heavy-block|control|light', 'heavy-block|control|accent', 'heavy-block|control|dark',
        ],
        'kit-form-patches.json'    => [
            'glass-edge|divi/contact-form|form|light', 'glass-edge|divi/contact-form|form|surface',
            'glass-edge|divi/contact-form|form|accent', 'glass-edge|divi/contact-form|form|dark',
            'heavy-block|divi/contact-form|form|light', 'heavy-block|divi/contact-form|form|surface',
            'heavy-block|divi/contact-form|form|accent', 'heavy-block|divi/contact-form|form|dark',
        ],
    ];

    /**
     * Put the pre-fix hover frame colour back into each listed patch (the old rule: a `same` frame followed the swapped fill, so
     * the hover frame was the hover fill) and the file encodes to the pre-fix digest: the listed hover frames are the only bytes
     * that moved. Each listed patch's new hover frame is its rest frame and differs from the old one.
     */
    public function testTheHoverFixMovedOnlyTheListedHoverFrames(): void
    {
        foreach ( self::HOVER_FIX_CHANGED as $file => $changed ) {
            $golden = json_decode( (string) file_get_contents( __DIR__ . '/golden/' . $file ), true );
            $this->assertIsArray( $golden );
            foreach ( $changed as $entry ) {
                [ $kit, $key ] = explode( '|', $entry, 2 );
                $this->assertArrayHasKey( $key, $golden[ $kit ]['patches'] ?? [], "$file $entry exists" );
                $d =& $golden[ $kit ]['patches'][ $key ]['button']['decoration'];
                $this->assertSame( $d['border']['desktop']['value']['styles']['all']['color'], $d['border']['desktop']['hover']['styles']['all']['color'], "$file $entry: the hover frame is the rest frame" );
                $this->assertNotSame( $d['background']['desktop']['hover']['color'], $d['border']['desktop']['hover']['styles']['all']['color'], "$file $entry: it no longer follows the hover fill" );
                $d['border']['desktop']['hover']['styles']['all']['color'] = $d['background']['desktop']['hover']['color'];
                unset( $d );
            }
            $old = json_encode( $golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
            $this->assertSame( self::BEFORE_THE_HOVER_FIX[ $file ], hash( 'sha256', $old ), "$file: only the listed hover frames moved" );
        }
    }

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
