<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ComponentAdvisory;
use AiEditorDivi5\WP\ComponentReader;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ComponentReader.php';
require_once __DIR__ . '/../wp-plugin/src/ComponentAdvisory.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';

/**
 * Real page contents and component templates saved by the Divi 5.15.1 builder (fixtures/valid/component-*-5.15.json,
 * fixtures/components/library-5.15.json). Live proof of what Divi does with these edits: scripts/spike-component-edit.php.
 */
class ComponentAdvisoryTest extends TestCase
{
    private function fixture( string $name ): string
    {
        $fx = json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/valid/{$name}-5.15.json" ), true );

        return (string) $fx['post_content'];
    }

    private function template( string $key ): string
    {
        $lib = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/components/library-5.15.json' ), true );

        return (string) $lib['templates'][ $key ]['post_content'];
    }

    private function advise( string $content, ?string $previous, ?bool $exists = true, ?string $template = null ): ?array
    {
        return ComponentAdvisory::advise( $content, $previous, static fn ( string $id ): ?bool => $exists, static fn ( string $id ): ?string => $template );
    }

    public function testALayoutWithoutComponentsGetsNoAdvice(): void
    {
        $this->assertNull( $this->advise( '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->', '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' ) );
    }

    public function testAnInstanceOfAComponentTheSiteDoesNotHaveIsFlaggedButNothingElseIs(): void
    {
        $content = $this->fixture( 'component-instance-plain' );
        $a       = $this->advise( $content, null, false );
        $this->assertSame( [ [ 'id' => '229359', 'count' => 1 ] ], $a['unknown_components'] );
        $this->assertSame( [], $a['component_owned_edits'] );
        $this->assertStringContainsString( 'Slots', $a['message'] );
        $this->assertNull( $this->advise( $content, null, true ), 'a component that exists' );
        $this->assertNull( $this->advise( $content, null, null ), 'Divi cannot tell: say nothing rather than guess' );
    }

    public function testChangingTheTextOfABlockTheComponentOwnsIsFlagged(): void
    {
        $before = $this->fixture( 'component-instance-plain' );
        $after  = str_replace( 'Component probe', 'A better headline', $before );
        $a      = $this->advise( $after, $before, true, $this->template( 'section-with-slot' ) );
        $this->assertSame( [ [ 'component' => '229359', 'block' => 'divi/heading', 'sync_node_id' => 'fc2c5484-71bd-485c-86a1-0ed9b523183b', 'attributes' => [ 'title' ] ] ], $a['component_owned_edits'] );
        $this->assertSame( [], $a['unknown_components'] );
    }

    public function testAnUnchangedPageAndAnEditOutsideAnyComponentGetNoAdvice(): void
    {
        $before = $this->fixture( 'component-instance-plain' );
        $this->assertNull( $this->advise( $before, $before, true, $this->template( 'section-with-slot' ) ), 'nothing changed' );
        $plain = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"a"}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $this->assertNull( $this->advise( str_replace( '>a<', '>b<', $plain ), $plain ) );
    }

    public function testAnAttributeTheComponentMarksAsUnsyncedMayBeChanged(): void
    {
        $before = $this->fixture( 'component-instance-plain' );
        $after  = str_replace( 'Component probe', 'A better headline', $before );
        $tpl    = str_replace(
            '"title":{"innerContent":{"desktop":{"value":"Component probe"}}},"builderVersion":"5.15.1","syncNodeId":"fc2c5484-71bd-485c-86a1-0ed9b523183b","localAttrsMap":[]',
            '"title":{"innerContent":{"desktop":{"value":"Component probe"}}},"builderVersion":"5.15.1","syncNodeId":"fc2c5484-71bd-485c-86a1-0ed9b523183b","localAttrsMap":[{"attrName":"title","subName":""}]',
            $this->template( 'section-with-slot' )
        );
        $this->assertNotSame( $this->template( 'section-with-slot' ), $tpl, 'the test really marked the heading title as unsynced' );
        $this->assertNull( $this->advise( $after, $before, true, $tpl ) );
    }

    public function testASlotsContentIsTheInstancesOwnAndNeverFlagged(): void
    {
        $before = $this->fixture( 'component-instance-slot-filled' );
        $after  = str_replace( 'Slot Button', 'Buy now', $before );
        $this->assertNotSame( $before, $after );
        $this->assertNull( $this->advise( $after, $before, true, $this->template( 'section-with-slot' ) ) );
    }

    public function testChangingAModuleInstancesOwnAttributesIsFlaggedBecauseTheComponentDoesNotUnsyncThem(): void
    {
        $before = $this->fixture( 'component-instance-module' );
        $after  = str_replace( 'Module component probe', 'Another title', $before );
        $a      = $this->advise( $after, $before, true, $this->template( 'module' ) );
        $this->assertSame( [ [ 'component' => '229387', 'block' => 'divi/heading', 'sync_node_id' => null, 'attributes' => [ 'title' ] ] ], $a['component_owned_edits'] );
    }

    public function testAnUnreadableComponentOrNoStoredPageSaysNothingAboutEdits(): void
    {
        $before = $this->fixture( 'component-instance-plain' );
        $after  = str_replace( 'Component probe', 'X', $before );
        $this->assertNull( $this->advise( $after, $before, true, null ), 'the component cannot be read' );
        $this->assertNull( $this->advise( $after, null, true, $this->template( 'section-with-slot' ) ), 'a create or a validate has nothing to compare with' );
    }

    public function testTheReaderListsNameKindSlotsAndUseAndCapsTheList(): void
    {
        $tpl = $this->template( 'section-with-slot' );
        $r   = ComponentReader::discoverWith( [ [ 'id' => 5, 'title' => 'Hero', 'kind' => 'section', 'content' => $tpl, 'used_on' => 4 ], [ 'id' => 6, 'title' => 'Odd', 'kind' => 'weird', 'content' => 'x', 'used_on' => -2 ] ] );
        $this->assertSame( [ 'id' => 5, 'name' => 'Hero', 'kind' => 'section', 'slots' => [ '18e7a400-ee42-4cf5-808c-e8db870de7ce' ], 'used_on' => 4 ], $r['components'][0] );
        $this->assertSame( [ 'id' => 6, 'name' => 'Odd', 'kind' => '', 'slots' => [], 'used_on' => 0 ], $r['components'][1] );
        $this->assertFalse( $r['truncated'] );
        $many = array_fill( 0, ComponentReader::MAX_COMPONENTS + 1, [ 'id' => 1, 'title' => 't', 'kind' => 'row', 'content' => '', 'used_on' => 0 ] );
        $c    = ComponentReader::discoverWith( $many );
        $this->assertTrue( $c['truncated'] );
        $this->assertCount( ComponentReader::MAX_COMPONENTS, $c['components'] );
    }

    public function testTheStyleGuideTeachesWhatTheExportsAndTheRenderProved(): void
    {
        $g = StyleGuide::markdown();
        foreach ( [ 'divi/global-layout', 'globalModule', 'syncNodeId', 'divi/global-slot', 'slotId', 'component_owned_edits', 'unknown_components', 'include=components', 'next to a Slot' ] as $needle ) {
            $this->assertStringContainsString( $needle, $g, $needle );
        }
    }

    public function testSlotIdsReadsOnlyRealSlotsInOrderWithoutRepeats(): void
    {
        $c = '<!-- wp:divi/global-slot {"slotId":"b"} /--><!-- wp:divi/global-slot {"slotId":"a"} --><!-- /wp:divi/global-slot --><!-- wp:divi/global-slot {"slotId":"b"} /--><!-- wp:divi/global-slot {"builderVersion":"x"} /--><!-- wp:divi/text {"slotId":"no"} /-->';
        $this->assertSame( [ 'b', 'a' ], ComponentReader::slotIds( $c ) );
    }
}
