<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\AcfFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class AcfFieldsTest extends TestCase
{
    /** @return array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private function index(): array
    {
        return AcfFields::indexWith(
            static fn () => [ [ 'key' => 'group_a' ], [ 'key' => 'group_b' ], [ 'no' => 'key' ] ],
            static fn ( string $key ) => 'group_a' === $key ? [
                [ 'key' => 'field_headline', 'name' => 'headline', 'type' => 'text' ],
                [ 'key' => 'field_size', 'name' => 'size', 'type' => 'select', 'choices' => [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ] ],
                [ 'key' => 'field_many', 'name' => 'many', 'type' => 'select', 'multiple' => 1, 'choices' => [ 'a' => 'A' ] ],
                [ 'key' => 'field_addr', 'name' => 'address', 'type' => 'group', 'sub_fields' => [ [ 'key' => 'field_city', 'name' => 'city', 'type' => 'text' ] ] ],
                [ 'key' => 'field_team', 'name' => 'team', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => 'field_member', 'name' => 'member', 'type' => 'text' ] ] ],
                [ 'name' => '', 'type' => 'tab' ],
            ] : false
        );
    }

    public function testTheIndexKeepsKeyTypeChoicesAndFlagsNestedGroupMembers(): void
    {
        $i = $this->index();
        $this->assertSame( [ 'headline', 'size', 'many', 'address', 'address_city', 'team' ], array_keys( $i['fields'] ) );
        $this->assertSame( 'field_headline', $i['fields']['headline']['field_key'] );
        $this->assertSame( [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ], $i['fields']['size']['choices'] );
        $this->assertTrue( $i['fields']['many']['multiple'] );
        $this->assertFalse( $i['fields']['address']['nested'] );
        $this->assertTrue( $i['fields']['address_city']['nested'] );
        $this->assertEqualsCanonicalizing( [ 'address_', 'team_' ], $i['prefixes'] );
    }

    public function testAMissingOrThrowingReaderGivesAnEmptyIndex(): void
    {
        $empty = [ 'fields' => [], 'prefixes' => [] ];
        $this->assertSame( $empty, AcfFields::indexWith( null, null ) );
        $this->assertSame( $empty, AcfFields::indexWith( static fn () => throw new \RuntimeException( 'x' ), static fn () => [] ) );
        $this->assertSame( $empty, AcfFields::indexWith( static fn () => 'nope', static fn () => [] ) );
    }

    /** @return list<array{string,string,?string}> type, value, expected reason substring (null = allowed) */
    public static function typeCases(): array
    {
        return [
            [ 'text', 'anything <b>goes</b>', null ], [ 'textarea', "multi\nline", null ], [ 'wysiwyg', '<p>x</p>', null ], [ 'url', 'https://example.test/', null ],
            [ 'email', 'a@b.test', null ], [ 'date_picker', '20261009', null ], [ 'color_picker', '#ff0000', null ],
            [ 'number', '42', null ], [ 'number', '4.5', null ], [ 'number', 'abc', 'number' ], [ 'range', '7', null ], [ 'range', 'x', 'number' ],
            [ 'true_false', '1', null ], [ 'true_false', '0', null ], [ 'true_false', 'yes', '0 or 1' ],
            [ 'password', 'x', 'ACF editor' ], [ 'checkbox', 'a', 'ACF editor' ], [ 'repeater', 'x', 'ACF editor' ], [ 'group', 'x', 'ACF editor' ],
            [ 'image', '12', 'ACF editor' ], [ 'relationship', '12', 'ACF editor' ], [ 'flexible_content', 'x', 'ACF editor' ], [ 'google_map', 'x', 'ACF editor' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'typeCases' )]
    public function testTheTypeRules( string $type, string $value, ?string $needle ): void
    {
        $def    = [ 'field_key' => 'f', 'type' => $type, 'choices' => [], 'multiple' => false, 'nested' => false ];
        $reason = AcfFields::refusalFor( $def, $value );
        if ( null === $needle ) {
            $this->assertNull( $reason, "{$type}: {$value}" );
        } else {
            $this->assertNotNull( $reason, "{$type}: {$value}" );
            $this->assertStringContainsString( $needle, $reason );
        }
    }

    public function testChoiceFieldsOnlyAcceptTheirChoicesAndMultiSelectIsRefused(): void
    {
        $choices = [ 's' => 'Small', 'l' => 'Large', 3 => 'Three' ];
        foreach ( [ 'select', 'radio', 'button_group' ] as $type ) {
            $def = [ 'field_key' => 'f', 'type' => $type, 'choices' => $choices, 'multiple' => false, 'nested' => false ];
            $this->assertNull( AcfFields::refusalFor( $def, 'l' ), $type );
            $this->assertNull( AcfFields::refusalFor( $def, '3' ), "{$type}: numeric choice key" );
            $this->assertStringContainsString( 'one of', (string) AcfFields::refusalFor( $def, 'xl' ) );
            $this->assertStringContainsString( 'one of', (string) AcfFields::refusalFor( $def, '' ) );
        }
        $multi = [ 'field_key' => 'f', 'type' => 'select', 'choices' => $choices, 'multiple' => true, 'nested' => false ];
        $this->assertStringContainsString( 'ACF editor', (string) AcfFields::refusalFor( $multi, 'l' ) );
    }
}
