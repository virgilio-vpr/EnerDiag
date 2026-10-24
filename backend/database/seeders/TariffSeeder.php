<?php

namespace Database\Seeders;

use App\Models\Distributor;
use App\Models\Tariff;
use Illuminate\Database\Seeder;

class TariffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $distributor = Distributor::where('parser_key', 'enel')->first();

        if (! $distributor) {
            return;
        }

        $validFrom = '2025-07-04';

        $tariffs = [
            // A4 - Azul - com tributos (includes_taxes = true)
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'demand', 'rate' => '37.80322', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'demand', 'rate' => '21.98516', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'energy_tusd', 'rate' => '0.16134', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'energy_tusd', 'rate' => '0.16134', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'energy_te', 'rate' => '0.56657', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'energy_te', 'rate' => '0.35293', 'includes_taxes' => true, 'source' => 'manual'],

            // A4 - Azul - sem tributos (includes_taxes = false, base ANEEL)
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'demand', 'rate' => '29.30000', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'demand', 'rate' => '17.04000', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'energy_tusd', 'rate' => '0.12505', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'energy_tusd', 'rate' => '0.12505', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'peak', 'component' => 'energy_te', 'rate' => '0.43913', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'blue', 'time_slot' => 'off_peak', 'component' => 'energy_te', 'rate' => '0.27354', 'includes_taxes' => false, 'source' => 'aneel_import'],

            // A4 - Verde - com tributos (includes_taxes = true)
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'single', 'component' => 'demand', 'rate' => '21.98516', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'peak', 'component' => 'energy_tusd', 'rate' => '1.45020', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'off_peak', 'component' => 'energy_tusd', 'rate' => '0.16134', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'peak', 'component' => 'energy_te', 'rate' => '0.56657', 'includes_taxes' => true, 'source' => 'manual'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'off_peak', 'component' => 'energy_te', 'rate' => '0.35293', 'includes_taxes' => true, 'source' => 'manual'],

            // A4 - Verde - sem tributos (includes_taxes = false, base ANEEL)
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'single', 'component' => 'demand', 'rate' => '17.04000', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'peak', 'component' => 'energy_tusd', 'rate' => '1.12398', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'off_peak', 'component' => 'energy_tusd', 'rate' => '0.12505', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'peak', 'component' => 'energy_te', 'rate' => '0.43913', 'includes_taxes' => false, 'source' => 'aneel_import'],
            ['subgroup' => 'A4', 'tariff_modality' => 'green', 'time_slot' => 'off_peak', 'component' => 'energy_te', 'rate' => '0.27354', 'includes_taxes' => false, 'source' => 'aneel_import'],
        ];

        foreach ($tariffs as $tariff) {
            Tariff::updateOrCreate(
                [
                    'distributor_id' => $distributor->id,
                    'subgroup' => $tariff['subgroup'],
                    'tariff_modality' => $tariff['tariff_modality'],
                    'time_slot' => $tariff['time_slot'],
                    'component' => $tariff['component'],
                    'includes_taxes' => $tariff['includes_taxes'],
                    'valid_from' => $validFrom,
                ],
                array_merge($tariff, [
                    'distributor_id' => $distributor->id,
                    'valid_from' => $validFrom,
                    'valid_to' => null,
                    'source_reference' => 'REH 3.345/2025',
                ])
            );
        }
    }
}
