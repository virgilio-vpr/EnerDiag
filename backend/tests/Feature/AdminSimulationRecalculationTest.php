<?php

namespace Tests\Feature;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\RecalculatedReportData;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Report;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSimulationRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_recalculates_report_from_extracted_data_and_keeps_invoice_data_unchanged(): void
    {
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create([
            'status' => 'completed',
            'expires_at' => now()->addDay(),
        ]);
        $invoice = Invoice::query()->create([
            'simulation_id' => $simulation->id,
            'reference_month' => '2026-01',
            'consumer_unit' => '123',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '1000.00',
        ]);
        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_type' => 'measured_demand',
            'tariff_component' => 'demand',
            'time_slot' => 'peak',
            'reactive_nature' => 'not_applicable',
            'quantity' => '10.000',
            'unit' => 'kW',
            'unit_price_taxed' => '0.50000',
            'tariff_rate' => '0.50000',
            'amount' => '5.00',
        ]);
        $report = Report::query()->create([
            'simulation_id' => $simulation->id,
            'total_monthly_savings' => '4.00',
            'total_yearly_savings' => '48.00',
            'algorithm_version' => '1.0',
            'explanatory_texts' => ['intro' => 'Texto existente'],
            'recommendations' => ['next' => 'Ação existente'],
        ]);
        $this->app->instance(ReportCalculationEngine::class, new class implements ReportCalculationEngine
        {
            public function calculate(Simulation $simulation): RecalculatedReportData
            {
                return new RecalculatedReportData(
                    calculatedAttributes: [
                        'total_monthly_savings' => '8.00',
                        'total_yearly_savings' => '96.00',
                    ],
                    algorithmVersion: '2.0',
                    tariffVersionIds: [],
                    regulatoryParameterVersionIds: [],
                );
            }
        });
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/simulations/'.$simulation->id.'/recalculate')
            ->assertOk()
            ->assertJsonPath('data.total_monthly_savings', '8.00')
            ->assertJsonPath('data.algorithm_version', '2.0');

        $this->assertSame('10.000', $item->fresh()->quantity);
        $this->assertSame('0.50000', $item->fresh()->unit_price_taxed);
        $this->assertSame('8.00', $simulation->fresh()->total_monthly_savings);
        $this->assertSame('4.00', $report->fresh()->revisions()->sole()->calculation_snapshot['total_monthly_savings']);
        $this->assertSame(['intro' => 'Texto existente'], $report->fresh()->explanatory_texts);
    }

    public function test_expired_simulation_cannot_be_recalculated_through_api(): void
    {
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create([
            'status' => 'completed',
            'expires_at' => now()->subSecond(),
        ]);
        Report::query()->create(['simulation_id' => $simulation->id]);
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/simulations/'.$simulation->id.'/recalculate')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'SIMULATION_EXPIRED');
    }

    public function test_only_admin_can_recalculate_a_simulation(): void
    {
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create(['status' => 'completed']);
        $customerToken = $customer->createToken('customer')->plainTextToken;

        $this->postJson('/api/v1/admin/simulations/'.$simulation->id.'/recalculate')->assertUnauthorized();
        $this->withToken($customerToken)
            ->postJson('/api/v1/admin/simulations/'.$simulation->id.'/recalculate')
            ->assertForbidden();
    }
}
