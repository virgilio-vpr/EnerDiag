<?php

namespace Tests\Feature;

use App\Invoices\Pdf\PdfPageCounter;
use App\Jobs\ProcessSimulation;
use App\Models\Distributor;
use App\Models\InvoiceUploadBatch;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceUploadBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('invoice_temp');
        Queue::fake();
        $this->mock(PdfPageCounter::class)->shouldReceive('count')->andReturn(1);
    }

    public function test_sequential_upload_keeps_original_names_and_only_queues_when_complete(): void
    {
        $batch = $this->batch();
        foreach (['Fatura março (original).pdf', 'Fatura abril.pdf'] as $name) {
            $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file($name))
                ->assertCreated()->assertJsonPath('data.name', $name)->assertJsonMissingPath('data.path');
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('simulations', 0);
        $this->assertCount(2, Storage::disk('invoice_temp')->files('upload-'.$batch));
        $response = $this->postJson('/api/v1/invoice-upload-batches/'.$batch.'/complete')->assertAccepted();
        $id = $response->json('data.id');
        $simulation = Simulation::query()->findOrFail($id);
        $this->assertSame(['Fatura março (original).pdf', 'Fatura abril.pdf'], array_column($simulation->processing_progress['files'], 'file'));
        $this->assertCount(2, Storage::disk('invoice_temp')->files('simulation-'.$id));
        $this->assertSame([], Storage::disk('invoice_temp')->files('upload-'.$batch));
        $this->postJson('/api/v1/invoice-upload-batches/'.$batch.'/complete')->assertAccepted()->assertJsonPath('data.id', $id);
        Queue::assertPushed(ProcessSimulation::class, 1);
        $this->assertDatabaseCount('simulations', 1);
        $this->getJson('/api/v1/simulations/'.$id.'/status')->assertOk()
            ->assertJsonPath('data.processing_progress.files.0.file', 'Fatura março (original).pdf')
            ->assertJsonMissingPath('data.processing_progress.files.0.path');
    }

    public function test_retrying_the_same_file_does_not_duplicate_it(): void
    {
        $batch = $this->batch();
        $input = $this->file('same.pdf');
        $url = '/api/v1/invoice-upload-batches/'.$batch.'/files';
        $this->post($url, $input)->assertCreated();
        $this->post($url, $input)->assertCreated();
        $this->assertCount(1, InvoiceUploadBatch::findOrFail($batch)->files);
        $input['invoice'] = UploadedFile::fake()->createWithContent('other.pdf', '%PDF-1.4 different');
        $this->post($url, $input)->assertUnprocessable();
        $this->postJson('/api/v1/invoice-upload-batches/'.$batch.'/complete')->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_invalid_pdf_and_invalid_file_identifier_are_rejected_without_storing_files(): void
    {
        $batch = $this->batch();
        $url = '/api/v1/invoice-upload-batches/'.$batch.'/files';
        $input = $this->file('fake.pdf');
        $input['invoice'] = UploadedFile::fake()->createWithContent('fake.pdf', 'MZ executable');
        $this->post($url, $input)->assertStatus(415);
        $this->post($url, $this->file('valid.pdf') + ['extra' => 'ignored'])->assertCreated();
        $this->post($url, ['invoice' => $this->file('valid.pdf')['invoice'], 'client_id' => '../escape'])->assertUnprocessable();
        $this->assertCount(1, Storage::disk('invoice_temp')->allFiles());
    }

    public function test_aggregate_page_limit_and_file_count_cannot_be_bypassed_by_sequential_uploads(): void
    {
        $batch = $this->batch();
        for ($index = 0; $index < 12; $index++) {
            $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file("invoice-{$index}.pdf"))->assertCreated();
        }
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('thirteenth.pdf'))->assertUnprocessable();
        $this->assertCount(12, InvoiceUploadBatch::findOrFail($batch)->files);

        $this->mock(PdfPageCounter::class)->shouldReceive('count')->andReturn(301);
        $batch = $this->batch();
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('one.pdf'))->assertCreated();
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('two.pdf'))->assertStatus(413);
        $this->assertCount(1, InvoiceUploadBatch::findOrFail($batch)->files);
    }

    public function test_sequential_uploads_cannot_bypass_individual_or_aggregate_byte_limits(): void
    {
        $batch = $this->batch();
        $url = '/api/v1/invoice-upload-batches/'.$batch.'/files';
        $input = $this->file('large.pdf');
        $input['invoice']->size(20 * 1024 + 1);
        $this->post($url, $input)->assertStatus(413);
        for ($index = 0; $index < 6; $index++) {
            $input = $this->file("invoice-{$index}.pdf");
            $input['invoice']->size(20 * 1024);
            $this->post($url, $input)->assertCreated();
        }
        $this->post($url, $this->file('excess.pdf'))->assertStatus(413);
        $this->assertCount(6, InvoiceUploadBatch::findOrFail($batch)->files);
    }

    public function test_other_users_cannot_upload_finalize_or_discard_a_batch(): void
    {
        $batch = $this->batch();
        $this->app['auth']->forgetGuards();
        $this->withToken(User::factory()->create()->createToken('other')->plainTextToken);
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('one.pdf'))->assertForbidden();
        $this->postJson('/api/v1/invoice-upload-batches/'.$batch.'/complete')->assertForbidden();
        $this->deleteJson('/api/v1/invoice-upload-batches/'.$batch)->assertForbidden();
        $this->assertDatabaseCount('invoice_upload_batches', 1);
    }

    public function test_endpoints_require_authentication_and_creation_requires_valid_company_data(): void
    {
        $this->postJson('/api/v1/invoice-upload-batches', [])->assertUnauthorized();
        $id = (string) Str::uuid();
        $this->postJson("/api/v1/invoice-upload-batches/{$id}/files", [])->assertUnauthorized();
        $this->postJson("/api/v1/invoice-upload-batches/{$id}/complete")->assertUnauthorized();
        $this->deleteJson("/api/v1/invoice-upload-batches/{$id}")->assertUnauthorized();
        $this->withToken(User::factory()->create()->createToken('owner')->plainTextToken)
            ->postJson('/api/v1/invoice-upload-batches', [])->assertUnprocessable();
    }

    public function test_discard_and_scheduled_expiration_remove_the_private_temporary_files(): void
    {
        $batch = $this->batch();
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('one.pdf'))->assertCreated();
        $this->deleteJson('/api/v1/invoice-upload-batches/'.$batch)->assertOk();
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles());
        $batch = $this->batch();
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('one.pdf'))->assertCreated();
        $this->travel(61)->minutes();
        $this->post('/api/v1/invoice-upload-batches/'.$batch.'/files', $this->file('two.pdf'))->assertStatus(410);
        $this->artisan('invoices:clean-temporary-files')->assertSuccessful();
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles());
        $this->assertDatabaseCount('invoice_upload_batches', 0);
    }

    private function batch(): string
    {
        $user = User::factory()->create();
        $distributor = Distributor::query()->firstOrCreate(['parser_key' => 'enel'], ['name' => 'ENEL SP', 'state' => 'SP', 'active' => true]);

        return $this->withHeader('Accept', 'application/json')->withToken($user->createToken('owner')->plainTextToken)
            ->postJson('/api/v1/invoice-upload-batches', ['company_name' => 'Empresa', 'distributor_id' => $distributor->id,
                'address_details' => 'Rua de teste, 10', 'substation_same_address' => true])->assertCreated()->json('data.id');
    }

    private function file(string $name): array
    {
        return ['invoice' => UploadedFile::fake()->createWithContent($name, '%PDF-1.4 synthetic'), 'client_id' => (string) Str::uuid()];
    }
}
