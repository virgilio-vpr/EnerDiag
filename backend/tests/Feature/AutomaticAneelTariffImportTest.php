<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\Tariff;
use App\Models\TariffImport;
use App\Models\User;
use App\Services\AutomaticAneelTariffImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomaticAneelTariffImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 25)->startOfDay());
        Distributor::query()->create(['name' => 'Distribuidora de teste', 'state' => 'SP', 'active' => true]);
        Http::preventStrayRequests();
    }

    public function test_scheduled_fetch_stages_tariffs_and_repeated_fetch_does_not_duplicate_them(): void
    {
        $this->source();
        $this->artisan('tariffs:fetch-aneel')->assertExitCode(0);
        $import = TariffImport::query()->sole();
        $this->assertSame('pending', $import->status);
        $this->assertNull($import->imported_by);
        $this->assertCount(3, $import->pending_tariffs);
        $this->assertSame(0, $import->rows_accepted);
        $this->assertDatabaseCount('tariffs', 0);
        $this->artisan('tariffs:fetch-aneel')->assertExitCode(0);
        $this->assertDatabaseCount('tariff_imports', 1);
        $this->assertSame([], glob(storage_path('app/private/aneel-downloads/tariffs-*')));
    }

    public function test_review_and_explicit_approval_are_required_before_activation(): void
    {
        $this->source();
        $import = app(AutomaticAneelTariffImport::class)->fetch();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withToken($admin->createToken('admin')->plainTextToken);
        $this->getJson('/api/v1/admin/tariff-imports')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.pending_tariffs');
        $this->getJson('/api/v1/admin/tariff-imports/'.$import->id)->assertOk()->assertJsonCount(3, 'data.pending_tariffs');
        $this->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve')->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('tariffs', 0);
        $this->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('tariffs', 3);
        $this->assertSame($admin->id, $import->fresh()->reviewed_by);
        $this->assertNotNull($import->fresh()->reviewed_at);
        $this->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])->assertStatus(409);
        $this->assertDatabaseCount('tariffs', 3);
    }

    public function test_review_and_approval_require_admin_and_authentication(): void
    {
        $this->source();
        $import = app(AutomaticAneelTariffImport::class)->fetch();
        $this->getJson('/api/v1/admin/tariff-imports')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tariff-imports/'.$import->id)->assertUnauthorized();
        $this->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])->assertUnauthorized();
        $this->withToken(User::factory()->create()->createToken('customer')->plainTextToken);
        $this->getJson('/api/v1/admin/tariff-imports')->assertForbidden();
        $this->getJson('/api/v1/admin/tariff-imports/'.$import->id)->assertForbidden();
        $this->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('tariffs', 0);
    }

    public function test_network_failure_keeps_existing_tariffs_and_creates_no_import(): void
    {
        $old = $this->existingTariff();
        Http::fake([config('aneel.csv_url') => Http::failedConnection()]);
        $this->artisan('tariffs:fetch-aneel')->assertExitCode(1);
        $this->assertSame('28.00000', $old->fresh()->rate);
        $this->assertNull($old->fresh()->valid_to);
        $this->assertDatabaseCount('tariffs', 1);
        $this->assertDatabaseCount('tariff_imports', 0);
        $this->assertSame([], glob(storage_path('app/private/aneel-downloads/tariffs-*')));
    }

    public function test_invalid_download_never_activates_tariffs(): void
    {
        Http::fake([config('aneel.csv_url') => Http::response('not;a;valid;csv', 200)]);
        $this->artisan('tariffs:fetch-aneel')->assertExitCode(1);
        $this->assertDatabaseCount('tariffs', 0);
        $this->assertDatabaseCount('tariff_imports', 0);
    }

    public function test_oversized_download_never_creates_an_import(): void
    {
        config(['aneel.max_download_bytes' => 10]);
        $this->source();
        $this->artisan('tariffs:fetch-aneel')->assertExitCode(1);
        $this->assertDatabaseCount('tariffs', 0);
        $this->assertDatabaseCount('tariff_imports', 0);
    }

    public function test_conflicting_approval_rolls_back_all_tariffs_and_previous_validity_changes(): void
    {
        $old = $this->existingTariff();
        $this->existingTariff(['component' => 'energy_te', 'rate' => '0.99999', 'valid_from' => '2026-10-01']);
        $this->source();
        $import = app(AutomaticAneelTariffImport::class)->fetch();
        $this->assertNull($old->fresh()->valid_to);
        $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken)
            ->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->assertDatabaseCount('tariffs', 2);
        $this->assertNull($old->fresh()->valid_to);
        $this->assertSame('pending', $import->fresh()->status);
    }

    public function test_approval_versions_old_tariffs_only_after_admin_confirmation(): void
    {
        $old = $this->existingTariff();
        $this->source();
        $import = app(AutomaticAneelTariffImport::class)->fetch();
        $this->assertNull($old->fresh()->valid_to);
        $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken)
            ->postJson('/api/v1/admin/tariff-imports/'.$import->id.'/approve', ['confirmed' => true])->assertOk();
        $this->assertSame('2026-09-30', $old->fresh()->valid_to->toDateString());
        $this->assertSame('28.00000', $old->fresh()->rate);
        $this->assertDatabaseHas('tariffs', ['rate' => '29.30000', 'includes_taxes' => false]);
    }

    public function test_official_modality_and_comma_decimal_are_supported_and_special_tariffs_ignored(): void
    {
        $csv = str_replace(['Horária Azul', '29.30000'], ['Azul', '29,30'], file_get_contents(base_path('tests/Fixtures/aneel-tariffs.csv')));
        $lines = explode("\n", trim($csv));
        $csv .= str_replace(';Não se aplica;Ponta;', ';APE;Ponta;', $lines[1])."\n";
        Http::fake([config('aneel.csv_url') => Http::response($csv, 200)]);
        $import = app(AutomaticAneelTariffImport::class)->fetch();
        $this->assertCount(3, $import->pending_tariffs);
        $this->assertSame(2, $import->rows_ignored);
        $this->assertContains('29.30000', array_column($import->pending_tariffs, 'rate'));
    }

    public function test_approval_routes_reject_unknown_or_malformed_identifiers(): void
    {
        $this->withToken(User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken);
        $this->getJson('/api/v1/admin/tariff-imports/999')->assertNotFound();
        $this->postJson('/api/v1/admin/tariff-imports/999/approve', ['confirmed' => true])->assertNotFound();
        $this->getJson('/api/v1/admin/tariff-imports/invalid')->assertNotFound();
    }

    private function source(): void
    {
        Http::fake(fn () => Http::response(file_get_contents(base_path('tests/Fixtures/aneel-tariffs.csv')), 200));
    }

    /** @param array<string, mixed> $overrides */
    private function existingTariff(array $overrides = []): Tariff
    {
        return Tariff::query()->create(array_replace([
            'distributor_id' => Distributor::query()->sole()->id, 'subgroup' => 'A4', 'tariff_modality' => 'blue',
            'time_slot' => 'peak', 'component' => 'demand', 'rate' => '28.00000', 'includes_taxes' => false,
            'source' => 'aneel_import', 'source_reference' => 'Previous', 'valid_from' => '2025-10-01', 'valid_to' => null,
        ], $overrides));
    }
}
