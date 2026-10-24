<?php

namespace App\Reports;

use App\Models\Report;

class PdfReportGenerator
{
    public function generate(Report $report): string
    {
        $lines = [
            'EnerDiag - Relatorio de economia',
            'Economia mensal: R$ '.($report->total_monthly_savings ?? '0,00'),
            'Economia anual: R$ '.($report->total_yearly_savings ?? '0,00'),
            'Percentual de economia: '.($report->savings_percentage ?? '0,00').'% ',
            'Economia mensal por demanda: R$ '.($report->demand_savings_monthly ?? '0,00'),
            'Economia mensal por ultrapassagem: R$ '.($report->overrun_savings_monthly ?? '0,00'),
            'Economia mensal por energia reativa: R$ '.($report->reactive_savings_monthly ?? '0,00'),
            'Economia mensal por migracao: R$ '.($report->migration_savings_monthly ?? '0,00'),
        ];
        foreach ($report->warnings ?? [] as $warning) {
            $lines[] = 'Aviso: '.(is_scalar($warning) ? (string) $warning : json_encode($warning));
        }
        foreach ($report->recommendations ?? [] as $recommendation) {
            $lines[] = 'Recomendacao: '.(is_scalar($recommendation) ? (string) $recommendation : json_encode($recommendation));
        }

        $stream = "BT\n/F1 16 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $stream .= "0 -24 Td\n/F1 11 Tf\n";
            }
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            $text = $encoded === false ? $line : $encoded;
            $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).") Tj\n";
        }
        $stream .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }
}
