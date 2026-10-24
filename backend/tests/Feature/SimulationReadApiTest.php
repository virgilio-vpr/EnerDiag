<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_list_is_paginated_and_scoped_to_authenticated_customer(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $ownIds = [];
        for ($index = 0; $index < 16; $index++) {
            $ownIds[] = $this->simulation($customer, $index % 2 === 0 ? 'queued' : 'failed')->id;
        }
        $foreignId = $this->simulation($otherCustomer, 'completed')->id;

        $token = $customer->createToken('simulation-list')->plainTextToken;
        $response = $this->withToken($token)
            ->getJson('/api/v1/simulations')
            ->assertOk()
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1);

        $listedIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(15, $listedIds);
        $this->assertSame([], array_diff($listedIds, $ownIds));
        $this->assertNotNull($response->json('links.next'));
        $this->assertArrayNotHasKey('processing_errors', $response->json('data.0'));

        $this->getJson($response->json('links.next'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonCount(1, 'data');

        $this->app['auth']->forgetGuards();
        $this->withToken($otherCustomer->createToken('simulation-list')->plainTextToken)
            ->getJson('/api/v1/simulations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $foreignId);
    }

    public function test_customer_can_read_status_transitions_and_file_errors(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('simulation-status')->plainTextToken;
        $simulation = $this->simulation($customer, 'queued', [[
            'file' => '01-conta.pdf',
            'code' => 'INVOICE_READ_FAILED',
            'errors' => ['message' => 'Não foi possível ler este arquivo.'],
        ]]);

        foreach (['queued', 'processing', 'completed', 'failed'] as $status) {
            $simulation->forceFill(['status' => $status])->save();
            $response = $this->withToken($token)->getJson('/api/v1/simulations/'.$simulation->id.'/status')
                ->assertOk()
                ->assertJsonPath('data.id', $simulation->id)
                ->assertJsonPath('data.status', $status);

            if ($status === 'failed') {
                $response->assertJsonPath('data.processing_errors.0.code', 'INVOICE_READ_FAILED')
                    ->assertJsonPath('data.processing_errors.0.file', '01-conta.pdf');
            } else {
                $response->assertJsonPath('data.processing_errors', null);
            }
        }
    }

    public function test_report_contains_indicators_series_and_warnings_only_for_owner_after_completion(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $simulation = $this->simulation($customer, 'completed');
        $report = Report::query()->create([
            'simulation_id' => $simulation->id,
            'total_monthly_savings' => '54.42',
            'total_yearly_savings' => '653.04',
            'savings_percentage' => '0.06',
            'reactive_savings_monthly' => '54.42',
            'reactive_savings_yearly' => '653.04',
            'chart_series' => ['reactive' => [['reference_month' => '2025-08', 'value' => '54.42']]],
            'warnings' => ['demand_optimization_unavailable_pending_section_9_limits'],
            'explanatory_texts' => ['percentage' => '% do valor médio pago no período'],
            'recommendations' => ['Otimização indisponível enquanto as regras estiverem pendentes.'],
            'r_values' => [['reference_month' => '2025-08', 'ratio' => '0.820000', 'source' => 'taxation']],
            'k_values' => ['2025-08' => ['factor' => '1.290210']],
        ]);
        $token = $customer->createToken('simulation-report')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/simulations/'.$simulation->id.'/report')
            ->assertOk()
            ->assertJsonPath('data.id', $report->id)
            ->assertJsonPath('data.total_monthly_savings', '54.42')
            ->assertJsonPath('data.chart_series.reactive.0.value', '54.42')
            ->assertJsonPath('data.warnings.0', 'demand_optimization_unavailable_pending_section_9_limits')
            ->assertJsonPath('data.r_values.0.ratio', '0.820000')
            ->assertJsonMissingPath('data.pdf_path');

        $this->withToken($token)->getJson('/api/v1/simulations/'.$this->simulation($customer, 'queued')->id.'/report')
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_NOT_READY');

        $this->app['auth']->forgetGuards();
        $foreignToken = $otherCustomer->createToken('simulation-report')->plainTextToken;
        $this->withToken($foreignToken)->getJson('/api/v1/simulations/'.$simulation->id.'/report')->assertNotFound();
    }

    public function test_read_endpoints_require_authentication(): void
    {
        $simulation = $this->simulation(User::factory()->create(), 'completed');

        $this->getJson('/api/v1/simulations')->assertUnauthorized();
        $this->getJson('/api/v1/simulations/'.$simulation->id.'/status')->assertUnauthorized();
        $this->getJson('/api/v1/simulations/'.$simulation->id.'/report')->assertUnauthorized();
        $this->getJson('/api/v1/simulations/'.$simulation->id.'/pdf')->assertUnauthorized();
    }

    public function test_customer_can_download_non_empty_pdf_with_report_indicators_only_after_completion(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $simulation = $this->simulation($customer, 'completed');
        Report::query()->create([
            'simulation_id' => $simulation->id,
            'total_monthly_savings' => '54.42',
            'total_yearly_savings' => '653.04',
            'savings_percentage' => '12.50',
            'reactive_savings_monthly' => '54.42',
            'reactive_savings_yearly' => '653.04',
            'warnings' => ['demand optimization pending'],
        ]);
        $token = $customer->createToken('simulation-pdf')->plainTextToken;

        $response = $this->withToken($token)->get('/api/v1/simulations/'.$simulation->id.'/pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="relatorio-enerdiag-'.$simulation->id.'.pdf"');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringContainsString('54.42', $response->getContent());
        $this->assertStringContainsString('653.04', $response->getContent());

        $this->withToken($token)->get('/api/v1/simulations/'.$this->simulation($customer, 'queued')->id.'/pdf')
            ->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->withToken($otherCustomer->createToken('simulation-pdf')->plainTextToken)
            ->get('/api/v1/simulations/'.$simulation->id.'/pdf')->assertNotFound();
    }

    /** @param list<array{file: string, code: string, errors: array<string, string>}>|null $errors */
    private function simulation(User $customer, string $status, ?array $errors = null): Simulation
    {
        return Simulation::query()->create([
            'user_id' => $customer->id,
            'status' => $status,
            'processing_errors' => $errors,
            'months_count' => $status === 'completed' ? 1 : 0,
        ]);
    }
}
