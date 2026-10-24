<?php

namespace App\Invoices\Exceptions;

use RuntimeException;

final class UnsupportedInvoiceReaderException extends RuntimeException
{
    public static function forParserKey(?string $parserKey): self
    {
        $key = $parserKey ?? '(ausente)';

        return new self("Nenhum leitor de fatura está registrado para a parser_key '{$key}'.");
    }
}
