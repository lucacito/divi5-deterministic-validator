<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CustomFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';

class CustomFieldsTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function acfGroups(): array
    {
        return [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ] ];
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function fieldsByGroup(): array
    {
        return [
            'group_a' => [
                [ 'name' => 'headline', 'type' => 'text' ],
                [ 'name' => 'photo', 'type' => 'image' ],
                [ 'name' => 'address', 'type' => 'group', 'sub_fields' => [ [ 'name' => 'street', 'type' => 'text' ], [ 'name' => 'city', 'type' => 'text' ] ] ],
            ],
            'group_b' => [
                [ 'name' => 'team', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'member', 'type' => 'text' ] ] ],
                [ 'name' => '', 'type' => 'tab' ],
                [ 'type' => 'message' ],
            ],
        ];
    }

    public function testAcfNamesCollectTopLevelAndGroupFieldsWithTheirStoredKeys(): void
    {
        $acf = CustomFields::acfNamesWith( fn () => $this->acfGroups(), fn ( string $key ) => $this->fieldsByGroup()[ $key ] ?? [] );

        $this->assertArrayHasKey( 'headline', $acf['names'] );
        $this->assertArrayHasKey( 'photo', $acf['names'] );
        $this->assertArrayHasKey( 'address', $acf['names'] );
        $this->assertArrayHasKey( 'address_street', $acf['names'], 'a group field is stored as group_sub' );
        $this->assertArrayHasKey( 'address_city', $acf['names'] );
        $this->assertArrayHasKey( 'team', $acf['names'] );
        $this->assertArrayNotHasKey( 'member', $acf['names'], 'a repeater sub field is not a top-level key' );
        $this->assertArrayNotHasKey( '', $acf['names'], 'a nameless field (tab, message) is not a key' );
        $this->assertSame( [ 'team_' ], $acf['prefixes'], 'a repeater is stored as team_0_member, so its prefix counts as known' );
    }

    public function testAcfNamesAreEmptyWhenAcfCannotBeRead(): void
    {
        $this->assertSame( [ 'names' => [], 'prefixes' => [] ], CustomFields::acfNamesWith( null, null ) );
        $this->assertSame( [ 'names' => [], 'prefixes' => [] ], CustomFields::acfNamesWith( function () { throw new \RuntimeException( 'x' ); }, null ) );
        $this->assertSame( [ 'names' => [], 'prefixes' => [] ], CustomFields::acfNamesWith( fn () => 'junk', fn () => [] ) );
        $acf = CustomFields::acfNamesWith( fn () => $this->acfGroups(), function () { throw new \RuntimeException( 'x' ); } );
        $this->assertSame( [], $acf['names'], 'a reader that throws for a group means that group is skipped' );
    }

    public function testALookupKnowsAcfFieldsAndPrefixesWithoutAskingTheDatabase(): void
    {
        $asked  = [];
        $lookup = CustomFields::lookupWith( [ 'names' => [ 'headline' => true ], 'prefixes' => [ 'team_' ] ], function ( string $k ) use ( &$asked ): bool {
            $asked[] = $k;

            return false;
        } );

        $this->assertTrue( $lookup( 'headline' ) );
        $this->assertTrue( $lookup( 'team_0_member' ) );
        $this->assertSame( [], $asked );
    }

    public function testALookupFallsBackToAnyPostHavingThatMetaKeyAndAsksOncePerKey(): void
    {
        $asked  = [];
        $lookup = CustomFields::lookupWith( [ 'names' => [], 'prefixes' => [] ], function ( string $k ) use ( &$asked ): bool {
            $asked[] = $k;

            return 'plain_meta' === $k;
        } );

        $this->assertTrue( $lookup( 'plain_meta' ) );
        $this->assertTrue( $lookup( 'plain_meta' ) );
        $this->assertFalse( $lookup( 'nowhere' ) );
        $this->assertFalse( $lookup( 'nowhere' ) );
        $this->assertSame( [ 'plain_meta', 'nowhere' ], $asked );
    }

    public function testALookupStopsClaimingAKeyIsMissingAfterTooManyDatabaseChecks(): void
    {
        $lookup = CustomFields::lookupWith( [ 'names' => [], 'prefixes' => [] ], fn (): bool => false );
        for ( $i = 0; $i < CustomFields::MAX_LOOKUPS; $i++ ) {
            $this->assertFalse( $lookup( 'missing_' . $i ) );
        }
        $this->assertTrue( $lookup( 'one_too_many' ), 'past the cap the answer is "cannot tell", never "missing"' );
    }
}
