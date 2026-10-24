<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContactRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_request_requires_authentication(): void
    {
        $this->postJson('/api/v1/me/contact-request')->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_contact_request_records_a_persistent_timestamp(): void
    {
        $user = User::factory()->create([
            'password_hash' => Hash::make('CorrectPassword2026'),
            'contact_requested_at' => null,
        ]);
        $simulation = $user->simulations()->create(['status' => 'completed']);
        $token = $user->createToken('contact-request-test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/me/contact-request');

        $response->assertOk()
            ->assertJsonPath('message', 'Solicitação de contato registrada.');
        $contactRequestedAt = $response->json('contact_requested_at');
        $this->assertNotNull($contactRequestedAt);
        $this->assertSame($contactRequestedAt, $user->fresh()->contact_requested_at->toIso8601String());

        $user->simulations()->whereKey($simulation->id)->delete();

        $this->assertNotNull($user->fresh()->contact_requested_at);
        $this->assertDatabaseMissing('simulations', ['id' => $simulation->id]);
    }

    public function test_repeated_contact_request_preserves_the_original_timestamp(): void
    {
        $user = User::factory()->create(['contact_requested_at' => now()->subDay()]);
        $originalTimestamp = $user->contact_requested_at->toIso8601String();
        $token = $user->createToken('contact-request-test')->plainTextToken;

        $this->travel(1)->day();
        $this->withToken($token)->postJson('/api/v1/me/contact-request')
            ->assertOk()
            ->assertJsonPath('contact_requested_at', $originalTimestamp);

        $this->assertSame($originalTimestamp, $user->fresh()->contact_requested_at->toIso8601String());
    }
}
