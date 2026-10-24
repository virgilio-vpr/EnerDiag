<?php

namespace App\Invoices\Pdf;

interface PdfTextParser
{
    public function extract(string $path): string;
}
