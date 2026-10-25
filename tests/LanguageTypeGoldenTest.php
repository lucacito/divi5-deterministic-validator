<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LanguageAdapters;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';

/**
 * The type patches of the ten design languages are pinned byte for byte: the golden file holds each language's `type` object
 * and the patch LanguageAdapters::patch() writes for the heading roles (display, title, eyebrow on `divi/heading`) and the
 * eyebrow label (`divi/text`), keys "<block>|<role>" (10 languages x 4 = 40 patches), in DesignLanguages::ids() order. The file
 * is also frozen by sha256 in KitGoldenFreezeTest, so plan B (rhythm) cannot change it. Deliberate changes only: regenerate by
 * writing LanguageAdapters::patch( $block, 'type', $role, $language ) for each language and key, in this file's format
 * (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, trailing newline), review the diff, and change the
 * pin in KitGoldenFreezeTest with it.
 */
class LanguageTypeGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/language-type-patches.json';

    private const KEYS = [ 'divi/heading|display', 'divi/heading|title', 'divi/heading|eyebrow', 'divi/text|eyebrow' ];

    public function testEveryLanguageResolvesItsTypeByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $this->assertSame( DesignLanguages::ids(), array_keys( $golden ), 'the golden holds the ten languages, in order' );

        $patches = 0;
        foreach ( $golden as $id => $entry ) {
            $language = DesignLanguages::get( (string) $id );
            $this->assertIsArray( $language, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['type'] ), json_encode( $language['type'] ), "$id: its type data is unchanged" );
            $this->assertSame( self::KEYS, array_keys( $entry['patches'] ), "$id: the four type patches (display, title, eyebrow heading; eyebrow text), in order" );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $block, $role ] = explode( '|', (string) $key );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( LanguageAdapters::patch( $block, 'type', $role, $language ), JSON_UNESCAPED_SLASHES ),
                    "$id $role ($block)"
                );
                ++$patches;
            }
        }
        $this->assertSame( 40, $patches );
    }

    public function testTheGoldenShowsTheLookEachLanguageAsksFor(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $caps = static fn( string $lang, string $key ): bool => str_contains( (string) json_encode( $golden[ $lang ]['patches'][ $key ] ), '"capitalization":"uppercase"' );
        $this->assertTrue( $caps( 'brutalist-flat', 'divi/heading|display' ), 'brutalist-flat asks for capital headlines' );
        $this->assertFalse( $caps( 'editorial', 'divi/heading|display' ) );
        $this->assertFalse( $caps( 'playful-pastel', 'divi/heading|eyebrow' ), 'playful-pastel writes no capitals' );
        $this->assertStringContainsString( '"weight":"300"', (string) json_encode( $golden['luxe']['patches']['divi/heading|display'] ) );
    }
}
