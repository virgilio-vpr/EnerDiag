<?php

namespace App\Jobs;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Invoices\Pdf\TemporaryPdfTextExtractor;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Invoices\Validation\InvoiceExtractionValidator;
use App\Invoices\Validation\InvoiceProcessingFailureRecorder;
use App\Mail\SimulationCompleted;
use App\Models\Simulation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessSimulation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 180];

    private ?string $activeFile = null;

    private string $activeStage = 'reading';

    public function __construct(public readonly int $simulationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('simulation-'.$this->simulationId))->expireAfter(360)];
    }

    public function handle(
        InvoiceReaderResolver $readerResolver,
        TemporaryPdfTextExtractor $textExtractor,
        InvoiceExtractionValidator $validator,
        InvoiceProcessingFailureRecorder $failureRecorder,
        ReportCalculationEngine $calculationEngine,
    ): void {
        $simulation = Simulation::query()->with('user.profile.distributor')->findOrFail($this->simulationId);
        $directory = 'simulation-'.$simulation->id;

        if ($simulation->status === 'completed') {
            Storage::disk(TemporaryPdfTextExtractor::DISK)->deleteDirectory($directory);

            return;
        }

        $this->activeStage = 'setup';
        $simulation->forceFill(['status' => 'processing', 'processing_errors' => null])->save();
        $profile = $simulation->user?->profile;
        $distributor = $profile?->distributor;
        if ($distributor === null) {
            throw new \RuntimeException('A simulação não possui distribuidora associada.');
        }

        $reader = $readerResolver->resolve($distributor);
        $disk = Storage::disk(TemporaryPdfTextExtractor::DISK);
        $paths = $disk->files($directory);
        sort($paths);
        if ($paths === []) {
            throw new \RuntimeException('Nenhum arquivo temporário foi encontrado para a simulação.');
        }

        $previousFiles = $simulation->processing_progress['files'] ?? [];
        $originalNames = array_column($previousFiles, 'file', 'path');
        $progress = array_map(fn (string $path): array => [
            'file' => $originalNames[basename($path)] ?? basename($path),
            'path' => basename($path),
            'status' => 'pending',
        ], $paths);
        $invoices = [];
        $fileNames = [];
        $readErrors = [];
        $firstFailure = null;
        foreach ($paths as $index => $path) {
            $this->activeStage = 'reading';
            $this->activeFile = $progress[$index]['file'];
            $fileNames[] = $this->activeFile;
            $progress[$index]['status'] = 'reading';
            $simulation->update(['processing_progress' => ['stage' => 'reading', 'files' => $progress]]);
            try {
                $contents = $disk->get($path);
                $invoices[] = $reader->read($textExtractor->extract($contents));
                $progress[$index]['status'] = 'read';
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
                $progress[$index]['status'] = 'failed';
                $readErrors[] = [
                    'file' => $this->activeFile, 'code' => 'INVOICE_READ_FAILED',
                    'errors' => ['message' => 'Não foi possível ler este arquivo. Envie outra cópia da fatura.'],
                ];
            }
            $simulation->update(['processing_progress' => ['stage' => 'reading', 'files' => $progress]]);
        }
        if ($firstFailure !== null) {
            $simulation->update([
                'processing_errors' => $readErrors,
                'processing_progress' => ['stage' => 'retrying', 'files' => $progress],
            ]);
            throw $firstFailure;
        }

        try {
            $this->activeStage = 'validating';
            $simulation->update(['processing_progress' => ['stage' => 'validating', 'files' => $progress]]);
            $validator->validate($invoices);
        } catch (ValidationException $exception) {
            $failureRecorder->recordValidationErrors($simulation, $fileNames, $exception->errors());
            foreach (array_keys($exception->errors()) as $field) {
                if (preg_match('/^invoices\\.(\\d+)\\./', $field, $match) === 1 && isset($progress[(int) $match[1]])) {
                    $progress[(int) $match[1]]['status'] = 'failed';
                }
            }
            $simulation->update(['processing_progress' => ['stage' => 'failed', 'files' => $progress]]);
            $disk->deleteDirectory($directory);

            return;
        }

        $this->activeStage = 'persisting';
        $simulation->update(['processing_progress' => ['stage' => 'calculating', 'files' => $progress]]);
        DB::transaction(function () use ($simulation, $invoices, $calculationEngine, $progress): void {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            $locked->invoices()->delete();
            $locked->report()->delete();

            foreach ($invoices as $data) {
                $invoice = $locked->invoices()->create([
                    'reference_month' => $data->referenceMonth,
                    'consumer_unit' => $data->consumerUnit,
                    'subgroup' => $data->subgroup,
                    'tariff_modality' => $data->tariffModality,
                    'total_paid' => $data->totalPaid,
                ]);
                foreach ($data->items as $item) {
                    $invoice->items()->create([
                        'item_type' => $item->itemType,
                        'tariff_component' => $item->tariffComponent,
                        'time_slot' => $item->timeSlot,
                        'reactive_nature' => $item->reactiveNature,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price_taxed' => $item->unitPriceTaxed,
                        'tariff_rate' => $item->tariffRate,
                        'amount' => $item->amount,
                        'pis_cofins_amount' => $item->pisCofinsAmount,
                        'icms_base' => $item->icmsBase,
                        'icms_rate' => $item->icmsRate,
                        'icms_amount' => $item->icmsAmount,
                        'source_label' => $item->sourceLabel,
                    ]);
                }
            }

            $locked->load('invoices.items');
            $this->activeStage = 'calculating';
            $calculated = $calculationEngine->calculate($locked);
            $attributes = $calculated->calculatedAttributes + [
                'algorithm_version' => $calculated->algorithmVersion,
                'tariff_version_ids' => $calculated->tariffVersionIds,
                'regulatory_parameter_version_ids' => $calculated->regulatoryParameterVersionIds,
                'explanatory_texts' => [
                    'percentage' => '% do valor médio pago no período',
                    'percentage_observation' => 'A economia usa tarifas atuais e o valor pago usa as tarifas históricas.',
                ],
                'recommendations' => [
                    'O potencial de economia por demanda não foi calculado porque os limites da demanda ótima seguem pendentes na seção 9.',
                    'A migração de modalidade não foi calculada porque as regras correspondentes seguem pendentes na seção 9.',
                ],
            ];
            $locked->report()->create($attributes);
            $locked->forceFill([
                'status' => 'completed',
                'processing_progress' => ['stage' => 'completed', 'files' => $progress],
                'processing_errors' => null,
                'months_count' => count($invoices),
                'total_monthly_savings' => $attributes['total_monthly_savings'] ?? '0.00',
                'total_yearly_savings' => $attributes['total_yearly_savings'] ?? '0.00',
            ])->save();
        });

        $completed = Simulation::query()->with(['user', 'report'])->findOrFail($simulation->id);
        if ($completed->user !== null && $completed->report !== null) {
            Mail::to($completed->user->email)->send(new SimulationCompleted($completed->report));
        }

        $disk->deleteDirectory($directory);
    }

    public function failed(Throwable $exception): void
    {
        $simulation = Simulation::query()->find($this->simulationId);
        if ($simulation !== null) {
            $progress = $simulation->processing_progress;
            $stage = $progress['stage'] ?? $this->activeStage;
            $readFailure = in_array($stage, ['reading', 'retrying'], true);
            $errors = $readFailure ? ($simulation->processing_errors ?? []) : [];
            if ($errors === []) {
                $active = null;
                foreach ($progress['files'] ?? [] as $entry) {
                    if ($entry['status'] === 'reading') {
                        $active = $entry['file'];
                        break;
                    }
                }
                $errors = [[
                    'file' => $readFailure ? ($active ?? $this->activeFile ?? 'Fatura não identificada') : 'simulação',
                    'code' => $readFailure ? 'INVOICE_READ_FAILED' : 'SIMULATION_PROCESSING_FAILED',
                    'errors' => ['message' => $readFailure
                        ? 'Não foi possível ler este arquivo. Envie outra cópia da fatura.'
                        : 'Não foi possível concluir o processamento. Tente enviar novamente.'],
                ]];
            }
            app(InvoiceProcessingFailureRecorder::class)->record($simulation, $errors);
            if ($progress !== null) {
                foreach ($progress['files'] as &$entry) {
                    if ($entry['status'] === 'reading') {
                        $entry['status'] = 'failed';
                    }
                }
                unset($entry);
                $progress['stage'] = 'failed';
                $simulation->update(['processing_progress' => $progress]);
            }
        }

        Storage::disk(TemporaryPdfTextExtractor::DISK)->deleteDirectory('simulation-'.$this->simulationId);
    }
}
