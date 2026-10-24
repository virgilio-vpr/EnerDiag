<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Invoices\Readers\EnelInvoiceReader;
use Tests\TestCase;

class EnelInvoiceReaderTest extends TestCase
{
    public function test_it_normalizes_the_reference_invoice_and_matches_reversed_idle_demand_rows(): void
    {
        $reader = new EnelInvoiceReader;
        $invoice = $reader->read(file_get_contents(__DIR__.'/../Fixtures/enel-reference-blue.txt'));

        $this->assertSame('2025-08', $invoice->referenceMonth);
        $this->assertSame('123456789', $invoice->consumerUnit);
        $this->assertSame('A4', $invoice->subgroup);
        $this->assertSame('blue', $invoice->tariffModality);
        $this->assertSame('98922.22', $invoice->totalPaid);

        $peakDemand = $this->item($invoice->items, 'measured_demand', 'peak');
        $this->assertSame('221.760', $peakDemand->quantity);
        $this->assertSame('37.80322', $peakDemand->unitPriceTaxed);
        $this->assertSame('29.30000', $peakDemand->tariffRate);
        $this->assertSame('8383.25', $peakDemand->amount);
        $this->assertSame('376.40', $peakDemand->pisCofinsAmount);
        $this->assertSame('0.1800', $peakDemand->icmsRate);
        $this->assertSame('1508.99', $peakDemand->icmsAmount);
        $overrun = $this->item($invoice->items, 'demand_overrun', 'peak');
        $this->assertSame('10.000', $overrun->quantity);
        $this->assertSame('756.06', $overrun->amount);

        $idleItems = array_values(array_filter($invoice->items, static fn ($item): bool => $item->itemType === 'idle_demand'));
        $this->assertCount(2, $idleItems);
        $this->assertSame('off_peak', $idleItems[0]->timeSlot);
        $this->assertSame('1845.32', $idleItems[0]->amount);
        $this->assertNull($idleItems[0]->quantity);
        $this->assertNull($idleItems[0]->unitPriceTaxed);
        $this->assertSame('peak', $idleItems[1]->timeSlot);
        $this->assertSame('3603.28', $idleItems[1]->amount);
        $this->assertSame([], $invoice->warnings);

        $this->assertNotContains('dmcr', array_map(static fn ($item): string => $item->itemType, $invoice->items));
        $this->assertSame('energy_tusd', $this->item($invoice->items, 'active_consumption', 'peak')->tariffComponent);
        $this->assertSame('reactive_demand_excess', $this->item($invoice->items, 'reactive_demand_excess', 'off_peak')->itemType);
        $this->assertSame('reactive_energy_excess', $this->item($invoice->items, 'reactive_energy_excess', 'late_night_weekend')->itemType);
    }

    public function test_it_returns_no_idle_demand_items_when_the_invoice_has_no_idle_demand_line(): void
    {
        $invoice = (new EnelInvoiceReader)->read(file_get_contents(__DIR__.'/../Fixtures/enel-no-idle-blue.txt'));

        $this->assertSame('2025-09', $invoice->referenceMonth);
        $this->assertSame([], array_values(array_filter($invoice->items, static fn ($item): bool => $item->itemType === 'idle_demand')));
        $this->assertSame([], $invoice->warnings);
    }

    public function test_it_keeps_idle_rows_unmatched_and_adds_a_warning_when_no_assignment_is_within_tolerance(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/enel-reference-blue.txt');
        $text = str_replace(['1.845,32', '3.603,28'], ['900,00', '800,00'], $text);

        $invoice = (new EnelInvoiceReader)->read($text);
        $idleItems = array_values(array_filter($invoice->items, static fn ($item): bool => $item->itemType === 'idle_demand'));

        $this->assertSame(['idle_demand_post_matching_unresolved'], $invoice->warnings);
        $this->assertSame(['unresolved_0', 'unresolved_1'], array_map(static fn ($item): string => $item->timeSlot, $idleItems));
    }

    /** @param list<ExtractedInvoiceItemData> $items */
    private function item(array $items, string $itemType, string $timeSlot): ExtractedInvoiceItemData
    {
        foreach ($items as $item) {
            if ($item->itemType === $itemType && $item->timeSlot === $timeSlot) {
                return $item;
            }
        }

        $this->fail("Missing invoice item {$itemType} / {$timeSlot}.");
    }
}
