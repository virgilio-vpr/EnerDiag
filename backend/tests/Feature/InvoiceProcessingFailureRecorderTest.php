<?php

namespace Tests\Feature;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Invoices\Validation\InvoiceExtractionValidator;
use App\Invoices\Validation\InvoiceProcessingFailureRecorder;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceProcessingFailureRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_simulation_failed_and_keeps_the_error_attached_to_its_file(): void
    {
        $simulation = Simulation::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'queued',
        ]);

        $valid = $this->invoice('2025-08', '10.00');
        $invalid = $this->invoice('2025-09', '9.00');
        try {
            app(InvoiceExtractionValidator::class)->validate([$valid, $invalid]);
            $this->fail('The inconsistent invoice should fail post-extraction validation.');
        } catch (ValidationException $exception) {
            app(InvoiceProcessingFailureRecorder::class)->recordValidationErrors(
                $simulation,
                ['invoice-01.pdf', 'invoice-02.pdf'],
                $exception->errors(),
            );
        }

        $simulation->refresh();
        $this->assertSame('failed', $simulation->status);
        $this->assertSame('invoice-02.pdf', $simulation->processing_errors[0]['file']);
        $this->assertSame(InvoiceProcessingFailureRecorder::VALIDATION_ERROR_CODE, $simulation->processing_errors[0]['code']);
        $this->assertArrayHasKey('items.0.amount', $simulation->processing_errors[0]['errors']);
    }

    private function invoice(string $month, string $amount): ExtractedInvoiceData
    {
        return ExtractedInvoiceData::fromArray([
            'reference_month' => $month,
            'consumer_unit' => 'UC-1',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '1000.00',
            'items' => [[
                'item_type' => 'active_consumption', 'tariff_component' => 'energy_te', 'time_slot' => 'peak',
                'reactive_nature' => 'not_applicable', 'quantity' => '1.000', 'unit' => 'kWh',
                'unit_price_taxed' => '10.00000', 'tariff_rate' => '8.00000', 'amount' => $amount,
                'pis_cofins_amount' => '0.00', 'icms_base' => '0.00', 'icms_rate' => '0.0000', 'icms_amount' => '0.00',
            ]],
        ]);
    }
}
