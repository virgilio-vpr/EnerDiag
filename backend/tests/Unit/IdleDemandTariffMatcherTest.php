<?php

namespace Tests\Unit;

use App\Calculation\Services\IdleDemandTariffMatcher;
use PHPUnit\Framework\TestCase;

class IdleDemandTariffMatcherTest extends TestCase
{
    public function test_it_matches_blue_posts_by_unit_tariff_independently_of_row_order_and_total_amount(): void
    {
        $rows = [
            ['unit_price_taxed' => '17.91395', 'amount' => '9000.00'],
            ['unit_price_taxed' => '30.80300', 'amount' => '200.00'],
        ];
        $normal = ['peak' => [], 'off_peak' => []];

        $this->assertSame(['off_peak', 'peak'], (new IdleDemandTariffMatcher)->match($rows, $normal));
        $this->assertSame(['peak', 'off_peak'], (new IdleDemandTariffMatcher)->match(array_reverse($rows), $normal));
    }

    public function test_it_uses_the_untaxed_tariff_column_when_unit_prices_are_missing(): void
    {
        $rows = [['tariff_rate' => '17.04000'], ['tariff_rate' => '29.30000']];

        $this->assertSame(['off_peak', 'peak'], (new IdleDemandTariffMatcher)->match($rows, ['peak' => [], 'off_peak' => []]));
    }

    public function test_it_confirms_a_single_idle_row_with_the_icms_from_its_normal_demand(): void
    {
        foreach (['0.1800' => '30.80300', '0.2000' => '30.05171'] as $icms => $price) {
            $normal = [
                'peak' => ['unit_price_taxed' => '37.56479', 'icms_rate' => $icms],
                'off_peak' => ['unit_price_taxed' => '21.84651', 'icms_rate' => $icms],
            ];
            $this->assertSame(['peak'], (new IdleDemandTariffMatcher)->match([['unit_price_taxed' => $price]], $normal));
        }
    }

    public function test_it_does_not_guess_from_equal_tariffs_or_total_amounts(): void
    {
        $normal = ['peak' => [], 'off_peak' => []];

        $this->assertNull((new IdleDemandTariffMatcher)->match([['tariff_rate' => '17'], ['tariff_rate' => '17']], $normal));
        $this->assertNull((new IdleDemandTariffMatcher)->match([['amount' => '9000'], ['amount' => '200']], $normal));
    }

    public function test_it_does_not_assign_two_rows_to_the_same_post_when_reference_tariffs_are_equal(): void
    {
        $rows = [['tariff_rate' => '17'], ['tariff_rate' => '17']];
        $normal = ['peak' => ['tariff_rate' => '17'], 'off_peak' => ['tariff_rate' => '17']];

        $this->assertNull((new IdleDemandTariffMatcher)->match($rows, $normal));
    }
}
