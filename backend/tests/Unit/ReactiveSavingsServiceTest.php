<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Services\ReactiveSavingsService;
use Tests\TestCase;

class ReactiveSavingsServiceTest extends TestCase
{
    public function test_series_savings_equal_the_charges_for_ufer_and_reactive_demand(): void
    {
        $invoices = [
            $this->invoice('2025-08', [
                $this->item(ReactiveSavingsService::REACTIVE_ENERGY_ITEM_TYPE, '30.25'),
                $this->item(ReactiveSavingsService::REACTIVE_ENERGY_ITEM_TYPE, '20.00'),
                $this->item(ReactiveSavingsService::REACTIVE_DEMAND_ITEM_TYPE, '40.00'),
            ]),
            $this->invoice('2025-09', []),
        ];

        $result = (new ReactiveSavingsService)->calculate($invoices);

        $this->assertTrue($result->occurred);
        $this->assertNull($result->message);
        $this->assertSame('50.25', $result->reactiveEnergySavings);
        $this->assertSame('40.00', $result->reactiveDemandSavings);
        $this->assertSame('90.25', $result->totalSavings);
        $this->assertSame('50.25', $result->byMonth['2025-08']['reactive_energy']);
        $this->assertSame('0.00', $result->byMonth['2025-09']['total']);
    }

    public function test_no_reactive_charges_returns_zero_and_no_occurrence_message(): void
    {
        $invoices = [
            $this->invoice('2025-08', [
                $this->item('measured_demand', '100.00', 'Leitura DMCR'),
                $this->item('dmcr', '200.00', 'DMCR'),
                $this->item('active_energy', '300.00'),
            ]),
        ];

        $result = (new ReactiveSavingsService)->calculate($invoices);

        $this->assertFalse($result->occurred);
        $this->assertSame('0.00', $result->totalSavings);
        $this->assertSame(ReactiveSavingsService::NO_OCCURRENCE_MESSAGE, $result->message);
    }

    private function invoice(string $referenceMonth, array $items): ExtractedInvoiceData
    {
        return ExtractedInvoiceData::fromArray([
            'reference_month' => $referenceMonth,
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '1000.00',
            'items' => $items,
        ]);
    }

    private function item(string $type, string $amount, ?string $sourceLabel = null): array
    {
        return [
            'item_type' => $type,
            'tariff_component' => 'not_applicable',
            'time_slot' => 'not_applicable',
            'reactive_nature' => 'not_applicable',
            'quantity' => '1.000',
            'unit' => 'unit',
            'unit_price_taxed' => '1.00000',
            'tariff_rate' => null,
            'amount' => $amount,
            'pis_cofins_amount' => '0.00',
            'icms_base' => '0.00',
            'icms_rate' => '0.0000',
            'icms_amount' => '0.00',
            'source_label' => $sourceLabel,
        ];
    }
}
