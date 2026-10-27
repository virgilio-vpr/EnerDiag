<?php

namespace Tests\Feature;

use App\Invoices\Pdf\PdfPageCounter;
use App\Jobs\ProcessSimulation;
use App\Models\Distributor;
use App\Models\Lead;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('invoice_temp');
        Queue::fake();
        $this->mock(PdfPageCounter::class, function ($mock): void {
            $mock->shouldReceive('count')->andReturn(1);
        });
    }

    public function test_simulation_submission_requires_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')->post('/api/v1/simulations', $this->validInput())
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertDatabaseCount('simulations', 0);
    }

    public function test_customer_can_submit_valid_company_details_and_six_pdf_invoices(): void
    {
        $user = User::factory()->create();
        $distributor = $this->supportedDistributor();
        $token = $user->createToken('simulation-upload')->plainTextToken;

        $response = $this->withHeader('Accept', 'application/json')->withToken($token)->post('/api/v1/simulations', $this->validInput($distributor->id));

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.id', 1);

        $simulation = Simulation::query()->firstOrFail();
        $this->assertSame($user->id, $simulation->user_id);
        $this->assertSame('queued', $simulation->status);
        $this->assertNotNull($simulation->expires_at);
        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $user->id,
            'company_name' => 'Empresa Exemplo Ltda.',
            'distributor_id' => $distributor->id,
            'address_details' => 'Rua Exemplo, 100, São Paulo/SP',
        ]);
        $this->assertCount(6, Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));
        Queue::assertPushed(ProcessSimulation::class, fn ($job): bool => $job->simulationId === $simulation->id);
    }

    public function test_customer_can_submit_two_invoices_for_temporary_manual_testing(): void
    {
        $user = User::factory()->create();
        $distributor = $this->supportedDistributor();
        $input = $this->validInput($distributor->id);
        $input['invoices'] = array_slice($input['invoices'], 0, 2);

        $this->withHeader('Accept', 'application/json')->withToken($user->createToken('simulation-upload')->plainTextToken)
            ->post('/api/v1/simulations', $input)->assertStatus(202)->assertJsonPath('data.status', 'queued');

        $simulation = Simulation::query()->sole();
        $this->assertCount(2, Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));
        Queue::assertPushed(ProcessSimulation::class, fn ($job): bool => $job->simulationId === $simulation->id);
    }

    public function test_invalid_company_data_is_rejected_before_simulation_or_file_storage(): void
    {
        $user = User::factory()->create();
        $distributor = $this->supportedDistributor();
        $input = $this->validInput($distributor->id);
        unset($input['address_details']);
        $token = $user->createToken('simulation-upload')->plainTextToken;

        $this->withHeader('Accept', 'application/json')->withToken($token)->post('/api/v1/simulations', $input)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('simulations', 0);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles());
    }

    public function test_invalid_invoice_count_is_rejected_before_simulation_creation(): void
    {
        $user = User::factory()->create();
        $distributor = $this->supportedDistributor();
        $input = $this->validInput($distributor->id);
        $input['invoices'] = array_slice($input['invoices'], 0, 1);
        $token = $user->createToken('simulation-upload')->plainTextToken;

        $this->withHeader('Accept', 'application/json')->withToken($token)->post('/api/v1/simulations', $input)
            ->assertUnprocessable()
            ->assertJsonPath('errors.invoices.0', 'Envie de 2 a 12 faturas em PDF.');

        $this->assertDatabaseCount('simulations', 0);
    }

    public function test_unsupported_distributor_returns_422_and_records_a_lead(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('simulation-upload')->plainTextToken;

        $this->withHeader('Accept', 'application/json')->withToken($token)->post('/api/v1/simulations', $this->validInput(null, 'Distribuidora Exemplo'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DISTRIBUTOR_UNSUPPORTED');

        $lead = Lead::query()->firstOrFail();
        $this->assertSame($user->email, $lead->email);
        $this->assertSame('Distribuidora Exemplo', $lead->distributor_name);
        $this->assertSame('unsupported_distributor', $lead->reason);
        $this->assertDatabaseCount('simulations', 0);
    }

    public function test_distributor_with_unregistered_parser_key_returns_422_and_records_a_lead(): void
    {
        $user = User::factory()->create();
        $distributor = Distributor::query()->create([
            'name' => 'Distribuidora sem leitor',
            'state' => 'SP',
            'parser_key' => 'unknown-reader',
            'active' => true,
        ]);
        $token = $user->createToken('simulation-upload')->plainTextToken;

        $this->withHeader('Accept', 'application/json')->withToken($token)->post('/api/v1/simulations', $this->validInput($distributor->id))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DISTRIBUTOR_UNSUPPORTED');

        $this->assertDatabaseHas('leads', [
            'email' => $user->email,
            'distributor_name' => $distributor->name,
            'reason' => 'unsupported_distributor',
        ]);
        $this->assertDatabaseCount('simulations', 0);
    }

    private function supportedDistributor(): Distributor
    {
        return Distributor::query()->create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(?int $distributorId = null, ?string $distributorName = null): array
    {
        $invoices = [];
        for ($index = 0; $index < 6; $index++) {
            $invoices[] = UploadedFile::fake()->createWithContent(
                "invoice-{$index}.pdf",
                "%PDF-1.4\nsynthetic PDF fixture",
            );
        }

        return [
            'company_name' => 'Empresa Exemplo Ltda.',
            'cnpj' => null,
            'distributor_id' => $distributorId,
            'distributor_name' => $distributorName,
            'transformer_power_kva' => '500.00',
            'address_details' => 'Rua Exemplo, 100, São Paulo/SP',
            'substation_same_address' => '1',
            'substation_address' => null,
            'invoices' => $invoices,
        ];
    }
}
