<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CustomerProfilePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_access_admin_profile_listing(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $authorization = Gate::forUser($customer)->inspect('viewAny', CustomerProfile::class);

        $this->assertTrue($authorization->denied());

        try {
            Gate::forUser($customer)->authorize('viewAny', CustomerProfile::class);
            $this->fail('Expected the customer policy to deny access.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }
    }

    public function test_customer_can_view_and_update_only_own_profile(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $ownProfile = $this->createProfile($customer);
        $otherProfile = $this->createProfile($otherCustomer);

        $this->assertTrue(Gate::forUser($customer)->allows('view', $ownProfile));
        $this->assertTrue(Gate::forUser($customer)->allows('update', $ownProfile));
        $this->assertFalse(Gate::forUser($customer)->allows('view', $otherProfile));
        $this->assertFalse(Gate::forUser($customer)->allows('update', $otherProfile));
    }

    public function test_admin_can_list_and_access_any_customer_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $profile = $this->createProfile($customer);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', CustomerProfile::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $profile));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $profile));
    }

    public function test_another_customers_profile_is_denied_as_not_found(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $otherProfile = $this->createProfile($otherCustomer);

        $response = Gate::forUser($customer)->inspect('view', $otherProfile);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    private function createProfile(User $user): CustomerProfile
    {
        return CustomerProfile::create([
            'user_id' => $user->id,
            'company_name' => 'Empresa '.$user->id,
        ]);
    }
}
