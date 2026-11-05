<?php

namespace Tests\Feature;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\RecalculatedReportData;
use App\Invoices\Pdf\PdfPageCounter;
use App\Jobs\ProcessSimulation;
use App\Mail\SimulationCompleted;
use App\Models\Distributor;
use App\Models\Report;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CustomerSimulationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Storage::fake('invoice_temp');
    }

    public function test_the_limit_counts_drafts_failed_and_completed_simulations_and_applies_to_both_creation_routes(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        foreach (['draft', 'failed', 'completed'] as $status) {
            $customer->simulations()->create(['status' => $status]);
        }
        $distributor = Distributor::query()->create(['name' => 'ENEL SP', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);
        $data = ['company_name' => 'Empresa Exemplo', 'distributor_id' => $distributor->id, 'address_details' => 'Rua Exemplo, 100', 'substation_same_address' => true];
        $this->postJson('/api/v1/invoice-imports', $data)->assertStatus(409)->assertJsonPath('code', 'SIMULATION_LIMIT_REACHED');
        $this->mock(PdfPageCounter::class)->shouldReceive('count')->andReturn(1);
        $this->postJson('/api/v1/simulations', $data + ['invoices' => [UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fixture')]])->assertStatus(409)->assertJsonPath('code', 'SIMULATION_LIMIT_REACHED');
        $this->getJson('/api/v1/simulations')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.limit', 3)->assertJsonPath('meta.can_create', false);
        $this->assertDatabaseCount('simulations', 3);
        Queue::assertNothingPushed();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/invoice-imports', $data)->assertCreated();
    }

    public function test_deleting_an_owned_simulation_removes_its_data_and_frees_a_slot(): void
    {
        $customer = User::factory()->create();
        $simulation = $this->savedSimulation($customer);
        $invoice = $simulation->invoices()->firstOrFail();
        $invoice->items()->create(['item_type' => 'measured_demand', 'time_slot' => 'peak', 'amount' => '10.00']);
        Report::query()->create(['simulation_id' => $simulation->id]);
        $customer->simulations()->create(['status' => 'draft']);
        $customer->simulations()->create(['status' => 'failed']);
        Storage::disk('invoice_temp')->put('simulation-'.$simulation->id.'/invoice.pdf', 'private temporary file');
        $this->actingAs($customer);
        $this->deleteJson('/api/v1/simulations/'.$simulation->id)->assertNoContent();
        $this->assertDatabaseMissing('simulations', ['id' => $simulation->id]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('reports', 0);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles());
        $this->getJson('/api/v1/simulations')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.can_create', true);
        $distributor = Distributor::query()->create(['name' => 'ENEL SP', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);
        $this->postJson('/api/v1/invoice-imports', ['company_name' => 'Empresa', 'distributor_id' => $distributor->id, 'address_details' => 'Rua Exemplo', 'substation_same_address' => true])->assertCreated();
        $this->assertSame(3, $customer->simulations()->count());
        app()->call([new ProcessSimulation($simulation->id), 'handle']);
    }

    public function test_a_customer_cannot_delete_or_process_another_customers_simulation(): void
    {
        $simulation = $this->savedSimulation(User::factory()->create());
        $this->deleteJson('/api/v1/simulations/'.$simulation->id)->assertUnauthorized();
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/simulations/'.$simulation->id)->assertNotFound();
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertNotFound();
        $this->assertDatabaseHas('simulations', ['id' => $simulation->id]);
    }

    public function test_a_queued_simulation_finishes_inline_and_repeated_requests_do_not_repeat_the_calculation_or_email(): void
    {
        $customer = User::factory()->create();
        $simulation = $this->savedSimulation($customer);
        $this->mock(ReportCalculationEngine::class)->shouldReceive('calculate')->once()->andReturn($this->calculatedReport());
        $this->actingAs($customer);
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('simulations', ['id' => $simulation->id, 'status' => 'completed', 'months_count' => 1]);
        $this->assertDatabaseCount('reports', 1);
        Queue::assertNotPushed(ProcessSimulation::class);
        Mail::assertQueued(SimulationCompleted::class, 1);
        $this->assertEqualsWithDelta(180, now()->diffInDays($simulation->fresh()->expires_at), 1);
    }

    public function test_a_notification_failure_does_not_turn_a_completed_report_into_a_failed_simulation(): void
    {
        $customer = User::factory()->create();
        $simulation = $this->savedSimulation($customer);
        $this->mock(ReportCalculationEngine::class)->shouldReceive('calculate')->once()->andReturn($this->calculatedReport());
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Notification unavailable'));
        $this->actingAs($customer)->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_a_failed_calculation_retains_invoices_and_can_be_retried(): void
    {
        $customer = User::factory()->create();
        $simulation = $this->savedSimulation($customer);
        $this->mock(ReportCalculationEngine::class)->shouldReceive('calculate')->once()->andThrow(new RuntimeException('Calculation failed'));
        $this->actingAs($customer)->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertStatus(500)->assertJsonPath('code', 'SIMULATION_PROCESSING_FAILED');
        $this->assertDatabaseHas('simulations', ['id' => $simulation->id, 'status' => 'failed']);
        $this->assertDatabaseCount('invoices', 1);
        $this->mock(ReportCalculationEngine::class)->shouldReceive('calculate')->once()->andReturn($this->calculatedReport());
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_an_active_calculation_is_not_started_twice_or_deleted_and_a_stale_one_can_be_resumed(): void
    {
        $customer = User::factory()->create();
        $simulation = $this->savedSimulation($customer);
        $simulation->update(['status' => 'processing']);
        $this->actingAs($customer);
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertStatus(202)->assertJsonPath('data.status', 'processing');
        $this->deleteJson('/api/v1/simulations/'.$simulation->id)->assertStatus(409);
        $simulation->forceFill(['updated_at' => now()->subMinutes(2)])->save();
        $this->mock(ReportCalculationEngine::class)->shouldReceive('calculate')->once()->andReturn($this->calculatedReport());
        $this->postJson('/api/v1/simulations/'.$simulation->id.'/process')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    private function savedSimulation(User $customer): Simulation
    {
        $simulation = $customer->simulations()->create(['status' => 'queued']);
        $simulation->invoices()->create(['reference_month' => '2026-03', 'consumer_unit' => '123456789', 'subgroup' => 'A4', 'tariff_modality' => 'blue', 'total_paid' => '1000.00']);

        return $simulation;
    }

    private function calculatedReport(): RecalculatedReportData
    {
        return new RecalculatedReportData(['total_monthly_savings' => '100.00', 'total_yearly_savings' => '1200.00'], '1.0', [], []);
    }
}
