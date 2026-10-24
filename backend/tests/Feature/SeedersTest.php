<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_populate_required_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Distributors
        $enel = Distributor::where('parser_key', 'enel')->first();
        $this->assertNotNull($enel);
        $this->assertSame('ENEL SP', $enel->name);
        $this->assertSame('SP', $enel->state);
        $this->assertTrue($enel->active);

        // Regulatory parameters
        $this->assertDatabaseHas('regulatory_parameters', [
            'key' => 'overrun_tolerance',
            'value' => '0.05000',
        ]);
        $this->assertDatabaseHas('regulatory_parameters', [
            'key' => 'overrun_multiplier',
            'value' => '2.00000',
        ]);
        $this->assertDatabaseHas('regulatory_parameters', [
            'key' => 'reference_power_factor',
            'value' => '0.92000',
        ]);
        $this->assertDatabaseHas('regulatory_parameters', [
            'key' => 'idle_demand_discount_fallback',
            'value' => '0.18000',
        ]);

        // Tariffs with includes_taxes true and false
        $this->assertTrue(Tariff::where('includes_taxes', true)->exists());
        $this->assertTrue(Tariff::where('includes_taxes', false)->exists());

        // Quotation base
        $this->assertDatabaseHas('quotation_base', ['item_key' => 'consulting_hour']);
        $this->assertDatabaseHas('quotation_base', ['item_key' => 'power_analyzer_daily']);
        $this->assertDatabaseHas('quotation_base', ['item_key' => 'capacitor_bank_design_hour']);

        // Users in non-production
        $admin = User::where('role', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin@enerdiag.com.br', $admin->email);

        $clients = User::where('role', 'customer')->get();
        $this->assertCount(2, $clients);
        $this->assertNotNull($clients[0]->profile);
        $this->assertNotNull($clients[1]->profile);
    }

    public function test_test_users_seeder_does_not_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->app->environment('production'));

        $this->seed(TestUserSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);

        // Also test DatabaseSeeder skips TestUserSeeder in production
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 0);
    }
}
