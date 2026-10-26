<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Design switch, Task 3 (Review Focus 2; controller Ruling 2 and the Global Constraint "No Divi schema from memory"): the
 * engine writes no attribute path, colour or patch of its own. Every `pro-addon/src/Design/*.php` file is scanned for an
 * attribute path segment (`decoration.`, `advanced.`), a hex colour (`#` + 3 to 6 hex digits) and `rgba(`.
 * `MarkerSpace.php` is exempt only inside its five copied `KitResolver` constants (pinned to the free values by
 * `MarkerSpaceTest`); the rest of that file is scanned like every other.
 */
class DesignSourceRulesTest extends TestCase
{
    private const DIR = __DIR__ . '/../src/Design';

    private const BANNED = [
        'an attribute path (decoration.)' => '/decoration\./',
        'an attribute path (advanced.)'   => '/advanced\./',
        'a hex colour'                    => '/#[0-9a-f]{3,6}/i',
        'an rgba() colour'                => '/rgba\(/i',
    ];

    private const COPIED = [ 'ROLE_BLOCKS', 'ROLE_SURFACES', 'ROLE_GROUNDS', 'GROUND_SURFACES', 'GROUND_BLOCKS' ];

    public function testNoDesignFileSpellsAnAttributePathOrHex(): void
    {
        $files = glob( self::DIR . '/*.php' ) ?: [];
        sort( $files );
        $names = array_map( 'basename', $files );
        foreach ( [ 'Bands.php', 'Design.php', 'Expectations.php', 'JsonLeaves.php', 'Leaves.php', 'MarkerRecovery.php', 'MarkerSpace.php', 'PageScan.php' ] as $known ) {
            $this->assertContains( $known, $names, 'the scan covers ' . $known );
        }
        $found = [];
        foreach ( $files as $file ) {
            $text = (string) file_get_contents( $file );
            if ( 'MarkerSpace.php' === basename( $file ) ) {
                $text = self::withoutCopiedConstants( $text );
            }
            foreach ( self::BANNED as $what => $re ) {
                if ( preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $m[0] as [ $hit, $at ] ) {
                        $found[] = basename( $file ) . ':' . ( substr_count( $text, "\n", 0, $at ) + 1 ) . ' ' . $what . ' "' . $hit . '"';
                    }
                }
            }
        }
        $this->assertSame( [], $found );
    }

    public function testTheScanCatchesWhatItBans(): void
    {
        foreach ( [ "'module.decoration.border'", '"advanced.text"', "'#fff'", '"#1A2B3C"', "'rgba(0,0,0,.5)'" ] as $sample ) {
            $hit = false;
            foreach ( self::BANNED as $re ) {
                $hit = $hit || 1 === preg_match( $re, $sample );
            }
            $this->assertTrue( $hit, $sample );
        }
    }

    public function testTheExemptionIsOnlyTheFiveConstants(): void
    {
        $text    = (string) file_get_contents( self::DIR . '/MarkerSpace.php' );
        $without = self::withoutCopiedConstants( $text );
        foreach ( self::COPIED as $name ) {
            $this->assertStringContainsString( 'public const ' . $name . ' =', $text );
            $this->assertStringNotContainsString( 'public const ' . $name . ' =', $without, $name . ' removed by the exemption' );
        }
        $this->assertStringContainsString( 'function kitCandidates', $without, 'the rest of the file is still scanned' );
        $this->assertStringContainsString( 'function languageCandidates', $without );
    }

    /** MarkerSpace.php with the bodies of its five copied constants cut out (each runs to the first `];` after its name). */
    private static function withoutCopiedConstants( string $text ): string
    {
        foreach ( self::COPIED as $name ) {
            $text = (string) preg_replace( '/public const ' . $name . ' = (?:\[[^\n]*\];|\[\n.*?\n    \];)/s', '', $text, 1 );
        }

        return $text;
    }
}
