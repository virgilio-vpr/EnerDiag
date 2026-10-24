<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Report;
use App\Models\ReportRevision;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurgeExpiredSimulationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_removes_simulations_older_than_180_days_and_all_related_records(): void
    {
        $user = User::factory()->create(['contact_requested_at' => now()->subDays(2)]);
        [$expired, $expiredInvoice, $expiredItem, $expiredReport, $expiredRevision] = $this->simulation($user, now()->subDays(181));
        [$recent] = $this->simulation($user, now()->subDays(179));

        $this->artisan('simulations:purge-expired')
            ->expectsOutput('Simulações expiradas removidas: 1.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('simulations', ['id' => $expired->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $expiredInvoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $expiredItem->id]);
        $this->assertDatabaseMissing('reports', ['id' => $expiredReport->id]);
        $this->assertDatabaseMissing('report_revisions', ['id' => $expiredRevision->id]);
        $this->assertDatabaseHas('simulations', ['id' => $recent->id]);
        $this->assertSame($user->contact_requested_at->toDateTimeString(), $user->fresh()->contact_requested_at->toDateTimeString());
    }

    /** @return array{Simulation, Invoice, InvoiceItem, Report, ReportRevision} */
    private function simulation(User $user, Carbon $createdAt): array
    {
        $simulation = Simulation::query()->create(['user_id' => $user->id, 'status' => 'completed']);
        $simulation->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $invoice = Invoice::query()->create([
            'simulation_id' => $simulation->id,
            'reference_month' => '2025-08',
            'consumer_unit' => '123456',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '100.00',
        ]);
        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_type' => 'consumption',
            'amount' => '100.00',
        ]);
        $report = Report::query()->create(['simulation_id' => $simulation->id]);
        $revision = ReportRevision::query()->create([
            'report_id' => $report->id,
            'explanatory_texts' => ['text' => 'Revision'],
        ]);

        return [$simulation, $invoice, $item, $report, $revision];
    }
}
