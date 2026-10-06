<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariablePlanner;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariablePlannerTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->seq = 0;
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    /** @param list<mixed> $entries */
    private function plan( array $entries ): array
    {
        return GlobalVariablePlanner::plan( (array) GlobalVariables::read(), GlobalVariablePlanner::normalize( $entries ), function (): string {
            return 'gvid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        } );
    }

    private function refused( array $entries, string $needle ): void
    {
        try {
            $this->plan( $entries );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was changed', $e->getMessage() );
        }
    }

    public function testNormalizeKeepsOnlyTheKnownFieldsAndTrimsValues(): void
    {
        $rows = GlobalVariablePlanner::normalize( [ [ 'type' => 'numbers', 'label' => ' Card radius ', 'value' => ' 12px ' ], [ 'id' => self::N1, 'status' => 'archived' ] ] );
        $this->assertSame( [ 'id' => null, 'type' => 'numbers', 'label' => 'Card radius', 'value' => '12px', 'status' => null ], $rows[0] );
        $this->assertSame( [ 'id' => self::N1, 'type' => null, 'label' => null, 'value' => null, 'status' => 'archived' ], $rows[1] );
    }

    /** @return array<string,array{list<mixed>,string}> */
    public static function badEntries(): array
    {
        return [
            'not an object'                 => [ [ 'x' ], 'must be an object' ],
            'a list'                        => [ [ [ 'a', 'b' ] ], 'must be an object' ],
            'unknown field'                 => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => '1px', 'order' => '5' ] ], 'unknown field "order"' ],
            'a non-string field'            => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => 12 ] ], '"value" must be a string' ],
            'create without type'           => [ [ [ 'label' => 'A', 'value' => '1px' ] ], 'creating a variable needs' ],
            'create without label'          => [ [ [ 'type' => 'numbers', 'value' => '1px' ] ], 'creating a variable needs' ],
            'create without value'          => [ [ [ 'type' => 'numbers', 'label' => 'A' ] ], 'creating a variable needs' ],
            'a type that is not managed'    => [ [ [ 'type' => 'images', 'label' => 'A', 'value' => 'x' ] ], '"type" must be numbers or strings' ],
            'a bad number'                  => [ [ [ 'type' => 'numbers', 'label' => 'A', 'value' => 'calc(1px; x)' ] ], 'numbers variable' ],
            'a bad string'                  => [ [ [ 'type' => 'strings', 'label' => 'A', 'value' => 'a{b}' ] ], 'strings variable' ],
            'a bad label'                   => [ [ [ 'type' => 'numbers', 'label' => '<b>', 'value' => '1px' ] ], '"label" must be 1 to 60' ],
            'a label of zero'               => [ [ [ 'type' => 'numbers', 'label' => '0', 'value' => '1px' ] ], '"label" must be 1 to 60' ],
            'a bad id'                      => [ [ [ 'id' => 'gcid-abc', 'status' => 'archived' ] ], '"id" must look like gvid-' ],
            'a bad status'                  => [ [ [ 'id' => self::N1, 'status' => 'inactive' ] ], '"status" must be active or archived' ],
            'type on an update'             => [ [ [ 'id' => self::N1, 'type' => 'numbers', 'value' => '2px' ] ], 'never changes type' ],
            'an update that changes nothing' => [ [ [ 'id' => self::N1 ] ], 'at least one of label, value, status' ],
        ];
    }

    /** @param list<mixed> $entries */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'badEntries' )]
    public function testNormalizeRefusesTheWholeRequestNamingTheEntry( array $entries, string $needle ): void
    {
        $this->refused( $entries, $needle );
        $this->refused( $entries, 'variables[0]' );
    }

    public function testASeveralProblemRequestNamesEveryBadEntry(): void
    {
        $this->refused( [ [ 'label' => 'A' ], [ 'type' => 'numbers', 'label' => 'B', 'value' => 'red' ] ], 'variables[1]' );
    }

    public function testCreateAssignsIdOrderAndStatusPerType(): void
    {
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Built with care', 'status' => 'archived' ], [ 'type' => 'numbers', 'label' => 'Pad two', 'value' => '16px' ] ] );
        $this->assertSame( [ 'create', 'create', 'create' ], array_column( $p['changes'], 'action' ) );
        $this->assertSame( [ 'gvid-new-00000000-0000-4000-8000-000000000001', 'gvid-new-00000000-0000-4000-8000-000000000002', 'gvid-new-00000000-0000-4000-8000-000000000003' ], array_column( $p['changes'], 'id' ) );
        $this->assertSame( [ 'label' => 'Pad', 'value' => '8px', 'order' => '3', 'status' => 'active' ], $p['changes'][0]['write'], 'numbers had orders 1 and 2' );
        $this->assertSame( [ 'label' => 'Tagline', 'value' => 'Built with care', 'order' => '2', 'status' => 'archived' ], $p['changes'][1]['write'], 'strings had order 1' );
        $this->assertSame( '4', $p['changes'][2]['write']['order'], 'two creates in one call do not share an order' );
        $this->assertNull( $p['changes'][0]['before'] );
        $this->assertSame( [ 'label' => 'Pad', 'value' => '8px', 'status' => 'active' ], $p['changes'][0]['after'] );
    }

    public function testTheFirstVariableOfATypeGetsOrderOne(): void
    {
        GlobalVariablesFixture::reset( [ 'strings' => [] ] );
        $p = $this->plan( [ [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hi' ] ] );
        $this->assertSame( '1', $p['changes'][0]['write']['order'] );
    }

    public function testStoredOrdersThatAreMissingOrOddAreHandled(): void
    {
        GlobalVariablesFixture::reset( [ 'numbers' => [ self::N1 => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ] ] ] );
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] );
        $this->assertSame( '1', $p['changes'][0]['write']['order'] );
    }

    public function testUpdateChangesOnlyTheGivenFieldsAndKeepsTheRestOfTheEntry(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'value' => '16px' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( [ 'value' ], $c['fields'] );
        $this->assertSame( 'numbers', $c['type'] );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '16px', 'order' => '1', 'status' => 'active' ], $c['write'] );
        $this->assertSame( '12px', $c['before']['value'] );
        $this->assertSame( '16px', $c['after']['value'] );
    }

    public function testAStatusOnlyChangeIsArchiveOrRestore(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'status' => 'archived' ], [ 'id' => self::N2, 'status' => 'active' ] ] );
        $this->assertSame( [ 'archive', 'restore' ], array_column( $p['changes'], 'action' ) );
    }

    public function testAnEntryThatAlreadyIsLikeThatIsUnchanged(): void
    {
        $p = $this->plan( [ [ 'id' => self::N1, 'value' => '12px', 'status' => 'active' ] ] );
        $this->assertSame( [], $p['changes'] );
        $this->assertSame( [ self::N1 ], $p['unchanged'] );
    }

    public function testUpdateValuesAreCheckedAgainstTheTypeOfTheStoredVariable(): void
    {
        $this->refused( [ [ 'id' => self::N1, 'value' => 'Call us' ] ], 'numbers variable' );
        $this->refused( [ [ 'id' => self::S1, 'value' => '12px;' ] ], 'strings variable' );
        $this->assertSame( 'update', $this->plan( [ [ 'id' => self::S1, 'value' => '12px' ] ] )['changes'][0]['action'] );
    }

    public function testAnUnknownIdAnIdOfAnotherGroupAndADuplicateRefuseTheWholeCall(): void
    {
        $this->refused( [ [ 'type' => 'numbers', 'label' => 'Ok', 'value' => '1px' ], [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ] ], 'no variable with the id gvid-doesnotexist-0000' );
        $this->refused( [ [ 'id' => self::IMG, 'label' => 'Renamed' ] ], 'only numbers and strings variables can be changed' );
        $this->refused( [ [ 'id' => self::N1, 'value' => '1px' ], [ 'id' => self::N1, 'value' => '2px' ] ], 'already in this call' );
        $this->refused( [ [ 'id' => '--et_global_heading_font', 'value' => '1px' ] ], 'must look like gvid-' );
    }

    public function testTwoCreatesWithTheSameTypeAndLabelAreRefusedButOtherTypesMayShareALabel(): void
    {
        $this->refused( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '1px' ], [ 'type' => 'numbers', 'label' => 'PAD', 'value' => '2px' ] ], 'already named' );
        $p = $this->plan( [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '1px' ], [ 'type' => 'strings', 'label' => 'Pad', 'value' => 'x' ] ] );
        $this->assertCount( 2, $p['changes'] );
    }

    public function testMultiByteLabelsAndValuesPass(): void
    {
        $p = $this->plan( [ [ 'type' => 'strings', 'label' => "Caf\u{E9} \u{2615}", 'value' => "Na\u{EF}ve caf\u{E9}" ] ] );
        $this->assertSame( "Caf\u{E9} \u{2615}", $p['changes'][0]['write']['label'] );
    }
}
