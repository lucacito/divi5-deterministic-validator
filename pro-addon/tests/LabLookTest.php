<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Lab\LabLook;
use AiEditorDivi5\WP\DesignLanguages;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

final class LabLookTest extends TestCase
{
    /** @return array<string,mixed> */
    private function brief( string $language, ?string $kit = null ): array
    {
        $b = [ 'version' => 1, 'title' => 'T', 'goal' => 'g', 'language' => $language, 'sections' => [ [ 'recipe' => 'hero-cta' ] ] ];
        if ( $kit !== null ) {
            $b['kit'] = $kit;
        }

        return $b;
    }

    public function testOfNormalisesTheKit(): void
    {
        $look = LabLook::of( $this->brief( 'editorial' ) );
        $this->assertSame( [ 'language' => 'editorial', 'kit' => DesignLanguages::get( 'editorial' )['kit'] ], $look );
        $this->assertSame( 'raw-frame', LabLook::of( $this->brief( 'editorial', 'raw-frame' ) )['kit'] );
        $this->assertNull( LabLook::of( $this->brief( 'no-such-language' ) ) );
        $this->assertNull( LabLook::of( [ 'sections' => [] ] ) );
    }

    public function testWithKeepsDefaultsAndSets(): void
    {
        $b = $this->brief( 'editorial', 'raw-frame' );

        $same = LabLook::with( $b, null, null );
        $this->assertSame( $b, $same );

        $luxe = LabLook::with( $b, 'luxe', null );
        $this->assertSame( 'luxe', $luxe['language'] );
        $this->assertSame( 'raw-frame', $luxe['kit'] );
        $this->assertSame( $b['sections'], $luxe['sections'] );
        $this->assertSame( $b['title'], $luxe['title'] );

        $dropped = LabLook::with( $b, null, 'default' );
        $this->assertArrayNotHasKey( 'kit', $dropped );
        $this->assertSame( 'editorial', $dropped['language'] );
        $this->assertSame( $b['sections'], $dropped['sections'] );

        $set = LabLook::with( $this->brief( 'editorial' ), null, 'heavy-block' );
        $this->assertSame( 'heavy-block', $set['kit'] );
        // The input is not changed.
        $this->assertSame( 'raw-frame', $b['kit'] );
    }

    public function testSameComparesNormalisedLooks(): void
    {
        $own = DesignLanguages::get( 'editorial' )['kit'];
        $this->assertTrue( LabLook::same( $this->brief( 'editorial' ), $this->brief( 'editorial', $own ) ) );
        $this->assertFalse( LabLook::same( $this->brief( 'editorial' ), $this->brief( 'editorial', 'raw-frame' ) ) );
        $this->assertFalse( LabLook::same( $this->brief( 'editorial' ), $this->brief( 'luxe' ) ) );
        $this->assertFalse( LabLook::same( $this->brief( 'nope' ), $this->brief( 'nope' ) ), 'an unknown language has no look to compare' );
    }
}
