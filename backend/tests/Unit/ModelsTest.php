<?php

namespace Tests\Unit;

use App\Models\Consent;
use App\Models\CustomerProfile;
use App\Models\Distributor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\QuotationBase;
use App\Models\RegulatoryParameter;
use App\Models\Report;
use App\Models\ReportRevision;
use App\Models\Simulation;
use App\Models\Tariff;
use App\Models\TariffImport;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relationships(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '11999999999',
            'password_hash' => 'secret123',
            'role' => 'customer',
        ]);

        $distributor = Distributor::create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);

        $profile = CustomerProfile::create([
            'user_id' => $user->id,
            'company_name' => 'Acme Corp',
            'cnpj' => '12.345.678/0001-90',
            'distributor_id' => $distributor->id,
            'transformer_power_kva' => '150.00',
        ]);

        $consent = Consent::create([
            'user_id' => $user->id,
            'terms_version' => '1.0',
            'accepted_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);

        $simulation = Simulation::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'months_count' => 12,
            'total_monthly_savings' => '1500.50',
            'total_yearly_savings' => '18006.00',
        ]);

        $code = VerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $import = TariffImport::create([
            'file_name' => 'aneel_tariffs.csv',
            'imported_by' => $user->id,
            'status' => 'completed',
        ]);

        $report = Report::create([
            'simulation_id' => $simulation->id,
            'total_monthly_savings' => '1500.50',
            'total_yearly_savings' => '18006.00',
            'savings_percentage' => '15.25',
        ]);

        $revision = ReportRevision::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'reason' => 'Texto atualizado',
        ]);

        $this->assertTrue($user->profile->is($profile));
        $this->assertTrue($user->consents->first()->is($consent));
        $this->assertTrue($user->simulations->first()->is($simulation));
        $this->assertTrue($user->verificationCodes->first()->is($code));
        $this->assertTrue($user->tariffImports->first()->is($import));
        $this->assertTrue($user->reportRevisions->first()->is($revision));

        $this->assertTrue($profile->user->is($user));
        $this->assertTrue($profile->distributor->is($distributor));
        $this->assertTrue($distributor->customerProfiles->first()->is($profile));
        $this->assertTrue($consent->user->is($user));
        $this->assertTrue($import->importedBy->is($user));
        $this->assertTrue($simulation->user->is($user));
        $this->assertTrue($code->user->is($user));
    }

    public function test_distributor_and_tariff_relationships(): void
    {
        $distributor = Distributor::create([
            'name' => 'ENEL SP',
            'state' => 'SP',
            'parser_key' => 'enel',
            'active' => true,
        ]);

        $tariff = Tariff::create([
            'distributor_id' => $distributor->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'time_slot' => 'peak',
            'component' => 'demand',
            'rate' => '37.80322',
            'includes_taxes' => true,
            'valid_from' => '2025-07-04',
        ]);

        $this->assertTrue($distributor->tariffs->first()->is($tariff));
        $this->assertTrue($tariff->distributor->is($distributor));
    }

    public function test_simulation_invoice_and_report_relationships(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'client@example.com',
            'password_hash' => 'secret123',
        ]);

        $simulation = Simulation::create([
            'user_id' => $user->id,
            'status' => 'queued',
        ]);

        $invoice = Invoice::create([
            'simulation_id' => $simulation->id,
            'reference_month' => '2025-08',
            'consumer_unit' => '12345678',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => '98922.22',
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'measured_demand',
            'tariff_component' => 'demand',
            'time_slot' => 'peak',
            'quantity' => '221.760',
            'unit' => 'kW',
            'unit_price_taxed' => '37.80322',
            'tariff_rate' => '29.30000',
            'amount' => '8383.25',
        ]);

        $report = Report::create([
            'simulation_id' => $simulation->id,
            'total_monthly_savings' => '1200.00',
            'total_yearly_savings' => '14400.00',
            'savings_percentage' => '12.50',
        ]);

        $revision = ReportRevision::create([
            'report_id' => $report->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($simulation->invoices->first()->is($invoice));
        $this->assertTrue($invoice->simulation->is($simulation));
        $this->assertTrue($invoice->items->first()->is($item));
        $this->assertTrue($item->invoice->is($invoice));
        $this->assertTrue($simulation->report->is($report));
        $this->assertTrue($report->simulation->is($simulation));
        $this->assertTrue($report->revisions->first()->is($revision));
        $this->assertTrue($revision->report->is($report));
    }

    public function test_decimal_casts_maintain_exact_precision_without_float(): void
    {
        $quotation = QuotationBase::create([
            'item_key' => 'consulting_hour',
            'name' => 'Hora de Consultoria Técnica',
            'unit' => 'hour',
            'unit_price' => 150.5,
        ]);

        $this->assertIsString($quotation->unit_price);
        $this->assertSame('150.50', $quotation->unit_price);

        $parameter = RegulatoryParameter::create([
            'key' => 'overrun_tolerance',
            'value' => 0.05,
            'valid_from' => '2026-01-01',
        ]);

        $this->assertIsString($parameter->value);
        $this->assertSame('0.05000', $parameter->value);

        $distributor = Distributor::create([
            'name' => 'ENEL',
            'state' => 'SP',
            'parser_key' => 'enel',
        ]);

        $tariff = Tariff::create([
            'distributor_id' => $distributor->id,
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'component' => 'demand',
            'rate' => 37.80322,
            'valid_from' => '2025-07-04',
        ]);

        $this->assertIsString($tariff->rate);
        $this->assertSame('37.80322', $tariff->rate);

        $user = User::create([
            'name' => 'Client',
            'email' => 'client2@example.com',
            'password_hash' => 'hash',
        ]);

        $simulation = Simulation::create([
            'user_id' => $user->id,
            'total_monthly_savings' => 1234.56,
            'total_yearly_savings' => 14814.72,
        ]);

        $this->assertIsString($simulation->total_monthly_savings);
        $this->assertSame('1234.56', $simulation->total_monthly_savings);
        $this->assertIsString($simulation->total_yearly_savings);
        $this->assertSame('14814.72', $simulation->total_yearly_savings);

        $invoice = Invoice::create([
            'simulation_id' => $simulation->id,
            'reference_month' => '2025-08',
            'consumer_unit' => '98765432',
            'subgroup' => 'A4',
            'tariff_modality' => 'blue',
            'total_paid' => 98922.22,
        ]);

        $this->assertIsString($invoice->total_paid);
        $this->assertSame('98922.22', $invoice->total_paid);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'measured_demand',
            'quantity' => 221.76,
            'unit_price_taxed' => 37.80322,
            'tariff_rate' => 29.3,
            'amount' => 8383.25,
            'pis_cofins_amount' => 4452.11,
            'icms_base' => 16637.37,
            'icms_rate' => 0.18,
            'icms_amount' => 2994.73,
        ]);

        $this->assertIsString($item->quantity);
        $this->assertSame('221.760', $item->quantity);
        $this->assertIsString($item->unit_price_taxed);
        $this->assertSame('37.80322', $item->unit_price_taxed);
        $this->assertIsString($item->tariff_rate);
        $this->assertSame('29.30000', $item->tariff_rate);
        $this->assertIsString($item->amount);
        $this->assertSame('8383.25', $item->amount);
        $this->assertIsString($item->pis_cofins_amount);
        $this->assertSame('4452.11', $item->pis_cofins_amount);
        $this->assertIsString($item->icms_base);
        $this->assertSame('16637.37', $item->icms_base);
        $this->assertIsString($item->icms_rate);
        $this->assertSame('0.1800', $item->icms_rate);
        $this->assertIsString($item->icms_amount);
        $this->assertSame('2994.73', $item->icms_amount);
    }
}
