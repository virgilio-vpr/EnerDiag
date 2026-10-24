<?php

namespace App\Services;

use App\Models\Consent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterCustomer
{
    /**
     * Create the customer, consent, profile, and initial email verification code.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, ?string $ipAddress): User
    {
        $verificationCode = '';

        $verification = app(EmailVerificationService::class);

        $user = DB::transaction(function () use ($validated, $ipAddress, $verification, &$verificationCode): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password_hash' => Hash::make($validated['password']),
                'role' => 'customer',
                'email_verified_at' => null,
            ]);

            CustomerProfile::create([
                'user_id' => $user->id,
                'company_name' => $validated['company_name'],
                'cnpj' => $validated['cnpj'] ?? null,
                'distributor_id' => $validated['distributor_id'] ?? null,
                'transformer_power_kva' => $validated['transformer_power_kva'] ?? null,
                'address_details' => $validated['address_details'] ?? null,
                'substation_same_address' => $validated['substation_same_address'],
                'substation_address' => $validated['substation_address'] ?? null,
            ]);

            Consent::create([
                'user_id' => $user->id,
                'terms_version' => config('enerdiag.terms_version'),
                'accepted_at' => now(),
                'ip_address' => $ipAddress,
            ]);

            $verificationCode = $verification->storeCode($user);

            return $user;
        });

        $verification->sendCode($user, $verificationCode);

        return $user;
    }
}
