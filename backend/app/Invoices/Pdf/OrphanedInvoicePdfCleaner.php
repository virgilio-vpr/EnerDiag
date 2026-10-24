<?php

namespace App\Invoices\Pdf;

use Illuminate\Support\Facades\Storage;

final class OrphanedInvoicePdfCleaner
{
    public const ORPHAN_AGE_SECONDS = 3600;

    public function clean(): int
    {
        $disk = Storage::disk(TemporaryPdfTextExtractor::DISK);
        $cutoff = now()->subSeconds(self::ORPHAN_AGE_SECONDS)->timestamp;
        $deleted = 0;

        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < $cutoff && $disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
