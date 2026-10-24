<?php

namespace App\Invoices\Readers;

use App\Invoices\Exceptions\UnsupportedInvoiceReaderException;
use App\Models\Distributor;

final class InvoiceReaderResolver
{
    /**
     * @var array<string, InvoiceReader>
     */
    private array $readersByParserKey = [];

    /**
     * @param  iterable<InvoiceReader>  $readers
     */
    public function __construct(iterable $readers)
    {
        foreach ($readers as $reader) {
            $this->readersByParserKey[$reader->parserKey()] = $reader;
        }
    }

    public function resolve(Distributor $distributor): InvoiceReader
    {
        $reader = $this->readersByParserKey[$distributor->parser_key] ?? null;

        if ($reader === null) {
            throw UnsupportedInvoiceReaderException::forParserKey($distributor->parser_key);
        }

        return $reader;
    }
}
