<?php

namespace Database\Seeders;

use App\Models\Distributor;
use Illuminate\Database\Seeder;

class DistributorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Distributor::updateOrCreate(
            ['parser_key' => 'enel'],
            [
                'name' => 'ENEL SP',
                'state' => 'SP',
                'active' => true,
            ]
        );
    }
}
