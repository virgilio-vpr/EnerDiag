<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Services\DemandBillingService;
use App\Calculation\Services\ReactiveSavingsService;
use App\Calculation\Services\RegulatoryParameterService;
use App\Models\RegulatoryParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpreadsheetCalculationBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHS = [
        '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03',
        '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09',
    ];

    /** Source: Analise_Faturas_Energia_Preenchida_Azul.xlsx, Demanda_Ponta and Demanda_F_Ponta. */
    private const BLUE_PEAK = [
        ['257.880', '37.99620'], ['265.440', '37.98405'], ['258.720', '37.95578'],
        ['301.560', '37.57269'], ['283.920', '38.03663'], ['298.200', '37.59636'],
        ['255.360', '37.56479'], ['254.520', '38.13402'], ['213.360', '37.36050'],
        ['228.480', '40.51689'], ['252.000', '20.45393'], ['247.800', '20.49077'],
    ];

    /** Source: Analise_Faturas_Energia_Preenchida_Azul.xlsx, Demanda_F_Ponta. */
    private const BLUE_OFF_PEAK = [
        ['323.400', '22.09743'], ['330.120', '22.09035'], ['312.480', '22.07396'],
        ['355.320', '21.85115'], ['319.200', '22.12094'], ['335.160', '21.86489'],
        ['305.760', '21.84651'], ['313.320', '22.17761'], ['272.160', '21.72774'],
        ['274.680', '24.23136'], ['304.080', '12.26589'], ['306.600', '12.28793'],
    ];

    /** Source: Analise_Faturas_Energia_Preenchida_Verde.xlsx, Demanda. */
    private const GREEN_DEMAND = [
        ['257.880', '37.99620'], ['265.440', '37.98405'], ['258.720', '37.95578'],
        ['301.560', '37.57269'], ['283.920', '38.03663'], ['298.200', '37.59636'],
        ['255.360', '37.56479'], ['254.520', '38.13402'], ['213.360', '37.36050'],
        ['228.480', '40.51689'], ['252.000', '20.45393'], ['247.800', '20.49077'],
    ];

    public function test_blue_annual_demand_costs_match_manual_peak_and_off_peak_tables(): void
    {
        $this->seedDemandParameters();
        $service = new DemandBillingService(new RegulatoryParameterService);

        $peakCurrent = $this->demandTotal($service, self::BLUE_PEAK, '338.000');
        $peakOptimized = $this->demandTotal($service, self::BLUE_PEAK, '290.000');
        $offPeakCurrent = $this->demandTotal($service, self::BLUE_OFF_PEAK, '372.000');
        $offPeakOptimized = $this->demandTotal($service, self::BLUE_OFF_PEAK, '345.000');

        // Spreadsheet totals: peak O19/O35 and off-peak O19/O35; compare within two cents for RC-0 rounding.
        $this->assertWithinCents('136632.159223808', $peakCurrent);
        $this->assertWithinCents('120644.475861896', $peakOptimized);
        $this->assertWithinCents('89127.053859312', $offPeakCurrent);
        $this->assertWithinCents('83851.451304672', $offPeakOptimized);
        $this->assertWithinCents('15987.683361912', bcsub($peakCurrent, $peakOptimized, 2));
        $this->assertWithinCents('5275.602554640', bcsub($offPeakCurrent, $offPeakOptimized, 2));
    }

    public function test_green_annual_demand_costs_match_manual_demand_table(): void
    {
        $this->seedDemandParameters();
        $service = new DemandBillingService(new RegulatoryParameterService);

        $current = $this->demandTotal($service, self::GREEN_DEMAND, '338.000');
        $optimized = $this->demandTotal($service, self::GREEN_DEMAND, '290.000');

        // Spreadsheet totals: Analise_Faturas_Energia_Preenchida_Verde.xlsx, Demanda!O19 and O35.
        $this->assertWithinCents('136632.159223808', $current);
        $this->assertWithinCents('120644.475861896', $optimized);
        $this->assertWithinCents('15987.683361912', bcsub($current, $optimized, 2));
    }

    public function test_reactive_savings_match_both_manual_workbooks(): void
    {
        $monthlyTotals = [
            '0.00', '0.00', '245.6379135', '221.152386', '298.1088474', '191.02062',
            '213.7811739', '239.9169612', '0.0699993', '0.00', '1.7800083', '1.960014',
        ];
        $invoices = [];
        foreach (self::MONTHS as $index => $month) {
            $amount = bcadd($monthlyTotals[$index], '0.005', 2);
            $items = bccomp($amount, '0.00', 2) === 0 ? [] : [$this->reactiveItem($amount)];
            $invoices[] = ExtractedInvoiceData::fromArray([
                'reference_month' => $month,
                'consumer_unit' => 'MTE0012654',
                'subgroup' => 'A4',
                'tariff_modality' => 'blue',
                'total_paid' => '1000.00',
                'items' => $items,
            ]);
        }

        $result = (new ReactiveSavingsService)->calculate($invoices);

        // Spreadsheet totals: Azul reactive sheet R18 and Verde reactive sheet O18.
        $this->assertWithinCents('1413.4279236', $result->totalSavings);
        $this->assertSame('1413.43', $result->totalSavings);
    }

    /** @param list<array{string, string}> $series */
    private function demandTotal(DemandBillingService $service, array $series, string $contractedDemand): string
    {
        $total = '0.00';
        foreach (self::MONTHS as $index => $month) {
            [$measuredDemand, $rate] = $series[$index];
            $result = $service->calculate($month, $contractedDemand, $measuredDemand, $rate, '0.820000');
            $total = bcadd($total, $result->total ?? '0.00', 2);
        }

        return $total;
    }

    private function seedDemandParameters(): void
    {
        RegulatoryParameter::create(['key' => 'overrun_tolerance', 'value' => '0.05000', 'state' => null, 'valid_from' => '2025-01-01']);
        RegulatoryParameter::create(['key' => 'overrun_multiplier', 'value' => '2.00000', 'state' => null, 'valid_from' => '2025-01-01']);
    }

    private function assertWithinCents(string $spreadsheetValue, string $calculatedValue): void
    {
        $difference = bcsub($spreadsheetValue, $calculatedValue, 8);
        if (bccomp($difference, '0.00000000', 8) < 0) {
            $difference = bcmul($difference, '-1', 8);
        }

        $this->assertLessThanOrEqual('0.02', $difference, "Expected {$calculatedValue} to be within R$ 0.02 of spreadsheet value {$spreadsheetValue}.");
    }

    /** @return array<string, string> */
    private function reactiveItem(string $amount): array
    {
        return [
            'item_type' => ReactiveSavingsService::REACTIVE_ENERGY_ITEM_TYPE,
            'tariff_component' => 'energy_te', 'time_slot' => 'off_peak', 'reactive_nature' => 'not_applicable',
            'quantity' => '1.000', 'unit' => 'kVArh', 'unit_price_taxed' => '1.00000', 'tariff_rate' => null,
            'amount' => $amount, 'pis_cofins_amount' => '0.00', 'icms_base' => '0.00', 'icms_rate' => '0.0000',
            'icms_amount' => '0.00', 'source_label' => 'UFER',
        ];
    }
}
