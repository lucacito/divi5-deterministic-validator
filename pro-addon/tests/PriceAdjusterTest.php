<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\PriceAdjuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The exact decimal arithmetic of bulk_adjust_prices. No floats: the generated expectations were worked out with Python's Decimal. */
class PriceAdjusterTest extends TestCase
{
    /** @return array{price: ?string, reason: ?string} */
    private function adjust( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): array
    {
        return PriceAdjuster::adjust( $old, $sale, $mode, $value, $decimals, $roundTo );
    }

    private function price( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): string
    {
        $r = $this->adjust( $old, $mode, $value, $decimals, $roundTo, $sale );
        $this->assertNull( $r['reason'], (string) $r['reason'] );

        return (string) $r['price'];
    }

    private function reason( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): string
    {
        $r = $this->adjust( $old, $mode, $value, $decimals, $roundTo, $sale );
        $this->assertNull( $r['price'], (string) $r['price'] );

        return (string) $r['reason'];
    }

    /** @return list<array{string,string,string,int,string}> old, mode, value, decimals, expected price or reason */
    public static function generated(): array
    {
        return [
        [ '67912.7', 'amount', '12659', 0, '80572' ],
        [ '1722338', 'amount', '8193', 2, '1730531.00' ],
        [ '450255', 'percent', '204.19', 2, '1369630.68' ],
        [ '14649.8', 'percent', '281.13', 2, '55834.78' ],
        [ '123964', 'percent', '333.28', 3, '537111.219' ],
        [ '1222634', 'amount', '-2675.1', 0, '1219959' ],
        [ '116741.1', 'amount', '-253.2', 2, '116487.90' ],
        [ '2470.29', 'percent', '-12.47', 2, '2162.24' ],
        [ '119790.3', 'amount', '-23615', 2, '96175.30' ],
        [ '12499.3', 'amount', '14.59', 4, '12513.8900' ],
        [ '658.815', 'amount', '-63.04', 1, '595.8' ],
        [ '166593.5', 'percent', '-26.36', 2, '122679.45' ],
        [ '629.669', 'amount', '17.804', 2, '647.47' ],
        [ '1277080', 'percent', '255.5', 2, '4540019.40' ],
        [ '3459.51', 'percent', '240.44', 2, '11777.56' ],
        [ '82224', 'amount', '-77.1', 2, '82146.90' ],
        [ '1041.603', 'percent', '-18.67', 2, '847.14' ],
        [ '994257', 'percent', '399.17', 3, '4963032.667' ],
        [ '649.294', 'amount', '16.964', 3, '666.258' ],
        [ '727723', 'amount', '-670.5', 2, '727052.50' ],
        [ '245.568', 'percent', '63', 4, '400.2758' ],
        [ '60278.9', 'percent', '180.76', 2, '169239.04' ],
        [ '1922.703', 'percent', '29.02', 2, '2480.67' ],
        [ '8423.1', 'percent', '202.14', 4, '25449.5543' ],
        [ '11538.95', 'amount', '-6.488', 1, '11532.5' ],
        [ '316505', 'percent', '19.15', 1, '377115.7' ],
        [ '138101', 'percent', '237.82', 4, '466532.7982' ],
        [ '123548.2', 'amount', '-11524', 1, '112024.2' ],
        [ '8785.95', 'amount', '-21776', 2, 'not_positive' ],
        [ '1886.458', 'amount', '-3.853', 0, '1883' ],
        [ '1009.827', 'percent', '44.91', 0, '1463' ],
        [ '437.809', 'percent', '-7.96', 2, '402.96' ],
        [ '1259817', 'percent', '-79.85', 2, '253853.13' ],
        [ '317226', 'amount', '10221', 0, '327447' ],
        [ '183360.8', 'amount', '-202.65', 2, '183158.15' ],
        [ '12630.72', 'amount', '-21950', 4, 'not_positive' ],
        [ '1023.553', 'amount', '17.08', 0, '1041' ],
        [ '302237', 'amount', '185.19', 2, '302422.19' ],
        [ '173823.6', 'percent', '54.48', 2, '268522.70' ],
        [ '75865', 'percent', '266.1', 2, '277741.77' ],
        [ '1348295', 'amount', '39.73', 1, '1348334.7' ],
        [ '74594.9', 'amount', '1170.9', 2, '75765.80' ],
        [ '170186.3', 'percent', '182.59', 3, '480929.465' ],
        [ '168469.7', 'percent', '259.23', 2, '605193.70' ],
        [ '745669', 'percent', '103.11', 2, '1514528.31' ],
        [ '54352.9', 'amount', '-6.91', 2, '54345.99' ],
        [ '16890.1', 'percent', '68.66', 2, '28486.84' ],
        [ '4125.23', 'percent', '236.31', 2, '13873.56' ],
        [ '1888084', 'amount', '295.85', 4, '1888379.8500' ],
        [ '1348748', 'percent', '174.63', 4, '3704066.6324' ],
        [ '149210.9', 'amount', '2826.2', 2, '152037.10' ],
        [ '16549.37', 'percent', '393.05', 2, '81596.67' ],
        [ '971.319', 'percent', '395', 1, '4808.0' ],
        [ '35652.4', 'percent', '19.05', 2, '42444.18' ],
        [ '1897.613', 'percent', '320.8', 4, '7985.1555' ],
        [ '1249.631', 'amount', '-1978.3', 0, 'not_positive' ],
        [ '29870', 'percent', '204.3', 4, '90894.4100' ],
        [ '40853.7', 'percent', '85.04', 1, '75595.7' ],
        [ '61439.6', 'amount', '-13.003', 4, '61426.5970' ],
        [ '274882', 'amount', '28.831', 3, '274910.831' ],
        ];
    }

    #[DataProvider( 'generated' )]
    public function testAgainstIndependentlyComputedDecimals( string $old, string $mode, string $value, int $decimals, string $expected ): void
    {
        $r = PriceAdjuster::adjust( $old, '', $mode, $value, $decimals, null );
        $this->assertSame( $expected, $r['price'] ?? $r['reason'], "{$old} {$mode} {$value} d{$decimals}" );
    }

    public function testPercentAndAmountToTheShopsDecimals(): void
    {
        $this->assertSame( '22.00', $this->price( '20', 'percent', '10' ) );
        $this->assertSame( '21.99', $this->price( '19.99', 'percent', '10' ), '21.989 rounds to 21.99 (a float gives 21.989000000000004)' );
        $this->assertSame( '70.00', $this->price( '80', 'percent', '-12.5' ) );
        $this->assertSame( '15.00', $this->price( '20', 'amount', '-5' ) );
        $this->assertSame( '20.50', $this->price( '20', 'amount', '0.5' ) );
        $this->assertSame( '22', $this->price( '19.99', 'percent', '10', 0 ) );
        $this->assertSame( '21.989', $this->price( '19.99', 'percent', '10', 3 ) );
    }

    public function testATieRoundsUp(): void
    {
        $this->assertSame( '11.06', $this->price( '10.05', 'percent', '10' ), '11.055 is a tie' );
        $this->assertSame( '1.01', $this->price( '1', 'amount', '0.005' ), '1.005 is a tie' );
        $this->assertSame( '1.00', $this->price( '1', 'amount', '-0.005' ), '0.995 is a tie, and up is back to the old price (no change)' );
    }

    public function testRoundToEndsPricesTheWayAskedNearestTiesUp(): void
    {
        $this->assertSame( '21.99', $this->price( '20', 'percent', '10', 2, '0.99' ), '22.00 -> nearest .99 is 21.99' );
        $this->assertSame( '22.5', $this->price( '20', 'percent', '10', 1, '0.5' ), '22.00 is exactly between 21.5 and 22.5: up' );
        $this->assertSame( '22.00', $this->price( '19.99', 'percent', '10', 2, '0' ), 'whole numbers' );
        $this->assertSame( '29.95', $this->price( '25', 'percent', '20', 2, '0.95' ) );
        $this->assertSame( '14.99', $this->price( '19.99', 'amount', '-5', 2, '0.99' ) );
        $this->assertSame( '21.99', $this->price( '19.99', 'percent', '10', 0, '0.99' ), '21.989 -> nearest .99; a shop with 0 decimals still gets the two decimals round_to asks for' );
    }

    public function testRoundingMayNotUndoTheChange(): void
    {
        $this->assertSame( 'rounding_reversed', $this->reason( '20', 'percent', '1', 2, '0.99' ), '20.20 -> 19.99 is below the old price' );
        $this->assertSame( 'rounding_reversed', $this->reason( '19.9', 'percent', '-1', 2, '0.99' ), '19.701 -> 19.99 is above the old price 19.90' );
        $this->assertSame( '20.00', $this->price( '20', 'amount', '0.004' ), 'a change that rounds away is no change, not an error' );
    }

    public function testZeroAndNegativePricesAndTheLargestPrice(): void
    {
        $this->assertSame( 'not_positive', $this->reason( '20', 'amount', '-20' ) );
        $this->assertSame( 'not_positive', $this->reason( '20', 'amount', '-25' ) );
        $this->assertSame( 'not_positive', $this->reason( '0.01', 'percent', '-90' ), '0.001 rounds to 0.00' );
        $this->assertSame( 'not_positive', $this->reason( '1', 'amount', '-0.996', 2, '0' ), 'rounds to 0' );
        $this->assertSame( 'too_large', $this->reason( '9999999999', 'percent', '10' ) );
        $this->assertSame( 'too_large', $this->reason( '9999999999.99', 'amount', '0.009999' ), 'rounds to 10000000000.00' );
        $this->assertSame( '9999999999.999999', $this->price( '9999999999.99', 'amount', '0.009999', 6 ) );
        $this->assertSame( 'too_large', $this->reason( '9999999999.999999', 'percent', '500' ), 'no integer overflow on the way' );
    }

    public function testThePriceMustStayAboveAnActiveSale(): void
    {
        $this->assertSame( 'not_above_sale', $this->reason( '20', 'amount', '-3', 2, null, '18' ), '17 is below the sale' );
        $this->assertSame( 'not_above_sale', $this->reason( '20', 'amount', '-2', 2, null, '18' ), 'equal is not above' );
        $this->assertSame( '18.01', $this->price( '20', 'amount', '-1.99', 2, null, '18' ) );
        $this->assertSame( '22.00', $this->price( '20', 'percent', '10', 2, null, '18' ), 'a rise is fine' );
    }

    public function testAProductWithoutARegularPrice(): void
    {
        $this->assertSame( 'no_regular_price', $this->reason( '', 'percent', '10' ) );
    }

    public function testSameAndUnits(): void
    {
        $this->assertTrue( PriceAdjuster::same( '20', '20.00' ) );
        $this->assertFalse( PriceAdjuster::same( '20', '20.01' ) );
        $this->assertFalse( PriceAdjuster::same( '', '' ), 'two empties are not a price' );
        $this->assertSame( 19990000, PriceAdjuster::units( '19.99' ) );
        $this->assertSame( -12500000, PriceAdjuster::units( '-12.5' ) );
        $this->assertSame( 1, PriceAdjuster::units( '0.000001' ) );
        $this->assertSame( 5000000, PriceAdjuster::units( '5' ) );
    }

    public function testEveryReasonHasAnExplanation(): void
    {
        foreach ( [ 'no_regular_price', 'not_positive', 'too_large', 'rounding_reversed', 'not_above_sale' ] as $code ) {
            $this->assertArrayHasKey( $code, PriceAdjuster::REASONS );
        }
    }
}
