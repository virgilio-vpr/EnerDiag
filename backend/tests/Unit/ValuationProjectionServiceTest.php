<?php

namespace Tests\Unit;

use App\Calculation\Data\ValuationMonthData;
use App\Calculation\Services\ValuationProjectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValuationProjectionServiceTest extends TestCase
{
    public function test_twelve_months_are_projected_at_latest_tariff_values(): void
    {
        $months = array_map(fn (int $month): ValuationMonthData => $this->month($month, '8.00', '10.00'), range(1, 12));

        $result = (new ValuationProjectionService)->project($months);

        $this->assertSame(12, $result->monthsCount);
        $this->assertSame(['demand' => '96.00'], $result->historicalTotalsByComponent);
        $this->assertSame(['demand' => '10.00'], $result->projectedMonthlyAveragesByComponent);
        $this->assertSame(['demand' => '120.00'], $result->projectedAnnualTotalsByComponent);
        $this->assertSame([], $result->warnings);
    }

    #[DataProvider('incompleteMonthCounts')]
    public function test_incomplete_history_is_averaged_then_multiplied_by_twelve_with_warning(int $count): void
    {
        $months = array_map(fn (int $month): ValuationMonthData => $this->month($month, '7.00', '11.25'), range(1, $count));

        $result = (new ValuationProjectionService)->project($months);

        $this->assertSame($count, $result->monthsCount);
        $this->assertSame(['demand' => '11.25'], $result->projectedMonthlyAveragesByComponent);
        $this->assertSame(['demand' => '135.00'], $result->projectedAnnualTotalsByComponent);
        $this->assertContains(ValuationProjectionService::INCOMPLETE_SERIES_WARNING, $result->warnings);
    }

    public static function incompleteMonthCounts(): array
    {
        return [[1], [2], [5], [6], [11]];
    }

    public function test_missing_current_tariff_uses_that_month_historical_value_and_warns(): void
    {
        $months = array_map(
            fn (int $month): ValuationMonthData => $this->month($month, '9.00', $month === 1 ? null : '12.00'),
            range(1, 12),
        );

        $result = (new ValuationProjectionService)->project($months);

        $this->assertSame(['demand' => '108.00'], $result->historicalTotalsByComponent);
        $this->assertSame(['demand' => '11.75'], $result->projectedMonthlyAveragesByComponent);
        $this->assertSame(['demand' => '141.00'], $result->projectedAnnualTotalsByComponent);
        $this->assertContains(ValuationProjectionService::TARIFF_UNAVAILABLE_WARNING, $result->warnings);
    }

    private function month(int $month, string $historical, ?string $current): ValuationMonthData
    {
        return new ValuationMonthData(
            sprintf('2025-%02d', $month),
            ['demand' => $historical],
            ['demand' => $current],
        );
    }
}
