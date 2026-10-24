<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Invoices\Validation\InvoiceExtractionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceExtractionValidatorTest extends TestCase
{
    public function test_it_rejects_duplicate_reference_months_and_associates_the_error_with_the_second_file(): void
    {
        $validator = new InvoiceExtractionValidator;

        try {
            $validator->validate([$this->invoice('2025-08'), $this->invoice('2025-08')]);
            $this->fail('Duplicate reference months must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoices.1.reference_month', $exception->errors());
        }
    }

    public function test_it_rejects_invoices_from_different_consumer_units(): void
    {
        $validator = new InvoiceExtractionValidator;

        try {
            $validator->validate([
                $this->invoice('2025-08', consumerUnit: 'UC-1'),
                $this->invoice('2025-09', consumerUnit: 'UC-2'),
            ]);
            $this->fail('Invoices from different consumer units must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoices.1.consumer_unit', $exception->errors());
        }
    }

    public function test_it_accepts_quantity_price_difference_of_one_cent(): void
    {
        $item = $this->item(quantity: '1.000', price: '9.99000', amount: '10.00');

        $this->assertNull((new InvoiceExtractionValidator)->validate([$this->invoice('2025-08', items: [$item])]));
    }

    public function test_it_rejects_quantity_price_difference_above_one_cent(): void
    {
        $item = $this->item(quantity: '1.000', price: '9.98000', amount: '10.00');

        try {
            (new InvoiceExtractionValidator)->validate([$this->invoice('2025-08', items: [$item])]);
            $this->fail('An amount mismatch above one cent must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoices.0.items.0.amount', $exception->errors());
        }
    }

    public function test_it_accepts_icms_difference_of_one_cent(): void
    {
        $item = $this->item(quantity: '1.000', price: '100.00000', amount: '100.00', icmsAmount: '17.99');

        $this->assertNull((new InvoiceExtractionValidator)->validate([$this->invoice('2025-08', items: [$item])]));
    }

    public function test_it_rejects_icms_difference_above_one_cent(): void
    {
        $item = $this->item(quantity: '1.000', price: '100.00000', amount: '100.00', icmsAmount: '17.98');

        try {
            (new InvoiceExtractionValidator)->validate([$this->invoice('2025-08', items: [$item])]);
            $this->fail('An ICMS mismatch above one cent must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoices.0.items.0.icms_amount', $exception->errors());
        }
    }

    public function test_it_does_not_require_extracted_items_to_sum_to_the_total_paid(): void
    {
        $this->assertNull((new InvoiceExtractionValidator)->validate([
            $this->invoice('2025-08', items: [$this->item(quantity: '1.000', price: '1.00000', amount: '1.00')]),
        ]));
    }

    private function invoice(string $month, string $consumerUnit = 'UC-1', array $items = []): ExtractedInvoiceData
    {
        return ExtractedInvoiceData::fromArray([
            'reference_month' => $month,
            'consumer_unit' => $consumerUnit,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '98922.22',
            'items' => $items,
        ]);
    }

    private function item(string $quantity, string $price, string $amount, string $icmsAmount = '18.00'): array
    {
        return [
            'item_type' => 'active_consumption',
            'tariff_component' => 'energy_te',
            'time_slot' => 'peak',
            'reactive_nature' => 'not_applicable',
            'quantity' => $quantity,
            'unit' => 'kWh',
            'unit_price_taxed' => $price,
            'tariff_rate' => null,
            'amount' => $amount,
            'pis_cofins_amount' => '4.49',
            'icms_base' => '100.00',
            'icms_rate' => '0.1800',
            'icms_amount' => $icmsAmount,
        ];
    }
}
