<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DiviMarkup;
use AiEditorDivi5\WP\ImageGuide;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\SiteGuide;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

foreach ( [ 'StyleGuide', 'ImagePack', 'ImageTokens', 'ImageGuide', 'SiteGuide', 'SectionRecipes', 'DiviMarkup' ] as $guideShapesClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $guideShapesClass . '.php';
}

/**
 * The per-device, equal-size-image and HTML-conversion teaching of the guides. The shapes themselves were proven on the
 * real Divi by measuring rendered pages (scripts/verify-guide-shapes.cjs, `make verify-guide-shapes`); these tests keep the
 * text honest: every example validates, the exact attribute paths are present, and the converter writes what the guide teaches.
 */
final class GuideShapesTest extends TestCase
{
    private const V = '5.14.0';

    /** @param array<string,mixed> $attrs */
    private function page( string $name, array $attrs ): string
    {
        return '<!-- wp:divi/placeholder -->' . $this->block( 'section', [ 'builderVersion' => self::V ], $this->block( 'row', [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => self::V ],
            $this->block( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => self::V ], $this->block( $name, $attrs + [ 'builderVersion' => self::V ], null ) ) ) ) . '<!-- /wp:divi/placeholder -->';
    }

    /** @param array<string,mixed> $attrs */
    private function block( string $name, array $attrs, ?string $inner ): string
    {
        $j = DiviMarkup::attributes( $attrs );

        return null === $inner ? '<!-- wp:divi/' . $name . ' ' . $j . ' /-->' : '<!-- wp:divi/' . $name . ' ' . $j . ' -->' . $inner . '<!-- /wp:divi/' . $name . ' -->';
    }

    private function assertValid( string $layout ): void
    {
        $r = ( new Validator() )->validateContent( $layout );
        $this->assertSame( [], array_map( static fn( $v ) => $v->toArray(), $r->violations() ) );
    }

    public function testTheHeadlineSizePerDeviceExampleValidatesAndIsInTheGuide(): void
    {
        $this->assertValid( $this->page( 'heading', [ 'title' => [
            'innerContent' => [ 'desktop' => [ 'value' => 'Hello' ] ],
            'decoration'   => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '48px' ] ], 'tablet' => [ 'value' => [ 'size' => '36px' ] ], 'phone' => [ 'value' => [ 'size' => '24px' ] ] ] ] ],
        ] ] ) );
        $g = StyleGuide::markdown();
        foreach ( [ '## Per-device (responsive) settings', 'title.decoration.font.font.desktop.value.size', '"48px"', '"36px"', '"24px"', 'make verify-guide-shapes' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
    }

    public function testTextSizeAlignmentAndSpacingExamplesValidateAndAreInTheGuide(): void
    {
        $this->assertValid( $this->page( 'text', [
            'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>x</p>' ] ], 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [
                'desktop' => [ 'value' => [ 'size' => '20px', 'textAlign' => 'center' ] ],
                'phone'   => [ 'value' => [ 'size' => '15px', 'textAlign' => 'left' ] ],
            ] ] ] ] ],
            'module'  => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '60px' ] ] ], 'phone' => [ 'value' => [ 'padding' => [ 'top' => '20px' ] ] ] ] ] ],
        ] ) );
        $g = StyleGuide::markdown();
        foreach ( [ 'content.decoration.bodyFont.body.font.{bp}.value.size', '.textAlign', 'module.decoration.spacing.{bp}.value.padding.top' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
    }

    public function testTheStickyAndScrollEffectExamplesValidateAndAreInTheGuide(): void
    {
        foreach ( [
            [ 'sticky', [ 'desktop' => [ 'value' => [ 'position' => 'top', 'offset' => [ 'top' => '20px' ] ] ] ] ],
            [ 'sticky', [ 'desktop' => [ 'value' => [ 'position' => 'topBottom' ] ] ] ],
            [ 'scroll', [ 'desktop' => [ 'value' => [ 'verticalMotion' => [ 'enable' => 'on' ] ] ] ] ],
            [ 'scroll', [ 'desktop' => [ 'value' => [ 'fade' => [ 'enable' => 'on' ] ] ] ] ],
        ] as [ $key, $value ] ) {
            $this->assertValid( '<!-- wp:divi/placeholder -->' . $this->block( 'section', [ 'module' => [ 'decoration' => [ $key => $value ] ], 'builderVersion' => '5.15.1' ], '' ) . '<!-- /wp:divi/placeholder -->' );
        }
        $g = StyleGuide::markdown();
        foreach ( [ 'module.decoration.sticky.{bp}.value.position', '"topBottom"', 'offset.top', 'module.decoration.scroll.{bp}.value.verticalMotion.enable', '`fade`', 'Do not guess other names' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
    }

    /** @return array<string,array{0:int,1:string,2:string,3:list<string>,4:array<string,string>}> */
    public static function rows(): array
    {
        return [
            'two'   => [ 2, '1_2,1_2', 'equal-columns_2', [ 'phone', 'phoneWide' ], [ 'desktop' => '12_24', 'phone' => '24_24', 'phoneWide' => '24_24' ] ],
            'three' => [ 3, '1_3,1_3,1_3', 'equal-columns_3', [ 'phone', 'phoneWide' ], [ 'desktop' => '8_24', 'phone' => '24_24', 'phoneWide' => '24_24' ] ],
            'four'  => [ 4, '1_4,1_4,1_4,1_4', 'equal-columns_4', [ 'tablet', 'tabletWide' ], [ 'desktop' => '6_24', 'tablet' => '12_24', 'phone' => '24_24', 'tabletWide' => '12_24', 'phoneWide' => '24_24' ] ],
        ];
    }

    /**
     * @param list<string>         $wrap
     * @param array<string,string> $widths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function testTheStackingShapesInTheGuideAreWhatTheConverterWrites( int $n, string $structure, string $flex, array $wrap, array $widths ): void
    {
        $g   = StyleGuide::markdown();
        $row = DiviMarkup::row( array_fill( 0, $n, '' ) );

        $this->assertStringContainsString( '"' . $structure . '"', $g );
        $this->assertStringContainsString( 'equal-columns_N', $g );
        $this->assertStringContainsString( '"columnStructure":{"desktop":{"value":"' . $structure . '"}}', $row );
        $this->assertStringContainsString( '"flexColumnStructure":{"desktop":{"value":"' . $flex . '"}}', $row );
        foreach ( $wrap as $bp ) {
            $this->assertStringContainsString( '"' . $bp . '":{"value":{"flexWrap":"wrap"}}', $row );
        }
        foreach ( $widths as $bp => $flexType ) {
            $this->assertStringContainsString( '"' . $bp . '":{"value":{"flexType":"' . $flexType . '"}}', $row, $bp );
            $this->assertStringContainsString( $flexType, $g, 'the guide names the width ' . $flexType );
        }
        $this->assertValid( '<!-- wp:divi/placeholder -->' . DiviMarkup::section( '', $row ) . '<!-- /wp:divi/placeholder -->' );
    }

    public function testTheEqualSizeImageExampleValidatesAndIsInTheGuide(): void
    {
        $image = [ 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'src' => 'https://example.com/a.png', 'alt' => 'a' ] ] ] ],
                   'module' => [ 'advanced' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'aspectRatio' => [ 'width' => '4', 'height' => '3' ] ] ] ] ] ] ];
        $image['image']['decoration'] = [ 'fit' => [ 'desktop' => [ 'value' => [ 'objectFit' => 'cover' ] ] ] ];
        $this->assertValid( $this->page( 'image', $image ) );

        $g = ImageGuide::markdown();
        foreach ( [ 'module.advanced.sizing.desktop.value.aspectRatio', 'image.decoration.fit.desktop.value.objectFit', 'an object, not a string' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
        $this->assertStringNotContainsString( 'decoration.fit.{bp}.value.type', $g, 'the old, wrong shape is gone' );
        $this->assertStringNotContainsString( 'forceFullwidth: true', $g );
    }

    public function testTheHtmlSectionTeachesTheToolAndNothingUnsafe(): void
    {
        $g = SiteGuide::markdown();
        $this->assertStringContainsString( '## 5. Turn existing HTML into Divi 5 pages', $g );
        foreach ( [ 'html_to_divi', 'create=true', 'text_check.equal', 'links.lost', 'list_media_images', 'get_style_guide', 'list_global_styles', 'URL-to-Divi converter', 'contact-form recipe' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
        $this->assertContains( 'contact-form', SectionRecipes::names(), 'a recipe the section names exists' );
        foreach ( [ 'freeForm', 'mainElement', '<style', '<script', 'inline style' ] as $bad ) {
            $this->assertStringNotContainsString( $bad, $g, $bad );
        }
        $this->assertStringNotContainsString( 'rebuild it by hand', $g, 'the old "no automatic conversion" text is gone' );
    }
}
