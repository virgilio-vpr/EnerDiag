<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandCostResult;
use App\Calculation\Data\DemandReconciliationResult;
use InvalidArgumentException;

final class DemandReconciliationService
{
    public const string MISMATCH_WARNING = 'demand_invoice_reconciliation_mismatch';

    public const string UNAVAILABLE_WARNING = 'demand_invoice_reconciliation_unavailable';

    public const string DEFAULT_TOLERANCE = '0.02';

    /**
     * Compare the calculated demand charges with invoice line totals.
     *
     * @param  array{demand?: string, idle_demand?: string, overrun?: string}  $invoicedAmounts
     */
    public function reconcile(
        DemandCostResult $calculated,
        array $invoicedAmounts,
        string $tolerance = self::DEFAULT_TOLERANCE,
    ): DemandReconciliationResult {
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $tolerance) !== 1) {
            throw new InvalidArgumentException('Reconciliation tolerance must be a non-negative amount with up to two decimals.');
        }

        if ($calculated->total === null) {
            return new DemandReconciliationResult(false, null, null, null, null, [self::UNAVAILABLE_WARNING]);
        }

        $calculatedAmounts = [
            'demand' => bcadd($calculated->measuredDemandCharge ?? '0.00', $calculated->contractedDemandCharge ?? '0.00', 2),
            'idle_demand' => $calculated->idleDemandCharge ?? '0.00',
            'overrun' => $calculated->overrunCharge ?? '0.00',
        ];
        $invoicedTotal = '0.00';
        $matchesInvoice = true;

        foreach ($calculatedAmounts as $component => $amount) {
            $invoicedAmount = $invoicedAmounts[$component] ?? '0.00';
            $invoicedTotal = bcadd($invoicedTotal, $invoicedAmount, 2);
            $difference = bcsub($amount, $invoicedAmount, 2);
            if (bccomp($difference, '0.00', 2) < 0) {
                $difference = bcmul($difference, '-1', 2);
            }
            if (bccomp($difference, $tolerance, 2) > 0) {
                $matchesInvoice = false;
            }
        }

        return new DemandReconciliationResult(
            available: true,
            matchesInvoice: $matchesInvoice,
            calculatedTotal: $calculated->total,
            invoicedTotal: $invoicedTotal,
            currentCost: $matchesInvoice ? $calculated->total : $invoicedTotal,
            warnings: $matchesInvoice ? [] : [self::MISMATCH_WARNING],
        );
    }
}
