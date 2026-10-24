<?php

namespace App\Calculation\Services;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\RecalculatedReportData;
use App\Calculation\Exceptions\ExpiredSimulationException;
use App\Models\Report;
use App\Models\Simulation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReportRecalculationService
{
    /** @var list<string> */
    private const CALCULATED_FIELDS = [
        'total_monthly_savings', 'total_yearly_savings', 'savings_percentage',
        'demand_savings_monthly', 'demand_savings_yearly',
        'overrun_savings_monthly', 'overrun_savings_yearly',
        'reactive_savings_monthly', 'reactive_savings_yearly',
        'migration_savings_monthly', 'migration_savings_yearly',
        'recommended_modality', 'optimal_demand_peak', 'optimal_demand_off_peak', 'optimal_demand_single',
        'chart_series', 'warnings', 'r_values', 'k_values',
    ];

    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        ...self::CALCULATED_FIELDS,
        'explanatory_texts', 'recommendations', 'algorithm_version',
        'tariff_version_ids', 'regulatory_parameter_version_ids',
    ];

    public function __construct(private ReportCalculationEngine $calculationEngine) {}

    public function recalculate(int $simulationId): Report
    {
        return DB::transaction(function () use ($simulationId): Report {
            $simulation = Simulation::query()
                ->with(['invoices.items', 'report'])
                ->lockForUpdate()
                ->findOrFail($simulationId);

            if ($simulation->expires_at !== null && $simulation->expires_at->lessThanOrEqualTo(now())) {
                throw new ExpiredSimulationException;
            }

            $report = $simulation->report;
            if ($report === null) {
                throw new InvalidArgumentException('A report must exist before it can be recalculated.');
            }

            $previousSnapshot = [];
            foreach (self::SNAPSHOT_FIELDS as $field) {
                $previousSnapshot[$field] = $report->getAttribute($field);
            }

            $recalculated = $this->calculationEngine->calculate($simulation);
            $attributes = $this->validatedCalculatedAttributes($recalculated);
            $report->revisions()->create([
                'user_id' => null,
                'explanatory_texts' => $report->explanatory_texts,
                'recommendations' => $report->recommendations,
                'calculation_snapshot' => $previousSnapshot,
                'reason' => 'calculation_recalculation',
            ]);

            $report->fill($attributes + [
                'algorithm_version' => $recalculated->algorithmVersion,
                'tariff_version_ids' => $recalculated->tariffVersionIds,
                'regulatory_parameter_version_ids' => $recalculated->regulatoryParameterVersionIds,
            ])->save();

            $simulation->forceFill([
                'total_monthly_savings' => $report->total_monthly_savings,
                'total_yearly_savings' => $report->total_yearly_savings,
            ])->save();

            return $report->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function validatedCalculatedAttributes(RecalculatedReportData $data): array
    {
        $unknownFields = array_diff(array_keys($data->calculatedAttributes), self::CALCULATED_FIELDS);
        if ($unknownFields !== []) {
            throw new InvalidArgumentException('The recalculation engine returned non-calculated report fields: '.implode(', ', $unknownFields));
        }

        return $data->calculatedAttributes;
    }
}
