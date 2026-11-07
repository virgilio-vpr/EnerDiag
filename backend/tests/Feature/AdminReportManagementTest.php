<?php

namespace Tests\Feature;

use App\Mail\AdminReportResend;
use App\Models\Invoice;
use App\Models\Report;
use App\Models\ReportRevision;
use App\Models\User;
use Database\Seeders\RegulatoryParameterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminReportManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_text_edit_creates_revision_with_author_and_preserves_calculated_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $report = $this->createReport([
            'total_monthly_savings' => '125.50',
            'total_yearly_savings' => '1506.00',
            'savings_percentage' => '12.34',
            'explanatory_texts' => ['Texto anterior'],
            'recommendations' => ['Recomendação anterior'],
        ]);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/reports/'.$report->id, [
            'explanatory_texts' => ['Texto atualizado'],
            'recommendations' => ['Recomendação atualizada'],
        ])->assertOk()
            ->assertJsonPath('data.total_monthly_savings', '125.50')
            ->assertJsonPath('data.explanatory_texts.0', 'Texto atualizado')
            ->assertJsonPath('data.recommendations.0', 'Recomendação atualizada');

        $revision = ReportRevision::query()->where('report_id', $report->id)->firstOrFail();
        $this->assertSame($admin->id, $revision->user_id);
        $this->assertNotNull($revision->created_at);
        $this->assertSame(['Texto atualizado'], $revision->explanatory_texts);
        $this->assertSame(['Recomendação atualizada'], $revision->recommendations);
        $this->assertSame('125.50', $revision->calculation_snapshot['total_monthly_savings']);
        $this->assertSame('1506.00', $report->fresh()->total_yearly_savings);
        $this->assertSame('12.34', $report->fresh()->savings_percentage);
    }

    public function test_attempt_to_change_calculated_result_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $report = $this->createReport(['total_monthly_savings' => '125.50']);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/reports/'.$report->id, [
            'explanatory_texts' => ['Texto válido'],
            'total_monthly_savings' => '999999.99',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertSame('125.50', $report->fresh()->total_monthly_savings);
        $this->assertDatabaseCount('report_revisions', 0);
    }

    public function test_report_edit_requires_authentication_and_admin_role(): void
    {
        $customer = User::factory()->create();
        $report = $this->createReport();

        $this->putJson('/api/v1/admin/reports/'.$report->id, [
            'explanatory_texts' => ['Texto'],
        ])->assertUnauthorized();

        $token = $customer->createToken('customer')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/admin/reports/'.$report->id, [
            'explanatory_texts' => ['Texto'],
        ])->assertForbidden();
    }

    public function test_report_resend_emails_current_report_to_customer_and_is_admin_only(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        $report = $this->createReport(['total_monthly_savings' => '125.50']);
        $report->simulation->user->update(['email' => 'customer@example.com']);
        $token = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/reports/'.$report->id.'/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Relatório reenviado por e-mail.');

        Mail::assertSent(AdminReportResend::class, fn (AdminReportResend $mail): bool => $mail->hasTo('customer@example.com')
            && $mail->report->is($report)
            && str_contains($mail->render(), 'Seu relatório de eficiência energética')
            && str_contains($mail->render(), 'R$ 125.50')
        );

    }

    public function test_customer_cannot_resend_report(): void
    {
        $customer = User::factory()->create();
        $report = $this->createReport();
        $customerToken = $customer->createToken('customer')->plainTextToken;

        $this->withToken($customerToken)->postJson('/api/v1/admin/reports/'.$report->id.'/resend')->assertForbidden();
    }

    public function test_report_operations_return_not_found_for_unknown_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->postJson('/api/v1/admin/reports/999999/resend')->assertUnauthorized();
        $this->withToken($token)->putJson('/api/v1/admin/reports/999999', [
            'explanatory_texts' => ['Texto'],
        ])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/admin/reports/999999/resend')->assertNotFound();
    }

    public function test_admin_downloads_the_same_pdf_as_the_customer(): void
    {
        $report = $this->createReport(['total_monthly_savings' => '125.50', 'total_yearly_savings' => '1506.00']);
        $admin = User::factory()->create(['role' => 'admin']);
        $adminPdf = $this->actingAs($admin)->get('/api/v1/admin/simulations/'.$report->simulation_id.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $customerPdf = $this->actingAs($report->simulation->user)->get('/api/v1/simulations/'.$report->simulation_id.'/pdf')
            ->assertOk()->getContent();
        $this->assertSame($customerPdf, $adminPdf);
    }

    public function test_admin_pdf_routes_reject_customers_and_missing_reports(): void
    {
        $report = $this->createReport();
        $url = '/api/v1/admin/simulations/'.$report->simulation_id;
        $this->getJson($url.'/pdf')->assertUnauthorized();
        $this->postJson($url.'/preview-demand/pdf')->assertUnauthorized();
        $this->actingAs($report->simulation->user)->getJson($url.'/pdf')->assertForbidden();
        $this->postJson($url.'/preview-demand/pdf', ['demands' => ['unique' => 120]])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/simulations/999999/pdf')->assertNotFound();
        $report->simulation->update(['status' => 'processing']);
        $this->getJson($url.'/pdf')->assertStatus(409)->assertJsonPath('code', 'REPORT_NOT_READY');
    }

    public function test_admin_previews_and_downloads_demand_scenarios_without_changing_saved_data(): void
    {
        $this->seed(RegulatoryParameterSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['blue' => ['peak', 'off_peak'], 'green' => ['unique']] as $modality => $slots) {
            $report = $this->createReport(['demand_savings_monthly' => '164.00', 'optimal_demand_single' => '77.000']);
            $invoice = Invoice::query()->create([
                'simulation_id' => $report->simulation_id, 'reference_month' => '2026-01',
                'consumer_unit' => 'test', 'subgroup' => 'A4', 'tariff_modality' => $modality, 'total_paid' => '2000.00',
            ]);
            foreach ($slots as $slot) {
                foreach (['contracted_demand' => ['100', null, '0'], 'measured_demand' => ['80', '10', '800'], 'idle_demand' => ['20', '8.2', '164']] as $type => [$quantity, $rate, $amount]) {
                    $invoice->items()->create([
                        'item_type' => $type, 'tariff_component' => 'demand', 'time_slot' => $slot,
                        'reactive_nature' => 'not_applicable', 'quantity' => $quantity, 'unit' => 'kW',
                        'unit_price_taxed' => $rate, 'amount' => $amount,
                    ]);
                }
            }
            $invoice->items()->create([
                'item_type' => 'reactive_energy_excess', 'tariff_component' => 'not_applicable',
                'time_slot' => 'not_applicable', 'reactive_nature' => 'inductive',
                'quantity' => '1.000', 'unit' => 'kWh', 'unit_price_taxed' => '128.96000', 'amount' => '128.96',
            ]);
            $originalReport = $report->fresh()->getAttributes();
            $originalItems = $invoice->items()->get()->toArray();
            $demands = array_fill_keys($slots, '120');
            $url = '/api/v1/admin/simulations/'.$report->simulation_id.'/preview-demand';
            $this->postJson($url, ['demands' => array_fill_keys($slots, '100')])->assertOk()
                ->assertJsonPath('data.demand_savings_monthly', '0.00')
                ->assertJsonPath('data.reactive_savings_monthly', '128.96')
                ->assertJsonPath('data.migration_savings_monthly', '0.00')
                ->assertJsonPath('data.total_monthly_savings', '128.96');
            $response = $this->postJson($url, ['demands' => $demands])->assertOk();
            $response->assertJsonPath('data.chart_series.demand.0.optimal', '120.000');
            $this->assertLessThan(0, (float) $response->json('data.demand_savings_monthly'));
            $pdf = $this->postJson($url.'/pdf', ['demands' => $demands])->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->assertSame($originalReport, $report->fresh()->getAttributes());
            $this->assertSame($originalItems, $invoice->items()->get()->toArray());
            $this->assertSame(0, $report->revisions()->count());
            $this->postJson($url, ['demands' => ['invalid' => 10]])->assertUnprocessable();
            $this->postJson($url.'/pdf', ['demands' => array_fill_keys($slots, -1)])->assertUnprocessable();
            $this->postJson($url, ['demands' => array_fill_keys($slots, '120.5')])->assertUnprocessable()->assertJsonValidationErrors('demands.'.$slots[0]);
            $this->postJson($url.'/pdf', ['demands' => array_fill_keys($slots, '120.5')])->assertUnprocessable()->assertJsonValidationErrors('demands.'.$slots[0]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createReport(array $attributes = []): Report
    {
        $user = User::factory()->create();
        $simulation = $user->simulations()->create(['status' => 'completed']);

        return Report::query()->create([
            'simulation_id' => $simulation->id,
            ...$attributes,
        ]);
    }
}
