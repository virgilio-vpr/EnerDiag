<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminTariffImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_official_csv_rows_and_reimport_does_not_duplicate_tariffs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin')->plainTextToken;
        $this->createDistributor();

        $this->withToken($token)->post('/api/v1/admin/tariffs/import', [
            'file' => $this->fixture(),
        ])->assertOk()
            ->assertJsonPath('data.rows_accepted', 3)
            ->assertJsonPath('data.rows_ignored', 1);

        $this->assertDatabaseHas('tariffs', [
            'component' => 'energy_tusd',
            'rate' => '0.12505',
            'includes_taxes' => false,
            'source' => 'aneel_import',
        ]);
        $this->assertDatabaseHas('tariffs', ['component' => 'energy_te', 'rate' => '0.43913']);
        $this->assertDatabaseHas('tariff_imports', [
            'imported_by' => $admin->id,
            'source_reference' => 'REH 1234/2026',
            'rows_accepted' => 3,
            'rows_ignored' => 1,
            'status' => 'completed',
        ]);

        $this->withToken($token)->post('/api/v1/admin/tariffs/import', [
            'file' => $this->fixture(),
        ])->assertOk()->assertJsonPath('data.rows_accepted', 0);

        $this->assertDatabaseCount('tariffs', 3);
        $this->assertDatabaseCount('tariff_imports', 2);
    }

    public function test_import_closes_previous_tariff_validity_without_changing_its_rate(): void
    {
        $distributor = $this->createDistributor();
        $previous = Tariff::query()->create([
            'distributor_id' => $distributor->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'time_slot' => 'peak',
            'component' => 'demand',
            'rate' => '28.00000',
            'includes_taxes' => false,
            'source' => 'aneel_import',
            'source_reference' => 'REH anterior',
            'valid_from' => '2025-10-01',
            'valid_to' => null,
        ]);
        $token = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($token)->post('/api/v1/admin/tariffs/import', ['file' => $this->fixture()])->assertOk();

        $this->assertSame('28.00000', $previous->fresh()->rate);
        $this->assertSame('2026-09-30', $previous->fresh()->valid_to->toDateString());
    }

    public function test_invalid_csv_returns_422_and_import_requires_admin(): void
    {
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;
        $invalidFile = UploadedFile::fake()->createWithContent('invalid.csv', "wrong;headers\nfoo;bar\n");

        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/tariffs/import', ['file' => $this->fixture()])->assertUnauthorized();
        $this->withToken($adminToken)->post('/api/v1/admin/tariffs/import', ['file' => $invalidFile])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

    }

    public function test_customer_cannot_import_aneel_tariffs(): void
    {
        $customerToken = User::factory()->create()->createToken('customer')->plainTextToken;

        $this->withToken($customerToken)
            ->post('/api/v1/admin/tariffs/import', ['file' => $this->fixture()])->assertForbidden();
    }

    private function createDistributor(): Distributor
    {
        return Distributor::query()->create([
            'name' => 'Distribuidora de teste',
            'state' => 'SP',
            'active' => true,
        ]);
    }

    private function fixture(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/aneel-tariffs.csv'),
            'aneel-tariffs.csv',
            'text/csv',
            null,
            true,
        );
    }
}
