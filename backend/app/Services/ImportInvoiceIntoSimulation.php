<?php

namespace App\Services;

use App\Invoices\Pdf\PdfPageCounter;
use App\Invoices\Pdf\TemporaryPdfTextExtractor;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Invoices\Validation\InvoiceExtractionValidator;
use App\Invoices\Validation\InvoiceUploadValidator;
use App\Models\Simulation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ImportInvoiceIntoSimulation
{
    public function __construct(
        private readonly InvoiceReaderResolver $readerResolver,
        private readonly TemporaryPdfTextExtractor $textExtractor,
        private readonly InvoiceUploadValidator $uploadValidator,
        private readonly InvoiceExtractionValidator $extractionValidator,
        private readonly PdfPageCounter $pageCounter,
    ) {}

    /** @return array{reference_month: string, invoice_count: int, invoices: list<array<string, mixed>>} */
    public function handle(Simulation $simulation, UploadedFile $file): array
    {
        if ($simulation->status !== 'draft') {
            throw new HttpException(409, 'Este envio já foi finalizado.');
        }
        if ($simulation->invoices()->count() >= InvoiceUploadValidator::MAX_FILES) {
            throw new HttpException(409, 'O limite de 12 faturas já foi atingido.');
        }

        $this->uploadValidator->validate([$file]);
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['invoice' => 'Não foi possível ler o PDF enviado.']);
        }

        $hash = hash('sha256', $contents);
        if ($simulation->invoices()->where('source_sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Esta fatura já foi enviada para este período.']);
        }

        $profile = $simulation->user?->profile;
        $distributor = $profile?->distributor;
        if ($distributor === null) {
            throw new HttpException(409, 'A distribuidora desta conta não está disponível.');
        }

        try {
            $extracted = $this->readerResolver->resolve($distributor)->read($this->textExtractor->extract($contents));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }
        $this->extractionValidator->validate([$extracted]);

        if ($simulation->invoices()->where('reference_month', $extracted->referenceMonth)->exists()) {
            throw ValidationException::withMessages([
                'invoice' => "Já existe uma fatura de {$extracted->referenceMonth} nesta unidade. Envie outro mês.",
            ]);
        }
        if ($simulation->invoices()->where('consumer_unit', '!=', $extracted->consumerUnit)->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'Esta fatura pertence a uma unidade consumidora diferente das anteriores.',
            ]);
        }

        $pageCount = $this->pageCounter->count($file->getRealPath());
        $fileBytes = $file->getSize();
        if (! is_int($fileBytes)) {
            throw ValidationException::withMessages(['invoice' => 'Não foi possível identificar o tamanho do PDF enviado.']);
        }
        $existingPages = (int) $simulation->invoices()->sum('page_count');
        if ($existingPages + $pageCount > InvoiceUploadValidator::MAX_PAGES) {
            throw new HttpException(413, 'O conjunto das faturas pode ter no máximo 600 páginas.');
        }

        DB::transaction(function () use ($simulation, $extracted, $hash, $pageCount, $fileBytes, $file): void {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => 'Este envio já foi finalizado.']);
            }
            if ($locked->invoices()->count() >= InvoiceUploadValidator::MAX_FILES) {
                throw ValidationException::withMessages(['invoice' => 'O limite de 12 faturas já foi atingido.']);
            }
            if ($locked->invoices()->where('source_sha256', $hash)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura já foi enviada para este período.']);
            }
            if ($locked->invoices()->where('reference_month', $extracted->referenceMonth)->exists()) {
                throw ValidationException::withMessages(['invoice' => "Já existe uma fatura de {$extracted->referenceMonth} nesta unidade. Envie outro mês."]);
            }
            if ($locked->invoices()->where('consumer_unit', '!=', $extracted->consumerUnit)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura pertence a uma unidade consumidora diferente das anteriores.']);
            }

            if ((int) $locked->invoices()->sum('page_count') + $pageCount > InvoiceUploadValidator::MAX_PAGES) {
                throw new HttpException(413, 'O conjunto das faturas pode ter no máximo 600 páginas.');
            }
            if ((int) $locked->invoices()->sum('source_bytes') + $fileBytes > InvoiceUploadValidator::MAX_TOTAL_BYTES) {
                throw new HttpException(413, 'O conjunto das faturas pode ter no máximo 120 MB.');
            }

            $invoice = $locked->invoices()->create([
                'source_sha256' => $hash,
                'source_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'extraction_warnings' => $extracted->warnings,
                'source_bytes' => $fileBytes,
                'page_count' => $pageCount,
                'reference_month' => $extracted->referenceMonth,
                'consumer_unit' => $extracted->consumerUnit,
                'subgroup' => $extracted->subgroup,
                'tariff_modality' => $extracted->tariffModality,
                'total_paid' => $extracted->totalPaid,
            ]);
            foreach ($extracted->items as $item) {
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
        });

        return $this->summary($simulation->fresh());
    }

    /** @return array{reference_month: string, invoice_count: int, invoices: list<array<string, mixed>>} */
    public function summary(Simulation $simulation): array
    {
        $invoices = $simulation->invoices()->with('items')->orderBy('reference_month')->get();

        return [
            'reference_month' => (string) $invoices->last()?->reference_month,
            'invoice_count' => $invoices->count(),
            'invoices' => $invoices->map(fn ($invoice): array => [
                'id' => $invoice->id,
                'reference_month' => $invoice->reference_month,
                'consumer_unit' => $invoice->consumer_unit,
                'source_filename' => $invoice->source_filename,
                'total_paid' => $invoice->total_paid,
                'warnings' => $invoice->extraction_warnings ?? [],
                'items' => $invoice->items->map(fn ($item): array => $item->only([
                    'id', 'item_type', 'tariff_component', 'time_slot', 'source_label',
                    'quantity', 'unit', 'unit_price_taxed', 'tariff_rate', 'amount',
                    'pis_cofins_amount', 'icms_base', 'icms_rate', 'icms_amount',
                ]))->all(),
            ])->all(),
        ];
    }
}
