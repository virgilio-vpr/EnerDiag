<?php

namespace Tests\Unit;

use App\Calculation\Data\DemandCostResult;
use App\Calculation\Services\DemandReconciliationService;
use PHPUnit\Framework\TestCase;

class DemandReconciliationServiceTest extends TestCase
{
    public function test_difference_at_two_cent_tolerance_matches_invoice_without_warning(): void
    {
        $result = (new DemandReconciliationService)->reconcile(
            $this->calculatedDemand(),
            ['demand' => '99.98', 'idle_demand' => '20.00'],
        );

        $this->assertTrue($result->available);
        $this->assertTrue($result->matchesInvoice);
        $this->assertSame('120.00', $result->calculatedTotal);
        $this->assertSame('119.98', $result->invoicedTotal);
        $this->assertSame('120.00', $result->currentCost);
        $this->assertSame([], $result->warnings);
    }

    public function test_divergent_month_warns_and_uses_invoiced_total_as_current_cost(): void
    {
        $result = (new DemandReconciliationService)->reconcile(
            $this->calculatedDemand(),
            ['demand' => '99.97', 'idle_demand' => '20.00'],
        );

        $this->assertFalse($result->matchesInvoice);
        $this->assertSame('119.97', $result->invoicedTotal);
        $this->assertSame('119.97', $result->currentCost);
        $this->assertSame([DemandReconciliationService::MISMATCH_WARNING], $result->warnings);
    }

    private function calculatedDemand(): DemandCostResult
    {
        return new DemandCostResult('120.00', 'below_contracted', '100.00', '20.00', '0.00', '0.00');
    }
}
