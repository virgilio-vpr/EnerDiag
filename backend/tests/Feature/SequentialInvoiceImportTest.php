<?php

namespace Tests\Feature;

use App\Invoices\Pdf\PdfPageCounter;
use App\Invoices\Pdf\PdfTextParser;
use App\Jobs\ProcessSimulation;
use App\Mail\SimulationCompleted;
use App\Models\Distributor;
use App\Models\Simulation;
use App\Models\User;
use Database\Seeders\RegulatoryParameterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SequentialInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->seed(RegulatoryParameterSeeder::class);
        Storage::fake('invoice_temp');
        $this->mock(PdfPageCounter::class)->shouldReceive('count')->andReturn(2);
    }

    public function test_it_saves_invoices_in_date_order_then_calculates_immediately_without_a_queue_worker(): void
    {
        $id = $this->startImport();
        $august = file_get_contents(base_path('tests/Fixtures/enel-discounted-text-blue.txt'));
        $march = str_replace('08/2025', '03/2026', file_get_contents(base_path('tests/Fixtures/enel-reference-blue.txt')));
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->twice()->andReturn($august, $march);
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('august.pdf')])
            ->assertCreated()->assertJsonPath('data.invoice_count', 1)
            ->assertJsonPath('data.invoices.0.source_filename', 'august.pdf')
            ->assertJsonPath('data.invoices.0.warnings.0', 'free_market_invoice');
        $response = $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('march.pdf')]);
        $response->assertCreated()->assertJsonPath('data.invoice_count', 2)
            ->assertJsonPath('data.invoices.0.reference_month', '2026-03')
            ->assertJsonPath('data.invoices.1.reference_month', '2026-08')
            ->assertJsonPath('data.invoices.1.total_paid', '42446.32');
        $idle = collect($response->json('data.invoices.1.items'))->first(fn ($item) => $item['item_type'] === 'idle_demand' && $item['time_slot'] === 'off_peak');
        $this->assertSame('67.920', $idle['quantity']);
        $this->assertSame('10.05771', $idle['unit_price_taxed']);
        $this->assertSame('9.52000', $idle['tariff_rate']);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('reports', 0);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles());
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        Queue::assertNotPushed(ProcessSimulation::class);
        Mail::assertQueued(SimulationCompleted::class, 1);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_it_rejects_duplicate_files_and_duplicate_months_without_saving_extra_rows(): void
    {
        $id = $this->startImport();
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->twice()->andReturn(file_get_contents(base_path('tests/Fixtures/enel-reference-blue.txt')));
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('first.pdf')])->assertCreated();
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('first.pdf')])->assertUnprocessable();
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('different-copy.pdf')])->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 1);
        Queue::assertNothingPushed();
    }

    public function test_it_preserves_incomplete_reading_for_review_but_blocks_calculation(): void
    {
        $id = $this->startImport();
        $text = file_get_contents(base_path('tests/Fixtures/enel-discounted-text-blue.txt'));
        $text = preg_replace('/^ENCARGO ESCASSEZ.*\n/mu', '', $text);
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->once()->andReturn($text);
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('partial.pdf')])->assertCreated();
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertUnprocessable()->assertJsonPath('code', 'INVOICE_REVIEW_REQUIRED');
        $this->assertDatabaseHas('simulations', ['id' => $id, 'status' => 'draft']);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_a_validation_message_for_unrecognized_text_instead_of_a_server_error(): void
    {
        $id = $this->startImport();
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->once()->andReturn('Unknown invoice text');
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('unknown.pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_it_does_not_allow_another_customer_to_access_an_import(): void
    {
        $id = $this->startImport();
        $this->actingAs(User::factory()->create());
        $this->getJson("/api/v1/invoice-imports/{$id}")->assertNotFound();
        $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('other.pdf')])->assertNotFound();
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertNotFound();
        $this->deleteJson("/api/v1/invoice-imports/{$id}/invoices/1")->assertNotFound();
    }

    public function test_a_customer_can_remove_a_draft_invoice_and_its_items_but_cannot_modify_a_finalized_import(): void
    {
        $id = $this->startImport();
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->once()->andReturn(file_get_contents(base_path('tests/Fixtures/enel-reference-blue.txt')));
        $invoiceId = $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf('review.pdf')])->assertCreated()->json('data.invoices.0.id');
        $this->deleteJson("/api/v1/invoice-imports/{$id}/invoices/{$invoiceId}")->assertOk()->assertJsonPath('data.invoice_count', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        Simulation::query()->findOrFail($id)->update(['status' => 'queued']);
        $this->deleteJson("/api/v1/invoice-imports/{$id}/invoices/{$invoiceId}")->assertStatus(409);
    }

    public function test_it_requires_authentication_and_at_least_one_saved_invoice(): void
    {
        $this->postJson('/api/v1/invoice-imports', [])->assertUnauthorized();
        $id = $this->startImport();
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_a_complete_twelve_invoice_import_is_not_throttled_halfway_through(): void
    {
        $id = $this->startImport();
        $fixture = file_get_contents(base_path('tests/Fixtures/enel-reference-blue.txt'));
        $texts = [];
        for ($month = 1; $month <= 12; $month++) {
            $texts[] = str_replace('08/2025', sprintf('%02d/2026', $month), $fixture);
        }
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->times(12)->andReturn(...$texts);
        for ($month = 1; $month <= 12; $month++) {
            $this->postJson("/api/v1/invoice-imports/{$id}/invoices", ['invoice' => $this->pdf("month-{$month}.pdf")])
                ->assertCreated()->assertJsonPath('data.invoice_count', $month);
        }
        $this->postJson("/api/v1/invoice-imports/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        Queue::assertNotPushed(ProcessSimulation::class);
        Mail::assertQueued(SimulationCompleted::class, 1);
    }

    private function startImport(): int
    {
        $this->actingAs(User::factory()->create());
        $distributor = Distributor::query()->create(['name' => 'ENEL SP', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);

        return $this->postJson('/api/v1/invoice-imports', [
            'company_name' => 'Empresa Exemplo', 'distributor_id' => $distributor->id,
            'address_details' => 'Rua Exemplo, 100', 'substation_same_address' => true,
        ])->assertCreated()->json('data.id');
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 '.$name);
    }
}
