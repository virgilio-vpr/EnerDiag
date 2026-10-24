<?php

namespace App\Calculation\Services;

use App\Calculation\Data\CalibratedTariffResult;
use App\Calculation\Data\CalibrationFactorResult;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Models\Tariff;

final class TariffCalibrationService
{
    public const string K_DIVERGENCE_TOLERANCE = '0.00003';

    public const string K_DIVERGENCE_WARNING = 'tax_calibration_factor_divergence';

    public const string K_UNAVAILABLE_WARNING = 'tax_calibration_factor_unavailable';

    public const string ANEEL_RATE_MISMATCH_WARNING = 'invoice_aneel_tariff_rate_mismatch';

    public const string MIGRATION_UNAVAILABLE_WARNING = 'migration_unavailable';

    public function calculateFactor(ExtractedInvoiceData $invoice): CalibrationFactorResult
    {
        $itemFactors = [];
        foreach ($invoice->items as $index => $item) {
            if ($item->itemType === 'idle_demand' || bccomp($item->icmsRate, '0', 4) <= 0) {
                continue;
            }

            $factor = $this->factorFromInvoiceItem($item);
            if ($factor !== null) {
                $itemFactors[$index] = $factor;
            }
        }

        if ($itemFactors === []) {
            return new CalibrationFactorResult(null, [], [self::K_UNAVAILABLE_WARNING]);
        }

        $values = array_values($itemFactors);
        usort($values, static fn (string $left, string $right): int => bccomp($left, $right, 6));
        $spread = bcsub($values[array_key_last($values)], $values[0], 6);
        $warnings = bccomp($spread, self::K_DIVERGENCE_TOLERANCE, 6) > 0
            ? [self::K_DIVERGENCE_WARNING]
            : [];

        $sum = '0.000000';
        foreach ($values as $value) {
            $sum = bcadd($sum, $value, 8);
        }
        $average = $this->roundHalfUp(bcdiv($sum, (string) count($values), 8), 6);

        return new CalibrationFactorResult($average, $itemFactors, $warnings);
    }

    /** @return list<string> */
    public function compareInvoiceRateWithAneel(ExtractedInvoiceItemData $invoiceItem, ?Tariff $aneelTariff): array
    {
        if ($aneelTariff === null || $invoiceItem->tariffRate === null) {
            return [];
        }

        return bccomp($invoiceItem->tariffRate, $aneelTariff->rate, 5) === 0
            ? []
            : [self::ANEEL_RATE_MISMATCH_WARNING];
    }

    public function convertTariff(?Tariff $tariff, ?string $factor): CalibratedTariffResult
    {
        if ($tariff === null || $factor === null) {
            return new CalibratedTariffResult(null, [self::MIGRATION_UNAVAILABLE_WARNING]);
        }

        if ($tariff->includes_taxes) {
            return new CalibratedTariffResult($tariff->rate);
        }

        $convertedRate = bcmul($tariff->rate, $factor, 10);

        return new CalibratedTariffResult($this->roundHalfUp($convertedRate, 5));
    }

    public function alternativeEnergyTe(
        ExtractedInvoiceItemData $invoiceTe,
        ?Tariff $otherModalityTe,
        ?string $factor,
    ): CalibratedTariffResult {
        if ($otherModalityTe === null || $factor === null) {
            return new CalibratedTariffResult(null, [self::MIGRATION_UNAVAILABLE_WARNING]);
        }

        if ($invoiceTe->tariffRate !== null
            && bccomp($invoiceTe->tariffRate, $otherModalityTe->rate, 5) === 0
            && $invoiceTe->unitPriceTaxed !== null) {
            return new CalibratedTariffResult($invoiceTe->unitPriceTaxed);
        }

        return $this->convertTariff($otherModalityTe, $factor);
    }

    private function factorFromInvoiceItem(ExtractedInvoiceItemData $item): ?string
    {
        if ($item->unitPriceTaxed !== null && $item->tariffRate !== null
            && bccomp($item->unitPriceTaxed, '0', 5) > 0
            && bccomp($item->tariffRate, '0', 5) > 0) {
            $factor = bcdiv($item->unitPriceTaxed, $item->tariffRate, 10);

            return $this->roundHalfUp($factor, 6);
        }

        if (bccomp($item->amount, '0', 2) <= 0) {
            return null;
        }

        $pisCofinsPercent = bcdiv($item->pisCofinsAmount, $item->amount, 10);
        $taxesTotal = bcadd($item->icmsRate, $pisCofinsPercent, 10);
        $remainingFactor = bcsub('1', $taxesTotal, 10);
        if (bccomp($remainingFactor, '0', 10) <= 0) {
            return null;
        }

        return $this->roundHalfUp(bcdiv('1', $remainingFactor, 10), 6);
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, $halfUnit, $scale);
    }
}
