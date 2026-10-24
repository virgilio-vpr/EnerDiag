<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StoreLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_interest_in_unsupported_distributor(): void
    {
        $response = $this->postJson('/api/v1/leads', [
            'email' => 'cliente@empresa.com.br',
            'distributor_name' => 'Distribuidora Regional',
            'reason' => 'unsupported_distributor',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'cliente@empresa.com.br')
            ->assertJsonPath('data.distributor_name', 'Distribuidora Regional')
            ->assertJsonPath('data.reason', 'unsupported_distributor');

        $this->assertDatabaseHas('leads', ['id' => $response->json('data.id'), 'email' => 'cliente@empresa.com.br']);
    }

    public function test_lead_email_must_be_valid(): void
    {
        $this->postJson('/api/v1/leads', [
            'email' => 'not-an-email',
            'distributor_name' => 'Distribuidora Regional',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_lead_endpoint_limits_requests_per_ip(): void
    {
        RateLimiter::clear('127.0.0.1');
        $payload = ['email' => 'cliente@empresa.com.br', 'distributor_name' => 'Distribuidora Regional'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/leads', $payload)->assertCreated();
        }
        $this->postJson('/api/v1/leads', $payload)->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->assertDatabaseCount('leads', 5);
    }
}
