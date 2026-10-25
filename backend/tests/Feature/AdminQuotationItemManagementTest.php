<?php

namespace Tests\Feature;

use App\Models\QuotationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminQuotationItemManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_create_and_update_quotation_items(): void
    {
        $token = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;
        $item = QuotationBase::query()->create([
            'item_key' => 'consulting_hour',
            'name' => 'Hora técnica de consultoria',
            'description' => 'Consultoria energética',
            'unit' => 'hour',
            'unit_price' => '250.00',
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/quotation-items')
            ->assertOk()
            ->assertJsonPath('data.0.item_key', 'consulting_hour')
            ->assertJsonPath('data.0.unit_price', '250.00');

        $this->withToken($token)->postJson('/api/v1/admin/quotation-items', [
            'item_key' => 'analyzer_day',
            'name' => 'Diária do analisador',
            'description' => null,
            'unit' => 'day',
            'unit_price' => '450.00',
        ])->assertCreated()->assertJsonPath('data.item_key', 'analyzer_day');

        $this->withToken($token)->putJson('/api/v1/admin/quotation-items/'.$item->id, [
            'item_key' => 'consulting_hour',
            'name' => 'Hora técnica de consultoria',
            'description' => 'Consultoria atualizada',
            'unit' => 'hour',
            'unit_price' => '275.00',
        ])->assertOk()->assertJsonPath('data.unit_price', '275.00');

        $this->assertSame('275.00', $item->fresh()->unit_price);
        $this->assertDatabaseCount('quotation_base', 2);
    }

    public function test_invalid_quotation_items_are_rejected(): void
    {
        $token = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;
        $payload = [
            'item_key' => 'consulting_hour',
            'name' => 'Hora técnica de consultoria',
            'unit' => 'hour',
            'unit_price' => '250.00',
        ];
        QuotationBase::query()->create($payload);

        $this->withToken($token)->postJson('/api/v1/admin/quotation-items', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('item_key');
        $this->withToken($token)->postJson('/api/v1/admin/quotation-items', [...$payload, 'item_key' => 'analyzer_day', 'unit_price' => '-1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_price');
    }

    public function test_quotation_item_endpoints_are_admin_only(): void
    {
        $this->getJson('/api/v1/admin/quotation-items')->assertUnauthorized();

        $customer = User::factory()->create();
        $token = $customer->createToken('customer')->plainTextToken;
        $item = [
            'item_key' => 'consulting_hour',
            'name' => 'Hora técnica de consultoria',
            'unit' => 'hour',
            'unit_price' => '250.00',
        ];

        $this->withToken($token)->getJson('/api/v1/admin/quotation-items')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admin/quotation-items', $item)->assertForbidden();

        $quotationItem = QuotationBase::query()->create($item);
        $this->withToken($token)->putJson('/api/v1/admin/quotation-items/'.$quotationItem->id, $item)->assertForbidden();
    }
}
