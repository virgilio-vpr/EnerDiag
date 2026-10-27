<?php

namespace App\Invoices\Validation;

use App\Invoices\Pdf\PdfPageCounter;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Throwable;

final class InvoiceUploadValidator
{
    /** Temporary minimum for manual testing; restore to six after testing. */
    public const int MIN_FILES = 2;

    public const int MAX_FILES = 12;

    public const int MAX_FILE_BYTES = 20 * 1024 * 1024;

    public const int MAX_TOTAL_BYTES = 120 * 1024 * 1024;

    public const int MAX_PAGES = 600;

    public function __construct(private readonly PdfPageCounter $pageCounter) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function validate(array $files): void
    {
        $fileCount = count($files);
        if ($fileCount < self::MIN_FILES || $fileCount > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'invoices' => 'Envie de '.self::MIN_FILES.' a '.self::MAX_FILES.' faturas em PDF.',
            ]);
        }

        $totalBytes = 0;
        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    "invoices.{$index}" => 'Não foi possível ler o arquivo enviado.',
                ]);
            }

            $size = $file->getSize();
            if (! is_int($size) || $size < 0) {
                throw ValidationException::withMessages([
                    "invoices.{$index}" => 'Não foi possível ler o arquivo enviado.',
                ]);
            }

            if ($size > self::MAX_FILE_BYTES) {
                throw new HttpException(413, 'Cada fatura deve ter no máximo 20 MB.');
            }

            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new HttpException(413, 'O conjunto de faturas deve ter no máximo 120 MB.');
            }
        }

        foreach ($files as $index => $file) {
            if (! $this->hasPdfSignature($file)) {
                throw new UnsupportedMediaTypeHttpException("O arquivo de índice {$index} não é um PDF válido.");
            }
        }

        $totalPages = 0;
        foreach ($files as $index => $file) {
            try {
                $totalPages += $this->pageCounter->count($file->getRealPath());
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "invoices.{$index}" => 'Não foi possível ler as páginas do PDF enviado.',
                ]);
            }

            if ($totalPages > self::MAX_PAGES) {
                throw new HttpException(413, 'O conjunto de faturas deve ter no máximo 600 páginas.');
            }
        }
    }

    private function hasPdfSignature(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $prefix = fread($handle, 1024);
        } finally {
            fclose($handle);
        }

        return $mimeType === 'application/pdf' && is_string($prefix) && preg_match('/%PDF-\d\.\d/', $prefix) === 1;
    }
}
