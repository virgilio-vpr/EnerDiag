<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Services\TariffCalibrationService;
use App\Calculation\Services\TariffService;
use App\Models\Distributor;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_invoice_factor_matches_all_taxed_items_within_configured_tolerance(): void
    {
        $invoice = $this->invoice([
            $this->item('0.56657', '0.43913'),
            $this->item('0.35293', '0.27354'),
            $this->item('37.80322', '29.30000'),
            $this->item('21.98516', '17.04000'),
            $this->item('0.16134', '0.12505'),
        ]);

        $result = (new TariffCalibrationService)->calculateFactor($invoice);

        $this->assertSame('1.290213', $result->factor);
        $this->assertSame([
            0 => '1.290210',
            1 => '1.290232',
            2 => '1.290212',
            3 => '1.290209',
            4 => '1.290204',
        ], $result->itemFactors);
        $this->assertSame([], $result->warnings);
    }

    public function test_factor_uses_tax_data_when_invoice_has_no_untaxed_rate(): void
    {
        $invoice = $this->invoice([
            $this->item('0.56657', null, '0.1800', '4.49', '100.00'),
        ]);

        $result = (new TariffCalibrationService)->calculateFactor($invoice);

        $this->assertSame('1.290156', $result->factor);
    }

    public function test_factor_divergence_above_tolerance_generates_a_warning(): void
    {
        $invoice = $this->invoice([
            $this->item('1.00000', '1.00000'),
            $this->item('1.10000', '1.00000'),
        ]);

        $result = (new TariffCalibrationService)->calculateFactor($invoice);

        $this->assertContains(TariffCalibrationService::K_DIVERGENCE_WARNING, $result->warnings);
        $this->assertTrue($result->isAvailable());
    }

    public function test_missing_tariff_and_tax_data_makes_calibration_unavailable(): void
    {
        $invoice = $this->invoice([
            $this->item('0.50000', null, '0.0000', '0.00', '100.00'),
        ]);

        $result = (new TariffCalibrationService)->calculateFactor($invoice);

        $this->assertNull($result->factor);
        $this->assertContains(TariffCalibrationService::K_UNAVAILABLE_WARNING, $result->warnings);
    }

    public function test_invoice_untaxed_rate_is_checked_against_aneel_rate(): void
    {
        $service = new TariffCalibrationService;
        $invoiceItem = $this->invoice([$this->item('37.80322', '29.30000')])->items[0];
        $matching = $this->tariff('29.30000');
        $mismatching = $this->tariff('29.31000');

        $this->assertSame([], $service->compareInvoiceRateWithAneel($invoiceItem, $matching));
        $this->assertSame(
            [TariffCalibrationService::ANEEL_RATE_MISMATCH_WARNING],
            $service->compareInvoiceRateWithAneel($invoiceItem, $mismatching),
        );
    }

    public function test_aneel_tariff_is_converted_by_k_and_taxed_tariff_is_not_converted_twice(): void
    {
        $service = new TariffCalibrationService;
        $aneelTariff = $this->tariff('29.30000', false);
        $taxedTariff = $this->tariff('37.80322', true);

        $converted = $service->convertTariff($aneelTariff, '1.290213');
        $alreadyTaxed = $service->convertTariff($taxedTariff, '1.290213');

        $this->assertSame('37.80324', $converted->rate);
        $this->assertSame('37.80322', $alreadyTaxed->rate);
    }

    public function test_matching_te_across_modalities_uses_the_invoice_price_and_different_te_uses_k(): void
    {
        $service = new TariffCalibrationService;
        $invoiceTe = $this->invoice([$this->item('0.56657', '0.43913', '0.1800', '4.49', '100.00', 'energy_te')])->items[0];
        $sameTe = $this->tariff('0.43913', false, 'energy_te');
        $differentTe = $this->tariff('0.50000', false, 'energy_te');

        $sameResult = $service->alternativeEnergyTe($invoiceTe, $sameTe, '1.290213');
        $differentResult = $service->alternativeEnergyTe($invoiceTe, $differentTe, '1.290213');

        $this->assertSame('0.56657', $sameResult->rate);
        $this->assertSame('0.64511', $differentResult->rate);
    }

    public function test_missing_aneel_tariff_or_factor_marks_migration_unavailable(): void
    {
        $service = new TariffCalibrationService;

        $missingTariff = $service->convertTariff(null, '1.290213');
        $missingFactor = $service->convertTariff($this->tariff('0.12505', false), null);

        $this->assertNull($missingTariff->rate);
        $this->assertContains(TariffCalibrationService::MIGRATION_UNAVAILABLE_WARNING, $missingTariff->warnings);
        $this->assertNull($missingFactor->rate);
        $this->assertContains(TariffCalibrationService::MIGRATION_UNAVAILABLE_WARNING, $missingFactor->warnings);
    }

    public function test_tariff_service_can_select_only_the_aneel_tax_base(): void
    {
        $distributor = Distributor::create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);
        $this->tariff('37.80322', true, 'demand', $distributor);
        $aneelTariff = $this->tariff('29.30000', false, 'demand', $distributor);

        $result = (new TariffService)->resolve($distributor->id, 'A4', 'blue', 'demand', 'peak', '2025-08-01', false);

        $this->assertTrue($result->tariff->is($aneelTariff));
        $this->assertFalse($result->tariff->includes_taxes);
    }

    /** @param list<array<string, mixed>> $items */
    private function invoice(array $items): ExtractedInvoiceData
    {
        return ExtractedInvoiceData::fromArray([
            'reference_month' => '2025-08',
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '98922.22',
            'items' => $items,
        ]);
    }

    private function item(
        ?string $unitPrice,
        ?string $tariffRate,
        string $icmsRate = '0.1800',
        string $pisCofins = '4.49',
        string $amount = '100.00',
        string $component = 'demand',
    ): array {
        return [
            'item_type' => 'measured_demand',
            'tariff_component' => $component,
            'time_slot' => 'unique',
            'reactive_nature' => 'not_applicable',
            'quantity' => '1.000',
            'unit' => $component === 'demand' ? 'kW' : 'kWh',
            'unit_price_taxed' => $unitPrice,
            'tariff_rate' => $tariffRate,
            'amount' => $amount,
            'pis_cofins_amount' => $pisCofins,
            'icms_base' => $amount,
            'icms_rate' => $icmsRate,
            'icms_amount' => '18.00',
        ];
    }

    private function tariff(
        string $rate,
        bool $includesTaxes = false,
        string $component = 'demand',
        ?Distributor $distributor = null,
    ): Tariff {
        $distributor ??= Distributor::firstOrCreate(
            ['parser_key' => 'enel'],
            ['name' => 'ENEL SP', 'state' => 'SP', 'active' => true],
        );

        return Tariff::create([
            'distributor_id' => $distributor->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'time_slot' => 'peak',
            'component' => $component,
            'rate' => $rate,
            'includes_taxes' => $includesTaxes,
            'source' => $includesTaxes ? 'manual' : 'aneel_import',
            'valid_from' => '2025-01-01',
        ]);
    }
}
