<?php

namespace Tests\Unit;

use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Calculation\Services\IdleDemandRatioService;
use App\Calculation\Services\RegulatoryParameterService;
use App\Models\RegulatoryParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleDemandRatioServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enel_taxation_resolves_r_confirms_pis_and_matches_reversed_idle_rows(): void
    {
        $month = $this->enelReferenceMonth();

        $resolution = $this->service()->resolve([$month], false);
        $peak = $resolution->forMonthAndTimeSlot('2025-08', 'peak');
        $offPeak = $resolution->forMonthAndTimeSlot('2025-08', 'off_peak');

        $this->assertSame('0.820000', $peak->ratio);
        $this->assertSame(IdleDemandRatioService::TAXATION_SOURCE, $peak->source);
        $this->assertSame(1, $peak->idleItemIndex);
        $this->assertSame('0.820000', $offPeak->ratio);
        $this->assertSame(0, $offPeak->idleItemIndex);
        $this->assertSame([], $resolution->warnings());
    }

    public function test_taxation_resolves_a_different_icms_rate(): void
    {
        $normal = $this->item('measured_demand', 'unique', '80.000', '10.00000', '1000.00', '40.00', '0.2000', '1000.00');
        $idle = $this->item('idle_demand', 'not_applicable', null, null, '164.00', '10.00', '0.0000', '164.00');
        $month = $this->month('2025-09', '2025-01-01', ['unique' => '100.000'], ['unique' => '80.000'], ['unique' => $normal], [$idle]);

        $entry = $this->service()->resolve([$month], false)->forMonthAndTimeSlot('2025-09', 'unique');

        $this->assertSame('0.800000', $entry->ratio);
    }

    public function test_unit_price_is_used_when_taxation_cannot_reveal_r(): void
    {
        $normal = $this->item('measured_demand', 'unique', '80.000', '10.00000', '1000.00', '0.00', '0.0000', '0.00');
        $idle = $this->item('idle_demand', 'not_applicable', null, '8.00000', '160.00', '0.00', '0.0000', '0.00');
        $month = $this->month('2025-09', '2025-01-01', ['unique' => '100.000'], ['unique' => '80.000'], ['unique' => $normal], [$idle]);

        $entry = $this->service()->resolve([$month], false)->forMonthAndTimeSlot('2025-09', 'unique');

        $this->assertSame('0.800000', $entry->ratio);
        $this->assertSame(IdleDemandRatioService::UNIT_PRICE_SOURCE, $entry->source);
    }

    public function test_recognized_idle_line_missing_from_a_month_means_full_charge(): void
    {
        $month = $this->month('2025-09', '2025-01-01', ['unique' => '100.000'], ['unique' => '80.000'], []);

        $entry = $this->service()->resolve([$month], true)->forMonthAndTimeSlot('2025-09', 'unique');

        $this->assertSame('1.000000', $entry->ratio);
        $this->assertSame(IdleDemandRatioService::FULL_CHARGE_SOURCE, $entry->source);
    }

    public function test_unrevealed_series_uses_regulatory_fallback_and_warns(): void
    {
        RegulatoryParameter::create([
            'key' => 'idle_demand_discount_fallback',
            'value' => '0.18000',
            'state' => null,
            'valid_from' => '2025-01-01',
        ]);
        $month = $this->month('2025-09', '2025-01-01', ['unique' => '100.000'], ['unique' => '80.000'], []);

        $resolution = $this->service()->resolve([$month], false);
        $entry = $resolution->forMonthAndTimeSlot('2025-09', 'unique');

        $this->assertSame('0.820000', $entry->ratio);
        $this->assertSame(IdleDemandRatioService::FALLBACK_SOURCE, $entry->source);
        $this->assertContains(IdleDemandRatioService::FALLBACK_WARNING, $entry->warnings);
    }

    public function test_inconsistent_pis_cofins_on_idle_line_warns_while_using_tax_ratio(): void
    {
        $normal = $this->item('measured_demand', 'unique', '80.000', '10.00000', '1000.00', '44.90', '0.1800', '1000.00');
        $idle = $this->item('idle_demand', 'not_applicable', null, null, '100.00', '1.00', '0.0000', '100.00');
        $month = $this->month('2025-09', '2025-01-01', ['unique' => '100.000'], ['unique' => '80.000'], ['unique' => $normal], [$idle]);

        $resolution = $this->service()->resolve([$month], false);
        $entry = $resolution->forMonthAndTimeSlot('2025-09', 'unique');

        $this->assertSame('0.820000', $entry->ratio);
        $this->assertContains(IdleDemandRatioService::PIS_COFINS_WARNING, $entry->warnings);
    }

    public function test_each_tariff_period_uses_its_own_revealed_ratio_and_propagates_within_period(): void
    {
        $firstMonth = $this->taxedMonth('2025-01', '2025-01-01', '0.2000', '0.800000');
        $samePeriod = $this->month('2025-02', '2025-01-01', ['unique' => '100.000'], ['unique' => '90.000'], []);
        $newPeriod = $this->taxedMonth('2025-03', '2025-03-01', '0.1800', '0.820000');
        $newPeriodWithoutObservation = $this->month('2025-04', '2025-03-01', ['unique' => '100.000'], ['unique' => '90.000'], []);

        $resolution = $this->service()->resolve([$newPeriodWithoutObservation, $samePeriod, $newPeriod, $firstMonth], false);

        $this->assertSame('0.800000', $resolution->forMonthAndTimeSlot('2025-01', 'unique')->ratio);
        $this->assertSame('0.800000', $resolution->forMonthAndTimeSlot('2025-02', 'unique')->ratio);
        $this->assertTrue($resolution->forMonthAndTimeSlot('2025-02', 'unique')->inherited);
        $this->assertSame('0.820000', $resolution->forMonthAndTimeSlot('2025-03', 'unique')->ratio);
        $this->assertSame('0.820000', $resolution->forMonthAndTimeSlot('2025-04', 'unique')->ratio);
    }

    public function test_equal_matching_candidates_are_reported_as_unsafe(): void
    {
        $peak = $this->item('measured_demand', 'peak', '90.000', '10.00000', '1000.00', '44.90', '0.1800', '1000.00');
        $offPeak = $this->item('measured_demand', 'off_peak', '90.000', '10.00000', '1000.00', '44.90', '0.1800', '1000.00');
        $idleOne = $this->item('idle_demand', 'not_applicable', null, null, '82.00', '4.49', '0.0000', '82.00');
        $idleTwo = $this->item('idle_demand', 'not_applicable', null, null, '82.00', '4.49', '0.0000', '82.00');
        $month = $this->month(
            '2025-08',
            '2025-01-01',
            ['peak' => '100.000', 'off_peak' => '100.000'],
            ['peak' => '90.000', 'off_peak' => '90.000'],
            ['peak' => $peak, 'off_peak' => $offPeak],
            [$idleOne, $idleTwo],
        );

        $resolution = $this->service()->resolve([$month], false);

        $this->assertNull($resolution->forMonthAndTimeSlot('2025-08', 'peak')->idleItemIndex);
        $this->assertContains(IdleDemandRatioService::MATCHING_WARNING, $resolution->warnings());
    }

    public function test_month_without_revelation_uses_the_nearest_tariff_period_observation(): void
    {
        $observed = $this->taxedMonth('2025-01', '2025-01-01', '0.1800', '0.820000');
        $unobserved = $this->month('2025-07', '2025-07-01', ['unique' => '100.000'], ['unique' => '90.000'], []);

        $entry = $this->service()->resolve([$observed, $unobserved], false)->forMonthAndTimeSlot('2025-07', 'unique');

        $this->assertSame('0.820000', $entry->ratio);
        $this->assertSame(IdleDemandRatioService::TAXATION_SOURCE, $entry->source);
        $this->assertTrue($entry->inherited);
    }

    private function service(): IdleDemandRatioService
    {
        return new IdleDemandRatioService(new RegulatoryParameterService);
    }

    private function enelReferenceMonth(): DemandMonthData
    {
        $peak = $this->item('measured_demand', 'peak', '100.000', '10.00000', '10000.00', '449.00', '0.1800', '10000.00');
        $offPeak = $this->item('measured_demand', 'off_peak', '10.000', '5.00000', '5000.00', '224.50', '0.1800', '5000.00');
        $idleOffPeak = $this->item('idle_demand', 'not_applicable', null, null, '4100.00', '224.68', '0.0000', '4100.00');
        $idlePeak = $this->item('idle_demand', 'not_applicable', null, null, '820.00', '44.94', '0.0000', '820.00');

        return $this->month(
            '2025-08',
            '2025-01-01',
            ['peak' => '200.000', 'off_peak' => '1010.000'],
            ['peak' => '100.000', 'off_peak' => '10.000'],
            ['peak' => $peak, 'off_peak' => $offPeak],
            [$idleOffPeak, $idlePeak],
        );
    }

    private function taxedMonth(string $referenceMonth, string $period, string $icmsRate, string $ratio): DemandMonthData
    {
        $normal = $this->item('measured_demand', 'unique', '80.000', '10.00000', '1000.00', '44.90', $icmsRate, '1000.00');
        $idleAmount = bcmul(bcmul('20', '10', 4), $ratio, 2);
        $idlePis = bcadd(bcmul($idleAmount, bcdiv('0.0449', $ratio, 8), 4), '0.005', 2);
        $idle = $this->item('idle_demand', 'not_applicable', null, null, $idleAmount, $idlePis, '0.0000', $idleAmount);

        return $this->month($referenceMonth, $period, ['unique' => '100.000'], ['unique' => '80.000'], ['unique' => $normal], [$idle]);
    }

    /**
     * @param  array<string, string>  $contracted
     * @param  array<string, string>  $measured
     * @param  array<string, ExtractedInvoiceItemData>  $normalItems
     * @param  list<ExtractedInvoiceItemData>  $idleItems
     */
    private function month(
        string $referenceMonth,
        string $period,
        array $contracted,
        array $measured,
        array $normalItems,
        array $idleItems = [],
    ): DemandMonthData {
        return new DemandMonthData($referenceMonth, $period, $contracted, $measured, $normalItems, $idleItems);
    }

    private function item(
        string $type,
        string $timeSlot,
        ?string $quantity,
        ?string $unitPrice,
        string $amount,
        string $pisCofins,
        string $icmsRate,
        string $icmsBase,
    ): ExtractedInvoiceItemData {
        $item = [
            'item_type' => $type,
            'tariff_component' => 'demand',
            'time_slot' => $timeSlot,
            'reactive_nature' => 'not_applicable',
            'quantity' => $quantity,
            'unit' => 'kW',
            'unit_price_taxed' => $unitPrice,
            'tariff_rate' => '29.30000',
            'amount' => $amount,
            'pis_cofins_amount' => $pisCofins,
            'icms_base' => $icmsBase,
            'icms_rate' => $icmsRate,
            'icms_amount' => '0.00',
            'source_label' => 'Demanda',
        ];

        return ExtractedInvoiceData::fromArray([
            'reference_month' => '2025-08',
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '100000.00',
            'items' => [$item],
        ])->items[0];
    }
}
