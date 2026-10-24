<?php

namespace App\Invoices\Readers;

final class EnelInvoiceReader implements InvoiceReader
{
    public function parserKey(): string
    {
        return 'enel';
    }

    public function recognizesIdleDemandLine(): bool
    {
        return true;
    }
}
