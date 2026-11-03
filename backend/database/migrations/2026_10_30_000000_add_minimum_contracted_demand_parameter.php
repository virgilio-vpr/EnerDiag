<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('regulatory_parameters')->where('key', 'minimum_contracted_demand')->exists()) {
            DB::table('regulatory_parameters')->insert([
                'key' => 'minimum_contracted_demand',
                'value' => '30.00000',
                'state' => null,
                'valid_from' => '2022-01-03',
                'valid_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Parameter history is retained when rolling back.
    }
};
