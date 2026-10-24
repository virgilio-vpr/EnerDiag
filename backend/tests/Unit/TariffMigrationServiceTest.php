<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Calculation\Data\TariffMigrationMonthData;
use App\Calculation\Services\DemandBillingService;
use App\Calculation\Services\DemandOptimizationService;
use App\Calculation\Services\RegulatoryParameterService;
use App\Calculation\Services\TariffCalibrationService;
use App\Calculation\Services\TariffMigrationService;
use App\Calculation\Services\TariffService;
use App\Models\Distributor;
use App\Models\RegulatoryParameter;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_green_migration_uses_green_peak_tusd_from_aneel_and_applies_k_and_idle_ratio(): void
    {
        $distributor = $this->distributor();
        $this->greenTariffs($distributor, '1.00000', '0.05000', '0.05000', '1.00000');

        $result = $this->service()->compare($distributor->id, 'A4', 'blue', [$this->month(greenMeasured: '80.000')], 100, 100);

        $this->assertTrue($result->available);
        $this->assertSame('green', $result->selectedModality);
        $this->assertSame('600.40', $result->blueCost);
        // Green demand: (80 + 20 x r=.82) x 1.00 x k(2); Green peak TUSD comes from ANEEL x k.
        $this->assertSame('195.10', $result->greenCost);
        $this->assertSame('405.30', $result->savings);
    }

    public function test_blue_wins_when_green_peak_tusd_is_high(): void
    {
        $distributor = $this->distributor();
        $this->greenTariffs($distributor, '1.00000', '0.05000', '5.00000', '0.05000');
        $month = $this->month(['peak' => '1000.000', 'off_peak' => '1.000']);

        $result = $this->service()->compare($distributor->id, 'A4', 'blue', [$month], 100, 100);

        $this->assertSame('blue', $result->selectedModality);
        $this->assertSame('800.20', $result->blueCost);
        $this->assertSame('10300.20', $result->greenCost);
        $this->assertSame('9500.00', $result->savings);
    }

    public function test_equal_costs_report_no_financial_gain(): void
    {
        $distributor = $this->distributor();
        $this->greenTariffs($distributor, '3.00000', '0.05000', '0.05000', '0.05000');

        $result = $this->service()->compare($distributor->id, 'A4', 'blue', [$this->month()], 100, 100);

        $this->assertSame('blue', $result->selectedModality);
        $this->assertSame('600.40', $result->blueCost);
        $this->assertSame('600.40', $result->greenCost);
        $this->assertSame('0.00', $result->savings);
        $this->assertSame('Sem ganho financeiro na migração', $result->message);
    }

    public function test_missing_required_other_modality_tariff_makes_migration_unavailable(): void
    {
        $distributor = $this->distributor();
        $this->greenTariffs($distributor, '1.00000', '0.05000', '1.00000', '1.00000', includePeakTusd: false);

        $result = $this->service()->compare($distributor->id, 'A4', 'blue', [$this->month()], 100, 100);

        $this->assertFalse($result->available);
        $this->assertNull($result->selectedModality);
        $this->assertContains(TariffService::MISSING_TARIFF_WARNING, $result->warnings);
        $this->assertContains(TariffMigrationService::MIGRATION_UNAVAILABLE_WARNING, $result->warnings);
    }

    private function service(): TariffMigrationService
    {
        RegulatoryParameter::create(['key' => 'overrun_tolerance', 'value' => '0.05000', 'state' => null, 'valid_from' => '2025-01-01']);
        RegulatoryParameter::create(['key' => 'overrun_multiplier', 'value' => '2.00000', 'state' => null, 'valid_from' => '2025-01-01']);

        return new TariffMigrationService(
            new TariffService,
            new TariffCalibrationService,
            new DemandOptimizationService(new DemandBillingService(new RegulatoryParameterService)),
        );
    }

    private function month(array $consumption = ['peak' => '1.000', 'off_peak' => '1.000'], string $greenMeasured = '100.000'): TariffMigrationMonthData
    {
        $invoiceItem = static fn (string $component, string $slot, string $taxed, string $untaxed): ExtractedInvoiceItemData => ExtractedInvoiceItemData::fromArray([
            'item_type' => 'active_consumption', 'tariff_component' => $component, 'time_slot' => $slot,
            'reactive_nature' => 'not_applicable', 'quantity' => $consumption[$slot], 'unit' => 'kWh',
            'unit_price_taxed' => $taxed, 'tariff_rate' => $untaxed, 'amount' => '1.00',
            'pis_cofins_amount' => '0.00', 'icms_base' => '0.00', 'icms_rate' => '0.0000', 'icms_amount' => '0.00',
        ]);

        return new TariffMigrationMonthData(
            '2025-01',
            ['blue' => ['peak' => '100.000', 'off_peak' => '100.000'], 'green' => ['unique' => $greenMeasured]],
            ['blue' => ['peak' => '0.820000', 'off_peak' => '0.820000'], 'green' => ['unique' => '0.820000']],
            ['peak' => '5.00000', 'off_peak' => '1.00000'],
            $consumption,
            [
                'energy_te' => ['peak' => $invoiceItem('energy_te', 'peak', '0.10000', '0.05000'), 'off_peak' => $invoiceItem('energy_te', 'off_peak', '0.10000', '0.05000')],
                // The invoice Blue peak TUSD is deliberately lower than Green's ANEEL peak tariff.
                'energy_tusd' => ['peak' => $invoiceItem('energy_tusd', 'peak', '0.10000', '0.05000'), 'off_peak' => $invoiceItem('energy_tusd', 'off_peak', '0.10000', '0.05000')],
            ],
            '2.000000',
        );
    }

    private function distributor(): Distributor
    {
        return Distributor::create(['name' => 'ENEL', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);
    }

    private function greenTariffs(Distributor $distributor, string $demand, string $te, string $peakTusd, string $offPeakTusd, bool $includePeakTusd = true): void
    {
        $rows = [
            ['unique', 'demand', $demand],
            ['peak', 'energy_te', $te], ['off_peak', 'energy_te', $te],
            ['off_peak', 'energy_tusd', $offPeakTusd],
        ];
        if ($includePeakTusd) {
            $rows[] = ['peak', 'energy_tusd', $peakTusd];
        }
        foreach ($rows as [$slot, $component, $rate]) {
            Tariff::create([
                'distributor_id' => $distributor->id, 'subgroup' => 'A4', 'tariff_modality' => 'green',
                'time_slot' => $slot, 'component' => $component, 'rate' => $rate, 'includes_taxes' => false,
                'source' => 'aneel_import', 'valid_from' => '2025-01-01',
            ]);
        }
    }
}
