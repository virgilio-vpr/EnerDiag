<?php

namespace Tests\Unit;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\RecalculatedReportData;
use App\Calculation\Exceptions\ExpiredSimulationException;
use App\Calculation\Services\ReportRecalculationService;
use App\Models\Distributor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Report;
use App\Models\Simulation;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportRecalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculation_uses_updated_tariffs_and_preserves_previous_report_and_extracted_data(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24));
        [$simulation, $report, $invoiceItem, $oldTariff, $newTariff] = $this->simulationWithTariffVersions();
        $engine = new TariffBackedReportCalculationEngine;
        $this->app->instance(ReportCalculationEngine::class, $engine);

        $updatedReport = app(ReportRecalculationService::class)->recalculate($simulation->id);

        $this->assertSame('80.00', $updatedReport->total_monthly_savings);
        $this->assertSame('960.00', $updatedReport->total_yearly_savings);
        $this->assertSame('2.0', $updatedReport->algorithm_version);
        $this->assertSame([$newTariff->id], $updatedReport->tariff_version_ids);
        $this->assertSame(['intro' => 'Texto existente'], $updatedReport->explanatory_texts);
        $this->assertSame('80.00', $simulation->fresh()->total_monthly_savings);
        $this->assertSame('10.000', $invoiceItem->fresh()->quantity);
        $this->assertSame('0.50000', $invoiceItem->fresh()->unit_price_taxed);

        $this->assertDatabaseHas('report_revisions', ['report_id' => $report->id, 'reason' => 'calculation_recalculation']);
        $revision = $report->revisions()->sole();
        $this->assertSame('4.00', $revision->calculation_snapshot['total_monthly_savings']);
        $this->assertSame([$oldTariff->id], $revision->calculation_snapshot['tariff_version_ids']);
        $this->assertSame(['intro' => 'Texto existente'], $revision->explanatory_texts);
    }

    public function test_expired_simulation_cannot_be_recalculated(): void
    {
        $simulation = $this->simulationWithReport(now()->subSecond());
        $this->app->instance(ReportCalculationEngine::class, new TariffBackedReportCalculationEngine);

        $this->expectException(ExpiredSimulationException::class);
        app(ReportRecalculationService::class)->recalculate($simulation->id);
    }

    /** @return array{Simulation, Report, InvoiceItem, Tariff, Tariff} */
    private function simulationWithTariffVersions(): array
    {
        $user = User::create([
            'name' => 'Test Customer', 'email' => 'recalculate@example.com', 'password_hash' => 'secret123', 'role' => 'customer',
        ]);
        $distributor = Distributor::create(['name' => 'ENEL SP', 'state' => 'SP', 'active' => true]);
        $oldTariff = $this->tariff($distributor, '0.50000', '2025-01-01', '2026-10-23');
        $newTariff = $this->tariff($distributor, '8.00000', '2026-10-24', null);
        $simulation = Simulation::create([
            'user_id' => $user->id, 'status' => 'completed', 'months_count' => 1,
            'total_monthly_savings' => '4.00', 'total_yearly_savings' => '48.00', 'expires_at' => now()->addDays(30),
        ]);
        $invoice = Invoice::create([
            'simulation_id' => $simulation->id, 'reference_month' => '2025-08', 'consumer_unit' => '123',
            'subgroup' => 'A4', 'tariff_modality' => 'blue', 'total_paid' => '1000.00',
        ]);
        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id, 'item_type' => 'measured_demand', 'tariff_component' => 'demand',
            'time_slot' => 'peak', 'reactive_nature' => 'not_applicable', 'quantity' => '10.000', 'unit' => 'kW',
            'unit_price_taxed' => '0.50000', 'tariff_rate' => '0.50000', 'amount' => '5.00',
            'pis_cofins_amount' => '0.00', 'icms_base' => '5.00', 'icms_rate' => '0.1800', 'icms_amount' => '0.90',
        ]);
        $report = Report::create([
            'simulation_id' => $simulation->id, 'total_monthly_savings' => '4.00', 'total_yearly_savings' => '48.00',
            'demand_savings_monthly' => '4.00', 'demand_savings_yearly' => '48.00', 'algorithm_version' => '1.0',
            'chart_series' => ['old' => true], 'warnings' => [], 'explanatory_texts' => ['intro' => 'Texto existente'],
            'recommendations' => ['next' => 'Ação existente'], 'tariff_version_ids' => [$oldTariff->id],
            'regulatory_parameter_version_ids' => [],
        ]);

        return [$simulation, $report, $invoiceItem, $oldTariff, $newTariff];
    }

    private function simulationWithReport($expiresAt): Simulation
    {
        $user = User::create([
            'name' => 'Expired Customer', 'email' => 'expired@example.com', 'password_hash' => 'secret123', 'role' => 'customer',
        ]);
        $simulation = Simulation::create(['user_id' => $user->id, 'status' => 'completed', 'months_count' => 1, 'expires_at' => $expiresAt]);
        Report::create(['simulation_id' => $simulation->id]);

        return $simulation;
    }

    private function tariff(Distributor $distributor, string $rate, string $validFrom, ?string $validTo): Tariff
    {
        return Tariff::create([
            'distributor_id' => $distributor->id, 'subgroup' => 'A4', 'tariff_modality' => 'blue',
            'time_slot' => 'peak', 'component' => 'demand', 'rate' => $rate, 'includes_taxes' => true,
            'source' => 'manual', 'valid_from' => $validFrom, 'valid_to' => $validTo,
        ]);
    }
}

final class TariffBackedReportCalculationEngine implements ReportCalculationEngine
{
    public function calculate(Simulation $simulation): RecalculatedReportData
    {
        $quantity = $simulation->invoices->flatMap->items->first()->quantity;
        $tariff = Tariff::query()->where('component', 'demand')->where('time_slot', 'peak')
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString()))
            ->orderByDesc('valid_from')->firstOrFail();
        $monthly = bcmul($quantity, $tariff->rate, 2);

        return new RecalculatedReportData(
            calculatedAttributes: [
                'total_monthly_savings' => $monthly,
                'total_yearly_savings' => bcmul($monthly, '12', 2),
                'demand_savings_monthly' => $monthly,
                'demand_savings_yearly' => bcmul($monthly, '12', 2),
            ],
            algorithmVersion: '2.0',
            tariffVersionIds: [$tariff->id],
            regulatoryParameterVersionIds: [],
        );
    }
}
