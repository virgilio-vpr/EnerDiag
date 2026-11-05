<?php

namespace Tests\Unit;

use App\Models\Report;
use App\Models\Simulation;
use App\Reports\PdfReportGenerator;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PdfReportGeneratorTest extends TestCase
{
    public function test_the_pdf_contains_the_same_indicators_series_warnings_and_recommendations_as_the_report(): void
    {
        $report = $this->report();
        $pdf = app(PdfReportGenerator::class)->generate($report);
        $text = $this->text($pdf);
        foreach ([
            'Seu relatório de energia', 'Resultados calculados para 12 faturas.',
            'Economia mensal média', 'Projeção de economia anual', 'R$ 1.234,56', 'R$ 14.814,72', '12,5%',
            'Economia por componente', 'R$ 100,11', 'R$ 1.201,32', 'R$ 20,22', 'R$ 242,64',
            'R$ 30,33', 'R$ 363,96', 'R$ 40,44', 'R$ 485,28', 'Modalidade recomendada: Verde',
            'Avisos da análise', 'Reserva aplicada à demanda ociosa', 'r = 82%',
            'Demanda ponta: registrada × contratada × ótima', 'Demanda fora de ponta: registrada × contratada × ótima',
            'Registrada (kW)', 'Contratada (kW)', 'Ótima (kW)', '01/2026', '02/2026', '250,125', '338', '270',
            'Economia com reativos por mês', 'Comparação entre Azul e Verde', 'R$ 9.000,00', 'R$ 8.000,00',
            'Próximos passos', 'Conferir o contrato (ponta) e a demanda', 'sem alterar os valores.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('O período inclui faturas do mercado livre (energia ACL).', $text);
        $this->assertStringNotContainsString('free_market_invoice', $text);
        $this->assertStringContainsString('A análise identificou um ponto que merece atenção.', $text);
        $this->assertStringContainsString('demanda \\\\ sem alterar os valores.', $pdf);
        $this->assertSame($pdf, app(PdfReportGenerator::class)->generate($report));
    }

    public function test_unavailable_components_and_missing_chart_values_are_not_represented_as_zero(): void
    {
        $report = $this->report();
        $report->forceFill([
            'savings_percentage' => null,
            'warnings' => ['demand_optimization_unavailable', 'migration_unavailable'],
            'chart_series' => ['demand' => [['reference_month' => '2026-01', 'time_slot' => 'unique', 'measured' => null, 'contracted' => '0.000', 'optimal' => null]]],
            'optimal_demand_single' => null,
            'r_values' => [['reference_month' => '2026-01', 'source' => 'fallback', 'ratio' => null]],
        ]);
        $text = $this->text(app(PdfReportGenerator::class)->generate($report));
        $this->assertStringContainsString('Demanda Indisponível Indisponível', $text);
        $this->assertStringContainsString('Migração de modalidade Indisponível Indisponível', $text);
        $this->assertStringContainsString('01/2026 — 0 —', $text);
        $this->assertStringContainsString('A série de reativos não está disponível neste relatório.', $text);
        $this->assertStringContainsString('A comparação não está disponível sem tarifas válidas', $text);
        $this->assertStringNotContainsString('Reserva aplicada à demanda ociosa', $text);
    }

    public function test_long_content_is_paginated_without_losing_the_last_recommendation_or_overflowing_the_page(): void
    {
        $report = $this->report();
        $report->setRelation('simulation', new Simulation(['months_count' => 5]));
        $recommendations = [];
        for ($index = 1; $index <= 60; $index++) {
            $recommendations[] = 'Recomendação '.$index.': conferir as condições de fornecimento, os valores contratados e as medições de energia nos meses avaliados.';
        }
        $recommendations[] = 'FIM DA CONFERÊNCIA DO RELATÓRIO';
        $report->forceFill(['recommendations' => $recommendations, 'warnings' => ['incomplete_invoice_series', 'incomplete_invoice_series']]);
        $pdf = app(PdfReportGenerator::class)->generate($report);
        $parsed = (new Parser)->parseContent($pdf);
        $pages = $parsed->getPages();
        $this->assertGreaterThan(3, count($pages));
        $text = $this->text($pdf);
        $this->assertSame(1, substr_count($text, 'Esta avaliação inicial considera menos de 12 faturas.'));
        $this->assertStringContainsString('FIM DA CONFERÊNCIA DO RELATÓRIO', $text);
        foreach ($pages as $index => $page) {
            $this->assertStringContainsString('Página '.($index + 1).' de '.count($pages), $this->normalize($page->getText()));
        }
        preg_match_all('/1 0 0 1 (-?\d+\.\d+) (-?\d+\.\d+) Tm/', $pdf, $positions, PREG_SET_ORDER);
        foreach ($positions as $position) {
            $this->assertGreaterThanOrEqual(0, (float) $position[1]);
            $this->assertLessThanOrEqual(595, (float) $position[1]);
            $this->assertGreaterThanOrEqual(0, (float) $position[2]);
            $this->assertLessThanOrEqual(842, (float) $position[2]);
        }
    }

    private function text(string $pdf): string
    {
        return $this->normalize((new Parser)->parseContent($pdf)->getText());
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text);
    }

    private function report(): Report
    {
        $report = new Report([
            'simulation_id' => 17, 'total_monthly_savings' => '1234.56', 'total_yearly_savings' => '14814.72', 'savings_percentage' => '12.50',
            'demand_savings_monthly' => '100.11', 'demand_savings_yearly' => '1201.32',
            'overrun_savings_monthly' => '20.22', 'overrun_savings_yearly' => '242.64',
            'reactive_savings_monthly' => '30.33', 'reactive_savings_yearly' => '363.96',
            'migration_savings_monthly' => '40.44', 'migration_savings_yearly' => '485.28',
            'recommended_modality' => 'green', 'optimal_demand_peak' => '270.000', 'optimal_demand_off_peak' => '320.000',
            'warnings' => ['free_market_invoice', 'unknown_future_warning'],
            'r_values' => [['reference_month' => '2026-01', 'source' => 'fallback', 'ratio' => '0.820000']],
            'explanatory_texts' => [],
            'recommendations' => ['Conferir o contrato (ponta) e a demanda \\ sem alterar os valores.'],
            'chart_series' => [
                'demand' => [
                    ['reference_month' => '2026-01', 'time_slot' => 'peak', 'measured' => '250.125', 'contracted' => '338.000'],
                    ['reference_month' => '2026-02', 'time_slot' => 'peak', 'measured' => '260.000', 'contracted' => '338.000'],
                    ['reference_month' => '2026-01', 'time_slot' => 'off_peak', 'measured' => '300.000', 'contracted' => '372.000'],
                    ['reference_month' => '2026-02', 'time_slot' => 'off_peak', 'measured' => '310.000', 'contracted' => '372.000'],
                ],
                'reactive' => [['reference_month' => '2026-01', 'value' => '30.33'], ['reference_month' => '2026-02', 'value' => '40.44']],
                'modality' => [['reference_month' => 'Período', 'blue' => '9000.00', 'green' => '8000.00']],
            ],
        ]);
        $report->setRelation('simulation', new Simulation(['months_count' => 12]));

        return $report;
    }
}
