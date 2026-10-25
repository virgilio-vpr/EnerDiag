<?php

namespace Tests\Feature;

use App\Mail\QuotationReleased;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\QuotationBase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminQuotationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_creates_a_decimal_draft_and_can_preview_pdf_without_sending_email(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        CustomerProfile::query()->create([
            'user_id' => $customer->id,
            'company_name' => 'Empresa Cliente Ltda.',
            'cnpj' => '12.345.678/0001-90',
            'address_details' => 'Rua das Flores, 10, São Paulo/SP',
        ]);
        $baseItem = $this->baseItem('consulting_hour', 'Hora técnica', 'hour', '250.00');
        $roundingItem = $this->baseItem('fractional_hour', 'Serviço fracionado', 'unit', '0.05');
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/admin/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                ['quotation_base_id' => $baseItem->id, 'quantity' => '1.250'],
                ['quotation_base_id' => $roundingItem->id, 'quantity' => '0.333'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.quantity', '1.250')
            ->assertJsonPath('data.items.0.total', '312.50')
            ->assertJsonPath('data.items.1.total', '0.02')
            ->assertJsonPath('data.total', '312.52');

        $quotationId = $response->json('data.id');
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'customer_name' => 'Ana Souza',
            'company_name' => 'Empresa Cliente Ltda.',
            'cnpj' => '12.345.678/0001-90',
            'status' => 'draft',
        ]);
        $baseItem->update(['unit_price' => '999.99']);
        $this->assertSame('250.00', $response->json('data.items.0.unit_price'));
        Mail::assertNothingSent();

        $this->withToken($token)->getJson('/api/v1/admin/quotations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.quote_number', $response->json('data.quote_number'));

        $pdfResponse = $this->withToken($token)->get('/api/v1/admin/quotations/'.$quotationId.'/pdf');
        $pdfResponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdfResponse->getContent());
        $this->assertStringContainsString('TOTAL GERAL', $pdfResponse->getContent());
        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $pdfResponse->getContent());
        $this->assertStringContainsString('1 de 1', $pdfResponse->getContent());
        Mail::assertNothingSent();
    }

    public function test_admin_release_requires_engineer_crea_and_art_then_sends_the_pdf_once(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['email' => 'cliente@example.com']);
        $baseItem = $this->baseItem('analyzer_day', 'Diária do analisador', 'day', '450.00');
        $quotation = $this->createDraft($admin, $customer, $baseItem);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/quotations/'.$quotation->id.'/release', [
            'technical_responsible_name' => 'Engenheira Exemplo',
        ])->assertUnprocessable()->assertJsonValidationErrors(['crea_registration', 'art_number']);
        Mail::assertNothingSent();

        $this->withToken($token)->postJson('/api/v1/admin/quotations/'.$quotation->id.'/release', [
            'technical_responsible_name' => 'Engenheira Exemplo',
            'crea_registration' => 'CREA-SP 1234567890',
            'art_number' => 'ART 28027230261000001',
        ])->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.valid_until', '2026-11-23');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'sent',
            'technical_responsible_name' => 'Engenheira Exemplo',
            'crea_registration' => 'CREA-SP 1234567890',
            'art_number' => 'ART 28027230261000001',
        ]);
        Mail::assertSent(QuotationReleased::class, function (QuotationReleased $mail): bool {
            return $mail->hasTo('cliente@example.com') && count($mail->attachments()) === 1;
        });

        $this->withToken($token)->postJson('/api/v1/admin/quotations/'.$quotation->id.'/release', [
            'technical_responsible_name' => 'Engenheira Exemplo',
            'crea_registration' => 'CREA-SP 1234567890',
            'art_number' => 'ART 28027230261000001',
        ])->assertUnprocessable();
        Mail::assertSent(QuotationReleased::class, 1);
    }

    public function test_quotation_endpoints_are_restricted_to_admin_and_validate_quote_inputs(): void
    {
        $customer = User::factory()->create();
        $baseItem = $this->baseItem('design_hour', 'Projeto de banco de capacitores', 'hour', '200.00');
        $payload = ['customer_id' => $customer->id, 'items' => [['quotation_base_id' => $baseItem->id, 'quantity' => '0']]];

        $this->getJson('/api/v1/admin/quotations')->assertUnauthorized();
        $this->postJson('/api/v1/admin/quotations', $payload)->assertUnauthorized();

        $customerToken = $customer->createToken('customer')->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/quotations')->assertForbidden();
        $this->withToken($customerToken)->postJson('/api/v1/admin/quotations', $payload)->assertForbidden();
        $this->withToken($customerToken)->get('/api/v1/admin/quotations/1/pdf')->assertForbidden();
        $this->withToken($customerToken)->postJson('/api/v1/admin/quotations/1/release', [])->assertForbidden();

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_invalid_quote_quantity_is_rejected_for_admin(): void
    {
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;
        $baseItem = $this->baseItem('design_hour', 'Projeto de banco de capacitores', 'hour', '200.00');

        $this->withToken($adminToken)->postJson('/api/v1/admin/quotations', [
            'customer_id' => User::factory()->create()->id,
            'items' => [['quotation_base_id' => $baseItem->id, 'quantity' => '0']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('quotations', 0);
    }

    private function baseItem(string $key, string $name, string $unit, string $price): QuotationBase
    {
        return QuotationBase::query()->create([
            'item_key' => $key,
            'name' => $name,
            'unit' => $unit,
            'unit_price' => $price,
        ]);
    }

    private function createDraft(User $admin, User $customer, QuotationBase $baseItem): Quotation
    {
        $token = $admin->createToken('admin')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/admin/quotations', [
            'customer_id' => $customer->id,
            'items' => [['quotation_base_id' => $baseItem->id, 'quantity' => '2.000']],
        ])->assertCreated();

        return Quotation::query()->findOrFail($response->json('data.id'));
    }
}
