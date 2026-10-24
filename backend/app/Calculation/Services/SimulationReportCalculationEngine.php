<?php

namespace App\Calculation\Services;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\RecalculatedReportData;
use App\Calculation\Data\ReportIndicatorMonthData;
use App\Models\RegulatoryParameter;
use App\Models\Simulation;

final class SimulationReportCalculationEngine implements ReportCalculationEngine
{
    public function __construct(
        private readonly ReactiveSavingsService $reactiveSavingsService,
        private readonly ReportIndicatorsService $indicatorsService,
        private readonly TariffCalibrationService $calibrationService,
        private readonly IdleDemandRatioService $idleDemandRatioService,
        private readonly DemandBillingService $demandBillingService,
        private readonly DemandReconciliationService $demandReconciliationService,
    ) {}

    public function calculate(Simulation $simulation): RecalculatedReportData
    {
        $invoices = $simulation->invoices->sortBy('reference_month')->values()->map(
            static fn ($invoice): ExtractedInvoiceData => ExtractedInvoiceData::fromArray([
                'reference_month' => $invoice->reference_month,
                'consumer_unit' => $invoice->consumer_unit,
                'subgroup' => $invoice->subgroup,
                'tariff_modality' => $invoice->tariff_modality,
                'total_paid' => $invoice->total_paid,
                'items' => $invoice->items->map(static fn ($item): array => [
                    'item_type' => $item->item_type,
                    'tariff_component' => $item->tariff_component,
                    'time_slot' => $item->time_slot,
                    'reactive_nature' => $item->reactive_nature,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price_taxed' => $item->unit_price_taxed,
                    'tariff_rate' => $item->tariff_rate,
                    'amount' => $item->amount,
                    'pis_cofins_amount' => $item->pis_cofins_amount,
                    'icms_base' => $item->icms_base,
                    'icms_rate' => $item->icms_rate,
                    'icms_amount' => $item->icms_amount,
                    'source_label' => $item->source_label,
                ])->all(),
            ]),
        )->all();

        $reactive = $this->reactiveSavingsService->calculate($invoices);
        $demandMonths = array_map(
            static fn (ExtractedInvoiceData $invoice): DemandMonthData => DemandMonthData::fromInvoice($invoice, $invoice->referenceMonth),
            $invoices,
        );
        $ratios = $this->idleDemandRatioService->resolve($demandMonths, true);
        $reactiveByMonth = $reactive->byMonth;
        $indicatorMonths = [];
        $chartSeries = ['demand' => []];
        $kValues = [];
        $rValues = [];
        $regulatoryParameterIds = [];
        $warnings = [
            'demand_optimization_unavailable_pending_section_9_limits',
            'demand_savings_unavailable_pending_section_9_limits',
            'tariff_migration_unavailable_pending_section_9_rules',
        ];

        $warnings = array_merge($warnings, $ratios->warnings());
        foreach ($invoices as $invoice) {
            $k = $this->calibrationService->calculateFactor($invoice);
            $kValues[$invoice->referenceMonth] = $k->factor === null ? null : [
                'factor' => $k->factor,
                'item_factors' => $k->itemFactors,
            ];
            $warnings = array_merge($warnings, $invoice->warnings, $k->warnings);
            foreach (['overrun_tolerance', 'overrun_multiplier'] as $key) {
                $parameter = RegulatoryParameter::query()
                    ->where('key', $key)
                    ->whereNull('state')
                    ->whereDate('valid_from', '<=', $invoice->referenceMonth.'-01')
                    ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $invoice->referenceMonth.'-01'))
                    ->orderByDesc('valid_from')
                    ->first();
                if ($parameter !== null) {
                    $regulatoryParameterIds[] = $parameter->id;
                }
            }
            $demandMonth = null;
            foreach ($demandMonths as $candidate) {
                if ($candidate->referenceMonth === $invoice->referenceMonth) {
                    $demandMonth = $candidate;
                    break;
                }
            }
            foreach ($demandMonth?->contractedDemandByTimeSlot ?? [] as $slot => $contracted) {
                $measured = $demandMonth->measuredDemandByTimeSlot[$slot] ?? null;
                $normalItem = $demandMonth->normalDemandItemsByTimeSlot[$slot] ?? null;
                $ratio = $ratios->forMonthAndTimeSlot($invoice->referenceMonth, $slot);
                $invoicedDemandAmounts = ['demand' => '0.00', 'idle_demand' => '0.00', 'overrun' => '0.00'];
                foreach ($invoice->items as $item) {
                    if ($item->timeSlot === $slot && $item->itemType === 'measured_demand') {
                        $invoicedDemandAmounts['demand'] = bcadd($invoicedDemandAmounts['demand'], $item->amount, 2);
                    }
                    if ($item->timeSlot === $slot && $item->itemType === 'demand_overrun') {
                        $invoicedDemandAmounts['overrun'] = bcadd($invoicedDemandAmounts['overrun'], $item->amount, 2);
                    }
                }
                $idleIndex = $ratio?->idleItemIndex;
                if ($idleIndex !== null && isset($demandMonth->idleDemandItems[$idleIndex])) {
                    $invoicedDemandAmounts['idle_demand'] = $demandMonth->idleDemandItems[$idleIndex]->amount;
                }
                if ($measured !== null && $normalItem !== null && $normalItem->unitPriceTaxed !== null) {
                    $billing = $this->demandBillingService->calculate(
                        $invoice->referenceMonth,
                        $contracted,
                        $measured,
                        $normalItem->unitPriceTaxed,
                        $ratio?->ratio,
                    );
                    $reconciliation = $this->demandReconciliationService->reconcile($billing, $invoicedDemandAmounts);
                    $warnings = array_merge($warnings, $billing->warnings, $reconciliation->warnings);
                }
                if ($ratio !== null) {
                    $rValues[] = [
                        'reference_month' => $invoice->referenceMonth,
                        'time_slot' => $slot,
                        'ratio' => $ratio->ratio,
                        'source' => $ratio->source,
                        'inherited' => $ratio->inherited,
                    ];
                    if ($ratio->source === IdleDemandRatioService::FALLBACK_SOURCE) {
                        $parameter = RegulatoryParameter::query()
                            ->where('key', 'idle_demand_discount_fallback')
                            ->whereNull('state')
                            ->whereDate('valid_from', '<=', $invoice->referenceMonth.'-01')
                            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $invoice->referenceMonth.'-01'))
                            ->orderByDesc('valid_from')
                            ->first();
                        if ($parameter !== null) {
                            $regulatoryParameterIds[] = $parameter->id;
                        }
                    }
                }
            }
            $reactiveSavings = $reactiveByMonth[$invoice->referenceMonth]['total'] ?? '0.00';
            $chartSeries['reactive'][] = ['reference_month' => $invoice->referenceMonth, 'value' => $reactiveSavings];
            $indicatorMonths[] = new ReportIndicatorMonthData(
                referenceMonth: $invoice->referenceMonth,
                monthlySavingsByComponent: [
                    'demand' => '0.00',
                    'overrun' => '0.00',
                    'reactive' => $reactiveSavings,
                    'migration' => '0.00',
                ],
                totalPaid: $invoice->totalPaid,
                chartValuesByKey: [],
            );

            $contracted = [];
            $measured = [];
            foreach ($invoice->items as $item) {
                if ($item->itemType === 'contracted_demand') {
                    $contracted[$item->timeSlot] = $item->quantity;
                }
                if ($item->itemType === 'measured_demand') {
                    $measured[$item->timeSlot] = $item->quantity;
                }
            }
            foreach (array_unique(array_merge(array_keys($contracted), array_keys($measured))) as $slot) {
                $chartSeries['demand'][] = [
                    'reference_month' => $invoice->referenceMonth,
                    'time_slot' => $slot,
                    'contracted' => $contracted[$slot] ?? null,
                    'measured' => $measured[$slot] ?? null,
                ];
            }
        }

        if (! $reactive->occurred) {
            $warnings[] = $reactive->message;
        }

        $indicators = $this->indicatorsService->calculate($indicatorMonths);
        $warnings = array_values(array_unique(array_filter(array_merge($warnings, $indicators->warnings))));
        $componentMonthly = $indicators->monthlySavingsByComponent;
        $componentAnnual = $indicators->annualSavingsByComponent;

        return new RecalculatedReportData(
            calculatedAttributes: [
                'total_monthly_savings' => $indicators->totalMonthlySavings,
                'total_yearly_savings' => $indicators->totalAnnualSavings,
                'savings_percentage' => $indicators->savingsPercentage ?? '0.00',
                'demand_savings_monthly' => $componentMonthly['demand'] ?? '0.00',
                'demand_savings_yearly' => $componentAnnual['demand'] ?? '0.00',
                'overrun_savings_monthly' => $componentMonthly['overrun'] ?? '0.00',
                'overrun_savings_yearly' => $componentAnnual['overrun'] ?? '0.00',
                'reactive_savings_monthly' => $componentMonthly['reactive'] ?? '0.00',
                'reactive_savings_yearly' => $componentAnnual['reactive'] ?? '0.00',
                'migration_savings_monthly' => $componentMonthly['migration'] ?? '0.00',
                'migration_savings_yearly' => $componentAnnual['migration'] ?? '0.00',
                'recommended_modality' => null,
                'optimal_demand_peak' => null,
                'optimal_demand_off_peak' => null,
                'optimal_demand_single' => null,
                'chart_series' => $chartSeries + $indicators->seriesByKey,
                'warnings' => $warnings,
                'r_values' => $rValues,
                'k_values' => $kValues,
            ],
            algorithmVersion: '1.0',
            tariffVersionIds: [],
            regulatoryParameterVersionIds: array_values(array_unique($regulatoryParameterIds)),
        );
    }
}
