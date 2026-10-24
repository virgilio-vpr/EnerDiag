<?php

namespace Tests\Unit;

use App\Calculation\Data\DemandOptimizationMonthData;
use App\Calculation\Services\DemandBillingService;
use App\Calculation\Services\DemandOptimizationService;
use App\Calculation\Services\RegulatoryParameterService;
use App\Models\RegulatoryParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandOptimizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_blue_optimizes_peak_and_off_peak_independently(): void
    {
        $service = $this->service();
        $peakMonths = [
            $this->month('2025-01', '80.000'),
            $this->month('2025-02', '100.000'),
            $this->month('2025-03', '120.000'),
        ];
        $offPeakMonths = [
            $this->month('2025-01', '70.000'),
            $this->month('2025-02', '90.000'),
            $this->month('2025-03', '110.000'),
        ];

        $result = $service->optimizeBlue($peakMonths, $offPeakMonths, 80, 120);

        $this->assertSame('100.000', $result->slots['peak']->optimalDemand);
        $this->assertSame('90.000', $result->slots['off_peak']->optimalDemand);
        $this->assertSame('3364.00', $result->slots['peak']->totalCost);
        $this->assertSame('3064.00', $result->slots['off_peak']->totalCost);
    }

    public function test_green_optimizes_its_single_demand_series(): void
    {
        $service = $this->service();
        $months = [
            $this->month('2025-01', '80.000'),
            $this->month('2025-02', '100.000'),
            $this->month('2025-03', '120.000'),
        ];

        $result = $service->optimizeGreen($months, 80, 120);

        $this->assertSame(['unique'], array_keys($result->slots));
        $this->assertSame('100.000', $result->slots['unique']->optimalDemand);
        $this->assertSame('3364.00', $result->slots['unique']->totalCost);
    }

    public function test_tied_cost_chooses_the_lowest_demand_without_unnecessary_overrun_penalty(): void
    {
        $service = $this->service();
        $months = [$this->month('2025-01', '100.000')];

        $result = $service->optimizeGreen($months, 99, 101);

        $this->assertSame('99.000', $result->slots['unique']->optimalDemand);
        $this->assertSame('1000.00', $result->slots['unique']->totalCost);
    }

    public function test_empty_series_returns_a_warning_without_an_optimal_demand(): void
    {
        $service = $this->service();

        $result = $service->optimizeGreen([], 80, 120);

        $this->assertNull($result->slots['unique']->optimalDemand);
        $this->assertNull($result->slots['unique']->totalCost);
        $this->assertSame([DemandOptimizationService::SERIES_UNAVAILABLE_WARNING], $result->slots['unique']->warnings);
    }

    private function service(): DemandOptimizationService
    {
        RegulatoryParameter::create([
            'key' => 'overrun_tolerance',
            'value' => '0.05000',
            'state' => null,
            'valid_from' => '2025-01-01',
        ]);
        RegulatoryParameter::create([
            'key' => 'overrun_multiplier',
            'value' => '2.00000',
            'state' => null,
            'valid_from' => '2025-01-01',
        ]);

        return new DemandOptimizationService(
            new DemandBillingService(new RegulatoryParameterService),
        );
    }

    private function month(string $referenceMonth, string $measuredDemand): DemandOptimizationMonthData
    {
        return new DemandOptimizationMonthData($referenceMonth, $measuredDemand, '10.00000', '0.820000');
    }
}
