<?php

namespace Tests\Unit;

use App\Calculation\Services\RegulatoryParameterService;
use App\Calculation\Services\TariffService;
use App\Models\Distributor;
use App\Models\RegulatoryParameter;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculationServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tariff_resolution_uses_dimensions_and_inclusive_validity_dates(): void
    {
        $distributor = Distributor::create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);

        $taxedTariff = $this->createTariff($distributor, '2025-01-01', '2025-06-30', true, '37.80322');
        $aneelTariff = $this->createTariff($distributor, '2025-07-01', null, false, '29.30000');
        $service = new TariffService;

        $firstResolution = $service->resolve($distributor->id, 'A4', 'blue', 'demand', 'peak', '2025-06-30');
        $secondResolution = $service->resolve($distributor->id, 'A4', 'blue', 'demand', 'peak', '2025-07-01');

        $this->assertTrue($firstResolution->tariff->is($taxedTariff));
        $this->assertTrue($firstResolution->tariff->includes_taxes);
        $this->assertSame('37.80322', $firstResolution->tariff->rate);
        $this->assertTrue($secondResolution->tariff->is($aneelTariff));
        $this->assertFalse($secondResolution->tariff->includes_taxes);
        $this->assertSame('29.30000', $secondResolution->tariff->rate);
        $this->assertSame([], $secondResolution->warnings);
    }

    public function test_missing_current_tariff_returns_a_report_warning_code(): void
    {
        $distributor = Distributor::create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);

        $resolution = (new TariffService)->resolve(
            $distributor->id,
            'A4',
            'green',
            'energy_tusd',
            'peak',
            '2025-08-01',
        );

        $this->assertNull($resolution->tariff);
        $this->assertSame([TariffService::MISSING_TARIFF_WARNING], $resolution->warnings);
    }

    public function test_regulatory_parameter_resolution_uses_national_scope_and_validity_dates(): void
    {
        $first = RegulatoryParameter::create([
            'key' => 'overrun_tolerance',
            'value' => '0.05000',
            'state' => null,
            'valid_from' => '2025-01-01',
            'valid_to' => '2025-12-31',
        ]);
        $second = RegulatoryParameter::create([
            'key' => 'overrun_tolerance',
            'value' => '0.06000',
            'state' => null,
            'valid_from' => '2026-01-01',
        ]);
        RegulatoryParameter::create([
            'key' => 'overrun_tolerance',
            'value' => '0.20000',
            'state' => 'SP',
            'valid_from' => '2025-01-01',
        ]);
        $service = new RegulatoryParameterService;

        $firstResolution = $service->resolve('overrun_tolerance', '2025-12-31');
        $secondResolution = $service->resolve('overrun_tolerance', '2026-01-01');

        $this->assertTrue($firstResolution->is($first));
        $this->assertSame('0.05000', $firstResolution->value);
        $this->assertTrue($secondResolution->is($second));
        $this->assertSame('0.06000', $secondResolution->value);
    }

    public function test_missing_regulatory_parameter_resolves_to_null(): void
    {
        $parameter = (new RegulatoryParameterService)->resolve('unknown_parameter', '2025-08-01');

        $this->assertNull($parameter);
    }

    private function createTariff(
        Distributor $distributor,
        string $validFrom,
        ?string $validTo,
        bool $includesTaxes,
        string $rate,
    ): Tariff {
        return Tariff::create([
            'distributor_id' => $distributor->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'time_slot' => 'peak',
            'component' => 'demand',
            'rate' => $rate,
            'includes_taxes' => $includesTaxes,
            'source' => $includesTaxes ? 'manual' : 'aneel_import',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);
    }
}
