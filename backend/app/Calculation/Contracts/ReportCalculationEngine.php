<?php

namespace App\Calculation\Contracts;

use App\Calculation\Data\RecalculatedReportData;
use App\Models\Simulation;

interface ReportCalculationEngine
{
    /** Calculate from the simulation's persisted invoice and invoice-item records. */
    public function calculate(Simulation $simulation): RecalculatedReportData;
}
