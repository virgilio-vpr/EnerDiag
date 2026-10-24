<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTariffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_taxed_and_untaxed_tariffs(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/v1/admin/tariffs', $this->payload(true))
            ->assertCreated()->assertJsonPath('data.includes_taxes', true);
        $this->withToken($token)->postJson('/api/v1/admin/tariffs', $this->payload(false))
            ->assertCreated()->assertJsonPath('data.includes_taxes', false);

        $this->assertDatabaseCount('tariffs', 2);
    }

    public function test_tax_basis_is_required_and_overlapping_validity_is_rejected(): void
    {
        $token = $this->adminToken();
        $payload = $this->payload(true);

        $this->withToken($token)->postJson('/api/v1/admin/tariffs', array_diff_key($payload, ['includes_taxes' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('includes_taxes');
        $this->withToken($token)->postJson('/api/v1/admin/tariffs', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/tariffs', [...$payload, 'rate' => '40.00000'])
            ->assertUnprocessable()->assertJsonValidationErrors('valid_from');
        $this->assertDatabaseCount('tariffs', 1);
    }

    public function test_put_creates_new_version_and_preserves_previous_rate(): void
    {
        $token = $this->adminToken();
        $original = Tariff::query()->create($this->payload(true));

        $this->withToken($token)->putJson('/api/v1/admin/tariffs/'.$original->id, [
            ...$this->payload(true),
            'rate' => '40.00000',
            'valid_from' => '2027-01-01',
            'valid_to' => null,
        ])->assertCreated()->assertJsonPath('data.rate', '40.00000');

        $this->assertSame('37.80322', $original->fresh()->rate);
        $this->assertSame('2026-12-31', $original->fresh()->valid_to->toDateString());
        $this->assertDatabaseCount('tariffs', 2);
    }

    public function test_tariff_management_is_admin_only(): void
    {
        $customer = User::factory()->create();
        $payload = $this->payload(true);

        $this->getJson('/api/v1/admin/tariffs')->assertUnauthorized();
        $this->withToken($customer->createToken('customer')->plainTextToken)
            ->postJson('/api/v1/admin/tariffs', $payload)->assertForbidden();
    }

    private function adminToken(): string
    {
        $this->distributor();

        return User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;
    }

    private function distributor(): Distributor
    {
        return Distributor::query()->firstOrCreate(
            ['name' => 'Distribuidora de teste'],
            ['state' => 'SP', 'active' => true],
        );
    }

    /** @return array<string, mixed> */
    private function payload(bool $includesTaxes): array
    {
        return [
            'distributor_id' => $this->distributor()->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'time_slot' => 'peak',
            'component' => 'demand',
            'rate' => '37.80322',
            'includes_taxes' => $includesTaxes,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_reference' => 'Resolução teste',
        ];
    }
}
