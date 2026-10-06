<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleReferences;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';

class StyleReferencesTest extends TestCase
{
    private const KNOWN_PRESET  = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';
    private const UNKNOWN       = '11111111-2222-4333-8444-555555555555';
    private const KNOWN_COLOR   = 'gcid-3e93bdc5-146d-448e-a761-9f1b4dd51113';
    private const UNKNOWN_COLOR = 'gcid-00000000-0000-4000-8000-000000000000';
    private const KNOWN_VAR     = 'gvid-f7rcuxrcdb';
    private const UNKNOWN_VAR   = 'gvid-00000000-0000-4000-8000-000000000000';

    private function known(): array
    {
        return [ 'presets' => [ self::KNOWN_PRESET => true ], 'colors' => [ self::KNOWN_COLOR => true, 'gcid-inactive' => true ] ];
    }

    private function heading( array $extra ): string
    {
        $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hi' ] ] ], 'builderVersion' => '5.14.0' ];

        return '<!-- wp:divi/heading ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ' /-->';
    }

    private function layout( string ...$blocks ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . implode( '', $blocks )
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function colorAttr( string $id ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable(' . json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new \stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
    }

    private function varAttr( string $id ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new \stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
    }

    /** The Custom Field binding as the builder stores it (a real export): name post_meta_key, the field in settings.select_meta_key. */
    private function legacyBinding( string $field ): string
    {
        return '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';
    }

    private function simpleBinding( string $field ): string
    {
        return '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'custom_meta_' . $field, 'settings' => new \stdClass() ] ] ) . ')$';
    }

    private function boundHeading( string $binding ): string
    {
        return $this->heading( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $binding ] ] ] ] );
    }

    /** @return array{presets: ?array<string,true>, colors: ?array<string,true>, custom_fields: callable} */
    private function knownWithFields( array $knownFields ): array
    {
        return [ 'presets' => null, 'colors' => null, 'custom_fields' => static fn ( string $k ): bool => in_array( $k, $knownFields, true ) ];
    }

    public function testACitedCustomFieldTheSiteDoesNotHaveIsReportedInBothBindingForms(): void
    {
        $content = $this->layout(
            $this->boundHeading( $this->legacyBinding( 'team_name' ) ),
            $this->boundHeading( $this->legacyBinding( 'gone_field' ) ),
            $this->boundHeading( $this->simpleBinding( 'gone_field' ) ),
            $this->boundHeading( $this->simpleBinding( 'other_gone' ) )
        );
        $advice  = StyleReferences::advise( $content, $this->knownWithFields( [ 'team_name' ] ) );

        $this->assertSame( [ [ 'id' => 'gone_field', 'count' => 2 ], [ 'id' => 'other_gone', 'count' => 1 ] ], $advice['unknown_custom_fields'] );
        $this->assertSame( [], $advice['unknown_presets'] );
        $this->assertSame( StyleReferences::MESSAGE, $advice['message'] );
    }

    public function testCustomFieldBindingsInAnyAttributeSlotAreRead(): void
    {
        $image  = '<!-- wp:divi/image ' . json_encode( [ 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'src' => $this->legacyBinding( 'photo_gone' ), 'id' => '', 'alt' => '' ] ] ] ], 'builderVersion' => '5.14.0' ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ' /-->';
        $button = '<!-- wp:divi/button ' . json_encode( [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Go', 'linkUrl' => $this->legacyBinding( 'link_gone' ) ] ] ] ], 'builderVersion' => '5.14.0' ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ' /-->';
        $advice = StyleReferences::advise( $this->layout( $image, $button ), $this->knownWithFields( [] ) );

        $this->assertSame( [ [ 'id' => 'photo_gone', 'count' => 1 ], [ 'id' => 'link_gone', 'count' => 1 ] ], $advice['unknown_custom_fields'] );
    }

    public function testAManualEntryCitesTheFieldNameInMetaKey(): void
    {
        $manual = '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'select_meta_key' => 'custom_meta_manual_custom_field_value', 'meta_key' => 'typed_by_hand' ] ] ] ) . ')$';
        $advice = StyleReferences::advise( $this->layout( $this->boundHeading( $manual ) ), $this->knownWithFields( [] ) );

        $this->assertSame( [ [ 'id' => 'typed_by_hand', 'count' => 1 ] ], $advice['unknown_custom_fields'] );
    }

    public function testBuiltInDynamicSourcesAndOtherVariablesAreNotCustomFields(): void
    {
        $title   = '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_title', 'settings' => [ 'before' => '<h1>', 'after' => '</h1>' ] ] ] ) . ')$';
        $featured = '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_featured_image', 'settings' => new \stdClass() ] ] ) . ')$';
        $empty   = '$variable(' . json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'select_meta_key' => '', 'meta_key' => '' ] ] ] ) . ')$';
        $content = $this->layout( $this->boundHeading( $title ), $this->boundHeading( $featured ), $this->boundHeading( $empty ), $this->heading( $this->colorAttr( self::KNOWN_COLOR ) ) );

        $this->assertNull( StyleReferences::advise( $content, $this->knownWithFields( [] ) + [ 'colors' => [ self::KNOWN_COLOR => true ] ] ) );
    }

    public function testNothingIsReportedWhenCustomFieldsCannotBeChecked(): void
    {
        $content = $this->layout( $this->boundHeading( $this->legacyBinding( 'gone_field' ) ) );

        $this->assertNull( StyleReferences::advise( $content, [ 'presets' => null, 'colors' => null, 'custom_fields' => null ] ) );
        $this->assertNull( StyleReferences::advise( $content, [ 'presets' => null, 'colors' => null ] ) );
    }

    public function testCleanLayoutGivesNull(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::KNOWN_PRESET ] ] + $this->colorAttr( self::KNOWN_COLOR ) ) );
        $this->assertNull( StyleReferences::advise( $content, $this->known() ) );
    }

    public function testDefaultAndEmptyPresetsAreNeverUnknown(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ 'default' ] ] ), $this->heading( [ 'modulePreset' => '' ] ), $this->heading( [] ) );
        $this->assertNull( StyleReferences::advise( $content, $this->known() ) );
    }

    public function testUnknownPresetListAndStringFormsAreCountedAcrossBlocks(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ), $this->heading( [ 'modulePreset' => self::UNKNOWN ] ), $this->heading( [ 'modulePreset' => [ 'default', 'other-id' ] ] ) );
        $advice  = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ [ 'id' => self::UNKNOWN, 'count' => 2 ], [ 'id' => 'other-id', 'count' => 1 ] ], $advice['unknown_presets'] );
        $this->assertSame( [], $advice['unknown_colors'] );
        $this->assertSame( StyleReferences::MESSAGE, $advice['message'] );
    }

    public function testUnknownColorInsideAVariableTokenIsFoundButKnownAndInactiveAreNot(): void
    {
        $content = $this->layout(
            $this->heading( $this->colorAttr( self::UNKNOWN_COLOR ) ),
            $this->heading( $this->colorAttr( self::KNOWN_COLOR ) ),
            $this->heading( $this->colorAttr( 'gcid-inactive' ) )
        );
        $advice = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_COLOR, 'count' => 1 ] ], $advice['unknown_colors'] );
        $this->assertSame( [], $advice['unknown_presets'] );
    }

    public function testABareColorIdAndAVarReferenceAreFoundToo(): void
    {
        $content = $this->layout( $this->heading( [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => 'gcid-bare-one', 'other' => 'var(--gcid-var-two)' ] ] ] ] ] ] ) );
        $advice  = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ 'gcid-bare-one', 'gcid-var-two' ], array_column( $advice['unknown_colors'], 'id' ) );
    }

    public function testAPartTheSiteCannotReadIsNeverFlagged(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] + $this->colorAttr( self::UNKNOWN_COLOR ) ) );
        $onlyColors = StyleReferences::advise( $content, [ 'presets' => null, 'colors' => [] ] );
        $this->assertSame( [], $onlyColors['unknown_presets'] );
        $this->assertCount( 1, $onlyColors['unknown_colors'] );
        $onlyPresets = StyleReferences::advise( $content, [ 'presets' => [], 'colors' => null ] );
        $this->assertSame( [], $onlyPresets['unknown_colors'] );
        $this->assertCount( 1, $onlyPresets['unknown_presets'] );
        $this->assertNull( StyleReferences::advise( $content, [ 'presets' => null, 'colors' => null ] ) );
    }

    public function testAtMostTwentyIdsPerListAndLongIdsAreCut(): void
    {
        $blocks = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $blocks[] = $this->heading( [ 'modulePreset' => [ "p{$i}" ] ] );
        }
        $blocks[] = $this->heading( [ 'modulePreset' => [ str_repeat( 'x', 300 ) ] ] );
        $advice = StyleReferences::advise( $this->layout( ...$blocks ), $this->known() );
        $this->assertCount( StyleReferences::MAX_IDS, $advice['unknown_presets'] );

        $only = StyleReferences::advise( $this->layout( $this->heading( [ 'modulePreset' => [ str_repeat( 'é', 300 ) ] ] ) ), $this->known() );
        $this->assertSame( str_repeat( 'é', 100 ), $only['unknown_presets'][0]['id'] );
        $this->assertNotFalse( json_encode( $only ) );
    }

    public function testUnparseableContentGivesNullAndNeverThrows(): void
    {
        $this->assertNull( StyleReferences::advise( '<!-- wp:divi/section {bad json} -->', $this->known() ) );
        $this->assertNull( StyleReferences::advise( '', $this->known() ) );
    }

    public function testAdviseNeverChangesItsInputsAndIsDeterministic(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ) );
        $known   = $this->known();
        $copyC   = $content;
        $copyK   = $known;
        $a = StyleReferences::advise( $content, $known );
        $b = StyleReferences::advise( $content, $known );
        $this->assertSame( $a, $b );
        $this->assertSame( $copyC, $content );
        $this->assertSame( $copyK, $known );
    }

    public function testAnUnknownVariableIsFoundCountedAndBoundedButKnownAndArchivedAreNot(): void
    {
        $known   = $this->known() + [ 'variables' => [ self::KNOWN_VAR => true, 'gvid-archived' => true ] ];
        $content = $this->layout(
            $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ),
            $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ),
            $this->heading( $this->varAttr( self::KNOWN_VAR ) ),
            $this->heading( $this->varAttr( 'gvid-archived' ) )
        );
        $advice = StyleReferences::advise( $content, $known );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_VAR, 'count' => 2 ] ], $advice['unknown_variables'] );
        $this->assertSame( [], $advice['unknown_presets'] );
        $this->assertSame( [], $advice['unknown_colors'] );
        $this->assertSame( StyleReferences::MESSAGE, $advice['message'] );
        $this->assertNull( StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( self::KNOWN_VAR ) ) ), $known ) );
    }

    public function testARawCssVariableReferenceIsCaughtToo(): void
    {
        $known   = $this->known() + [ 'variables' => [ self::KNOWN_VAR => true ] ];
        $content = $this->layout( $this->heading( [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => 'var(--' . self::UNKNOWN_VAR . ')' ] ] ] ] ] ] ] ) );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_VAR, 'count' => 1 ] ], StyleReferences::advise( $content, $known )['unknown_variables'] );
    }

    public function testWhenTheVariablesReaderCannotBeReadNothingIsFlaggedForVariablesButOtherAdviceStays(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] + $this->varAttr( self::UNKNOWN_VAR ) ) );
        foreach ( [ $this->known(), $this->known() + [ 'variables' => null ] ] as $known ) {
            $advice = StyleReferences::advise( $content, $known );
            $this->assertSame( [ self::UNKNOWN ], array_column( $advice['unknown_presets'], 'id' ) );
            $this->assertSame( [], $advice['unknown_variables'] );
        }
        $this->assertNull( StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( self::UNKNOWN_VAR ) ) ), [ 'presets' => null, 'colors' => null, 'variables' => null ] ) );
    }

    public function testMoreThanTwentyUnknownVariablesAreCappedAndLongIdsCut(): void
    {
        $blocks = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $blocks[] = $this->heading( $this->varAttr( 'gvid-missing-' . $i ) );
        }
        $blocks[] = $this->heading( $this->varAttr( 'gvid-' . str_repeat( 'a', 150 ) ) );
        $advice   = StyleReferences::advise( $this->layout( ...$blocks ), $this->known() + [ 'variables' => [] ] );
        $this->assertCount( StyleReferences::MAX_IDS, $advice['unknown_variables'] );
        $long = StyleReferences::advise( $this->layout( $this->heading( $this->varAttr( 'gvid-' . str_repeat( 'a', 150 ) ) ) ), $this->known() + [ 'variables' => [] ] );
        $this->assertSame( 100, strlen( $long['unknown_variables'][0]['id'] ) );
    }

    public function testAttachLeavesAPayloadUntouchedWhenCleanAndAddsTheAdvisoryWhenNot(): void
    {
        $GLOBALS['__divi_presets'] = [ 'module' => [ 'divi/heading' => [ 'default' => '', 'items' => [ self::KNOWN_PRESET => [ 'id' => self::KNOWN_PRESET, 'name' => 'H' ] ] ] ] ];
        $GLOBALS['__divi_colors']  = [ self::KNOWN_COLOR => [ 'color' => '#fff', 'status' => 'active' ] ];
        try {
            $payload = [ 'valid' => true, 'violations' => [] ];
            $clean   = $this->layout( $this->heading( [ 'modulePreset' => [ self::KNOWN_PRESET ] ] ) );
            $this->assertSame( $payload, StyleReferences::attach( $payload, $clean ) );
            $this->assertSame( json_encode( $payload ), json_encode( StyleReferences::attach( $payload, $clean ) ) );

            $dirty  = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ) );
            $result = StyleReferences::attach( $payload, $dirty );
            $this->assertTrue( $result['valid'] );
            $this->assertSame( self::UNKNOWN, $result['advisories']['style_references']['unknown_presets'][0]['id'] );
        } finally {
            unset( $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'] );
        }
    }
}
