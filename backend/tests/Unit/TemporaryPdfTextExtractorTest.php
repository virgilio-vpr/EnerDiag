<?php

namespace Tests\Unit;

use App\Invoices\Pdf\OrphanedInvoicePdfCleaner;
use App\Invoices\Pdf\PdfTextParser;
use App\Invoices\Pdf\SmalotPdfTextParser;
use App\Invoices\Pdf\TemporaryPdfTextExtractor;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TemporaryPdfTextExtractorTest extends TestCase
{
    public function test_it_extracts_text_from_a_pdf_and_deletes_the_temporary_file(): void
    {
        Storage::fake(TemporaryPdfTextExtractor::DISK);

        $text = app(TemporaryPdfTextExtractor::class)->extract($this->pdfWithText('Invoice reference text'));

        $this->assertStringContainsString('Invoice reference text', $text);
        $this->assertSame([], Storage::disk(TemporaryPdfTextExtractor::DISK)->allFiles());
    }

    public function test_it_deletes_the_temporary_file_when_extraction_fails(): void
    {
        $disk = Storage::fake(TemporaryPdfTextExtractor::DISK);
        $parser = $this->createMock(PdfTextParser::class);
        $parser->expects($this->once())
            ->method('extract')
            ->willReturnCallback(function (string $path): string {
                $this->assertFileExists($path);

                throw new RuntimeException('Falha de extração simulada.');
            });

        try {
            (new TemporaryPdfTextExtractor($parser))->extract('invalid pdf content');
            $this->fail('A extração deveria propagar o erro do parser.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de extração simulada.', $exception->getMessage());
        }

        $this->assertSame([], $disk->allFiles());
    }

    public function test_it_removes_only_temporary_files_older_than_one_hour(): void
    {
        $disk = Storage::fake(TemporaryPdfTextExtractor::DISK);
        $disk->put('orphan.pdf', 'old');
        $disk->put('recent.pdf', 'recent');
        touch($disk->path('orphan.pdf'), now()->subHours(2)->timestamp);

        $deleted = app(OrphanedInvoicePdfCleaner::class)->clean();

        $this->assertSame(1, $deleted);
        $this->assertFalse($disk->exists('orphan.pdf'));
        $this->assertTrue($disk->exists('recent.pdf'));
    }

    public function test_the_smalot_parser_is_registered_in_the_container(): void
    {
        $this->assertInstanceOf(SmalotPdfTextParser::class, app(PdfTextParser::class));
    }

    private function pdfWithText(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}
