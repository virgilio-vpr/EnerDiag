<?php

namespace Tests\Feature;

use App\Mail\AdminReportResend;
use App\Models\Report;
use App\Models\ReportRevision;
use App\Models\User;
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
