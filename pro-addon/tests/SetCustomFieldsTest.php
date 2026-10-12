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
require_once __DIR__ . '/CleanPostCacheShim.php';
require_once __DIR__ . '/WpSiteShims.php'; // get_posts() with meta_key support, for the production known-key wiring

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
            // Mimics ACF's update_field(): it unslashes what it receives (verified live on ACF 6.8.10).
            $GLOBALS['__wp_postmeta'][ $postId ][ $name ] = stripslashes( $value );
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
        $GLOBALS['__wp_meta_keys']   = [];
        $GLOBALS['__clean_post_cache'] = [];
        $GLOBALS['__clean_post_cache_throws'] = false;
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

    public function testBackslashesQuotesAndUnicodeSurviveThePlainWriteAndTheUndo(): void
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

    public function testBackslashesQuotesAndUnicodeSurviveTheAcfWriteToo(): void
    {
        $value = 'D:\\dir "q" \'s\' Ünï ✓';
        $this->assertTrue( $this->call( 5, [ $this->field( 'acf_text', $value ) ] )['changed'] );
        $this->assertSame( [ [ 'field_acf_text', wp_slash( $value ), 5 ] ], $GLOBALS['__acf_calls'], 'update_field gets the slashed form' );
        $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['acf_text'] );
    }

    // ---- key spelling -------------------------------------------------------------------------

    public function testAPaddedOrControlCharacterKeyIsRefusedAsAnArgumentError(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline ', 'x' ) ] ], 'leading or trailing whitespace or control characters' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( ' headline', 'x' ) ] ], 'leading or trailing whitespace or control characters' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( "head\nline", 'x' ) ] ], 'leading or trailing whitespace or control characters' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( "headline\0", 'x' ) ] ], 'leading or trailing whitespace or control characters' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( "head\x7Fline", 'x' ) ] ], 'leading or trailing whitespace or control characters' );
    }

    public function testAKeyThatDiffersOnlyByCaseFromAnExistingFieldIsRefused(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'Headline', 'x' ) ] ], 'differs only by letter case from the existing field "headline"; use the exact key' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'HEADLINE', 'x' ) ] ], 'differs only by letter case' );
    }

    public function testAKeyThatDiffersOnlyByCaseFromAnAcfFieldOrPrefixIsRefused(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'Size', 'l' ) ] ], 'differs only by letter case from the ACF field "size"; use the exact key' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'Team', 'x' ) ] ], 'differs only by letter case from the ACF field "team"; use the exact key' );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'Team_0_member', 'x' ) ] ], 'differs only by letter case from the ACF field "team"' );
        $this->assertSame( [], $GLOBALS['__acf_calls'] );
    }

    public function testANumericKeyIsHandledCleanly(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( '123', 'x' ) ] ], '123 is not a custom field this site knows' );
        $GLOBALS['__wp_postmeta'][5]['123'] = 'num';
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( '123', 'x' ) ] ], 'this site knows' );
    }

    // ---- ampersands and markup ----------------------------------------------------------------

    public function testABareAmpersandAndAnEntityAreAcceptedAndStoredByteExactly(): void
    {
        foreach ( [ 'Terms & Conditions', 'Fish &amp; Chips', 'A &lt; B &#38; C' ] as $value ) {
            $r = $this->call( 5, [ $this->field( 'headline', $value ) ] );
            $this->assertTrue( $r['changed'], $value );
            $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['headline'], $value );
        }
    }

    public function testRealMarkupIsStillRefusedNextToAmpersands(): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', '<form action="x">Terms & Conditions</form>' ) ] ], 'markup' );
    }

    // ---- messages -----------------------------------------------------------------------------

    public function testTwentyFiveLongRefusedKeysAreAllReportedInTheMessage(): void
    {
        $fields = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $fields[] = $this->field( sprintf( 'k%02d', $i ) . str_repeat( 'a', 188 ), 'x' );
        }
        try {
            $this->tool->run( [ 'id' => 5, 'fields' => $fields ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringContainsString( '25 field(s) refused', $m );
            $this->assertSame( 25, substr_count( $m, 'this site knows' ), 'every refusal survives' );
            $this->assertStringContainsString( 'k24', $m );
            $this->assertStringEndsWith( '.', $m );
            $this->assertStringNotContainsString( str_repeat( 'a', 100 ), $m, 'keys are cut at 80 characters' );
        }
    }

    // ---- rollback honesty ---------------------------------------------------------------------

    private function storeWhose( \Closure $write ): PostMetaStore
    {
        return new class( $write ) extends PostMetaStore {
            public int $calls = 0;
            public function __construct( private \Closure $hook )
            {
                parent::__construct();
            }
            public function write( int $postId, string $key, string $value, ?string $acfSelector = null ): bool
            {
                $this->calls++;

                return ( $this->hook )( $this, $postId, $key, $value, $acfSelector );
            }
        };
    }

    public function testAWriteThatStoresAMangledValueAndFailsIsRolledBackToo(): void
    {
        $store = $this->storeWhose( static function ( PostMetaStore $s, int $postId, string $key, string $value, ?string $acf ): bool {
            if ( 'subtitle' === $key && 'boom' === $value ) {
                $GLOBALS['__wp_postmeta'][ $postId ]['subtitle'] = 'boom (mangled by a filter)';

                return false; // stored something else, then reported failure
            }

            return ( new PostMetaStore() )->write( $postId, $key, $value, $acf );
        } );
        $tool = new SetCustomFields( $this->log, $store, static fn (): bool => true, $this->acf );
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'First' ), $this->field( 'seo_title', 'Created' ), $this->field( 'subtitle', 'boom' ) ] ] );
            $this->fail( 'expected a failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not write "subtitle"', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
        }
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( 'Old sub', $GLOBALS['__wp_postmeta'][5]['subtitle'], 'the failing field itself is back to its old value' );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARollbackThatCannotRestoreAFieldSaysSoHonestly(): void
    {
        $headlineWrites = 0;
        $store          = $this->storeWhose( static function ( PostMetaStore $s, int $postId, string $key, string $value, ?string $acf ) use ( &$headlineWrites ): bool {
            if ( 'subtitle' === $key && 'boom' === $value ) {
                return false;
            }
            if ( 'headline' === $key && ++$headlineWrites > 1 ) {
                return false; // the restore of headline fails
            }

            return ( new PostMetaStore() )->write( $postId, $key, $value, $acf );
        } );
        $tool = new SetCustomFields( $this->log, $store, static fn (): bool => true, $this->acf );
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'First' ), $this->field( 'seo_title', 'Created' ), $this->field( 'subtitle', 'boom' ) ] ] );
            $this->fail( 'expected a failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not write "subtitle". These fields may not have been restored: headline. Check them with get_custom_fields.', $e->getMessage() );
            $this->assertStringNotContainsString( 'nothing was changed', $e->getMessage() );
        }
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5], 'the fields that could be restored were' );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAStoreThatThrowsMidWayIsRolledBackAndNothingLeaks(): void
    {
        $store = $this->storeWhose( static function ( PostMetaStore $s, int $postId, string $key, string $value, ?string $acf ): bool {
            if ( 'subtitle' === $key && 'boom' === $value ) {
                throw new \RuntimeException( 'secret internal detail' );
            }

            return ( new PostMetaStore() )->write( $postId, $key, $value, $acf );
        } );
        $tool = new SetCustomFields( $this->log, $store, static fn (): bool => true, $this->acf );
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'First' ), $this->field( 'seo_title', 'Created' ), $this->field( 'subtitle', 'boom' ) ] ] );
            $this->fail( 'expected a failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not write "subtitle"', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
            $this->assertStringNotContainsString( 'secret internal detail', $e->getMessage() );
        }
        $this->assertSame( 'Old headline', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertArrayNotHasKey( 'seo_title', $GLOBALS['__wp_postmeta'][5] );
        $this->assertSame( 'Old sub', $GLOBALS['__wp_postmeta'][5]['subtitle'] );
        $this->assertSame( [], $this->repo->rows );
    }

    /** @return array<string,array{string}> */
    public static function nonAsciiOrOverlongKeys(): array
    {
        return [
            'accented'           => [ "api_token\u{00E9}" ],
            'accented in middle' => [ "api_tok\u{00E9}n" ],
            'greek homoglyph'    => [ "h\u{03B5}adline" ],
            'cyrillic homoglyph' => [ "h\u{0435}adline" ],
            'ligature'           => [ "\u{FB01}eld" ],
            'emoji'              => [ "mood_\u{1F600}" ],
            '192 characters'     => [ str_repeat( 'a', 192 ) ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonAsciiOrOverlongKeys' )]
    public function testAKeyThatIsNotPrintableAsciiOrIsTooLongIsRefusedAsAnArgumentError( string $key ): void
    {
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( $key, 'x' ) ] ], 'item 1: "key" may contain only printable ASCII characters (at most 191).' );
        $this->assertSame( [], $GLOBALS['__acf_calls'] );
    }

    public function testA191CharacterAsciiKeyAndAKeyWithAnInternalSpaceStillPass(): void
    {
        $tool = new SetCustomFields( $this->log, null, static fn (): bool => true, $this->acf );
        $long = str_repeat( 'a', 191 );
        foreach ( [ $long, 'My Field' ] as $key ) {
            $r = $tool->run( [ 'id' => 6, 'fields' => [ $this->field( $key, 'v' ) ], 'dry_run' => true ] );
            $this->assertSame( 'create', $r['fields'][0]['status'], $key );
        }
    }

    // ---- the production "known key" wiring ----------------------------------------------------

    public function testTheProductionKnownCheckAcceptsOnlyAKeyAPostHasAndNeverAnswersYesForEverything(): void
    {
        $tool = new SetCustomFields( $this->log, null, null, $this->acf ); // no injected closure: the real default
        $GLOBALS['__wp_meta_keys']['used_somewhere'] = true;
        $this->assertSame( 'create', $tool->run( [ 'id' => 6, 'fields' => [ $this->field( 'used_somewhere', 'v' ) ], 'dry_run' => true ] )['fields'][0]['status'] );
        for ( $i = 0; $i < 60; $i++ ) { // past the free closure's 50-lookup cap, where it would answer "known" for everything
            try {
                $tool->run( [ 'id' => 6, 'fields' => [ $this->field( "never_seen_{$i}", 'v' ) ], 'dry_run' => true ] );
                $this->fail( "never_seen_{$i} must not be known" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'this site knows', $e->getMessage() );
            }
        }
    }

    // ---- ACF clone members and other ACF-managed keys outside the index -----------------------

    public function testAKeyWithAnAcfReferenceRowThatIsNotInTheIndexIsRefused(): void
    {
        $GLOBALS['__wp_postmeta'][5]['cloned_title']  = 'v';
        $GLOBALS['__wp_postmeta'][5]['_cloned_title'] = 'field_xyz';
        $tool = new SetCustomFields( $this->log, null, static fn (): bool => true, $this->acf );
        $before = $GLOBALS['__wp_postmeta'];
        try {
            $tool->run( [ 'id' => 5, 'fields' => [ $this->field( 'cloned_title', 'new' ) ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'cloned_title is managed by an ACF field this tool cannot edit; edit it in the ACF editor', $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAnOrdinaryKeyAndAnIndexedAcfFieldAreUnaffectedByTheReferenceRule(): void
    {
        $GLOBALS['__wp_postmeta'][5]['_headline'] = 'not an acf reference';
        $this->assertTrue( $this->call( 5, [ $this->field( 'headline', 'Fine' ) ] )['changed'] );
        $GLOBALS['__wp_postmeta'][5]['_acf_text'] = 'field_acf_text';
        $this->assertTrue( $this->call( 5, [ $this->field( 'acf_text', 'Still fine' ) ] )['changed'], 'a top-level indexed field keeps its normal type rules' );
        $this->assertSame( [ [ 'field_acf_text', 'Still fine', 5 ] ], $GLOBALS['__acf_calls'] );
    }

    // ---- a stored value too large to undo safely ----------------------------------------------

    public function testAFieldWhoseCurrentValueIsOver512KbIsRefused(): void
    {
        $GLOBALS['__wp_postmeta'][5]['headline'] = str_repeat( 'a', 524289 );
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( 'headline', 'x' ) ] ], 'currently holds more than 512 KB and is too large to undo safely' );
        $GLOBALS['__wp_postmeta'][5]['headline'] = str_repeat( 'a', 524288 );
        $this->assertTrue( $this->call( 5, [ $this->field( 'headline', 'x' ) ] )['changed'], 'exactly 512 KB is allowed' );
    }

    // ---- the page cache -----------------------------------------------------------------------

    public function testThePostCacheIsClearedAfterAWriteButNotAfterADryRunOrARefusal(): void
    {
        $this->call( 5, [ $this->field( 'headline', 'Preview' ) ], true );
        $this->assertSame( [], $GLOBALS['__clean_post_cache'] );
        $this->call( 5, [ $this->field( 'headline', 'Real' ) ] );
        $this->assertSame( [ 5 ], $GLOBALS['__clean_post_cache'] );
    }

    public function testACleanPostCacheHookThatThrowsAfterASuccessfulWriteDoesNotHideTheWrite(): void
    {
        $GLOBALS['__clean_post_cache_throws'] = true;
        $result = $this->call( 5, [ $this->field( 'headline', 'Saved anyway' ) ] );
        $this->assertTrue( $result['changed'], 'the values are stored, so the call reports them and records the batch' );
        $this->assertSame( 'Saved anyway', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( [ 5 ], $GLOBALS['__clean_post_cache'] );
    }

    // ---- meta key "0" -------------------------------------------------------------------------

    public function testTheKeyZeroIsRefusedBecauseTheKnownKeyLookupCannotTellItFromNoKey(): void
    {
        // WP_Meta_Query ignores meta_key "0" as empty, so CustomFields::postHasMeta('0') is true on any site and the
        // "known key" rule would let the tool create a meta key named 0.
        $this->refused( [ 'id' => 5, 'fields' => [ $this->field( '0', 'x' ) ] ], 'item 1: "key" may not be "0"' );
        $this->assertSame( [], $GLOBALS['__acf_calls'] );
    }
}
