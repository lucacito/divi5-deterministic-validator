<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\MegaMenuContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

final class MegaMenuContentTest extends TestCase
{
    /** @var array<int|string,mixed> */
    private array $store = [];

    private function content( array $images = [ 7 => [ 'url' => 'https://example.test/a.jpg', 'alt' => 'A team' ] ] ): MegaMenuContent
    {
        $this->store = [];

        return new MegaMenuContent(
            fn (): array => $this->store,
            function ( array $all ): void {
                $this->store = $all;
            },
            static fn ( int $id ): ?array => $images[ $id ] ?? null
        );
    }

    public function testNothingStoredMeansDefaultsAndNothingForTheBuilder(): void
    {
        $c = $this->content();
        $this->assertSame( [ 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'image' => 0, 'edits' => 'ask' ], $c->get( 5 ) );
        $this->assertFalse( $c->hasContent( 5 ) );
        $this->assertSame( [], $c->forBuilder() );
    }

    public function testSavingCleansEveryValue(): void
    {
        $c = $this->content();
        $saved = $c->save( 5, [ 'title' => "  <b>Our</b>\n  team  ", 'text' => str_repeat( 'x', 400 ), 'button' => 'Go <script>', 'url' => ' https://example.test/team/ ', 'image' => '7' ] );
        $this->assertSame( 'Our team', $saved['title'] );
        $this->assertSame( 280, mb_strlen( $saved['text'] ) );
        $this->assertSame( 'Go', $saved['button'] );
        $this->assertSame( 'https://example.test/team/', $saved['url'] );
        $this->assertSame( 7, $saved['image'] );
        $this->assertTrue( $c->hasContent( 5 ) );
        $this->assertSame( $saved, $this->store[5] );
    }

    #[DataProvider( 'badUrls' )]
    public function testOnlyHttpAndSitePathsAreKeptAsLinks( string $url, string $kept ): void
    {
        $this->assertSame( $kept, MegaMenuContent::normalise( [ 'url' => $url ] )['url'] );
    }

    public static function badUrls(): array
    {
        return [
            [ 'javascript:alert(1)', '' ], [ 'data:text/html,x', '' ], [ '//evil.test/x', '' ], [ 'mailto:a@b.c', '' ], [ 'ftp://x.test/', '' ],
            [ 'https://', '' ], [ 'not a url', '' ], [ 'https://a.test/"onmouseover=', '' ], [ "https://a.test/\nx", '' ],
            [ '/services/', '/services/' ], [ 'http://a.test/x?y=1#z', 'http://a.test/x?y=1#z' ], [ str_repeat( 'a', 301 ), '' ],
        ];
    }

    public function testNonStringsAreEmptyNotCast(): void
    {
        $e = MegaMenuContent::normalise( [ 'title' => [ 'x' ], 'text' => 5, 'button' => null, 'url' => [], 'image' => 'abc', 'edits' => 'maybe' ] );
        $this->assertSame( [ 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'image' => 0, 'edits' => 'ask' ], $e );
    }

    public function testAPictureThatIsNotAnImageIsNeverStored(): void
    {
        $c = $this->content();
        $this->assertSame( 0, $c->save( 5, [ 'title' => 'T', 'image' => 99 ] )['image'] );
    }

    public function testAnEntryThatIsEmptyAndDefaultIsRemoved(): void
    {
        $c = $this->content();
        $c->save( 5, [ 'title' => 'T' ] );
        $this->assertArrayHasKey( 5, $this->store );
        $c->save( 5, [] );
        $this->assertArrayNotHasKey( 5, $this->store );
    }

    public function testTheEditsChoiceIsKeptWhenTheContentIsSavedOrReset(): void
    {
        $c = $this->content();
        $c->setEdits( 5, 'keep' );
        $c->save( 5, [ 'title' => 'T' ] );
        $this->assertSame( 'keep', $c->get( 5 )['edits'] );
        $c->reset( 5 );
        $this->assertSame( [ 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'image' => 0, 'edits' => 'keep' ], $c->get( 5 ) );
        $this->assertFalse( $c->hasContent( 5 ), 'a choice alone is not content' );
        $this->assertSame( [], $c->forBuilder() );
        $c->setEdits( 5, 'ask' );
        $this->assertArrayNotHasKey( 5, $this->store, 'back to the defaults: nothing stored' );
    }

    public function testAnUnknownEditsChoiceIsRefused(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->content()->setEdits( 5, 'delete-everything' );
    }

    public function testTheBuilderGetsTheTextsAndTheResolvedPicture(): void
    {
        $c = $this->content();
        $c->save( 5, [ 'title' => 'Hello', 'image' => 7 ] );
        $c->save( 6, [ 'text' => 'Only text' ] );
        $c->setEdits( 8, 'keep' );
        $b = $c->forBuilder();
        $this->assertSame( [ 5, 6 ], array_keys( $b ) );
        $this->assertSame( [ 'url' => 'https://example.test/a.jpg', 'alt' => 'A team' ], $b[5]['image'] );
        $this->assertNull( $b[6]['image'] );
    }

    public function testAPictureThatVanishedFromTheLibraryIsDroppedAtBuildTime(): void
    {
        $c = $this->content();
        $c->save( 5, [ 'image' => 7 ] );
        $gone = new MegaMenuContent( fn (): array => $this->store, function ( array $a ): void { $this->store = $a; }, static fn ( int $id ): ?array => null );
        $this->assertSame( [], $gone->forBuilder() );
    }

    public function testEntriesOfOtherMenusStayUntouched(): void
    {
        $c = $this->content();
        $c->save( 100, [ 'title' => 'Old menu item' ] );
        $c->save( 5, [ 'title' => 'New menu item' ] );
        $this->assertSame( 'Old menu item', $c->get( 100 )['title'] );
    }

    public function testGarbageInTheOptionIsIgnored(): void
    {
        $c = new MegaMenuContent( static fn (): string => 'oops', static function (): void {} );
        $this->assertSame( [], $c->all() );
        $c = new MegaMenuContent( static fn (): array => [ 'x' => [ 'title' => 'a' ], 0 => [ 'title' => 'b' ], 5 => 'string', 6 => [ 'title' => 'ok' ] ], static function (): void {} );
        $this->assertSame( [ 6 ], array_keys( $c->all() ) );
    }
}
