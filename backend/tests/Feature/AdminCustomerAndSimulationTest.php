<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerAndSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_endpoints_require_admin_access(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('customer')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customer->id)->assertForbidden();
    }

    public function test_simulation_endpoints_require_admin_access(): void
    {
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create(['status' => 'completed']);
        $token = $customer->createToken('customer')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/simulations')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/simulations/'.$simulation->id)->assertForbidden();
    }

    public function test_customers_are_paginated_and_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create();
        User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/customers?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.role', 'customer');
    }

    public function test_simulations_are_paginated_and_details_include_extracted_data_without_pdf_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create(['status' => 'completed', 'months_count' => 1]);
        $invoice = $simulation->invoices()->create([
            'reference_month' => '2026-09',
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '100.00',
        ]);
        $invoice->items()->create([
            'item_type' => 'active_energy',
            'tariff_component' => 'energy_te',
            'time_slot' => 'peak',
            'reactive_nature' => 'not_applicable',
            'quantity' => '12.000',
            'unit' => 'kWh',
            'unit_price_taxed' => '0.50000',
            'tariff_rate' => '0.40000',
            'amount' => '6.00',
        ]);
        Report::query()->create(['simulation_id' => $simulation->id]);
        $customer->simulations()->create(['status' => 'queued']);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/simulations?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);

        $response = $this->withToken($token)->getJson('/api/v1/admin/simulations/'.$simulation->id);
        $response->assertOk()
            ->assertJsonPath('data.invoices.0.reference_month', '2026-09')
            ->assertJsonPath('data.invoices.0.items.0.quantity', '12.000')
            ->assertJsonPath('data.report.id', $simulation->report->id)
            ->assertJsonMissingPath('data.pdf')
            ->assertJsonMissingPath('data.invoices.0.pdf_path');

        $this->assertStringNotContainsString('pdf_path', $response->getContent());
        $this->assertStringNotContainsString('file_path', $response->getContent());
    }

    public function test_admin_detail_endpoints_return_not_found_for_unknown_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/customers/9999')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/admin/simulations/9999')->assertNotFound();
    }
}
