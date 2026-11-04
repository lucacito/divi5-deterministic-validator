<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\MegaIcons;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

final class MegaIconsTest extends TestCase
{
    public function testTheSetHasUniqueIdsLabelsAndCodes(): void
    {
        $all = MegaIcons::all();
        $this->assertGreaterThanOrEqual( 40, count( $all ) );
        foreach ( [ 'id', 'label', 'code' ] as $key ) {
            $values = array_column( $all, $key );
            $this->assertSame( $values, array_values( array_unique( $values ) ), 'unique ' . $key );
        }
        foreach ( $all as $i ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $i['id'] );
            $this->assertMatchesRegularExpression( '/^f[0-9a-f]{3}$/', $i['code'], $i['id'] . ' is a Font Awesome 5 private-use code' );
            $this->assertNotSame( '', $i['label'] );
            $this->assertSame( '&#x' . $i['code'] . ';', MegaIcons::unicode( $i['id'] ) );
            $this->assertSame( $i['label'], MegaIcons::label( $i['id'] ) );
        }
    }

    public function testTheDefaultIsInTheSetAndIsTheOldArrow(): void
    {
        $this->assertTrue( MegaIcons::exists( MegaIcons::DEFAULT ) );
        $this->assertSame( \AiEditorDivi5\Pro\MegaMenu\PanelBuilder::GLYPH, MegaIcons::unicode( MegaIcons::DEFAULT ) );
    }

    public function testUnknownIdsAreNotInTheSet(): void
    {
        foreach ( [ '', 'nope', 'Star', 'star ', 'f061', '../star' ] as $bad ) {
            $this->assertFalse( MegaIcons::exists( $bad ), $bad );
            $this->assertNull( MegaIcons::unicode( $bad ) );
        }
    }

    public function testGroupsKeepEveryIconOnceInTheOrderOfTheSet(): void
    {
        $flat = [];
        foreach ( MegaIcons::groups() as $group => $icons ) {
            $this->assertNotSame( '', $group );
            foreach ( $icons as $i ) {
                $flat[] = $i['id'];
            }
        }
        $this->assertSame( array_column( MegaIcons::all(), 'id' ), $flat );
    }
}
