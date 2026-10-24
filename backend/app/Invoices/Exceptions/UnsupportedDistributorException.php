<?php

namespace App\Invoices\Exceptions;

use RuntimeException;

final class UnsupportedDistributorException extends RuntimeException
{
    public function __construct(public readonly string $distributorName)
    {
        parent::__construct('A distribuidora selecionada ainda não possui leitor disponível.');
    }
}
