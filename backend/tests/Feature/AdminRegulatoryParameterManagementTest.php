<?php

namespace Tests\Feature;

use App\Models\RegulatoryParameter;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRegulatoryParameterManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_new_parameter_version_and_preserves_previous_report_reference(): void
    {
        $previous = RegulatoryParameter::query()->create([
            'key' => 'idle_demand_discount_fallback',
            'value' => '0.18000',
            'state' => null,
            'valid_from' => '2021-01-01',
        ]);
        $customer = User::factory()->create();
        $simulation = $customer->simulations()->create(['status' => 'completed']);
        $report = Report::query()->create([
            'simulation_id' => $simulation->id,
            'regulatory_parameter_version_ids' => [$previous->id],
        ]);
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)->postJson('/api/v1/admin/parameters', [
            'key' => 'idle_demand_discount_fallback',
            'value' => '0.20000',
            'valid_from' => '2027-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.value', '0.20000')
            ->assertJsonPath('data.state', null);

        $this->assertSame('0.18000', $previous->fresh()->value);
        $this->assertSame('2026-12-31', $previous->fresh()->valid_to->toDateString());
        $this->assertSame([$previous->id], $report->fresh()->regulatory_parameter_version_ids);
        $this->assertDatabaseCount('regulatory_parameters', 2);
    }

    public function test_overlapping_or_out_of_range_parameter_versions_are_rejected(): void
    {
        RegulatoryParameter::query()->create([
            'key' => 'reference_power_factor',
            'value' => '0.92000',
            'state' => null,
            'valid_from' => '2021-01-01',
        ]);
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)->postJson('/api/v1/admin/parameters', [
            'key' => 'reference_power_factor',
            'value' => '1.20000',
            'valid_from' => '2027-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->withToken($adminToken)->postJson('/api/v1/admin/parameters', [
            'key' => 'reference_power_factor',
            'value' => '0.95000',
            'valid_from' => '2020-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('valid_from');

        $this->assertDatabaseCount('regulatory_parameters', 1);
    }

    public function test_parameter_listing_is_admin_only(): void
    {
        $this->getJson('/api/v1/admin/parameters')->assertUnauthorized();
        $customerToken = User::factory()->create()->createToken('customer')->plainTextToken;

        $this->withToken($customerToken)->getJson('/api/v1/admin/parameters')->assertForbidden();
    }
}
