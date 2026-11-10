<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Compat;
use AiEditorDivi5\Pro\Lab\LabSupport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

final class LabSupportTest extends TestCase
{
    public function testAllPresentIsSupported(): void
    {
        $this->assertNull( LabSupport::unsupported( 'lab_restyle' ) );
        $this->assertNull( LabSupport::unsupported( "build_site's brief option" ) );
    }

    public function testAMissingClassOrMethodGivesTheExactMessage(): void
    {
        $expected = 'lab_restyle needs a newer version of the free plugin "JHMG AI Editor for Divi 5" (one with Divi 5 Lab). Update the free plugin; the other Pro tools keep working.';
        $this->assertSame( $expected, LabSupport::message( 'lab_restyle' ) );

        $noStore = static fn ( string $c ): bool => $c !== 'AiEditorDivi5\\WP\\StudioStore' && class_exists( $c );
        $this->assertSame( $expected, LabSupport::unsupported( 'lab_restyle', \Closure::fromCallable( $noStore ) ) );

        $noLinked = static fn ( string $c, string $m ): bool => ! ( $c === 'AiEditorDivi5\\WP\\StudioStore' && $m === 'linked' ) && method_exists( $c, $m );
        $this->assertSame( $expected, LabSupport::unsupported( 'lab_restyle', null, \Closure::fromCallable( $noLinked ) ) );

        $this->assertStringStartsWith( "build_site's brief option needs a newer version", LabSupport::message( "build_site's brief option" ) );
    }

    public function testNoneOfItIsInCompat(): void
    {
        // Only HistoryService is in both: it exists in every free version Pro supports. Every other Lab class is new in the free
        // plugin, so none of them may be in Compat (that would switch every Pro tool off on an older one).
        $this->assertSame(
            [ 'AiEditorDivi5\\WP\\HistoryService' ],
            array_values( array_intersect( LabSupport::REQUIRED_CLASSES, Compat::REQUIRED_CLASSES ) )
        );
        $this->assertContains( 'AiEditorDivi5\\WP\\StudioCompiler', LabSupport::REQUIRED_CLASSES );
    }

    public function testEveryRequiredMethodExistsInTheFreePlugin(): void
    {
        foreach ( LabSupport::REQUIRED_METHODS as [ $class, $method ] ) {
            $this->assertTrue( method_exists( $class, $method ), $class . '::' . $method );
        }
    }
}
