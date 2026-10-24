<?php

namespace Tests\Unit;

use App\Invoices\Exceptions\UnsupportedInvoiceReaderException;
use App\Invoices\Readers\EnelInvoiceReader;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Models\Distributor;
use Tests\TestCase;

class InvoiceReaderResolverTest extends TestCase
{
    public function test_it_resolves_the_enel_reader_and_declares_idle_demand_support(): void
    {
        $reader = app(InvoiceReaderResolver::class)->resolve(Distributor::make(['parser_key' => 'enel']));

        $this->assertInstanceOf(EnelInvoiceReader::class, $reader);
        $this->assertSame('enel', $reader->parserKey());
        $this->assertTrue($reader->recognizesIdleDemandLine());
    }

    public function test_it_throws_a_specific_exception_for_an_unsupported_distributor(): void
    {
        $this->expectException(UnsupportedInvoiceReaderException::class);
        $this->expectExceptionMessage("parser_key 'other'");

        app(InvoiceReaderResolver::class)->resolve(Distributor::make(['parser_key' => 'other']));
    }
}
