<?php

namespace Database\Seeders;

use App\Models\CustomerProfile;
use App\Models\Distributor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $distributor = Distributor::where('parser_key', 'enel')->first();

        // Admin
        User::updateOrCreate(
            ['email' => 'admin@enerdiag.com.br'],
            [
                'name' => 'Administrador EnerDiag',
                'phone' => '11988887777',
                'password_hash' => Hash::make('Admin123456!'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        // Cliente 1
        $client1 = User::updateOrCreate(
            ['email' => 'cliente1@industriasilva.com.br'],
            [
                'name' => 'Indústria Metalúrgica Silva Ltda',
                'phone' => '11977776666',
                'password_hash' => Hash::make('Cliente123456!'),
                'role' => 'customer',
                'email_verified_at' => now(),
            ]
        );

        CustomerProfile::updateOrCreate(
            ['user_id' => $client1->id],
            [
                'company_name' => 'Indústria Metalúrgica Silva Ltda',
                'cnpj' => '12.345.678/0001-90',
                'distributor_id' => $distributor?->id,
                'transformer_power_kva' => '300.00',
                'address_details' => 'Av. Industrial, 1000 - São Paulo, SP',
                'substation_same_address' => true,
            ]
        );

        // Cliente 2
        $client2 = User::updateOrCreate(
            ['email' => 'cliente2@alimentossaopaulo.com.br'],
            [
                'name' => 'Comércio de Alimentos São Paulo SA',
                'phone' => '11966665555',
                'password_hash' => Hash::make('Cliente123456!'),
                'role' => 'customer',
                'email_verified_at' => now(),
            ]
        );

        CustomerProfile::updateOrCreate(
            ['user_id' => $client2->id],
            [
                'company_name' => 'Comércio de Alimentos São Paulo SA',
                'cnpj' => '98.765.432/0001-10',
                'distributor_id' => $distributor?->id,
                'transformer_power_kva' => '150.00',
                'address_details' => 'Rua do Comércio, 500 - São Paulo, SP',
                'substation_same_address' => true,
            ]
        );
    }
}
