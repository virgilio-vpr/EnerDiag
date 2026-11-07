<?php

namespace Tests\Unit;

use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\IdleDemandRatioEntry;
use App\Calculation\Data\IdleDemandRatioResolution;
use App\Calculation\Services\ReportDemandOptimizationService;
use App\Models\Distributor;
use App\Models\Tariff;
use Database\Seeders\RegulatoryParameterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportDemandOptimizationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegulatoryParameterSeeder::class);
    }

    #[DataProvider('historicalPriceChanges')]
    public function test_price_changes_do_not_create_or_hide_demand_savings(string $historicalRate, string $historicalCost, string $referenceRate, string $referenceMonth, string $saving, string $current, string $optimal): void
    {
        $invoices = [
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => $historicalRate]]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '10.00000']]),
        ];

        $result = $this->calculate($invoices);

        $this->assertSame('77.000', $result['optimal']['unique']);
        $this->assertSame(['2026-01' => $saving, '2026-02' => $saving], $result['savings']);
        $comparison = $result['comparison']['2026-01']['unique'];
        $this->assertSame($referenceMonth, $comparison['tariff_reference_month']);
        $this->assertSame('series_maximum', $comparison['tariff_reference_source']);
        $this->assertSame($referenceRate, $comparison['reference_tariff']);
        $this->assertSame($current, $comparison['current_contract_cost']);
        $this->assertSame($optimal, $comparison['optimal_contract_cost']);
        $this->assertSame($historicalCost, $comparison['historical_invoiced_cost']);
    }

    public static function historicalPriceChanges(): array
    {
        return [
            'tariff decrease' => ['20.00000', '1928.00', '20.00000', '2026-01', '328.00', '1928.00', '1600.00'],
            'tariff increase' => ['5.00000', '482.00', '10.00000', '2026-02', '164.00', '964.00', '800.00'],
        ];
    }

    public function test_a_tariff_decrease_alone_is_not_an_optimization_gain(): void
    {
        $result = $this->calculate([
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '80.000', 'measured' => '80.000', 'rate' => '20.00000']]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '80.000', 'measured' => '80.000', 'rate' => '10.00000']]),
        ]);

        $this->assertSame(['2026-01' => '0.00', '2026-02' => '0.00'], $result['savings']);
        $this->assertSame('1600.00', $result['comparison']['2026-01']['unique']['historical_invoiced_cost']);
    }

    public function test_each_month_keeps_its_own_contracted_demand_in_the_reference_scenario(): void
    {
        $result = $this->calculate([
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '20.00000']]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '120.000', 'measured' => '80.000', 'rate' => '10.00000']]),
        ]);

        $this->assertSame(['2026-01' => '328.00', '2026-02' => '656.00'], $result['savings']);
        $this->assertSame('2256.00', $result['comparison']['2026-02']['unique']['current_contract_cost']);
    }

    public function test_blue_uses_the_same_reference_rate_in_both_scenarios_for_each_time_slot(): void
    {
        $result = $this->calculate([
            $this->invoice('2026-01', 'blue', [
                'peak' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '20.00000'],
                'off_peak' => ['contracted' => '200.000', 'measured' => '180.000', 'rate' => '6.00000'],
            ]),
            $this->invoice('2026-02', 'blue', [
                'peak' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '10.00000'],
                'off_peak' => ['contracted' => '200.000', 'measured' => '180.000', 'rate' => '3.00000'],
            ]),
        ]);

        $this->assertSame(['peak' => '77.000', 'off_peak' => '172.000'], $result['optimal']);
        $this->assertSame(['2026-01' => '426.40', '2026-02' => '426.40'], $result['savings']);
        $this->assertSame('6.00000', $result['comparison']['2026-01']['off_peak']['reference_tariff']);
        $this->assertSame('1178.40', $result['comparison']['2026-01']['off_peak']['current_contract_cost']);
        $this->assertSame('1080.00', $result['comparison']['2026-01']['off_peak']['optimal_contract_cost']);
    }

    public function test_twelve_month_tariff_drop_is_not_counted_as_contract_reduction_savings(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/demand-tariff-change-blue.json')), true, flags: JSON_THROW_ON_ERROR);
        $invoices = array_map(fn (array $row): ExtractedInvoiceData => $this->invoice($row['month'], 'blue', $row['slots']), $fixture);
        $result = $this->calculate($invoices);

        $this->assertSame(['peak' => '271.000', 'off_peak' => '320.000'], $result['optimal']);
        $total = '0.00';
        foreach ($result['savings'] as $saving) {
            $total = bcadd($total, $saving, 2);
        }
        $this->assertSame('29098.55', $total);
        $reference = '0.00';
        $simulated = '0.00';
        $historical = '0.00';
        foreach ($result['comparison'] as $slots) {
            foreach ($slots as $comparison) {
                $reference = bcadd($reference, $comparison['current_contract_cost'], 2);
                $simulated = bcadd($simulated, $comparison['optimal_contract_cost'], 2);
                $historical = bcadd($historical, $comparison['historical_invoiced_cost'], 2);
            }
        }
        $this->assertSame('262554.63', $reference);
        $this->assertSame('233456.08', $simulated);
        $this->assertSame('225759.17', $historical);
    }

    public function test_current_registered_tariff_takes_priority_and_is_converted_with_invoice_taxes(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6));
        $tariff = $this->tariff('8.00000', false);
        $taxedAlternative = $tariff->replicate();
        $taxedAlternative->fill(['includes_taxes' => true, 'rate' => '99.00000'])->save();
        $invoices = [
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '25.00000']]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '12.50000', 'untaxed_rate' => '10.00000', 'icms_rate' => '0.1800']]),
        ];

        $result = $this->calculate($invoices, $tariff->distributor_id);
        $reference = $result['comparison']['2026-01']['unique'];
        $this->assertSame('10.00000', $reference['reference_tariff']);
        $this->assertSame('1.250000', $reference['reference_tax_factor']);
        $this->assertSame('8.00000', $reference['reference_tariff_untaxed']);
        $this->assertSame('registered_tariff', $reference['tariff_reference_source']);
        $this->assertSame('2026-11', $reference['tariff_reference_month']);
        $this->assertSame('2026-02', $reference['tax_reference_month']);
        $this->assertSame([$tariff->id], $result['tariff_ids']);
        $this->assertSame(['2026-01' => '164.00', '2026-02' => '164.00'], $result['savings']);
    }

    public function test_taxed_registered_tariff_is_not_taxed_again(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6));
        $tariff = $this->tariff('10.00000', true);
        $result = $this->calculate([
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '25.00000']]),
        ], $tariff->distributor_id);

        $this->assertSame('10.00000', $result['comparison']['2026-02']['unique']['reference_tariff']);
        $this->assertSame('164.00', $result['savings']['2026-02']);
    }

    public function test_expired_and_future_tariffs_do_not_replace_the_series_maximum(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6));
        $tariff = $this->tariff('8.00000', true);
        $tariff->update(['valid_from' => '2026-01-01', 'valid_to' => '2026-10-31']);
        $future = $tariff->replicate();
        $future->fill(['valid_from' => '2026-12-01', 'valid_to' => null])->save();
        $result = $this->calculate([
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '25.00000']]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '12.50000']]),
        ], $tariff->distributor_id);

        $this->assertSame('25.00000', $result['comparison']['2026-02']['unique']['reference_tariff']);
        $this->assertSame('series_maximum', $result['comparison']['2026-02']['unique']['tariff_reference_source']);
        $this->assertSame([], $result['tariff_ids']);
    }

    public function test_open_ended_old_registration_is_not_assumed_to_be_an_updated_tariff(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6));
        $tariff = $this->tariff('8.00000', true);
        $tariff->update(['valid_from' => '2025-07-04', 'valid_to' => null]);
        $result = $this->calculate([
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '25.00000']]),
        ], $tariff->distributor_id);

        $this->assertSame('series_maximum', $result['comparison']['2026-02']['unique']['tariff_reference_source']);
        $this->assertSame([], $result['tariff_ids']);
    }

    public function test_missing_tax_calibration_uses_the_series_maximum_instead_of_an_untaxed_tariff(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6));
        $tariff = $this->tariff('8.00000', false);
        $result = $this->calculate([
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '25.00000']]),
        ], $tariff->distributor_id);

        $this->assertSame('25.00000', $result['comparison']['2026-02']['unique']['reference_tariff']);
        $this->assertSame('series_maximum', $result['comparison']['2026-02']['unique']['tariff_reference_source']);
        $this->assertSame([], $result['tariff_ids']);
    }

    public function test_each_slot_uses_its_own_maximum_even_when_the_invoice_amount_is_lower(): void
    {
        $result = $this->calculate([
            $this->invoice('2026-01', 'blue', [
                'peak' => ['contracted' => '100.000', 'measured' => '10.000', 'rate' => '20.00000'],
                'off_peak' => ['contracted' => '200.000', 'measured' => '180.000', 'rate' => '3.00000'],
            ]),
            $this->invoice('2026-02', 'blue', [
                'peak' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '10.00000'],
                'off_peak' => ['contracted' => '200.000', 'measured' => '180.000', 'rate' => '6.00000'],
            ]),
        ]);

        $this->assertSame('20.00000', $result['comparison']['2026-02']['peak']['reference_tariff']);
        $this->assertSame('2026-01', $result['comparison']['2026-02']['peak']['tariff_reference_month']);
        $this->assertSame('6.00000', $result['comparison']['2026-01']['off_peak']['reference_tariff']);
        $this->assertSame('2026-02', $result['comparison']['2026-01']['off_peak']['tariff_reference_month']);
    }

    public function test_flag_charges_are_not_considered_demand_rates_and_ties_use_the_latest_month(): void
    {
        $flag = [
            'item_type' => 'other_charge', 'tariff_component' => 'not_applicable', 'time_slot' => 'not_applicable',
            'reactive_nature' => 'not_applicable', 'quantity' => '100.000', 'unit' => 'kWh',
            'unit_price_taxed' => '999.00000', 'tariff_rate' => null, 'amount' => '99900.00',
            'pis_cofins_amount' => '0.00', 'icms_base' => '0.00', 'icms_rate' => '0.0000', 'icms_amount' => '0.00',
            'source_label' => 'ADIC. BANDEIRA TARIFARIA',
        ];
        $result = $this->calculate([
            $this->invoice('2026-01', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '10.00000']], [$flag]),
            $this->invoice('2026-02', 'green', ['unique' => ['contracted' => '100.000', 'measured' => '80.000', 'rate' => '10.00000']]),
        ]);

        $this->assertSame('10.00000', $result['comparison']['2026-01']['unique']['reference_tariff']);
        $this->assertSame('2026-02', $result['comparison']['2026-01']['unique']['tariff_reference_month']);
        $this->assertSame('164.00', $result['savings']['2026-01']);
    }

    private function tariff(string $rate, bool $includesTaxes): Tariff
    {
        $distributor = Distributor::create(['name' => 'ENEL SP', 'state' => 'SP', 'parser_key' => 'enel', 'active' => true]);

        return Tariff::create([
            'distributor_id' => $distributor->id, 'subgroup' => 'A4', 'tariff_modality' => 'green',
            'component' => 'demand', 'time_slot' => 'single', 'rate' => $rate, 'includes_taxes' => $includesTaxes,
            'source' => 'aneel_import', 'source_reference' => 'Test reference', 'valid_from' => '2026-11-01', 'valid_to' => '2027-10-31',
        ]);
    }

    /** @param list<ExtractedInvoiceData> $invoices */
    private function calculate(array $invoices, ?int $distributorId = null): array
    {
        $months = array_map(fn (ExtractedInvoiceData $invoice): DemandMonthData => DemandMonthData::fromInvoice($invoice, $invoice->referenceMonth), $invoices);
        $entries = [];
        foreach ($months as $month) {
            foreach (array_keys($month->contractedDemandByTimeSlot) as $slot) {
                $entries[] = new IdleDemandRatioEntry($month->referenceMonth, $slot, '0.820000', 'taxation', false, null);
            }
        }

        return app(ReportDemandOptimizationService::class)->calculate($invoices, $months, new IdleDemandRatioResolution($entries), $distributorId);
    }

    /** @param array<string, array<string, string>> $slots */
    private function invoice(string $month, string $modality, array $slots, array $otherItems = []): ExtractedInvoiceData
    {
        $items = [];
        foreach ($slots as $slot => $values) {
            $normal = $values['normal_amount'] ?? bcadd(bcmul($values['measured'], $values['rate'], 10), '0.005', 2);
            $idleQuantity = bcsub($values['contracted'], $values['measured'], 3);
            $idle = $values['idle_amount'] ?? bcadd(bcmul(bcmul($idleQuantity, $values['rate'], 10), '0.820000', 10), '0.005', 2);
            foreach ([
                ['contracted_demand', $values['contracted'], null, '0.00'],
                ['measured_demand', $values['measured'], $values['rate'], $normal],
                ['idle_demand', $idleQuantity, null, $idle],
            ] as [$type, $quantity, $rate, $amount]) {
                $items[] = [
                    'item_type' => $type, 'tariff_component' => 'demand', 'time_slot' => $slot, 'reactive_nature' => 'not_applicable',
                    'quantity' => $quantity, 'unit' => 'kW', 'unit_price_taxed' => $rate, 'tariff_rate' => $type === 'measured_demand' ? ($values['untaxed_rate'] ?? null) : null, 'amount' => $amount,
                    'pis_cofins_amount' => '0.00', 'icms_base' => '0.00', 'icms_rate' => $values['icms_rate'] ?? '0.0000', 'icms_amount' => '0.00',
                ];
            }
        }

        return ExtractedInvoiceData::fromArray([
            'reference_month' => $month, 'consumer_unit' => 'test-unit', 'subgroup' => 'A4', 'tariff_modality' => $modality,
            'total_paid' => '0.00', 'items' => array_merge($items, $otherItems),
        ]);
    }
}
