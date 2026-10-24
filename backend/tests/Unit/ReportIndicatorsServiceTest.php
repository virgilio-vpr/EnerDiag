<?php

namespace Tests\Unit;

use App\Calculation\Data\ReportIndicatorMonthData;
use App\Calculation\Services\ReportIndicatorsService;
use PHPUnit\Framework\TestCase;

class ReportIndicatorsServiceTest extends TestCase
{
    public function test_monthly_and_annual_savings_percentage_and_monthly_series_are_calculated(): void
    {
        $result = (new ReportIndicatorsService)->calculate([
            $this->month('2025-03', ['demand' => '300.00', 'reactive' => '30.00', 'migration' => '10.00'], '3000.00', '300.000'),
            $this->month('2025-01', ['demand' => '100.00', 'reactive' => '10.00', 'migration' => '40.00'], '1000.00', '100.000'),
            $this->month('2025-02', ['demand' => '200.00', 'reactive' => '20.00', 'migration' => '20.00'], '2000.00', '200.000'),
        ]);

        $this->assertSame('243.33', $result->totalMonthlySavings);
        $this->assertSame('2919.96', $result->totalAnnualSavings);
        $this->assertSame(['demand' => '200.00', 'reactive' => '20.00', 'migration' => '23.33'], $result->monthlySavingsByComponent);
        $this->assertSame(['demand' => '2400.00', 'reactive' => '240.00', 'migration' => '279.96'], $result->annualSavingsByComponent);
        $this->assertSame('2000.00', $result->averageTotalPaid);
        $this->assertSame('12.17', $result->savingsPercentage);
        $this->assertSame('% do valor médio pago no período', $result->percentageLabel);
        $this->assertSame('A economia usa tarifas atuais e o valor pago usa as tarifas históricas.', $result->percentageObservation);
        $this->assertSame([
            ['reference_month' => '2025-01', 'value' => '100.000'],
            ['reference_month' => '2025-02', 'value' => '200.000'],
            ['reference_month' => '2025-03', 'value' => '300.000'],
        ], $result->seriesByKey['demand_measured']);
    }

    public function test_zero_average_total_paid_returns_null_percentage_and_warning(): void
    {
        $result = (new ReportIndicatorsService)->calculate([
            new ReportIndicatorMonthData('2025-01', ['demand' => '10.00'], '0.00', []),
        ]);

        $this->assertSame('0.00', $result->averageTotalPaid);
        $this->assertNull($result->savingsPercentage);
        $this->assertSame([ReportIndicatorsService::ZERO_PAID_WARNING], $result->warnings);
    }

    /** @param array<string, string> $savings */
    private function month(string $referenceMonth, array $savings, string $paid, string $measuredDemand): ReportIndicatorMonthData
    {
        return new ReportIndicatorMonthData(
            $referenceMonth,
            $savings,
            $paid,
            ['demand_measured' => $measuredDemand, 'reactive_savings' => $savings['reactive'], 'blue_cost' => '500.00', 'green_cost' => '450.00'],
        );
    }
}
