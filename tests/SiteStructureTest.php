<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SiteStructure;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';

class SiteStructureTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ( [ 'post_types', 'taxonomies', 'object_taxonomies', 'supports', 'image_sizes', 'acf_groups', 'acf_fields' ] as $k ) {
            unset( $GLOBALS[ '__ss_' . $k ] );
        }
    }

    /** @return array<string,mixed> */
    private function readers( array $override = [] ): array
    {
        return $override + [
            'post_types'  => static fn () => [
                'page' => [ 'label' => 'Pages', 'supports' => [ 'title', 'editor' ], 'taxonomies' => [], 'has_archive' => false, 'hierarchical' => true ],
                'book' => [ 'label' => 'Books', 'supports' => [ 'title' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ],
            ],
            'taxonomies'  => static fn () => [ 'genre' => [ 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ] ],
            'image_sizes' => static fn () => [ 'thumbnail' => [ 'width' => 150, 'height' => 150, 'crop' => true ], 'large' => [ 'width' => 1024, 'height' => 1024, 'crop' => false ] ],
            'acf_groups'  => static fn () => [
                [ 'key' => 'group_a', 'title' => 'Book details', 'active' => true ],
                [ 'key' => 'group_off', 'title' => 'Switched off', 'active' => false ],
                [ 'title' => 'No key here' ],
            ],
            'acf_fields'  => static fn ( string $key ) => 'group_a' === $key ? [
                [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ],
                [ 'name' => 'format', 'label' => 'Format', 'type' => 'select', 'choices' => [ 'hc' => 'Hardcover', 'pb' => 'Paperback' ] ],
            ] : false,
        ];
    }

    // ---- parseInclude -------------------------------------------------------------------------

    public function testParseIncludeDefaultsToEverySection(): void
    {
        foreach ( [ null, '', [] ] as $empty ) {
            $this->assertSame( SiteStructure::SECTIONS, SiteStructure::parseInclude( $empty ) );
        }
    }

    public function testParseIncludeAcceptsAListOrACommaStringAndKeepsSectionOrder(): void
    {
        $this->assertSame( [ 'post_types', 'acf' ], SiteStructure::parseInclude( [ 'acf', 'post_types', 'acf' ] ) );
        $this->assertSame( [ 'post_types', 'acf' ], SiteStructure::parseInclude( 'post_types, acf' ), 'spaces are trimmed' );
        $this->assertSame( [ 'image_sizes' ], SiteStructure::parseInclude( 'image_sizes' ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badIncludes' )]
    public function testParseIncludeRejectsAnythingElse( mixed $bad ): void
    {
        $this->assertNull( SiteStructure::parseInclude( $bad ) );
    }

    /** @return list<array{mixed}> */
    public static function badIncludes(): array
    {
        return [ [ [ 'nope' ] ], [ [ 1 ] ], [ 'post_types,nope' ], [ 'post_types,,acf' ], [ [ 'a' => 'acf' ] ], [ 7 ], [ true ], [ false ], [ [ [ 'acf' ] ] ] ];
    }

    // ---- catalogWith --------------------------------------------------------------------------

    public function testReturnsEverySectionByDefaultWithTheDocumentedShape(): void
    {
        $d = SiteStructure::catalogWith( $this->readers() );

        $this->assertSame( [ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'truncated', 'notes' ], array_keys( $d ) );
        $this->assertSame( [ 'page', 'book' ], array_column( $d['post_types'], 'name' ) );
        $this->assertSame( [ 'name' => 'book', 'label' => 'Books', 'supports' => [ 'title' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ], $d['post_types'][1] );
        $this->assertSame( [ [ 'name' => 'genre', 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ] ], $d['taxonomies'] );
        $this->assertSame( [ 'name' => 'thumbnail', 'width' => 150, 'height' => 150, 'crop' => true ], $d['image_sizes'][0] );
        $this->assertFalse( $d['truncated'] );
        $this->assertSame( [], $d['notes'] );
    }

    public function testIncludeLimitsTheSectionsReturned(): void
    {
        $d = SiteStructure::catalogWith( $this->readers(), [ 'image_sizes' ] );
        $this->assertSame( [ 'image_sizes', 'truncated', 'notes' ], array_keys( $d ) );
    }

    public function testAcfGroupsListFieldsAndChoicesButSkipInactiveAndKeylessGroups(): void
    {
        $acf = SiteStructure::catalogWith( $this->readers(), [ 'acf' ] )['acf'];

        $this->assertTrue( $acf['active'] );
        $this->assertSame( [ 'group_a', '' ], array_column( $acf['groups'], 'key' ), 'the inactive group is skipped, the keyless one has no fields' );
        $this->assertSame( [], $acf['groups'][1]['fields'] );
        $this->assertSame( [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ], $acf['groups'][0]['fields'][0] );
        $this->assertSame(
            [ [ 'value' => 'hc', 'label' => 'Hardcover' ], [ 'value' => 'pb', 'label' => 'Paperback' ] ],
            $acf['groups'][0]['fields'][1]['choices']
        );
    }

    public function testAGroupListsWhereItAppliesAsAlternativesOfRequiredRules(): void
    {
        $r   = $this->readers( [ 'acf_groups' => static fn () => [ [ 'key' => 'group_a', 'title' => 'A', 'location' => [
            [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ],
            [ [ 'param' => 'page_template', 'operator' => '==', 'value' => 'default' ], [ 'param' => 'post_category', 'operator' => '!=', 'value' => 'category:news' ] ],
        ] ] ] ] );
        $acf = SiteStructure::catalogWith( $r, [ 'acf' ] )['acf'];

        $this->assertSame( [ [ 'post_type == page' ], [ 'page_template == default', 'post_category != category:news' ] ], $acf['groups'][0]['location'] );
    }

    public function testAGroupWithNoUsableLocationHasAnEmptyList(): void
    {
        foreach ( [ null, 'junk', [], [ 'junk' ], [ [ 'junk' ] ], [ [ [ 'param' => 'post_type' ] ] ], [ [ [ 'param' => 5, 'operator' => '==', 'value' => 'x' ] ] ] ] as $bad ) {
            $r   = $this->readers( [ 'acf_groups' => static fn () => [ [ 'key' => 'group_a', 'title' => 'A', 'location' => $bad ] ] ] );
            $acf = SiteStructure::catalogWith( $r, [ 'acf' ] )['acf'];
            $this->assertSame( [], $acf['groups'][0]['location'], 'unusable location data gives an empty list, never an error' );
        }
        $acf = SiteStructure::catalogWith( $this->readers(), [ 'acf' ] )['acf'];
        $this->assertSame( [], $acf['groups'][0]['location'], 'a group with no location key at all is listed with an empty list' );
    }

    public function testLocationRulesAreCappedAndLongValuesCut(): void
    {
        $rule  = static fn ( string $v ): array => [ 'param' => 'post_type', 'operator' => '==', 'value' => $v ];
        $many  = array_map( static fn ( int $i ): array => [ $rule( 'p' . $i ) ], range( 1, SiteStructure::MAX_LOCATION_SETS + 2 ) );
        $wide  = [ array_map( static fn ( int $i ): array => $rule( 'q' . $i ), range( 1, SiteStructure::MAX_LOCATION_RULES + 2 ) ) ];
        $long  = [ [ $rule( str_repeat( 'x', 500 ) ) ] ];
        $r     = $this->readers( [ 'acf_groups' => static fn () => [
            [ 'key' => 'group_a', 'title' => 'A', 'location' => $many ],
            [ 'key' => 'group_b', 'title' => 'B', 'location' => $wide ],
            [ 'key' => 'group_c', 'title' => 'C', 'location' => $long ],
        ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'acf' ] );

        $this->assertCount( SiteStructure::MAX_LOCATION_SETS, $d['acf']['groups'][0]['location'] );
        $this->assertCount( SiteStructure::MAX_LOCATION_RULES, $d['acf']['groups'][1]['location'][0] );
        $this->assertLessThanOrEqual( SiteStructure::MAX_TEXT, mb_strlen( $d['acf']['groups'][2]['location'][0][0] ) );
        $this->assertTrue( $d['truncated'] );
    }

    public function testAGroupWhoseFieldsComeBackAsFalseHasAnEmptyFieldList(): void
    {
        $r   = $this->readers( [ 'acf_groups' => static fn () => [ [ 'key' => 'group_empty', 'title' => 'Empty' ] ] ] );
        $acf = SiteStructure::catalogWith( $r, [ 'acf' ] )['acf'];
        $this->assertSame( [], $acf['groups'][0]['fields'] );
    }

    public function testWithoutAcfTheSectionIsInactiveWithANote(): void
    {
        $d = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => null, 'acf_fields' => null ] ), [ 'acf' ] );
        $this->assertSame( [ 'active' => false, 'groups' => [] ], $d['acf'] );
        $this->assertNotEmpty( $d['notes'] );
    }

    public function testAMissingOrThrowingReaderGivesAnEmptySectionAndANoteNeverAnError(): void
    {
        $r = $this->readers( [
            'post_types' => null,
            'taxonomies' => static function () { throw new \RuntimeException( 'boom' ); },
        ] );
        $d = SiteStructure::catalogWith( $r );
        $this->assertSame( [], $d['post_types'] );
        $this->assertSame( [], $d['taxonomies'] );
        $this->assertCount( 2, $d['notes'] );
        $this->assertNotEmpty( $d['image_sizes'], 'the other sections are unaffected' );
    }

    public function testLongListsAreCappedAndFlaggedTruncated(): void
    {
        $many  = [];
        for ( $i = 1; $i <= 101; $i++ ) {
            $many[ 'type' . $i ] = [ 'label' => 'T' . $i ];
        }
        $d = SiteStructure::catalogWith( $this->readers( [ 'post_types' => static fn () => $many ] ), [ 'post_types' ] );
        $this->assertCount( SiteStructure::MAX_ITEMS, $d['post_types'] );
        $this->assertTrue( $d['truncated'] );

        $choices = [];
        for ( $i = 1; $i <= 51; $i++ ) {
            $choices[ 'v' . $i ] = 'L' . $i;
        }
        $r = $this->readers( [ 'acf_fields' => static fn () => [ [ 'name' => 'c', 'label' => 'C', 'type' => 'select', 'choices' => $choices ] ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_CHOICES, $d['acf']['groups'][0]['fields'][0]['choices'] );
        $this->assertTrue( $d['truncated'] );
    }

    public function testAThrowingAcfGroupReaderMeansAnInactiveSectionWithANoteNotAnError(): void
    {
        $r = $this->readers( [ 'acf_groups' => static function () { throw new \RuntimeException( 'boom' ); } ] );
        $d = SiteStructure::catalogWith( $r, [ 'acf' ] );
        $this->assertSame( [ 'active' => false, 'groups' => [] ], $d['acf'], 'a throwing group reader is treated like ACF being absent' );
        $this->assertNotEmpty( $d['notes'] );
    }

    public function testAThrowingAcfFieldReaderKeepsTheGroupWithAnEmptyFieldList(): void
    {
        $r   = $this->readers( [
            'acf_groups' => static fn () => [ [ 'key' => 'group_a', 'title' => 'A' ] ],
            'acf_fields' => static function () { throw new \RuntimeException( 'boom' ); },
        ] );
        $acf = SiteStructure::catalogWith( $r, [ 'acf' ] )['acf'];
        $this->assertTrue( $acf['active'], 'ACF is still active when only the field reader fails' );
        $this->assertCount( 1, $acf['groups'] );
        $this->assertSame( [], $acf['groups'][0]['fields'], 'the group is listed with no fields, no exception' );
    }

    public function testAcfGroupsAreCappedAtTheLimitAndOnlyAnExtraOneFlagsTruncated(): void
    {
        $groups = static function ( int $n ): array {
            $g = [];
            for ( $i = 1; $i <= $n; $i++ ) {
                $g[] = [ 'key' => 'group_' . $i, 'title' => 'G' . $i ];
            }

            return $g;
        };
        $over = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => static fn () => $groups( SiteStructure::MAX_ITEMS + 1 ) ] ), [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_ITEMS, $over['acf']['groups'] );
        $this->assertTrue( $over['truncated'] );

        $exact = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => static fn () => $groups( SiteStructure::MAX_ITEMS ) ] ), [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_ITEMS, $exact['acf']['groups'] );
        $this->assertFalse( $exact['truncated'], 'exactly the limit is not truncated' );
    }

    public function testAcfFieldsInOneGroupAreCappedAtTheLimitAndOnlyAnExtraOneFlagsTruncated(): void
    {
        $fields = static function ( int $n ): array {
            $f = [];
            for ( $i = 1; $i <= $n; $i++ ) {
                $f[] = [ 'name' => 'f' . $i, 'label' => 'F' . $i, 'type' => 'text' ];
            }

            return $f;
        };
        $one  = static fn () => [ [ 'key' => 'group_a', 'title' => 'A' ] ];
        $over = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => $one, 'acf_fields' => static fn () => $fields( SiteStructure::MAX_FIELDS + 1 ) ] ), [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_FIELDS, $over['acf']['groups'][0]['fields'] );
        $this->assertTrue( $over['truncated'] );

        $exact = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => $one, 'acf_fields' => static fn () => $fields( SiteStructure::MAX_FIELDS ) ] ), [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_FIELDS, $exact['acf']['groups'][0]['fields'] );
        $this->assertFalse( $exact['truncated'], 'exactly the limit is not truncated' );
    }

    public function testTextValuesAreCutAndNonScalarsBecomeEmpty(): void
    {
        $r = $this->readers( [ 'post_types' => static fn () => [ 'x' => [ 'label' => str_repeat( 'é', 300 ), 'supports' => [ [ 'nested' ], 'title' ] ] ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'post_types' ] );
        $this->assertSame( SiteStructure::MAX_TEXT, mb_strlen( $d['post_types'][0]['label'] ) );
        $this->assertSame( [ 'title' ], $d['post_types'][0]['supports'], 'only strings are kept' );
    }

    // ---- the real readers over the WordPress / ACF shims ---------------------------------------

    public function testTheRealReadersMapWordPressObjectsAndAcfArrays(): void
    {
        $GLOBALS['__ss_post_types']        = [ 'book' => (object) [ 'label' => 'Books', 'has_archive' => true, 'hierarchical' => false ] ];
        $GLOBALS['__ss_supports']          = [ 'book' => [ 'title' => true, 'editor' => true, 'comments' => false ] ];
        $GLOBALS['__ss_object_taxonomies'] = [ 'book' => [ 'genre' ] ];
        $GLOBALS['__ss_taxonomies']        = [ 'genre' => (object) [ 'label' => 'Genres', 'object_type' => [ 'book' ], 'hierarchical' => true ] ];
        $GLOBALS['__ss_image_sizes']       = [ 'card' => [ 'width' => 400, 'height' => 300, 'crop' => [ 'center', 'top' ] ] ];
        $GLOBALS['__ss_acf_groups']        = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']        = [ 'group_1' => [ [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'value' => 'SECRET-VALUE' ] ] ];

        $d = SiteStructure::catalog();

        $this->assertSame( [ 'name' => 'book', 'label' => 'Books', 'supports' => [ 'title', 'editor' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ], $d['post_types'][0] );
        $this->assertSame( [ 'name' => 'genre', 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ], $d['taxonomies'][0] );
        $this->assertTrue( $d['image_sizes'][0]['crop'], 'an array crop position counts as cropped' );
        $this->assertSame( [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ], $d['acf']['groups'][0]['fields'][0] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $d ), 'field values are never returned' );
    }
}
