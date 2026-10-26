<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\ContextTable;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\Detector;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 5: detection of the source design (spec 2026-10-25-pro-design-switch-design.md section 5.4; Review
 * Focus 7: detection never accepts a kit that would restyle the page differently from the true one).
 */
class DetectorTest extends TestCase
{
    /**
     * The detection table measured on the library (Task 5 Step 1): every recipe under 4 designs drawn with
     * `mt_srand(11)` (the first the language's own kit, then three random kits), detected for the target luxe +
     * gilt-hairline. `exact`: the true kit; `harmless`: another kit that unresolves the page the same way; `none`: no
     * kit block, nothing to recover; `refused`: two kits within the floor that unresolve differently; `wrong`: accepted
     * although it unresolves differently (must stay 0).
     */
    private const TABLE = [ 'languages' => 284, 'exact' => 129, 'harmless' => 39, 'none' => 76, 'refused' => 40, 'wrong' => 0 ];

    /** sha256 of the drawn (recipe, language, kit) list, so a PHP generator change cannot move the table silently. */
    private const DRAW_HASH = 'ddea0049ef2f55e90188adc5906f6d846523010dd86793c9fa1c7f6c25626a0c';

    private static function build( string $recipe, string $language, string $kit ): string
    {
        return (string) SectionRecipes::resolveMarkup( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $recipe ), '' ), (array) DesignLanguages::get( $language ), (array) StyleKits::get( $kit ) );
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private static function draws(): array
    {
        mt_srand( 11 );
        $kits  = StyleKits::ids();
        $langs = DesignLanguages::ids();
        $out   = [];
        foreach ( SectionRecipes::names() as $name ) {
            for ( $t = 0; $t < 4; $t++ ) {
                $l     = $langs[ mt_rand( 0, 9 ) ];
                $k     = 0 === $t ? (string) DesignLanguages::get( $l )['kit'] : $kits[ mt_rand( 0, 39 ) ];
                $out[] = [ $name, $l, $k ];
            }
        }
        mt_srand();

        return $out;
    }

    public function testDetectionOnTheLibrary(): void
    {
        $draws = self::draws();
        $this->assertCount( 284, $draws );
        $this->assertSame( self::DRAW_HASH, hash( 'sha256', (string) json_encode( $draws ) ) );

        $to    = Design::make( 'luxe', 'gilt-hairline' );
        $table = ContextTable::current();
        $count = [ 'languages' => 0, 'exact' => 0, 'harmless' => 0, 'none' => 0, 'refused' => 0, 'wrong' => 0 ];
        $wrong = [];
        $byLanguage = $draws;
        usort( $byLanguage, static fn ( array $a, array $b ): int => [ $a[1], $a[0], $a[2] ] <=> [ $b[1], $b[0], $b[2] ] ); // memo locality only
        foreach ( $byLanguage as [ $name, $l, $k ] ) {
            $page = self::build( $name, $l, $k );
            $d    = Detector::detect( $page, null, null, $to, $table );
            if ( $d['language'] !== $l ) {
                $wrong[] = "$name $l $k: language " . json_encode( $d['language'] );
                continue;
            }
            ++$count['languages'];
            $this->assertSame( 'detected', $d['source']['language'] );
            if ( null !== $d['refused'] ) {
                $this->assertSame( Detector::REFUSED_KIT_TIE, $d['refused'] );
                $this->assertNull( $d['kit'] );
                $this->assertStringContainsString( 'from_kit', (string) $d['message'] );
                ++$count['refused'];
                continue;
            }
            if ( 'none' === $d['source']['kit'] ) {
                $this->assertSame( $to->kitId, $d['kit'], 'no kit block: the target kit is the source kit' );
                $cls = 'none';
            } else {
                $this->assertSame( 'detected', $d['source']['kit'] );
                $cls = $d['kit'] === $k ? 'exact' : 'harmless';
            }
            if ( $d['kit'] !== $k ) {
                $same = DesignSwitch::unresolve( $page, Design::make( $l, $k ), $to, $table ) === DesignSwitch::unresolve( $page, Design::make( $l, (string) $d['kit'] ), $to, $table );
                if ( ! $same ) {
                    $cls     = 'wrong';
                    $wrong[] = "$name $l $k: kit {$d['kit']}";
                }
            }
            ++$count[ $cls ];
        }
        $this->assertSame( [], $wrong, 'no wrong language, no wrong kit accepted' );
        $this->assertSame( self::TABLE, $count );
    }

    public function testATieThatUnresolvesDifferentlyIsRefused(): void
    {
        // steps-numbered under bold-vibrant + outline-pill: quiet-solid and chunky-pill explain its blocks with the same
        // number of leaves, and they would restyle it differently.
        $page = self::build( 'steps-numbered', 'bold-vibrant', 'outline-pill' );
        $d    = Detector::detect( $page, 'bold-vibrant', null, Design::make( 'luxe', 'gilt-hairline' ) );
        $this->assertSame( Detector::REFUSED_KIT_TIE, $d['refused'] );
        $this->assertSame( 'given', $d['source']['language'] );
        $this->assertNull( $d['kit'] );
        $this->assertIsArray( $d['runner_up'] );
        $this->assertStringContainsString( (string) $d['runner_up']['kit'], (string) $d['message'] );
        $this->assertSame( 0, $d['confidence']['kit']['leaves'] - $d['runner_up']['leaves'] );
    }

    public function testACoincidentalSingleMatchIsRefused(): void
    {
        // proof-logo-wall has no kit block; under another kit one of its plain groups happens to equal a kit patch.
        $page = self::build( 'proof-logo-wall', 'editorial', 'double-frame' );
        $d    = Detector::detect( $page, null, null, Design::make( 'luxe', 'gilt-hairline' ) );
        $this->assertSame( 'editorial', $d['language'] );
        $this->assertSame( Detector::REFUSED_KIT_TIE, $d['refused'] );
        $this->assertSame( 1, $d['confidence']['kit']['blocks'] );
        $this->assertSame( Detector::KIT_SINGLE_BLOCK_FLOOR, $d['confidence']['kit']['floor'] );
    }

    public function testFlushImagesAloneAreNotEvidence(): void
    {
        // gallery-masonry-style: six square images; a zero-radius kit would read them as kit images, another not.
        $page = self::build( 'gallery-masonry-style', 'editorial', 'quiet-solid' );
        $d    = Detector::detect( $page, 'editorial', null, Design::make( 'luxe', 'gilt-hairline' ) );
        $this->assertSame( 0, $d['confidence']['kit']['blocks'] );
        $this->assertSame( Detector::REFUSED_KIT_TIE, $d['refused'] );
    }

    public function testAPageWithoutKitBlocksTakesTheTargetKit(): void
    {
        $page = self::build( 'header-title-band', 'organic-warm', 'thick-rounded-caps' );
        $d    = Detector::detect( $page, null, null, Design::make( 'luxe', 'gilt-hairline' ) );
        $this->assertNull( $d['refused'] );
        $this->assertSame( 'organic-warm', $d['language'] );
        $this->assertSame( 'gilt-hairline', $d['kit'] );
        $this->assertSame( 'none', $d['source']['kit'] );
        $this->assertSame( 'organic-warm', Detector::detect( $page, null, null )['language'] );
        $this->assertSame( 'warm-pill-soft', Detector::detect( $page, null, null )['kit'], 'without a target: the language\'s own kit' );
    }

    public function testGivenIdsWin(): void
    {
        $page = self::build( 'pricing-three-tier', 'editorial', 'quiet-solid' );
        $d    = Detector::detect( $page, 'luxe', 'raw-frame' );
        $this->assertSame( 'luxe', $d['language'] );
        $this->assertSame( 'raw-frame', $d['kit'] );
        $this->assertSame( [ 'language' => 'given', 'kit' => 'given' ], $d['source'] );
        $this->assertNull( $d['refused'] );

        $d = Detector::detect( $page, null, 'raw-frame' );
        $this->assertSame( 'editorial', $d['language'] );
        $this->assertSame( 'raw-frame', $d['kit'] );
        $this->assertSame( [ 'language' => 'detected', 'kit' => 'given' ], $d['source'] );

        $d = Detector::detect( $page, 'editorial', null );
        $this->assertSame( 'quiet-solid', $d['kit'] );
        $this->assertSame( [ 'language' => 'given', 'kit' => 'detected' ], $d['source'] );
        $this->assertGreaterThan( 0, $d['confidence']['kit']['margin'] );
    }

    public function testAPageWithNothingKnownIsRefused(): void
    {
        $d = Detector::detect( '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"hi"}}}} /-->', null, null );
        $this->assertSame( Detector::REFUSED_NO_LANGUAGE, $d['refused'] );
        $this->assertNull( $d['language'] );
        $this->assertStringContainsString( 'from_language', (string) $d['message'] );
    }

    public function testDetectionIsDeterministic(): void
    {
        $page = self::build( 'features-bento', 'tech-mono', 'warm-pill' );
        $this->assertSame( Detector::detect( $page, null, null ), Detector::detect( $page, null, null ) );
    }
}
