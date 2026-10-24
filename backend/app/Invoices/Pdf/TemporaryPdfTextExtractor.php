<?php

namespace App\Invoices\Pdf;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class TemporaryPdfTextExtractor
{
    public const DISK = 'invoice_temp';

    public function __construct(private readonly PdfTextParser $parser) {}

    public function extract(string $pdfContents): string
    {
        $disk = Storage::disk(self::DISK);
        $path = Str::uuid().'.pdf';

        try {
            if (! $disk->put($path, $pdfContents)) {
                throw new RuntimeException('Não foi possível armazenar temporariamente a fatura.');
            }

            return $this->parser->extract($disk->path($path));
        } finally {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
