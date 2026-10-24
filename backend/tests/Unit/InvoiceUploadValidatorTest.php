<?php

namespace Tests\Unit;

use App\Invoices\Pdf\PdfPageCounter;
use App\Invoices\Validation\InvoiceUploadValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class InvoiceUploadValidatorTest extends TestCase
{
    public function test_it_accepts_six_pdf_files_with_exactly_six_hundred_pages(): void
    {
        $files = $this->pdfFiles(6);
        $pageCounter = $this->createMock(PdfPageCounter::class);
        $pageCounter->expects($this->exactly(6))->method('count')->willReturn(100);

        $this->assertNull((new InvoiceUploadValidator($pageCounter))->validate($files));
    }

    public function test_it_rejects_file_counts_outside_the_six_to_twelve_range(): void
    {
        foreach ([5, 13] as $count) {
            try {
                (new InvoiceUploadValidator($this->createMock(PdfPageCounter::class)))->validate($this->pdfFiles($count));
                $this->fail("A batch of {$count} files should be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('invoices', $exception->errors());
            }
        }
    }

    public function test_it_rejects_an_executable_renamed_with_a_pdf_extension_as_415(): void
    {
        $files = $this->pdfFiles(6);
        $files[0] = UploadedFile::fake()->createWithContent('invoice.pdf', "MZ\x90\0not a PDF");

        try {
            (new InvoiceUploadValidator($this->createMock(PdfPageCounter::class)))->validate($files);
            $this->fail('A renamed executable should be rejected.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(415, $exception->getStatusCode());
        }
    }

    public function test_it_rejects_an_individual_file_above_twenty_megabytes_as_413(): void
    {
        $files = $this->pdfFiles(6);
        $files[0] = UploadedFile::fake()->create('large.pdf', 20 * 1024 + 1, 'application/pdf');

        $this->assertStatus(413, fn () => (new InvoiceUploadValidator($this->createMock(PdfPageCounter::class)))->validate($files));
    }

    public function test_it_rejects_a_batch_above_one_hundred_twenty_megabytes_as_413(): void
    {
        $file = UploadedFile::fake()->create('invoice.pdf', 18 * 1024, 'application/pdf');
        $files = array_fill(0, 7, $file);

        $this->assertStatus(413, fn () => (new InvoiceUploadValidator($this->createMock(PdfPageCounter::class)))->validate($files));
    }

    public function test_it_rejects_a_batch_above_six_hundred_pages_as_413(): void
    {
        $pageCounter = $this->createMock(PdfPageCounter::class);
        $pageCounter->expects($this->exactly(6))
            ->method('count')
            ->willReturnOnConsecutiveCalls(101, 100, 100, 100, 100, 100);

        $this->assertStatus(413, fn () => (new InvoiceUploadValidator($pageCounter))->validate($this->pdfFiles(6)));
    }

    private function assertStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected an HTTP {$status} exception.");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    /** @return list<UploadedFile> */
    private function pdfFiles(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; $index++) {
            $files[] = UploadedFile::fake()->createWithContent("invoice-{$index}.pdf", "%PDF-1.4\nsynthetic upload");
        }

        return $files;
    }
}
