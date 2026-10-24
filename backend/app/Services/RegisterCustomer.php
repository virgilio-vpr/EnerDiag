<?php

namespace App\Services;

use App\Mail\EmailVerificationCode;
use App\Models\Consent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class RegisterCustomer
{
    /**
     * Create the customer, consent, profile, and initial email verification code.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, ?string $ipAddress): User
    {
        $verificationCode = (string) random_int(100000, 999999);

        $user = DB::transaction(function () use ($validated, $ipAddress, $verificationCode): User {
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

            $user->verificationCodes()->create([
                'code_hash' => Hash::make($verificationCode),
                'expires_at' => now()->addMinutes(15),
                'attempts' => 0,
            ]);

            return $user;
        });

        Mail::to($user->email)->send(new EmailVerificationCode($verificationCode));

        return $user;
    }
}
