<?php

namespace App\Calculation\Exceptions;

use RuntimeException;

final class ExpiredSimulationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Expired simulations cannot be recalculated.');
    }
}
