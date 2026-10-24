<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->putJson('/api/v1/me', ['name' => 'Ana'])->assertUnauthorized();
        $this->deleteJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_customer_can_view_own_account_and_profile(): void
    {
        [$user, $token] = $this->createAuthenticatedCustomer();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.profile.company_name', 'Empresa Inicial')
            ->assertJsonMissingPath('data.password_hash');
    }

    public function test_customer_can_update_allowed_account_and_profile_fields(): void
    {
        [$user, $token] = $this->createAuthenticatedCustomer();

        $this->withToken($token)->putJson('/api/v1/me', [
            'name' => 'Ana Atualizada',
            'phone' => '11988887777',
            'company_name' => 'Empresa Atualizada',
            'substation_same_address' => false,
            'substation_address' => 'Rua da Subestação, 123',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Ana Atualizada')
            ->assertJsonPath('data.phone', '11988887777')
            ->assertJsonPath('data.profile.company_name', 'Empresa Atualizada')
            ->assertJsonPath('data.profile.substation_same_address', false)
            ->assertJsonPath('data.profile.substation_address', 'Rua da Subestação, 123');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ana Atualizada',
            'email' => $user->email,
            'role' => 'customer',
        ]);
    }

    public function test_me_update_ignores_unapproved_identity_fields(): void
    {
        [$user, $token] = $this->createAuthenticatedCustomer();

        $this->withToken($token)->putJson('/api/v1/me', [
            'email' => 'attacker@empresa.com.br',
            'role' => 'admin',
            'password_hash' => 'plain-text-password',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $user->email,
            'role' => 'customer',
        ]);
        $this->assertFalse(Hash::check('plain-text-password', $user->fresh()->password_hash));
    }

    public function test_delete_me_removes_account_and_related_personal_data(): void
    {
        [$user, $token] = $this->createAuthenticatedCustomer();
        $user->consents()->create([
            'terms_version' => '1.0',
            'accepted_at' => now(),
            'ip_address' => '127.0.0.1',
        ]);
        $user->verificationCodes()->create([
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
        ]);
        $simulation = $user->simulations()->create(['status' => 'completed']);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('single-use-token'),
            'created_at' => now(),
        ]);

        $this->withToken($token)->deleteJson('/api/v1/me')->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('customer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('consents', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('verification_codes', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('simulations', ['id' => $simulation->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    /**
     * @return array{User, string}
     */
    private function createAuthenticatedCustomer(): array
    {
        $user = User::factory()->create([
            'name' => 'Ana Souza',
            'email' => 'ana@empresa.com.br',
            'password_hash' => Hash::make('CorrectPassword2026'),
        ]);

        CustomerProfile::create([
            'user_id' => $user->id,
            'company_name' => 'Empresa Inicial',
        ]);

        $token = $user->createToken('me-test-token')->plainTextToken;

        return [$user, $token];
    }
}
