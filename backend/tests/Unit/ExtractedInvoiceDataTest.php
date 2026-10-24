<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExtractedInvoiceDataTest extends TestCase
{
    public function test_it_validates_required_invoice_fields(): void
    {
        $data = $this->invoiceData();
        unset($data['consumer_unit']);

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected invalid extracted data to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('consumer_unit', $exception->errors());
        }
    }

    public function test_it_requires_the_total_paid_value(): void
    {
        $data = $this->invoiceData();
        unset($data['total_paid']);

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('An invoice without total paid must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_paid', $exception->errors());
        }
    }

    public function test_an_omitted_item_list_is_normalized_to_no_items(): void
    {
        $data = $this->invoiceData();
        unset($data['items']);

        $invoice = ExtractedInvoiceData::fromArray($data);

        $this->assertSame([], $invoice->items);
    }

    public function test_it_accepts_idle_demand_without_quantity_or_unit_price(): void
    {
        $data = $this->invoiceData();
        $data['items'] = [$this->itemData('idle_demand', null, null, '1845.32')];

        $invoice = ExtractedInvoiceData::fromArray($data);

        $this->assertNull($invoice->items[0]->quantity);
        $this->assertNull($invoice->items[0]->unitPriceTaxed);
        $this->assertSame('0.0000', $invoice->items[0]->icmsRate);
    }

    public function test_it_rejects_missing_quantity_for_a_regular_item(): void
    {
        $data = $this->invoiceData();
        $data['items'] = [$this->itemData('measured_demand', null, '37.80322', '8383.25')];

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected a regular item without quantity to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.quantity', $exception->errors());
        }
    }

    public function test_it_requires_the_tax_fields_on_present_items(): void
    {
        $data = $this->invoiceData();
        $item = $this->itemData('measured_demand', '1.000', '37.80322', '37.80');
        unset($item['pis_cofins_amount']);
        $data['items'] = [$item];

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected a present item without PIS/COFINS to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.pis_cofins_amount', $exception->errors());
        }
    }

    public function test_it_enforces_decimal_precision_and_rejects_floats(): void
    {
        $data = $this->invoiceData();
        $data['total_paid'] = '123.456';

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected excess decimal precision to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_paid', $exception->errors());
        }

        $data['total_paid'] = 123.45;

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected a floating-point value to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_paid', $exception->errors());
        }
    }

    public function test_it_preserves_the_decimal_scales_defined_for_invoice_items(): void
    {
        $data = $this->invoiceData();
        $data['items'] = [$this->itemData('measured_demand', '221.760', '37.80322', '8383.25')];
        $data['items'][0]['tariff_rate'] = '29.30000';

        $invoice = ExtractedInvoiceData::fromArray($data);

        $this->assertSame('221.760', $invoice->items[0]->quantity);
        $this->assertSame('37.80322', $invoice->items[0]->unitPriceTaxed);
        $this->assertSame('29.30000', $invoice->items[0]->tariffRate);

        $data['items'][0]['quantity'] = '221.7601';

        try {
            ExtractedInvoiceData::fromArray($data);
            $this->fail('Expected quantity beyond three decimal places to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.quantity', $exception->errors());
        }
    }

    /** @return array<string, mixed> */
    private function invoiceData(): array
    {
        return [
            'reference_month' => '2025-08',
            'consumer_unit' => '123456789',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '98922.22',
            'items' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function itemData(string $type, ?string $quantity, ?string $unitPrice, string $amount): array
    {
        return [
            'item_type' => $type,
            'tariff_component' => 'demand',
            'time_slot' => 'off_peak',
            'reactive_nature' => 'not_applicable',
            'quantity' => $quantity,
            'unit' => 'kW',
            'unit_price_taxed' => $unitPrice,
            'tariff_rate' => null,
            'amount' => $amount,
            'pis_cofins_amount' => '0.00',
            'icms_base' => '0.00',
            'icms_rate' => '0.0000',
            'icms_amount' => '0.00',
            'source_label' => 'DEMANDA LEI ESTADUAL 16.886/18',
        ];
    }
}
