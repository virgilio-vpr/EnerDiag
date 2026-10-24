<?php

namespace App\Invoices\Pdf;

interface PdfPageCounter
{
    public function count(string $path): int;
}
