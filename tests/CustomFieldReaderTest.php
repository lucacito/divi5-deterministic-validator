<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CustomFieldReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';

class CustomFieldReaderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__cf_protected'] = [ 'wp_protected_by_filter' ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__cf_protected'] );
    }

    /** @param array<string,mixed> $meta */
    private function read( array $meta, array $only = [], array $acf = [] ): array
    {
        return CustomFieldReader::readWith( array_map( 'strval', array_keys( $meta ) ), $only, static fn ( string $k ): mixed => $meta[ $k ], $acf );
    }

    // ---- classify -----------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider( 'keyClasses' )]
    public function testClassify( string $key, string $expected ): void
    {
        $this->assertSame( $expected, CustomFieldReader::classify( $key ) );
    }

    /** @return list<array{string,string}> */
    public static function keyClasses(): array
    {
        return [
            [ 'headline', 'shown' ], [ 'project_url', 'shown' ], [ '123', 'shown' ],
            [ '_edit_lock', 'hidden' ], [ '_headline', 'hidden' ], [ '', 'hidden' ], [ 'wp_protected_by_filter', 'hidden' ],
            [ 'api_key', 'secret' ], [ 'my-api-key', 'secret' ], [ 'apikey', 'secret' ], [ 'Stripe_Token', 'secret' ],
            [ 'admin_password', 'secret' ], [ 'passwd', 'secret' ], [ 'client_secret', 'secret' ], [ 'license_code', 'secret' ], [ 'licence', 'secret' ],
            [ 'smtp_pass', 'secret' ], [ 'db-pass', 'secret' ], [ 'pass', 'secret' ], [ 'Pass_word_hint', 'secret' ], [ 'pwd', 'secret' ], [ 'stripe_private_key', 'secret' ],
            [ 'private-key', 'secret' ], [ 'aws_access_key', 'secret' ], [ 'auth_key', 'secret' ], [ 'credentials', 'secret' ], [ 'user_credential', 'secret' ],
            [ 'passage', 'shown' ], [ 'compass', 'shown' ], [ 'bypass_url', 'shown' ], [ 'pass_through', 'secret' ], [ 'passport_no', 'shown' ],
        ];
    }

    // ---- parseKeys ----------------------------------------------------------------------------

    public function testParseKeysNoFilter(): void
    {
        foreach ( [ null, '', [], ' ', ' , ' ] as $none ) {
            $this->assertSame( [], CustomFieldReader::parseKeys( $none ) );
        }
    }

    public function testParseKeysAcceptsAListOrACommaStringTrimmedAndDeduplicated(): void
    {
        $this->assertSame( [ 'a', 'b' ], CustomFieldReader::parseKeys( [ 'a', ' b ', 'a' ] ) );
        $this->assertSame( [ 'a', 'b' ], CustomFieldReader::parseKeys( 'a, b' ) );
        $this->assertSame( [ '123' ], CustomFieldReader::parseKeys( '123' ), 'a numeric-looking key stays a string' );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badKeys' )]
    public function testParseKeysRejectsAnythingElse( mixed $bad ): void
    {
        $this->assertNull( CustomFieldReader::parseKeys( $bad ) );
    }

    /** @return list<array{mixed}> */
    public static function badKeys(): array
    {
        return [ [ 7 ], [ true ], [ [ 1 ] ], [ [ [ 'a' ] ] ], [ [ 'k' => 'a' ] ], [ array_map( 'strval', range( 1, 101 ) ) ] ];
    }

    // ---- readWith -----------------------------------------------------------------------------

    public function testReturnsShownKeysSortedAndWithholdsSecretsAndDropsProtectedOnes(): void
    {
        $r = $this->read( [ 'subtitle' => 'Sub', 'headline' => 'Hi', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE', 'stripe_token' => 'x', 'wp_protected_by_filter' => 'p' ] );
        $this->assertSame( [ 'headline', 'subtitle' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 'Hi', $r['fields'][0]['value'] );
        $this->assertSame( 2, $r['withheld'], 'only secret-looking keys are counted; protected keys are dropped silently' );
        $this->assertFalse( $r['truncated'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $r ) );
    }

    public function testAnExplicitlyRequestedHiddenKeyIsStillNotReturned(): void
    {
        $meta = [ 'headline' => 'Hi', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE' ];
        $r    = $this->read( $meta, [ '_edit_lock', 'api_key', 'headline', 'missing' ] );
        $this->assertSame( [ 'headline' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 1, $r['withheld'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $r ) );
    }

    public function testValuesAreOnlyLoadedForKeysThatAreReturned(): void
    {
        $asked = [];
        CustomFieldReader::readWith( [ 'headline', '_edit_lock', 'api_key' ], [], static function ( string $k ) use ( &$asked ): mixed {
            $asked[] = $k;

            return 'v';
        }, [] );
        $this->assertSame( [ 'headline' ], $asked );
    }

    public function testNumericLookingKeysStayStrings(): void
    {
        $r = $this->read( [ '10' => 'ten', '9' => 'nine' ], [ '10' ] );
        $this->assertSame( [ '10' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 'ten', $r['fields'][0]['value'] );
        $all = $this->read( [ '10' => 'ten', '9' => 'nine' ] );
        $this->assertSame( [ '10', '9' ], array_column( $all['fields'], 'key' ), 'string sort, deterministic' );
        $this->assertIsString( $all['fields'][0]['key'] );
    }

    public function testValuesAreAlwaysStrings(): void
    {
        $r = $this->read( [ 'arr' => [ 1, 2, 3 ], 'flag' => true, 'num' => 5, 'nothing' => null, 'obj' => [ 'a' => 'b' ] ] );
        $by = array_column( $r['fields'], 'value', 'key' );
        $this->assertSame( '[1,2,3]', $by['arr'] );
        $this->assertSame( '1', $by['flag'] );
        $this->assertSame( '5', $by['num'] );
        $this->assertSame( '', $by['nothing'] );
        $this->assertSame( '{"a":"b"}', $by['obj'] );
    }

    public function testAValueIsCutAtTheCapAndTheAnswerSaysSo(): void
    {
        $exact = $this->read( [ 'k' => str_repeat( 'a', CustomFieldReader::MAX_VALUE ) ] );
        $this->assertFalse( $exact['truncated'] );
        $over = $this->read( [ 'k' => str_repeat( 'a', CustomFieldReader::MAX_VALUE + 1 ) ] );
        $this->assertTrue( $over['truncated'] );
        $this->assertSame( CustomFieldReader::MAX_VALUE, mb_strlen( $over['fields'][0]['value'] ) );
    }

    public function testAMultibyteValueIsCutOnACharacterBoundary(): void
    {
        $r = $this->read( [ 'k' => str_repeat( 'é', 600 ) . '😀' ] );
        $this->assertSame( CustomFieldReader::MAX_VALUE, mb_strlen( $r['fields'][0]['value'] ) );
        $this->assertSame( 1, preg_match( '//u', $r['fields'][0]['value'] ), 'still valid UTF-8' );
    }

    public function testTheFieldCapTruncatesButStillCountsLaterSecrets(): void
    {
        $meta = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_FIELDS + 5; $i++ ) {
            $meta[ sprintf( 'k%03d', $i ) ] = 'v';
        }
        $meta['zz_token'] = 'x';
        $meta['zz_password'] = 'y';
        $r = $this->read( $meta );
        $this->assertCount( CustomFieldReader::MAX_FIELDS, $r['fields'] );
        $this->assertTrue( $r['truncated'] );
        $this->assertSame( 2, $r['withheld'] );
        $this->assertSame( 'k000', $r['fields'][0]['key'] );
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $meta = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_FIELDS; $i++ ) {
            $meta[ sprintf( 'k%03d', $i ) ] = 'v';
        }
        $r = $this->read( $meta );
        $this->assertCount( CustomFieldReader::MAX_FIELDS, $r['fields'] );
        $this->assertFalse( $r['truncated'] );
    }

    public function testAcfLabelAndTypeAreJoinedWhenKnownAndNullOtherwise(): void
    {
        $r = $this->read( [ 'headline' => 'Hi', 'plain' => 'x' ], [], [ 'headline' => [ 'label' => 'Headline', 'type' => 'text' ] ] );
        $this->assertSame( [ 'label' => 'Headline', 'type' => 'text' ], $r['fields'][0]['acf'] );
        $this->assertNull( $r['fields'][1]['acf'] );
    }

    public function testNoFieldsIsANormalAnswer(): void
    {
        $this->assertSame( [ 'fields' => [], 'withheld' => 0, 'truncated' => false ], $this->read( [] ) );
    }

    // ---- acfInfoWith --------------------------------------------------------------------------

    public function testAcfInfoCollectsTopLevelAndGroupFieldsWithTheirStoredKeys(): void
    {
        $info = CustomFieldReader::acfInfoWith(
            static fn () => [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ], [ 'no' => 'key' ] ],
            static fn ( string $key ) => 'group_a' === $key
                ? [ [ 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ], [ 'name' => 'address', 'label' => 'Address', 'type' => 'group', 'sub_fields' => [ [ 'name' => 'city', 'label' => 'City', 'type' => 'text' ] ] ], [ 'name' => '', 'type' => 'tab' ] ]
                : false
        );
        $this->assertSame( [ 'headline', 'address', 'address_city' ], array_keys( $info ) );
        $this->assertSame( [ 'label' => 'City', 'type' => 'text' ], $info['address_city'] );
    }

    public function testAcfInfoIsEmptyWhenAcfCannotBeReadOrThrows(): void
    {
        $this->assertSame( [], CustomFieldReader::acfInfoWith( null, null ) );
        $this->assertSame( [], CustomFieldReader::acfInfoWith( static fn () => throw new \RuntimeException( 'x' ), static fn () => [] ) );
        $this->assertSame( [], CustomFieldReader::acfInfoWith( static fn () => 'nope', static fn () => [] ) );
    }

    public function testAnAcfPasswordFieldIsWithheldWhateverItsNameAndItsValueIsNeverLoaded(): void
    {
        $asked = [];
        $r = CustomFieldReader::readWith( [ 'headline', 'site_login' ], [], static function ( string $k ) use ( &$asked ): mixed {
            $asked[] = $k;

            return 'SECRET-VALUE';
        }, [ 'site_login' => [ 'label' => 'Login', 'type' => 'password' ], 'headline' => [ 'label' => 'H', 'type' => 'text' ] ] );
        $this->assertSame( [ 'headline' ], array_column( $r['fields'], 'key' ) );
        $this->assertSame( 1, $r['withheld'] );
        $this->assertSame( [ 'headline' ], $asked, 'the password value is never loaded' );
    }

    public function testDiscoveryDoesNotListAnAcfPasswordField(): void
    {
        $d = CustomFieldReader::discoverWith( [ 'book' => [ [ 'isbn', 'site_login' ] ] ], [ 'site_login' => [ 'label' => 'Login', 'type' => 'password' ] ] );
        $this->assertSame( [ 'isbn' ], array_column( $d['post_types'][0]['keys'], 'key' ) );
    }

    public function testAValueThatCannotBeJsonEncodedIsTreatedAsCut(): void
    {
        foreach ( [ [ 'x' => NAN ], [ "bad\xB1utf8" ], [ 'deep' => [ 'bad' => "\xff" ] ] ] as $value ) {
            $r = $this->read( [ 'k' => $value ] );
            $this->assertSame( '', $r['fields'][0]['value'] );
            $this->assertTrue( $r['truncated'] );
        }
    }

    // ---- discoverWith -------------------------------------------------------------------------

    public function testDiscoveryCountsPostsPerKeyAndFiltersLikeReading(): void
    {
        $d = CustomFieldReader::discoverWith( [
            'book'    => [ [ 'isbn', '_edit_lock', 'api_key', 'isbn' ], [ 'isbn', 'format' ], [] ],
            'page'    => [],
            'project' => [ [ 'project_url' ] ],
        ], [ 'isbn' => [ 'label' => 'ISBN', 'type' => 'text' ] ] );
        $this->assertFalse( $d['truncated'] );
        $this->assertSame( [ 'book', 'project' ], array_column( $d['post_types'], 'post_type' ), 'a type with no sampled posts is omitted' );
        $book = $d['post_types'][0];
        $this->assertSame( 3, $book['sampled'] );
        $this->assertSame( [ [ 'key' => 'isbn', 'posts' => 2, 'acf' => [ 'label' => 'ISBN', 'type' => 'text' ] ], [ 'key' => 'format', 'posts' => 1, 'acf' => null ] ], $book['keys'] );
    }

    public function testDiscoveryCapsKeysPerTypeAndSaysSo(): void
    {
        $keys = [];
        for ( $i = 0; $i < CustomFieldReader::MAX_KEYS_PER_TYPE + 3; $i++ ) {
            $keys[] = sprintf( 'k%03d', $i );
        }
        $d = CustomFieldReader::discoverWith( [ 'book' => [ $keys ] ], [] );
        $this->assertCount( CustomFieldReader::MAX_KEYS_PER_TYPE, $d['post_types'][0]['keys'] );
        $this->assertTrue( $d['truncated'] );
    }
}
