<?php

namespace Database\Seeders;

use App\Models\RegulatoryParameter;
use Illuminate\Database\Seeder;

class RegulatoryParameterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $parameters = [
            [
                'key' => 'overrun_tolerance',
                'value' => '0.05000',
                'state' => null,
                'valid_from' => '2021-01-01',
                'valid_to' => null,
            ],
            [
                'key' => 'overrun_multiplier',
                'value' => '2.00000',
                'state' => null,
                'valid_from' => '2021-01-01',
                'valid_to' => null,
            ],
            [
                'key' => 'reference_power_factor',
                'value' => '0.92000',
                'state' => null,
                'valid_from' => '2021-01-01',
                'valid_to' => null,
            ],
            [
                'key' => 'idle_demand_discount_fallback',
                'value' => '0.18000',
                'state' => null,
                'valid_from' => '2021-01-01',
                'valid_to' => null,
            ],
        ];

        foreach ($parameters as $parameter) {
            RegulatoryParameter::updateOrCreate(
                [
                    'key' => $parameter['key'],
                    'state' => $parameter['state'],
                    'valid_from' => $parameter['valid_from'],
                ],
                $parameter
            );
        }
    }
}
