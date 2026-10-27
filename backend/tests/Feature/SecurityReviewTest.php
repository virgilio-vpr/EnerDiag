<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_injection_cannot_authenticate_or_modify_users(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => "' OR 1=1 --",
            'password' => 'Password12345',
        ])->assertUnprocessable();

        $this->withToken($user->createToken('review')->plainTextToken)
            ->getJson('/api/v1/simulations/1%20OR%201=1/status')->assertNotFound();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_varying_email_does_not_bypass_authentication_rate_limit(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "unknown{$index}@example.com",
                'password' => 'Password12345',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'another@example.com',
            'password' => 'Password12345',
        ])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_exception_reporting_does_not_log_personal_data_or_sql_bindings(): void
    {
        config(['app.debug' => false]);
        Log::shouldReceive('error')->once()->with('Application exception', [
            'exception_type' => RuntimeException::class,
        ]);
        Route::get('/api/v1/security-review-failure', function (): void {
            throw new RuntimeException('Customer maria@example.com; password=Secret123; invoice UC-123');
        });

        $this->getJson('/api/v1/security-review-failure')
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Erro interno do servidor.',
                'code' => 'INTERNAL_SERVER_ERROR',
                'errors' => null,
            ]);
    }
}
