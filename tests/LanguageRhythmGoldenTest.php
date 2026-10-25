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
 * The rhythm patches of the ten design languages are pinned byte for byte: the golden file holds each language's
 * `section_space` data and the patch LanguageAdapters::patch() writes for the three section roles (section, open, tight on
 * `divi/section`), keys "<block>|<role>" (10 languages x 3 = 30 patches), in DesignLanguages::ids() order. The file is also
 * frozen by sha256 in KitGoldenFreezeTest. Deliberate changes only: regenerate by writing
 * LanguageAdapters::patch( 'divi/section', 'rhythm', $role, $language ) for each language and role, in this file's format
 * (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, trailing newline), review the diff, and change the
 * pin in KitGoldenFreezeTest with it.
 */
class LanguageRhythmGoldenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/golden/language-rhythm-patches.json';

    private const KEYS = [ 'divi/section|section', 'divi/section|open', 'divi/section|tight' ];

    public function testEveryLanguageResolvesItsRhythmByteIdentically(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $this->assertSame( DesignLanguages::ids(), array_keys( $golden ), 'the golden holds the ten languages, in order' );

        $patches = 0;
        foreach ( $golden as $id => $entry ) {
            $language = DesignLanguages::get( (string) $id );
            $this->assertIsArray( $language, "$id is still shipped" );
            $this->assertSame( json_encode( $entry['section_space'] ), json_encode( $language['section_space'] ), "$id: its section_space data is unchanged" );
            $this->assertSame( self::KEYS, array_keys( $entry['patches'] ), "$id: the three rhythm patches (section, open, tight), in order" );
            foreach ( $entry['patches'] as $key => $patch ) {
                [ $block, $role ] = explode( '|', (string) $key );
                $this->assertSame(
                    json_encode( $patch, JSON_UNESCAPED_SLASHES ),
                    json_encode( LanguageAdapters::patch( $block, 'rhythm', $role, $language ), JSON_UNESCAPED_SLASHES ),
                    "$id $role ($block)"
                );
                ++$patches;
            }
        }
        $this->assertSame( 30, $patches );
    }

    public function testTheGoldenShowsTheRhythmEachLanguageAsksFor(): void
    {
        $golden = json_decode( (string) file_get_contents( self::GOLDEN ), true );
        $this->assertIsArray( $golden );
        $top = static fn( string $lang, string $role, string $bp ): string => $golden[ $lang ]['patches'][ "divi/section|$role" ]['module']['decoration']['spacing'][ $bp ]['value']['padding']['top'];
        $this->assertSame( '200px', $top( 'luxe', 'open', 'desktop' ), 'luxe is the roomiest: its open band is 200px' );
        foreach ( [ 'organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust' ] as $lang ) {
            $this->assertSame( [ '56px', '40px', '28px' ], [ $top( $lang, 'tight', 'desktop' ), $top( $lang, 'tight', 'tablet' ), $top( $lang, 'tight', 'phone' ) ], "$lang: tight 56/40/28" );
        }
        foreach ( $golden as $id => $entry ) {
            foreach ( $entry['patches'] as $key => $patch ) {
                foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
                    $padding = $patch['module']['decoration']['spacing'][ $bp ]['value']['padding'];
                    $this->assertSame( 'on', $padding['syncVertical'] ?? null, "$id $key $bp: syncVertical on" );
                    $this->assertSame( $padding['top'], $padding['bottom'], "$id $key $bp: top equals bottom" );
                }
            }
        }
    }
}
