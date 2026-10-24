<?php

namespace App\Invoices\Validation;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\ExtractedInvoiceItemData;
use Illuminate\Validation\ValidationException;

final class InvoiceExtractionValidator
{
    public const string AMOUNT_TOLERANCE = '0.01';

    /**
     * @param  list<ExtractedInvoiceData>  $invoices
     */
    public function validate(array $invoices): void
    {
        $errors = [];
        $firstConsumerUnit = null;
        $months = [];

        foreach ($invoices as $invoiceIndex => $invoice) {
            if (! $invoice instanceof ExtractedInvoiceData) {
                throw new \InvalidArgumentException('Every invoice must be normalized before batch validation.');
            }

            if (isset($months[$invoice->referenceMonth])) {
                $errors["invoices.{$invoiceIndex}.reference_month"][] = 'O mês de referência está duplicado.';
            }
            $months[$invoice->referenceMonth] = true;

            if ($firstConsumerUnit === null) {
                $firstConsumerUnit = $invoice->consumerUnit;
            } elseif ($invoice->consumerUnit !== $firstConsumerUnit) {
                $errors["invoices.{$invoiceIndex}.consumer_unit"][] = 'As faturas devem pertencer à mesma unidade consumidora.';
            }

            foreach ($invoice->items as $itemIndex => $item) {
                $this->validateItem($item, $invoiceIndex, $itemIndex, $errors);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, list<string>> $errors */
    private function validateItem(ExtractedInvoiceItemData $item, int $invoiceIndex, int $itemIndex, array &$errors): void
    {
        if ($item->quantity !== null && $item->unitPriceTaxed !== null) {
            $expectedAmount = $this->roundHalfUp(bcmul($item->quantity, $item->unitPriceTaxed, 10));
            $amountDifference = $this->absolute(bcsub($expectedAmount, $item->amount, 2));

            if (bccomp($amountDifference, self::AMOUNT_TOLERANCE, 2) > 0) {
                $errors["invoices.{$invoiceIndex}.items.{$itemIndex}.amount"][] = 'A quantidade multiplicada pelo preço unitário não confere com o valor faturado.';
            }
        }

        $expectedIcms = $this->roundHalfUp(bcmul($item->icmsBase, $item->icmsRate, 10));
        $icmsDifference = $this->absolute(bcsub($expectedIcms, $item->icmsAmount, 2));
        if (bccomp($icmsDifference, self::AMOUNT_TOLERANCE, 2) > 0) {
            $errors["invoices.{$invoiceIndex}.items.{$itemIndex}.icms_amount"][] = 'A base e a alíquota de ICMS não conferem com o valor do imposto.';
        }
    }

    private function roundHalfUp(string $value): string
    {
        $negative = bccomp($value, '0', 10) < 0;
        $absolute = $negative ? bcsub('0', $value, 10) : $value;
        $rounded = bcadd($absolute, '0.0050000000', 10);
        $rounded = bcadd($rounded, '0', 2);

        return $negative ? bcsub('0', $rounded, 2) : $rounded;
    }

    private function absolute(string $value): string
    {
        return bccomp($value, '0', 2) < 0 ? bcsub('0', $value, 2) : $value;
    }
}
