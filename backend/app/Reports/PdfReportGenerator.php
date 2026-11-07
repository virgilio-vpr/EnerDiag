<?php

namespace App\Reports;

use App\Models\Report;

class PdfReportGenerator
{
    public function generate(Report $report): string
    {
        $report->loadMissing('simulation');
        $months = (int) ($report->simulation?->months_count ?? 0);
        $warnings = $report->warnings ?? [];
        if ($months > 0 && $months < 12) {
            array_unshift($warnings, 'incomplete_invoice_series');
        }
        $warnings = array_values(array_unique($warnings));
        $texts = json_decode(file_get_contents(resource_path('report-texts.json')), true, flags: JSON_THROW_ON_ERROR);
        $contact = json_decode(file_get_contents(resource_path('company-contact.json')), true, flags: JSON_THROW_ON_ERROR);
        $contacts = [];
        foreach (['email' => 'E-mail', 'phone' => 'Telefone', 'whatsapp' => 'WhatsApp'] as $key => $label) {
            if (($contact[$key] ?? '') !== '') {
                $contacts[] = $label.': '.$contact[$key];
            }
        }
        $document = new PdfDocument((string) $report->simulation_id, implode(' · ', $contacts));
        $document->textAt(PdfDocument::MARGIN, $document->y, 25, 'Seu relatório de energia', '#17352d', true);
        $document->y += 38;
        $document->paragraph('Resultados calculados para '.$months.' '.($months === 1 ? 'fatura.' : 'faturas.'), 11);
        $document->cards([
            ['label' => 'Economia mensal média', 'value' => $this->money($report->total_monthly_savings)],
            ['label' => 'Projeção de economia anual', 'value' => $this->money($report->total_yearly_savings)],
            ['label' => ($report->explanatory_texts['percentage'] ?? '') ?: '% do valor médio pago no período', 'value' => $report->savings_percentage === null ? '—' : $this->decimal($report->savings_percentage).'%'],
        ]);
        $document->paragraph(str_replace('A economia usa tarifas atuais e o valor pago usa as tarifas históricas.', 'A economia usa tarifas de referência; o valor pago usa as tarifas históricas.', ($report->explanatory_texts['percentage_observation'] ?? '') ?: 'A economia usa tarifas de referência; o valor pago usa as tarifas históricas.'), 9);

        $demandUnavailable = array_intersect($warnings, ['demand_savings_unavailable_pending_section_9_limits', 'demand_optimization_unavailable_pending_section_9_limits', 'demand_optimization_unavailable']) !== [];
        $migrationUnavailable = array_intersect($warnings, ['migration_unavailable', 'tariff_migration_unavailable_pending_section_9_rules']) !== [];
        $rows = [];
        foreach ([['Demanda', 'demand', $demandUnavailable], ['Ultrapassagem', 'overrun', false], ['Reativos', 'reactive', false], ['Migração de modalidade', 'migration', $migrationUnavailable]] as [$label, $key, $unavailable]) {
            $rows[] = [$label, $unavailable ? 'Indisponível' : $this->money($report->{$key.'_savings_monthly'}), $unavailable ? 'Indisponível' : $this->money($report->{$key.'_savings_yearly'})];
        }
        $document->heading('Economia por componente');
        $document->table(['Componente', 'Por mês', 'Por ano'], $rows, [211, 150, 150]);
        $references = [];
        foreach ($report->chart_series['demand'] ?? [] as $point) {
            if (! empty($point['tariff_reference_source'])) {
                $references[$point['time_slot']] = $point;
            }
        }
        if ($references !== []) {
            $document->paragraph($texts['demand_reference_note'], 9);
            foreach ($references as $slot => $point) {
                $label = ['peak' => 'Ponta', 'off_peak' => 'Fora de ponta', 'unique' => 'Única'][$slot] ?? $slot;
                $source = $texts['demand_reference_sources'][$point['tariff_reference_source']] ?? 'Tarifa de referência';
                $document->paragraph($label.': '.str_replace('.', ',', $point['reference_tariff']).' R$/kW · '.$source.' · '.$this->monthLabel($point['tariff_reference_month']).'.', 9);
            }
        } else {
            foreach ($report->chart_series['demand'] ?? [] as $point) {
                if (! empty($point['tariff_reference_month'])) {
                    $document->paragraph(str_replace('{month}', $this->monthLabel($point['tariff_reference_month']), $texts['demand_comparison_note']), 9);
                    break;
                }
            }
        }
        if ($report->recommended_modality) {
            $modality = ['blue' => 'Azul', 'green' => 'Verde'][$report->recommended_modality] ?? $report->recommended_modality;
            $document->paragraph('Modalidade recomendada: '.$modality);
        }

        $consultationWarning = 'demand_invoice_reconciliation_mismatch';
        if (in_array($consultationWarning, $warnings, true)) {
            $document->callout(
                $texts['consultation_title'],
                $texts['warnings'][$consultationWarning],
                $texts['consultation_contact'].($contacts === [] ? '' : ' · '.implode(' · ', $contacts)),
            );
        }

        $customerWarnings = array_values(array_diff($warnings, $texts['internal_warnings'], [$consultationWarning]));
        if ($customerWarnings !== []) {
            $document->heading('Avisos da análise');
            foreach ($customerWarnings as $warning) {
                $document->paragraph('• '.($texts['warnings'][$warning] ?? $texts['fallback_warning']));
            }
        }
        foreach ($report->r_values ?? [] as $entry) {
            if (($entry['source'] ?? null) === 'fallback' && isset($entry['ratio'])) {
                $document->heading('Reserva aplicada à demanda ociosa');
                $document->paragraph('Foi usada a proporção r = '.$this->decimal(bcmul($entry['ratio'], '100', 8)).'% nos períodos em que a fatura não indicou essa proporção.');
                break;
            }
        }

        $chartSeries = $report->chart_series ?? [];
        $demand = $chartSeries['demand'] ?? [];
        $slots = array_filter($demand, static fn (array $point): bool => in_array($point['time_slot'] ?? 'unique', ['peak', 'off_peak'], true)) !== []
            ? ['peak' => 'ponta', 'off_peak' => 'fora de ponta'] : ['unique' => 'única'];
        foreach ($slots as $slot => $label) {
            $points = [];
            foreach ($demand as $point) {
                if (($point['time_slot'] ?? 'unique') !== $slot) {
                    continue;
                }
                $optimal = match ($slot) {
                    'peak' => $report->optimal_demand_peak,
                    'off_peak' => $report->optimal_demand_off_peak,
                    default => $report->optimal_demand_single,
                };
                $point['optimal'] ??= $optimal;
                $contracted = $this->pointValue($point, 'contracted');
                $point['contracted_tolerance'] = $contracted === null ? null : bcmul($contracted, '1.05', 8);
                $optimalValue = $this->pointValue($point, 'optimal');
                $point['optimal_tolerance'] = $optimalValue === null ? null : bcmul($optimalValue, '1.05', 8);
                $points[] = $point;
            }
            $this->chartSection($document, 'Demanda '.$label.': registrada × contratada × ótima', $points, [
                ['key' => 'measured', 'label' => 'Registrada', 'color' => '#0b8f6a'],
                ['key' => 'contracted', 'label' => 'Contratada', 'color' => '#2764c5'],
                ['key' => 'contracted_tolerance', 'label' => 'Contratada + 5%', 'color' => '#dc2626', 'dashed' => true],
                ['key' => 'optimal', 'label' => 'Ótima', 'color' => '#b86d13'],
                ['key' => 'optimal_tolerance', 'label' => 'Ótima + 5%', 'color' => '#a855f7', 'dashed' => true],
            ], false, false, 'A série de demanda não está disponível neste relatório.');
            if ($points !== []) {
                $document->paragraph('As linhas tracejadas indicam +5% sobre a demanda contratada e sobre a ótima simulada, para comparar a medição com o limite de tolerância de cada cenário.', 9);
            }
        }
        $this->chartSection($document, 'Economia com reativos por mês', $chartSeries['reactive'] ?? [], [
            ['key' => 'value', 'label' => 'Economia mensal', 'color' => '#0b8f6a'],
        ], true, true, 'A série de reativos não está disponível neste relatório.');
        if (! empty($chartSeries['modality'][0]['tariff_reference_date'])) {
            $document->paragraph($texts['modality_reference_note'], 9);
        }
        $this->chartSection($document, 'Comparação entre Azul e Verde', $chartSeries['modality'] ?? $chartSeries['blue_green'] ?? [], [
            ['key' => 'blue', 'label' => 'Azul', 'color' => '#2764c5'],
            ['key' => 'green', 'label' => 'Verde', 'color' => '#0b8f6a'],
        ], true, true, 'A comparação não está disponível sem tarifas válidas e dados suficientes para as duas modalidades.');

        if (($report->recommendations ?? []) !== []) {
            $document->heading('Próximos passos');
            foreach ($report->recommendations as $recommendation) {
                $document->paragraph('• '.$recommendation);
            }
        }

        return $document->render();
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @param  list<array{key: string, label: string, color: string, dashed?: bool}>  $series
     */
    private function chartSection(PdfDocument $document, string $title, array $points, array $series, bool $money, bool $bars, string $emptyText): void
    {
        $document->ensureSpace($points === [] ? 95 : 350);
        $document->heading($title);
        if ($points === []) {
            $document->paragraph($emptyText);

            return;
        }
        $this->drawChart($document, $points, $series, $money, $bars);
        $headers = ['Mês / período'];
        foreach ($series as $entry) {
            $headers[] = $entry['label'].($money ? ' (R$)' : ' (kW)');
        }
        $rows = [];
        foreach ($points as $point) {
            $row = [$this->monthLabel($point['reference_month'] ?? '')];
            foreach ($series as $entry) {
                $value = $this->pointValue($point, $entry['key']);
                $row[] = $money ? $this->money($value) : $this->decimal($value, 3);
            }
            $rows[] = $row;
        }
        $document->table($headers, $rows, array_fill(0, count($headers), PdfDocument::CONTENT_WIDTH / count($headers)));
    }

    /**
     * Floating point values are used only for chart coordinates, never for report calculations.
     *
     * @param  list<array<string, mixed>>  $points
     * @param  list<array{key: string, label: string, color: string, dashed?: bool}>  $series
     */
    private function drawChart(PdfDocument $document, array $points, array $series, bool $money, bool $bars): void
    {
        $values = [0.0];
        foreach ($points as $point) {
            foreach ($series as $entry) {
                $value = $this->pointValue($point, $entry['key']);
                if ($value !== null) {
                    $values[] = (float) $value;
                }
            }
        }
        if (count($values) === 1) {
            $document->paragraph('Não há valores disponíveis para desenhar este gráfico.');

            return;
        }
        $minimum = min($values);
        $maximum = max($values);
        $range = $maximum - $minimum;
        $roughStep = $range > 0 ? $range / 4 : 0.25;
        $power = 10 ** floor(log10($roughStep));
        $relative = $roughStep / $power;
        $step = (match (true) {
            $relative <= 1 => 1, $relative <= 2 => 2, $relative <= 5 => 5, default => 10
        }) * $power;
        $lower = floor($minimum / $step) * $step;
        $upper = ceil($maximum / $step) * $step;
        if ($upper <= $lower) {
            $upper = $lower + $step * 4;
        }
        $left = PdfDocument::MARGIN + 62;
        $legendColumns = min(3, count($series));
        $legendExtraHeight = (ceil(count($series) / $legendColumns) - 1) * 18;
        $top = $document->y + 33 + $legendExtraHeight;
        $width = PdfDocument::CONTENT_WIDTH - 78;
        $height = 156.0;
        $yCoordinate = static fn (float $value): float => $top + $height - ($value - $lower) / ($upper - $lower) * $height;
        foreach ($series as $index => $entry) {
            $x = $left + ($index % $legendColumns) * min(150, $width / $legendColumns);
            $legendY = $document->y + intdiv($index, $legendColumns) * 18;
            if ($bars) {
                $document->rectangle($x, $legendY + 3, 8, 8, $entry['color']);
            } else {
                $document->line($x, $legendY + 7, $x + 18, $legendY + 7, $entry['color'], 1.8, $entry['dashed'] ?? false);
            }
            $document->textAt($x + ($bars ? 13 : 22), $legendY + 2, 8, $entry['label']);
        }
        $document->textAt(PdfDocument::MARGIN, $top - 17, 8, $money ? 'R$' : 'kW', '#64748b');
        for ($tick = $lower; $tick <= $upper + $step / 100; $tick += $step) {
            $y = $yCoordinate($tick);
            $document->line($left, $y, $left + $width, $y, '#e2e8e5', 0.5);
            $label = rtrim(rtrim(number_format($tick, 2, ',', '.'), '0'), ',');
            $document->textAt($left - 8 - $document->textWidth($label, 8), $y - 5, 8, $label, '#64748b');
        }
        $document->line($left, $yCoordinate(0), $left + $width, $yCoordinate(0), '#94a3b8', 0.7);
        $spacing = $width / ($bars ? count($points) : max(1, count($points) - 1));
        foreach ($points as $index => $point) {
            $x = $bars ? $left + ($index + 0.5) * $spacing : $left + (count($points) === 1 ? $width / 2 : $index * $spacing);
            $label = $this->monthLabel($point['reference_month'] ?? '', true);
            $document->textAt($x - $document->textWidth($label, 7) / 2, $top + $height + 9, 7, $label, '#64748b');
        }
        foreach ($series as $seriesIndex => $entry) {
            $previous = null;
            foreach ($points as $index => $point) {
                $value = $this->pointValue($point, $entry['key']);
                if ($value === null) {
                    continue;
                }
                $x = $bars ? $left + ($index + 0.5) * $spacing : $left + (count($points) === 1 ? $width / 2 : $index * $spacing);
                $y = $yCoordinate((float) $value);
                if ($bars) {
                    $barWidth = min(32, $spacing * 0.7 / count($series));
                    $barX = $x - count($series) * $barWidth / 2 + $seriesIndex * $barWidth;
                    $document->rectangle($barX, min($y, $yCoordinate(0)), max(1, $barWidth - 2), abs($yCoordinate(0) - $y), $entry['color']);
                } else {
                    if ($previous !== null) {
                        $document->line($previous[0], $previous[1], $x, $y, $entry['color'], 1.8, $entry['dashed'] ?? false);
                    }
                    if (! ($entry['dashed'] ?? false) || count($points) === 1) {
                        $document->rectangle($x - 2, $y - 2, 4, 4, $entry['color']);
                    }
                    $previous = [$x, $y];
                }
            }
        }
        $document->y += 226 + $legendExtraHeight;
    }

    /** @param array<string, mixed> $point */
    private function pointValue(array $point, string $key): ?string
    {
        $value = $point[$key] ?? null;

        return is_scalar($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value) === 1 ? (string) $value : null;
    }

    private function monthLabel(string $value, bool $short = false): string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])/', $value, $match) !== 1) {
            return $value;
        }
        if (! $short) {
            return $match[2].'/'.$match[1];
        }
        $months = ['jan.', 'fev.', 'mar.', 'abr.', 'mai.', 'jun.', 'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.'];

        return $months[(int) $match[2] - 1].'/'.substr($match[1], -2);
    }

    private function money(?string $value): string
    {
        return $value === null ? '—' : 'R$ '.$this->decimal($value, 2, true);
    }

    private function decimal(?string $value, int $scale = 2, bool $fixed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $negative = bccomp($value, '0', $scale + 2) < 0;
        $absolute = $negative ? bcsub('0', $value, $scale + 3) : $value;
        $rounded = bcadd($absolute, '0.'.str_repeat('0', $scale).'5', $scale);
        [$integer, $fraction] = array_pad(explode('.', $rounded, 2), 2, '');
        $integer = strrev(implode('.', str_split(strrev($integer), 3)));
        $fraction = $fixed ? str_pad($fraction, $scale, '0') : rtrim($fraction, '0');

        return ($negative && bccomp($rounded, '0', $scale) !== 0 ? '-' : '').$integer.($fraction === '' ? '' : ','.$fraction);
    }
}
