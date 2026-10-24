<?php

namespace App\Invoices\Readers;

use App\Calculation\Data\ExtractedInvoiceData;

interface InvoiceReader
{
    public function parserKey(): string;

    public function recognizesIdleDemandLine(): bool;

    public function read(string $text): ExtractedInvoiceData;
}
