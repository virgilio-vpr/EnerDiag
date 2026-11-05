<?php

namespace App\Services;

use App\Invoices\Validation\InvoiceProcessingFailureRecorder;
use App\Invoices\Validation\InvoiceUploadValidator;
use App\Models\Simulation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RunSavedSimulation
{
    public function __construct(
        private readonly FinalizeImportedSimulation $finalizer,
        private readonly InvoiceProcessingFailureRecorder $failureRecorder,
    ) {}

    public function handle(Simulation $simulation): Simulation
    {
        $claimed = DB::transaction(function () use ($simulation): bool {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            if ($locked->status === 'completed') {
                return false;
            }
            if ($locked->status === 'processing' && $locked->updated_at->greaterThan(now()->subMinute())) {
                return false;
            }
            $invoices = $locked->invoices()->get(['reference_month', 'extraction_warnings']);
            if ($invoices->count() < InvoiceUploadValidator::MIN_FILES || $invoices->count() > InvoiceUploadValidator::MAX_FILES) {
                throw ValidationException::withMessages(['invoices' => 'Salve de 1 a 12 faturas antes de simular.']);
            }
            foreach ($invoices as $invoice) {
                if (in_array('invoice_billing_total_mismatch', $invoice->extraction_warnings ?? [], true)) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Confira os itens da fatura de '.$invoice->reference_month.': a soma dos valores lidos difere do total do documento.',
                        'code' => 'INVOICE_REVIEW_REQUIRED', 'errors' => null,
                    ], 422));
                }
            }
            $locked->forceFill(['status' => 'processing', 'processing_errors' => null, 'expires_at' => now()->addDays(180)])->save();

            return true;
        });
        if ($claimed) {
            try {
                $this->finalizer->handle($simulation);
            } catch (Throwable $exception) {
                report($exception);
                $this->failureRecorder->record($simulation, [[
                    'file' => 'simulação', 'code' => 'SIMULATION_PROCESSING_FAILED',
                    'errors' => ['message' => 'Não foi possível concluir o cálculo. Suas faturas continuam salvas para tentar novamente.'],
                ]]);
                throw new HttpResponseException(response()->json([
                    'message' => 'Não foi possível concluir o cálculo. Suas faturas continuam salvas para tentar novamente.',
                    'code' => 'SIMULATION_PROCESSING_FAILED', 'errors' => null,
                ], 500));
            }
        }

        return $simulation->fresh();
    }
}
