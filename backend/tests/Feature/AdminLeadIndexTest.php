<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLeadIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_both_lead_types_in_a_paginated_list(): void
    {
        Lead::query()->create([
            'email' => 'lead@example.com',
            'distributor_name' => 'Distribuidora Y',
            'reason' => 'Não atendida',
            'created_at' => now()->subDay(),
        ]);
        User::factory()->create([
            'email' => 'contact@example.com',
            'contact_requested_at' => now()->addDays(2),
        ]);
        User::factory()->create(['contact_requested_at' => null]);
        $adminToken = User::factory()->create(['role' => 'admin'])->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)->getJson('/api/v1/admin/leads?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.type', 'contact_request')
            ->assertJsonPath('data.0.email', 'contact@example.com');

        $this->withToken($adminToken)->getJson('/api/v1/admin/leads?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['type' => 'unsupported_distributor', 'distributor_name' => 'Distribuidora Y'])
            ->assertJsonFragment(['type' => 'contact_request', 'email' => 'contact@example.com']);
    }

    public function test_lead_list_requires_admin_role(): void
    {
        $this->getJson('/api/v1/admin/leads')->assertUnauthorized();
        $customerToken = User::factory()->create()->createToken('customer')->plainTextToken;

        $this->withToken($customerToken)->getJson('/api/v1/admin/leads')->assertForbidden();
    }
}
