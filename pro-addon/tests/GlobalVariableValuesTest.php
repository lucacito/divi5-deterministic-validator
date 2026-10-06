<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpBuildShims.php';

/** The values reach the site's generated CSS: a strict whitelist per type. */
class GlobalVariableValuesTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function goodNumbers(): array
    {
        $cases = [ '12px', '1.5rem', '0', '-4px', '.5em', '100%', '1.5', 'clamp(1rem, 2vw + 1rem, 3rem)', 'calc(100% - 2rem)', 'min(10px,5vw)', 'max(1px, calc(2px * 3))', 'calc((1px + 2px) * 3)', 'calc(calc(calc(calc(1px))))', '10svh', '2ch' ];

        return array_combine( $cases, array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    /** @return array<string,array{string}> */
    public static function badNumbers(): array
    {
        $cases = [ '', ' 12px', '12px ', '12 px', '12px;', 'calc(1px; x)', 'url(x)', 'var(--x)', '12px !important', '12pt', '1e3px', 'calc(1px', 'calc(1px))', 'clamp(1px, 2px)', 'clamp(1px,2px,3px,4px)', 'min()', '(1px)', 'calc(calc(calc(calc(calc(1px)))))', str_repeat( '1', 101 ), "12px\n", 'red', 'calc(1px + var(--a))', '{12px}', "12px\0", 'calc(1px, 2px)', '1px 2px', 'calc()', 'calc(1px +)', '--1px', '+1px', '12px"', "12px'", '12px\\' ];

        return array_combine( array_map( static fn ( string $c ): string => '' === $c ? '(empty)' : (string) json_encode( $c, JSON_INVALID_UTF8_SUBSTITUTE ), $cases ), array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    #[DataProvider( 'goodNumbers' )]
    public function testANumberValueIsAccepted( string $value ): void
    {
        $this->assertTrue( GlobalVariables::validNumber( $value ), $value );
        $this->assertTrue( GlobalVariables::valueFits( 'numbers', $value ) );
    }

    #[DataProvider( 'badNumbers' )]
    public function testANumberValueIsRefused( string $value ): void
    {
        $this->assertFalse( GlobalVariables::validNumber( $value ) );
        $this->assertFalse( GlobalVariables::valueFits( 'numbers', $value ) );
    }

    /** @return array<string,array{string}> */
    public static function goodStrings(): array
    {
        $cases = [ 'Call us today', '555 0100', 'Built with care. Since 1999!', "Caf\u{E9} \u{2615} na\u{EF}ve", 'a', '$99 off', '50% off (this week)', 'Tom & Jerry' ];

        return array_combine( $cases, array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    /** @return array<string,array{string}> */
    public static function badStrings(): array
    {
        $cases = [ '', ' x', 'x ', 'a{b}', 'a;b', 'a\\b', 'a<b>', 'a"b', "a'b", 'a`b', "a\nb", "a\0b", 'url(x)', 'URL (x)', '@import x', 'Expression(1)', '$variable({})$', '$VARIABLE({})$', str_repeat( 'a', 201 ), "bad \xC3\x28 utf8", "tab\there" ];

        return array_combine( array_map( static fn ( string $c ): string => '' === $c ? '(empty)' : (string) json_encode( $c, JSON_INVALID_UTF8_SUBSTITUTE ), $cases ), array_map( static fn ( string $c ): array => [ $c ], $cases ) );
    }

    #[DataProvider( 'goodStrings' )]
    public function testAStringValueIsAccepted( string $value ): void
    {
        $this->assertTrue( GlobalVariables::validString( $value ), $value );
        $this->assertTrue( GlobalVariables::valueFits( 'strings', $value ) );
    }

    #[DataProvider( 'badStrings' )]
    public function testAStringValueIsRefused( string $value ): void
    {
        $this->assertFalse( GlobalVariables::validString( $value ) );
        $this->assertFalse( GlobalVariables::valueFits( 'strings', $value ) );
    }

    public function testTheLimitsAreExactlyTheSpecs(): void
    {
        $this->assertTrue( GlobalVariables::validString( str_repeat( 'a', 200 ) ) );
        $this->assertTrue( GlobalVariables::validString( str_repeat( "\u{E9}", 200 ) ), '200 characters, not 200 bytes' );
        $this->assertTrue( GlobalVariables::validNumber( '1' . str_repeat( '0', 99 ) ) );
    }

    public function testOnlyNumbersAndStringsHaveARule(): void
    {
        foreach ( [ 'images', 'links', 'fonts', 'gradients', 'colors', '' ] as $type ) {
            $this->assertFalse( GlobalVariables::valueFits( $type, '12px' ), $type );
        }
    }
}
