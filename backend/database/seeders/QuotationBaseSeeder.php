<?php

namespace Database\Seeders;

use App\Models\QuotationBase;
use Illuminate\Database\Seeder;

class QuotationBaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            [
                'item_key' => 'consulting_hour',
                'name' => 'Hora técnica de consultoria',
                'description' => 'Hora técnica de consultoria especializada em eficiência energética',
                'unit' => 'hour',
                'unit_price' => '250.00',
            ],
            [
                'item_key' => 'power_analyzer_daily',
                'name' => 'Diária do analisador de qualidade de energia (24h/7d)',
                'description' => 'Locação, instalação e monitoramento contínuo de analisador de qualidade de energia',
                'unit' => 'day',
                'unit_price' => '450.00',
            ],
            [
                'item_key' => 'capacitor_bank_design_hour',
                'name' => 'Hora de projeto de banco de capacitores',
                'description' => 'Dimensionamento e elaboração de projeto executivo para correção de fator de potência',
                'unit' => 'hour',
                'unit_price' => '200.00',
            ],
        ];

        foreach ($items as $item) {
            QuotationBase::updateOrCreate(
                ['item_key' => $item['item_key']],
                $item
            );
        }
    }
}
