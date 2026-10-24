<?php

namespace App\Invoices\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

final class SmalotPdfTextParser implements PdfTextParser
{
    private const DECODE_MEMORY_LIMIT = 104_857_600;

    public function extract(string $path): string
    {
        $config = new Config;
        $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);

        return (new Parser([], $config))->parseFile($path)->getText();
    }
}
