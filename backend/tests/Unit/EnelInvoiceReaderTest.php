<?php

namespace Tests\Unit;

use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Invoices\Readers\EnelInvoiceReader;
use App\Invoices\Validation\InvoiceExtractionValidator;
use Tests\TestCase;

class EnelInvoiceReaderTest extends TestCase
{
    public function test_it_reads_discounted_demand_credits_and_all_billing_rows_without_losing_signs_or_precision(): void
    {
        $invoice = (new EnelInvoiceReader)->read(file_get_contents(__DIR__.'/../Fixtures/enel-discounted-text-blue.txt'));
        $this->assertSame('2026-08', $invoice->referenceMonth);
        $this->assertSame('42446.32', $invoice->totalPaid);
        $this->assertSame(['free_market_invoice'], $invoice->warnings);
        $this->assertCount(17, $invoice->items);
        $this->assertSame('252.000', $this->item($invoice->items, 'measured_demand', 'peak')->quantity);
        $idle = $this->item($invoice->items, 'idle_demand', 'off_peak');
        $this->assertSame('67.920', $idle->quantity);
        $this->assertSame('10.05771', $idle->unitPriceTaxed);
        $this->assertSame('9.52000', $idle->tariffRate);
        $credits = array_values(array_filter($invoice->items, static fn ($item): bool => $item->sourceLabel === 'BENEFÍCIO TARIFÁRIO LÍQUIDO'));
        $this->assertSame('-9410.65', $credits[0]->amount);
        $this->assertSame('-503.46', $credits[0]->pisCofinsAmount);
        $sum = '0.00';
        foreach ($invoice->items as $item) {
            $sum = bcadd($sum, $item->amount, 2);
        }
        $this->assertSame($invoice->totalPaid, $sum);
        $this->assertNull((new InvoiceExtractionValidator)->validate([$invoice]));
    }

    public function test_it_warns_when_a_billing_row_is_missing_instead_of_silently_accepting_a_partial_table(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/enel-discounted-text-blue.txt');
        $text = preg_replace('/^ENCARGO ESCASSEZ.*\n/mu', '', $text);
        $invoice = (new EnelInvoiceReader)->read($text);
        $this->assertContains('invoice_billing_total_mismatch', $invoice->warnings);
    }

    public function test_reading_date_takes_precedence_over_issue_month_due_date_and_dates_in_legal_notices(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/enel-reference-blue.txt');
        $invoice = (new EnelInvoiceReader)->read("LEITURA ATUAL 30/06/2026\nMês/Ano: 07/2026\n".$text);
        $this->assertSame('2026-06', $invoice->referenceMonth);

        $invoice = (new EnelInvoiceReader)->read("13/07/2026\n31/05/2026 30/06/2026 31/07/2026\n".$text);
        $this->assertSame('2026-06', $invoice->referenceMonth);
    }

    public function test_it_uses_the_standalone_header_month_without_taking_the_year_from_a_legal_notice(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/enel-discounted-text-blue.txt');
        $text = str_replace('31/07/2026 31/08/2026 30/09/2026', '', $text);
        $this->assertSame('2026-08', (new EnelInvoiceReader)->read($text)->referenceMonth);
    }

    public function test_it_matches_short_idle_demand_rows_using_net_tariffs_when_tax_rounding_exceeds_two_cents(): void
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/enel-rounded-january-blue.txt');
        $invoice = (new EnelInvoiceReader)->read($text);
        $this->assertSame([], $invoice->warnings);
        $this->assertSame('571.06', $this->item($invoice->items, 'idle_demand', 'off_peak')->amount);
        $this->assertSame('1795.88', $this->item($invoice->items, 'idle_demand', 'peak')->amount);
    }

    public function test_it_preserves_values_from_january_and_february_invoices_with_rounding_differences(): void
    {
        $invoices = [];
        foreach (['january' => '48427.71', 'february' => '50934.67'] as $month => $billedAmount) {
            $invoice = (new EnelInvoiceReader)->read(file_get_contents(__DIR__.'/../Fixtures/enel-rounded-'.$month.'-blue.txt'));
            $consumption = array_values(array_filter($invoice->items, static fn ($item): bool => $item->itemType === 'active_consumption' && $item->timeSlot === 'off_peak' && $item->tariffComponent === 'energy_te'))[0];
            $this->assertSame($billedAmount, $consumption->amount);
            $this->assertSame($month === 'january' ? '0.32136' : '0.32129', $consumption->unitPriceTaxed);
            $invoices[] = $invoice;
        }
        $this->assertNull((new InvoiceExtractionValidator)->validate($invoices));
    }

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
        $this->assertNull((new InvoiceExtractionValidator)->validate([$invoice]));
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
