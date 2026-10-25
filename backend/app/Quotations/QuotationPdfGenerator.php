<?php

namespace App\Quotations;

use App\Models\Quotation;

final class QuotationPdfGenerator
{
    public function generate(Quotation $quotation): string
    {
        $quotation->loadMissing('items');
        $issuer = config('quotation.issuer');
        $commands = [];
        $this->text($commands, 42, 800, 16, $issuer['name']);
        $this->text($commands, 42, 782, 9, $issuer['cnpj'].' | '.$issuer['address']);
        $this->text($commands, 42, 768, 9, $issuer['email'].' | '.$issuer['phone']);
        $this->line($commands, 42, 754, 553, 754);
        $this->text($commands, 42, 730, 18, 'ORÇAMENTO');
        $this->text($commands, 400, 730, 11, 'Nº '.$quotation->quote_number);
        $this->text($commands, 42, 710, 10, 'Emissão: '.($quotation->issue_date?->format('d/m/Y') ?? 'após liberação'));
        $this->text($commands, 280, 710, 10, 'Validade: '.($quotation->valid_until?->format('d/m/Y') ?? '30 dias após a liberação'));
        $this->text($commands, 42, 682, 11, 'CLIENTE');
        $this->text($commands, 42, 664, 10, $quotation->company_name ?: $quotation->customer_name);
        $this->text($commands, 42, 648, 9, 'Contato: '.$quotation->customer_name.' | '.$quotation->customer_email);
        $this->text($commands, 42, 634, 9, 'CNPJ: '.($quotation->cnpj ?: 'Não informado'));
        if ($quotation->customer_address) {
            $this->text($commands, 42, 620, 9, 'Endereço: '.$quotation->customer_address);
        }

        $this->line($commands, 42, 596, 553, 596);
        $this->text($commands, 42, 580, 9, 'ITEM');
        $this->text($commands, 300, 580, 9, 'QTD.');
        $this->text($commands, 352, 580, 9, 'UN.');
        $this->text($commands, 397, 580, 9, 'VALOR UNIT.');
        $this->text($commands, 493, 580, 9, 'TOTAL');
        $this->line($commands, 42, 572, 553, 572);

        $rowY = 552;
        foreach ($quotation->items as $item) {
            $this->text($commands, 42, $rowY, 9, $this->truncate($item->name, 42));
            $this->text($commands, 300, $rowY, 9, $item->quantity);
            $this->text($commands, 352, $rowY, 9, $this->truncate($item->unit, 8));
            $this->text($commands, 397, $rowY, 9, $this->formatMoney($item->unit_price));
            $this->text($commands, 493, $rowY, 9, $this->formatMoney($item->total));
            $rowY -= 24;
        }

        $totalY = max($rowY - 3, 250);
        $this->line($commands, 350, $totalY + 18, 553, $totalY + 18);
        $this->text($commands, 395, $totalY, 10, 'TOTAL GERAL');
        $this->text($commands, 493, $totalY, 12, $this->formatMoney($quotation->total));
        $this->text($commands, 42, 205, 9, 'Esta proposta é válida por 30 dias a partir da data de emissão.');
        $this->text($commands, 42, 178, 10, 'RESPONSABILIDADE TÉCNICA');
        $this->text($commands, 42, 160, 9, 'Engenheiro(a) Eletricista: '.($quotation->technical_responsible_name ?: 'a informar após revisão'));
        $this->text($commands, 42, 145, 9, 'Registro CREA-SP: '.($quotation->crea_registration ?: 'a informar após revisão'));
        $this->text($commands, 42, 130, 9, 'ART: '.($quotation->art_number ?: 'a emitir antes da liberação'));
        $this->line($commands, 42, 62, 553, 62);
        $this->text($commands, 42, 46, 8, $issuer['name']);
        $this->text($commands, 42, 34, 8, $issuer['email'].' | '.$issuer['phone']);
        $this->text($commands, 500, 46, 8, 'Página 1 de 1');

        $stream = implode("\n", $commands)."\n";
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

    /** @param list<string> $commands */
    private function text(array &$commands, int $x, int $y, int $size, string $value): void
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        $text = substr($encoded === false ? $value : $encoded, 0, 100);
        $commands[] = 'BT /F1 '.$size.' Tf '.$x.' '.$y.' Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
    }

    /** @param list<string> $commands */
    private function line(array &$commands, int $x1, int $y1, int $x2, int $y2): void
    {
        $commands[] = 'q 0.6 w '.$x1.' '.$y1.' m '.$x2.' '.$y2.' l S Q';
    }

    private function truncate(string $value, int $length): string
    {
        preg_match_all('/./us', $value, $characters);

        return implode('', array_slice($characters[0] ?? [$value], 0, $length));
    }

    private function formatMoney(string $amount): string
    {
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $integer = strrev(implode('.', str_split(strrev($integer), 3)));

        return 'R$ '.$integer.','.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
