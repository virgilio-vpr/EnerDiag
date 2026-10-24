<?php

namespace App\Invoices\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

final class SmalotPdfPageCounter implements PdfPageCounter
{
    private const DECODE_MEMORY_LIMIT = 104_857_600;

    public function count(string $path): int
    {
        $config = new Config;
        $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);

        return count((new Parser([], $config))->parseFile($path)->getPages());
    }
}
