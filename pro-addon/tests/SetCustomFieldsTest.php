<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Fields\AcfFields;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Tools\SetCustomFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

require_once __DIR__ . '/WpKsesShim.php'; // a GLOBAL (non-namespaced) stand-in for wp_kses_post(); see below

class SetCustomFieldsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetCustomFields $tool;

    /** @var array<string,array<string,mixed>> */
    private array $acf;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->acf  = AcfFields::indexWith(
            static fn () => [ [ 'key' => 'group_a' ] ],
            static fn () => [
                [ 'key' => 'field_size', 'name' => 'size', 'type' => 'select', 'choices' => [ 's' => 'Small', 'l' => 'Large' ] ],
                [ 'key' => 'field_count', 'name' => 'count', 'type' => 'number' ],
                [ 'key' => 'field_acf_text', 'name' => 'acf_text', 'type' => 'text' ],
                [ 'key' => 'field_team', 'name' => 'team', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_m', 'name' => 'member', 'type' => 'text' ] ] ],
                [ 'key' => 'field_addr', 'name' => 'address', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_city', 'name' => 'city', 'type' => 'text' ] ] ],
                [ 'key' => 'field_pw', 'name' => 'acf_pw', 'type' => 'password' ],
            ]
        );
        $known      = static fn ( string $key ): bool => in_array( $key, [ 'headline', 'subtitle', 'seo_title', 'twice', 'listy' ], true );
        $this->tool = new SetCustomFields( $this->log, new PostMetaStore( static function ( string $selector, string $value, int $postId ): void {
            $GLOBALS['__acf_calls'][] = [ $selector, $value, $postId ];
            $name = [ 'field_acf_text' => 'acf_text', 'field_size' => 'size', 'field_count' => 'count' ][ $selector ] ?? 'unknown';
            $GLOBALS['__wp_postmeta'][ $postId ][ $name ] = $value;
        } ), $known, $this->acf );

        $GLOBALS['__acf_calls']   = [];
        $GLOBALS['__wp_postmeta'] = [
            5 => [ 'headline' => 'Old headline', 'subtitle' => 'Old sub', 'listy' => [ 1, 2 ], '_hidden' => 'h', 'api_token' => 't' ],
            6 => [],
            7 => [],
        ];
        $GLOBALS['__wp_posts'] = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'Proj', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Hdr', 'post_content' => '' ],
        ];
        $GLOBALS['__cf_types']  = [ 'page' => true, 'project' => true, 'et_header_layout' => false ];
        $GLOBALS['__cf_protected'] = [];
        $GLOBALS['__wp_can']    = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<array{key:string,value:string}> $fields */
    private function call( int $id, array $fields, bool $dry = false ): array
    {
        return $this->tool->run( [ 'id' => $id, 'fields' => $fields, 'dry_run' => $dry ] );
    }

    private function refused( array $args, string $message ): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'], 'nothing was written' );
        $this->assertSame( [], $this->repo->rows, 'no batch was recorded' );
    }

    private function field( string $key, string $value ): array
    {
        return [ 'key' => $key, 'value' => $value ];
    }

    // ---- definition ---------------------------------------------------------------------------

    public function testTheDefinitionIsGeminiSafeAndShortAndNeedsIdAndFields(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'set_custom_fields', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'id', 'fields' ], $d['inputSchema']['required'] );
        $this->assertSame( 'integer', $d['inputSchema']['properties']['id']['type'] );
        $this->assertSame( 'array', $d['inputSchema']['properties']['fields']['type'] );
        $this->assertSame( [ 'key', 'value' ], $d['inputSchema']['properties']['fields']['items']['required'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $json = (string) json_encode( $d['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf', 'anyOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }

    // ---- the happy path -----------------------------------------------------------------------

    public function testItSetsTheListedKeysOnlyAndRecordsOneUndoableBatch(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'New headline' ), $this->field( 'subtitle', 'Old sub' ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 'id' => 5, 'title' => 'Home', 'post_type' => 'page' ], $r['post'] );
        $this->assertSame( [
            [ 'key' => 'headline', 'status' => 'set', 'before' => 'Old headline', 'after' => 'New headline' ],
            [ 'key' => 'subtitle', 'status' => 'unchanged', 'before' => 'Old sub', 'after' => 'Old sub' ],
        ], $r['fields'] );
        $this->assertSame( 'New headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( 'h', $GLOBALS['__wp_postmeta'][5]['_hidden'], 'nothing else was touched' );
        $this->assertSame( 1, $r['batch']['id'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertCount( 1, $this->repo->rows );
        $this->assertCount( 1, $this->repo->rows[1]['items'], 'only the changed field is an item' );
        $this->assertSame( 'post_meta', $this->repo->rows[1]['items'][0]['kind'] );
    }

    public function testAKnownKeyThePostDoesNotHaveYetIsCreatedAndLabelledCreate(): void
    {
        $r = $this->call( 6, [ $this->field( 'seo_title', 'A title' ) ] );
        $this->assertSame( 'create', $r['fields'][0]['status'] );
        $this->assertNull( $r['fields'][0]['before'] );
        $this->assertSame( 'A title', $GLOBALS['__wp_postmeta'][6]['seo_title'] );
    }

    public function testADiviProjectAndAnyPublicTypeIsAccepted(): void
    {
        $this->assertTrue( $this->call( 6, [ $this->field( 'headline', 'On a project' ) ] )['changed'] );
    }

    public function testNothingToChangeIsReportedAndRecordsNoBatch(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'Old headline' ) ] );
        $this->assertFalse( $r['changed'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testDryRunPreviewsEverythingAndWritesNothing(): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        $r      = $this->call( 5, [ $this->field( 'headline', 'Preview' ) ], true );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'set', $r['fields'][0]['status'] );
        $this->assertSame( 'Preview', $r['fields'][0]['after'] );
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testBackslashesQuotesAndUnicodeSurviveTheWriteAndTheUndo(): void
    {
        $value = 'C:\\path "quoted" \'single\' Café ✓';
        $r     = $this->call( 5, [ $this->field( 'headline', $value ) ] );
        $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['headline'] );
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $u    = $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertTrue( $u['ok'] );
        $this->assertSame( 1, $u['restored'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( 'restored', $u['items'][0]['result'] );
    }

    public function testAnAcfTopLevelFieldIsWrittenThroughAcfWithItsFieldKey(): void
    {
        $r = $this->call( 5, [ $this->field( 'acf_text', 'Via ACF' ), $this->field( 'size', 'l' ), $this->field( 'count', '12' ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'field_acf_text', 'Via ACF', 5 ], [ 'field_size', 'l', 5 ], [ 'field_count', '12', 5 ] ], $GLOBALS['__acf_calls'] );
        $items = $this->repo->rows[1]['items'];
        $this->assertSame( [ 'acf' => 'field_acf_text' ], $items[0]['meta'] );
    }

    // ---- argument checking --------------------------------------------------------------------

    public function testTheArgumentsAreTypeCheckedNeverCast(): void
    {
        $ok = [ $this->field( 'headline', 'x' ) ];
        $this->refused( [ 'fields' => $ok ], '"id" is required.' );
        $this->refused( [ 'id' => '5', 'fields' => $ok ], '"id" must be an integer.' );
        $this->refused( [ 'id' => 5 ], '"fields" is required.' );
        $this->refused( [ 'id' => 5, 'fields' => 'headline' ], '"fields" must be a list.' );
        $this->refused( [ 'id' => 5, 'fields' => [] ], '"fields" must not be empty.' );
        $this->refused( [ 'id' => 5, 'fields' => $ok, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'id' => 5, 'fields' => [ 'headline' ] ], 'item 1 must be an object' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 5, 'value' => 'x' ] ] ], 'item 1: "key" must be a non-empty string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => '', 'value' => 'x' ] ] ], 'item 1: "key" must be a non-empty string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 'headline', 'value' => 5 ] ] ], 'item 1: "value" must be a string' );
        $this->refused( [ 'id' => 5, 'fields' => [ [ 'key' => 'headline' ] ] ], 'item 1: "value" must be a string' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'a' ), $this->field( 'headline', 'b' ) ] ], 'appears more than once' );
    }

    public function testAtMost25FieldsPerCall(): void
    {
        $many = [];
        for ( $i = 0; $i < 26; $i++ ) {
            $many[] = $this->field( 'headline', (string) $i );
        }
        $this->refused( [ 'id' => 5, 'fields' => $many ], 'at most 25 items' );
    }

    // ---- the target ---------------------------------------------------------------------------

    public function testMissingNonPublicAndNotEditablePostsAllLookMissing(): void
    {
        $fields = [ $this->field( 'headline', 'x' ) ];
        $this->refused( [ 'id' => 999, 'fields' => $fields ], 'Post 999 not found.' );
        $this->refused( [ 'id' => 7, 'fields' => $fields ], 'Post 7 not found.' );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:5' => false, 'edit_post_meta' => true ];
        $this->refused( [ 'id' => 5, 'fields' => $fields ], 'Post 5 not found.' );
    }

    // ---- per-field refusals (all-or-nothing) --------------------------------------------------

    /** @return list<array{string,string,string}> key, value, reason substring */
    public static function refusals(): array
    {
        return [
            'underscore key'        => [ '_hidden', 'x', 'protected or private' ],
            'secret-looking key'    => [ 'api_token', 'x', 'password, token' ],
            'unknown key'           => [ 'never_seen', 'x', 'this site knows' ],
            'existing list value'   => [ 'listy', 'x', 'list or object' ],
            'script in the value'   => [ 'headline', '<script>alert(1)</script>', 'script' ],
            'event handler'         => [ 'headline', '<p onclick="x()">hi</p>', 'event-handler' ],
            'javascript url'        => [ 'headline', 'javascript:alert(1)', 'javascript' ],
            'markup kses changes'   => [ 'headline', '<form action="x">hi</form>', 'markup' ],
            'acf select not a choice' => [ 'size', 'xl', 'one of' ],
            'acf number not numeric'  => [ 'count', 'abc', 'number' ],
            'acf repeater'          => [ 'team', 'x', 'ACF editor' ],
            'acf group'             => [ 'address', 'x', 'ACF editor' ],
            'acf group member'      => [ 'address_city', 'x', 'ACF editor' ],
            'acf repeater row key'  => [ 'team_0_member', 'x', 'ACF editor' ],
            'acf password field'    => [ 'acf_pw', 'x', 'ACF editor' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testEachRefusalNamesTheKeyAndTheReason( string $key, string $value, string $needle ): void
    {
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $this->tool->run( [ 'id' => 5, 'fields' => [ $this->field( $key, $value ) ] ] );
            $this->fail( "expected a refusal for {$key}" );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $key, $e->getMessage() );
            $this->assertStringContainsString( $needle, $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was written', $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__acf_calls'] );
    }

    public function testAValueOverTheLengthLimitIsRefused(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', str_repeat( 'a', 10001 ) ) ] ], '10,000' );
        $this->assertTrue( $this->call( 5, [ $this->field( 'headline', str_repeat( 'a', 10000 ) ) ] )['changed'], 'exactly 10,000 characters is allowed' );
    }

    public function testAKeyStoredTwiceOnThePostIsRefused(): void
    {
        $tool = new SetCustomFields( $this->log, new class() extends PostMetaStore {
            public function read( int $postId, string $key ): array
            {
                return 'twice' === $key ? [ 'exists' => true, 'value' => 'a', 'rows' => 2, 'structured' => false ] : parent::read( $postId, $key );
            }
        }, static fn ( string $k ): bool => true, $this->acf );
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'twice', 'x' ) ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'twice', $e->getMessage() );
            $this->assertStringContainsString( 'more than once', $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testWordPressCanRefuseAKeyForThisPost(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true, 'edit_post_meta:5' => false ];
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'x' ) ] ], 'not allowed by WordPress' );
    }

    public function testAProtectedKeyThatWordPressMarksProtectedIsRefused(): void
    {
        $GLOBALS['__cf_protected'] = [ 'subtitle' ];
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'subtitle', 'x' ) ] ], 'protected or private' );
    }

    public function testOneRefusedFieldWritesNothingAtAll(): void
    {
        $this->refused(
            [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'fine' ), $this->field( 'subtitle', 'also fine' ), $this->field( '_hidden', 'bad' ), $this->field( 'seo_title', 'ok' ) ] ],
            '_hidden'
        );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
    }

    public function testEveryRefusalIsReportedNotJustTheFirst(): void
    {
        try {
            $this->tool->run( [ 'id' => 5, 'fields' => [ $this->field( '_hidden', 'x' ), $this->field( 'never_seen', 'y' ), $this->field( 'headline', '<script>' ) ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            foreach ( [ '_hidden', 'never_seen', 'headline' ] as $key ) {
                $this->assertStringContainsString( $key, $e->getMessage() );
            }
            $this->assertStringContainsString( '3 field(s) refused', $e->getMessage() );
        }
    }

    public function testDryRunRefusesTheSameWayAndWritesNothing(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( '_hidden', 'x' ) ], 'dry_run' => true ], '_hidden' );
    }

    // ---- atomic writes ------------------------------------------------------------------------

    public function testAFailedWriteRollsBackEveryFieldAlreadyWritten(): void
    {
        $this->tool = new SetCustomFields( $this->log, new class() extends PostMetaStore {
            public function write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool
            {
                if ( 'subtitle' === $key && 'boom' === $value ) {
                    return false; // the second write fails
                }

                return parent::write( $postId, $key, $value, $acfSelector );
            }
        }, static fn ( string $k ): bool => true, $this->acf );

        try {
            $this->call( 5, [ $this->field( 'headline', 'First' ), $this->field( 'seo_title', 'Created' ), $this->field( 'subtitle', 'boom' ) ] );
            $this->fail( 'expected a failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'subtitle', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
        }
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'], 'the first write was rolled back' );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5], 'the created key was removed again' );
        $this->assertSame( 'Old sub', $GLOBALS['__wp_postmeta'][5]['subtitle'] );
        $this->assertSame( [], $this->repo->rows );
    }

    // ---- undo through the real batch machinery ------------------------------------------------

    public function testUndoRestoresAnEditedValueAndDeletesACreatedKey(): void
    {
        $r    = $this->call( 5, [ $this->field( 'headline', 'Changed' ), $this->field( 'seo_title', 'Created' ) ] );
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $u    = $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 2, $u['restored'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5] );
    }

    public function testUndoRefusesAValueSomeoneElseChangedSince(): void
    {
        $r = $this->call( 5, [ $this->field( 'headline', 'Changed' ) ] );
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'Edited by a person';
        $undo = new BatchUndo( Batches::kinds(), $this->log );
        $u    = $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 0, $u['restored'] );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'changed_since', $u['items'][0]['result'] );
        $this->assertSame( 'Edited by a person', $GLOBALS['__wp_postmeta'][5]['headline'], 'the later edit is not overwritten' );
    }
}
