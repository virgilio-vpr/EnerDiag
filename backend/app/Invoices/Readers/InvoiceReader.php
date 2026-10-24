<?php

namespace App\Invoices\Readers;

interface InvoiceReader
{
    public function parserKey(): string;

    public function recognizesIdleDemandLine(): bool;
}
