<?php

namespace Tests\Unit;

use App\Calculation\Services\DemandBillingService;
use App\Calculation\Services\RegulatoryParameterService;
use App\Models\RegulatoryParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_below_contracted_demand_applies_idle_ratio(): void
    {
        $service = $this->service();

        $result = $service->calculate('2025-08', '100.000', '80.000', '10.00000', '0.820000');

        $this->assertSame('below_contracted', $result->condition);
        $this->assertSame('964.00', $result->total);
        $this->assertSame('800.00', $result->measuredDemandCharge);
        $this->assertSame('164.00', $result->idleDemandCharge);
        $this->assertSame('0.00', $result->overrunCharge);
    }

    public function test_demand_at_exact_five_percent_limit_has_no_overrun_charge(): void
    {
        $service = $this->service();

        $result = $service->calculate('2025-08', '100.000', '105.000', '10.00000', '0.820000');

        $this->assertSame('within_tolerance', $result->condition);
        $this->assertSame('1050.00', $result->total);
        $this->assertSame('0.00', $result->overrunCharge);
    }

    public function test_overrun_is_charged_on_the_excess_over_contracted_demand(): void
    {
        $service = $this->service();

        $result = $service->calculate('2025-08', '100.000', '120.000', '10.00000', '0.820000');

        $this->assertSame('overrun', $result->condition);
        $this->assertSame('1400.00', $result->total);
        $this->assertSame('1000.00', $result->contractedDemandCharge);
        $this->assertSame('400.00', $result->overrunCharge);
    }

    public function test_full_idle_demand_ratio_charges_the_entire_contracted_demand(): void
    {
        $service = $this->service();

        $result = $service->calculate('2025-08', '100.000', '80.000', '10.00000', '1.000000');

        $this->assertSame('1000.00', $result->total);
    }

    public function test_missing_idle_ratio_returns_an_unavailable_result_warning(): void
    {
        $service = $this->service();

        $result = $service->calculate('2025-08', '100.000', '80.000', '10.00000', null);

        $this->assertNull($result->total);
        $this->assertSame([DemandBillingService::RATIO_UNAVAILABLE_WARNING], $result->warnings);
    }

    public function test_missing_regulatory_parameters_returns_an_unavailable_result_warning(): void
    {
        $service = new DemandBillingService(new RegulatoryParameterService);

        $result = $service->calculate('2025-08', '100.000', '80.000', '10.00000', '0.820000');

        $this->assertNull($result->total);
        $this->assertSame([DemandBillingService::PARAMETER_UNAVAILABLE_WARNING], $result->warnings);
    }

    private function service(): DemandBillingService
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

        return new DemandBillingService(new RegulatoryParameterService);
    }
}
