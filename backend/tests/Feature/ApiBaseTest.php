<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiBaseTest extends TestCase
{
    public function test_unauthenticated_request_returns_standard_error_format(): void
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'UNAUTHENTICATED',
                'errors' => null,
            ])
            ->assertJsonStructure(['message', 'code', 'errors']);
    }

    public function test_not_found_request_returns_standard_error_format(): void
    {
        $response = $this->getJson('/api/v1/non-existent-endpoint');

        $response->assertStatus(404)
            ->assertJson([
                'code' => 'NOT_FOUND',
                'errors' => null,
            ])
            ->assertJsonStructure(['message', 'code', 'errors']);
    }

    public function test_validation_failure_returns_standard_error_format(): void
    {
        $response = $this->postJson('/api/v1/test-validation', []);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'VALIDATION_ERROR',
            ])
            ->assertJsonValidationErrors(['email'])
            ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
    }

    public function test_cors_blocks_unauthorized_origin_and_allows_frontend_origin(): void
    {
        // Allowed origin returns Access-Control-Allow-Origin header
        $allowedResponse = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->getJson('/api/v1/non-existent');

        $allowedResponse->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

        // Disallowed origin does not return Access-Control-Allow-Origin header
        $disallowedResponse = $this->withHeaders([
            'Origin' => 'http://malicious-site.com',
        ])->getJson('/api/v1/non-existent');

        $disallowedResponse->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/non-existent');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
    }

    public function test_named_rate_limiters_are_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('api'));
        $this->assertNotNull(RateLimiter::limiter('auth'));
        $this->assertNotNull(RateLimiter::limiter('verification_codes'));
        $this->assertNotNull(RateLimiter::limiter('uploads'));
        $this->assertNotNull(RateLimiter::limiter('leads'));
    }
}
