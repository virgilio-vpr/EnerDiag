<?php

namespace Tests\Feature;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Invoices\Pdf\PdfTextParser;
use App\Invoices\Pdf\TemporaryPdfTextExtractor;
use App\Invoices\Readers\EnelInvoiceReader;
use App\Invoices\Readers\InvoiceReader;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Invoices\Validation\InvoiceExtractionValidator;
use App\Invoices\Validation\InvoiceProcessingFailureRecorder;
use App\Jobs\ProcessSimulation;
use App\Mail\SimulationCompleted;
use App\Models\CustomerProfile;
use App\Models\Distributor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Report;
use App\Models\Simulation;
use App\Models\User;
use Database\Seeders\RegulatoryParameterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProcessSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegulatoryParameterSeeder::class);
        Storage::fake('invoice_temp');
    }

    public function test_job_persists_invoices_creates_report_and_is_idempotent(): void
    {
        Mail::fake();
        [$simulation] = $this->simulationWithFiles();
        $fixture = file_get_contents(base_path('tests/Fixtures/enel-reference-blue.txt'));
        $this->app->instance(InvoiceReaderResolver::class, new InvoiceReaderResolver([new EnelInvoiceReader]));
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->andReturn($fixture);

        $job = new ProcessSimulation($simulation->id);
        $job->handle(
            app(InvoiceReaderResolver::class),
            app(TemporaryPdfTextExtractor::class),
            app(InvoiceExtractionValidator::class),
            app(InvoiceProcessingFailureRecorder::class),
            app(ReportCalculationEngine::class),
        );
        $job->handle(
            app(InvoiceReaderResolver::class),
            app(TemporaryPdfTextExtractor::class),
            app(InvoiceExtractionValidator::class),
            app(InvoiceProcessingFailureRecorder::class),
            app(ReportCalculationEngine::class),
        );

        $this->assertDatabaseHas('simulations', ['id' => $simulation->id, 'status' => 'completed', 'months_count' => 1]);
        $this->assertSame(1, Invoice::query()->where('simulation_id', $simulation->id)->count());
        $this->assertGreaterThan(0, InvoiceItem::query()->whereHas('invoice', fn ($query) => $query->where('simulation_id', $simulation->id))->count());
        $report = Report::query()->where('simulation_id', $simulation->id)->firstOrFail();
        $this->assertSame('1.0', $report->algorithm_version);
        $this->assertContains('demand_invoice_reconciliation_mismatch', $report->warnings);
        $this->assertSame('0.820000', $report->r_values[0]['ratio']);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));
        Mail::assertSent(SimulationCompleted::class, fn (SimulationCompleted $mail): bool => $mail->report->simulation_id === $simulation->id);
    }

    public function test_final_read_failure_records_file_error_and_removes_temporary_pdf(): void
    {
        [$simulation] = $this->simulationWithFiles();
        $this->bindReader(ExtractedInvoiceData::fromArray($this->invoiceArray()));
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->andThrow(new RuntimeException('parser details must not leak'));

        $job = new ProcessSimulation($simulation->id);
        try {
            $job->handle(
                app(InvoiceReaderResolver::class),
                app(TemporaryPdfTextExtractor::class),
                app(InvoiceExtractionValidator::class),
                app(InvoiceProcessingFailureRecorder::class),
                app(ReportCalculationEngine::class),
            );
            $this->fail('The parser failure should be retried by the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('parser details must not leak', $exception->getMessage());
        }

        $job->failed(new RuntimeException('parser details must not leak'));
        $simulation->refresh();
        $this->assertSame('failed', $simulation->status);
        $this->assertSame('INVOICE_READ_FAILED', $simulation->processing_errors[0]['code']);
        $this->assertStringContainsString('invoice-1.pdf', $simulation->processing_errors[0]['file']);
        $this->assertStringNotContainsString('parser details', json_encode($simulation->processing_errors));
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));

        $token = $simulation->user->createToken('status-check')->plainTextToken;
        $this->getJson('/api/v1/simulations/'.$simulation->id.'/status')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/simulations/'.$simulation->id.'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.processing_errors.0.code', 'INVOICE_READ_FAILED')
            ->assertJsonPath('data.processing_errors.0.file', $simulation->processing_errors[0]['file']);

        $other = User::factory()->create();
        $otherToken = $other->createToken('status-check')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $otherResponse = $this->getJson('/api/v1/simulations/'.$simulation->id.'/status', ['Authorization' => 'Bearer '.$otherToken]);
        $this->assertSame(404, $otherResponse->status(), $otherResponse->getContent());
    }

    public function test_extraction_validation_failure_is_associated_with_the_offending_file(): void
    {
        [$simulation] = $this->simulationWithFiles();
        Storage::disk('invoice_temp')->put('simulation-'.$simulation->id.'/invoice-2.pdf', '%PDF-1.4 fixture');
        $this->bindReader(ExtractedInvoiceData::fromArray($this->invoiceArray()));
        $this->mock(PdfTextParser::class)->shouldReceive('extract')->twice()->andReturn('fixture invoice');

        $job = new ProcessSimulation($simulation->id);
        $job->handle(
            app(InvoiceReaderResolver::class),
            app(TemporaryPdfTextExtractor::class),
            app(InvoiceExtractionValidator::class),
            app(InvoiceProcessingFailureRecorder::class),
            app(ReportCalculationEngine::class),
        );

        $simulation->refresh();
        $this->assertSame('failed', $simulation->status);
        $this->assertStringContainsString('invoice-2.pdf', $simulation->processing_errors[0]['file']);
        $this->assertSame('INVOICE_EXTRACTION_INVALID', $simulation->processing_errors[0]['code']);
        $this->assertArrayHasKey('reference_month', $simulation->processing_errors[0]['errors']);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_terminal_timeout_failure_records_failure_and_cleans_temporary_files(): void
    {
        [$simulation] = $this->simulationWithFiles();
        $simulation->forceFill(['status' => 'processing'])->save();

        (new ProcessSimulation($simulation->id))->failed(new RuntimeException('timeout'));

        $this->assertDatabaseHas('simulations', ['id' => $simulation->id, 'status' => 'failed']);
        $this->assertSame([], Storage::disk('invoice_temp')->allFiles('simulation-'.$simulation->id));
    }

    private function simulationWithFiles(): array
    {
        $user = User::factory()->create();
        $distributor = Distributor::query()->create(['name' => 'ENEL SP', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);
        CustomerProfile::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Empresa Exemplo Ltda.',
            'distributor_id' => $distributor->id,
            'address_details' => 'Rua Exemplo, 100',
            'substation_same_address' => true,
        ]);
        $simulation = Simulation::query()->create(['user_id' => $user->id, 'status' => 'queued', 'months_count' => 0]);
        Storage::disk('invoice_temp')->put('simulation-'.$simulation->id.'/invoice-1.pdf', '%PDF-1.4 fixture');

        return [$simulation];
    }

    private function bindReader(ExtractedInvoiceData $invoiceData): void
    {
        $reader = new class($invoiceData) implements InvoiceReader
        {
            public function __construct(private ExtractedInvoiceData $invoiceData) {}

            public function parserKey(): string
            {
                return 'enel';
            }

            public function recognizesIdleDemandLine(): bool
            {
                return true;
            }

            public function read(string $text): ExtractedInvoiceData
            {
                return $this->invoiceData;
            }
        };

        $this->app->instance(InvoiceReaderResolver::class, new InvoiceReaderResolver([$reader]));
    }

    private function invoiceArray(): array
    {
        return [
            'reference_month' => '2025-01',
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '100.00',
            'items' => [],
        ];
    }
}
