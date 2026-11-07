<?php

namespace App\Services;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Invoices\Validation\InvoiceUploadValidator;
use App\Mail\SimulationCompleted;
use App\Models\Simulation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class FinalizeImportedSimulation
{
    public function __construct(private readonly ReportCalculationEngine $calculationEngine) {}

    public function handle(Simulation $simulation): void
    {
        $finished = DB::transaction(function () use ($simulation): bool {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            if ($locked->status === 'completed') {
                return false;
            }
            if ($locked->status !== 'processing') {
                throw new RuntimeException('A simulação não está pronta para o cálculo.');
            }
            $locked->load('invoices.items', 'user.profile.distributor');
            $invoices = $locked->invoices;
            if ($invoices->count() < InvoiceUploadValidator::MIN_FILES || $invoices->count() > InvoiceUploadValidator::MAX_FILES) {
                throw new RuntimeException('A simulação precisa ter de 1 a 12 faturas salvas.');
            }

            $calculated = $this->calculationEngine->calculate($locked);
            $attributes = $calculated->calculatedAttributes + [
                'algorithm_version' => $calculated->algorithmVersion,
                'tariff_version_ids' => $calculated->tariffVersionIds,
                'regulatory_parameter_version_ids' => $calculated->regulatoryParameterVersionIds,
                'explanatory_texts' => [
                    'percentage' => '% do valor médio pago no período',
                    'percentage_observation' => 'A economia usa tarifas de referência; o valor pago usa as tarifas históricas.',
                ],
                'recommendations' => [],
            ];
            $locked->report()->delete();
            $locked->report()->create($attributes);
            $locked->forceFill([
                'status' => 'completed',
                'processing_errors' => null,
                'months_count' => $invoices->count(),
                'total_monthly_savings' => $attributes['total_monthly_savings'] ?? '0.00',
                'total_yearly_savings' => $attributes['total_yearly_savings'] ?? '0.00',
            ])->save();

            return true;
        });

        if (! $finished) {
            return;
        }
        $completed = Simulation::query()->with(['user', 'report'])->findOrFail($simulation->id);
        if ($completed->user !== null && $completed->report !== null) {
            try {
                Mail::to($completed->user->email)->queue(new SimulationCompleted($completed->report));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
