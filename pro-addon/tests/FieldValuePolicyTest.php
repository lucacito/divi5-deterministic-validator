<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\FieldValuePolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class FieldValuePolicyTest extends TestCase
{
    /** @return list<array{string}> */
    public static function clean(): array
    {
        return [ [ '' ], [ 'Hello' ], [ 'Café – naïve ✓ 日本語' ], [ '<p>Hi <strong>there</strong></p>' ], [ 'a lesson on sections' ], [ '5 < 6 and 7 > 3' ], [ "Line one\nLine two" ], [ 'C:\\path "quoted" \'single\'' ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'clean' )]
    public function testOrdinaryTextIsAllowed( string $value ): void
    {
        $this->assertNull( FieldValuePolicy::refusal( $value ) );
    }

    /** @return list<array{string,string}> */
    public static function code(): array
    {
        return [
            [ '<script>alert(1)</script>', 'script' ],
            [ '<SCRIPT src=x>', 'script' ],
            [ '< script>', 'script' ],
            [ '<style>body{}</style>', 'style' ],
            [ '<iframe src="x"></iframe>', 'iframe' ],
            [ '<object data="x">', 'object' ],
            [ '<embed src="x">', 'embed' ],
            [ '<?php echo 1; ?>', 'PHP tag' ],
            [ '<a href="javascript:alert(1)">x</a>', 'javascript' ],
            [ '<a href="  JaVaScRiPt :alert(1)">x</a>', 'javascript' ],
            [ 'vbscript:msgbox', 'javascript' ],
            [ 'data:text/html;base64,AAAA', 'javascript' ],
            [ '<img src=x onerror=alert(1)>', 'event' ],
            [ '<p onclick="x()">hi</p>', 'event' ],
            [ "<div\nonmouseover='x'>", 'event' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'code' )]
    public function testCustomCodeIsRefusedWithAReason( string $value, string $needle ): void
    {
        $reason = FieldValuePolicy::refusal( $value );
        $this->assertNotNull( $reason, $value );
        $this->assertStringContainsString( $needle, $reason );
    }

    /** @return list<array{string}> */
    public static function disguisedSchemes(): array
    {
        return [
            [ '&#106;avascript:alert(1)' ], [ '&#x6A;avascript:alert(1)' ], [ 'java&#x09;script:alert(1)' ], [ "java	script:alert(1)" ],
            [ "java
script:alert(1)" ], [ "java
 script :alert(1)" ], [ 'java&Tab;script:alert(1)' ], [ 'javascript&colon;alert(1)' ],
            [ 'data&colon;text/html,x' ], [ "data :
text/html,x" ], [ 'vb&#115;cript:x' ], [ "jav\0ascript:x" ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'disguisedSchemes' )]
    public function testDisguisedScriptSchemesAreRefused( string $value ): void
    {
        $this->assertStringContainsString( 'javascript', (string) FieldValuePolicy::refusal( $value ), $value );
    }

    public function testProseWithAUrlIsStillAllowed(): void
    {
        $this->assertNull( FieldValuePolicy::refusal( 'Visit https://example.test/ for the data: it is free' ) );
        $this->assertNull( FieldValuePolicy::refusal( 'Learn Java. Script writing tips: see the guide' ) );
    }

    public function testAPatternThatCannotBeEvaluatedRefusesInsteadOfAllowing(): void
    {
        $scan = new \ReflectionMethod( FieldValuePolicy::class, 'scan' );
        set_error_handler( static fn (): bool => true ); // preg_match() warns about the deliberately broken pattern
        try {
            $broken = $scan->invoke( null, 'a reason', '/(/', 'anything' );
        } finally {
            restore_error_handler();
        }
        $this->assertSame( 'could not be checked safely', $broken );
        $this->assertNull( $scan->invoke( null, 'a reason', '/zzz/', 'anything' ) );
        $this->assertSame( 'a reason', $scan->invoke( null, 'a reason', '/any/', 'anything' ) );
    }

    public function testTheLengthLimitIsInCharactersNotBytes(): void
    {
        $this->assertNull( FieldValuePolicy::refusal( str_repeat( 'é', FieldValuePolicy::MAX_CHARS ) ) );
        $this->assertNotNull( FieldValuePolicy::refusal( str_repeat( 'é', FieldValuePolicy::MAX_CHARS + 1 ) ) );
        $this->assertStringContainsString( '10,000', (string) FieldValuePolicy::refusal( str_repeat( 'a', FieldValuePolicy::MAX_CHARS + 1 ) ) );
    }

    public function testAnInvalidUtf8ValueIsRefused(): void
    {
        $this->assertNotNull( FieldValuePolicy::refusal( "bad \xB1\x31 bytes" ) );
    }
}
