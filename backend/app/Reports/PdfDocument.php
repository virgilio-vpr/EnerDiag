<?php

namespace App\Reports;

final class PdfDocument
{
    public const WIDTH = 595.0;

    public const HEIGHT = 842.0;

    public const MARGIN = 42.0;

    public const CONTENT_WIDTH = 511.0;

    public float $y = 64.0;

    /** @var list<list<string>> */
    private array $pages = [];

    public function __construct(private readonly string $protocol)
    {
        $this->newPage();
    }

    public function newPage(): void
    {
        $this->pages[] = [];
        $this->y = 64.0;
        $this->rectangle(self::MARGIN, 29, 8, 8, '#0b8f6a');
        $this->textAt(56, 25, 11, 'ENERDIAG', '#0b8f6a', true);
        $label = 'Protocolo '.$this->protocol;
        $this->textAt(self::WIDTH - self::MARGIN - $this->textWidth($label, 9), 27, 9, $label, '#64748b');
    }

    public function ensureSpace(float $height): void
    {
        if ($this->y + $height > self::HEIGHT - 64) {
            $this->newPage();
        }
    }

    public function heading(string $text): void
    {
        $this->ensureSpace(64);
        $this->rectangle(self::MARGIN, $this->y + 2, 3, 18, '#0b8f6a');
        $this->textAt(self::MARGIN + 11, $this->y, 15, $text, '#17352d', true);
        $this->y += 30;
    }

    public function paragraph(string $text, float $size = 10, string $color = '#475569'): void
    {
        foreach ($this->wrap($text, self::CONTENT_WIDTH, $size) as $line) {
            $this->ensureSpace($size * 1.5);
            $this->textAt(self::MARGIN, $this->y, $size, $line, $color);
            $this->y += $size * 1.5;
        }
        $this->y += 7;
    }

    /** @param list<array{label: string, value: string}> $cards */
    public function cards(array $cards): void
    {
        $this->ensureSpace(105);
        $width = (self::CONTENT_WIDTH - 20) / 3;
        foreach ($cards as $index => $card) {
            $x = self::MARGIN + $index * ($width + 10);
            $this->rectangle($x, $this->y, $width, 92, '#eef8f4');
            foreach ($this->wrap($card['label'], $width - 20, 9) as $lineIndex => $line) {
                $this->textAt($x + 10, $this->y + 10 + $lineIndex * 12, 9, $line, '#475569');
            }
            $size = 22.0;
            while ($size > 10 && $this->textWidth($card['value'], $size, true) > $width - 20) {
                $size--;
            }
            $this->textAt($x + 10, $this->y + 53, $size, $card['value'], '#087452', true);
        }
        $this->y += 105;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<float|int>  $widths
     */
    public function table(array $headers, array $rows, array $widths): void
    {
        $this->ensureSpace($this->rowHeight($headers, $widths) + 40);
        $this->tableRow($headers, $widths, true, true);
        foreach ($rows as $index => $row) {
            if ($this->y + $this->rowHeight($row, $widths) > self::HEIGHT - 64) {
                $this->newPage();
                $this->tableRow($headers, $widths, true, true);
            }
            $this->tableRow($row, $widths, false, $index % 2 === 1);
        }
        $this->y += 14;
    }

    /** @param list<string> $cells
     * @param  list<float|int>  $widths
     */
    private function rowHeight(array $cells, array $widths): float
    {
        $lineCount = 1;
        foreach ($cells as $index => $cell) {
            $lineCount = max($lineCount, count($this->wrap($cell, $widths[$index] - 16, 9, true)));
        }

        return 16 + 13 * $lineCount;
    }

    /** @param list<string> $cells
     * @param  list<float|int>  $widths
     */
    private function tableRow(array $cells, array $widths, bool $header, bool $shaded): void
    {
        $height = $this->rowHeight($cells, $widths);
        if ($shaded) {
            $this->rectangle(self::MARGIN, $this->y, self::CONTENT_WIDTH, $height, $header ? '#e6f0ec' : '#f8faf9');
        }
        $x = self::MARGIN;
        foreach ($cells as $index => $cell) {
            foreach ($this->wrap($cell, $widths[$index] - 16, 9, $header) as $lineIndex => $line) {
                $this->textAt($x + 8, $this->y + 8 + 13 * $lineIndex, 9, $line, '#17352d', $header);
            }
            $x += $widths[$index];
        }
        $this->line(self::MARGIN, $this->y + $height, self::WIDTH - self::MARGIN, $this->y + $height, '#dbe5e0', 0.5);
        $this->y += $height;
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, float $size, bool $bold = false): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->textWidth($candidate, $size, $bold) <= $width) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            foreach (mb_str_split($word) as $character) {
                if ($line !== '' && $this->textWidth($line.$character, $size, $bold) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $character;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $units = 0;
        foreach (str_split($ascii === false ? '' : $ascii) as $character) {
            $units += match (true) {
                str_contains(' ilI.,:;!\'|', $character) => 278,
                str_contains('mwMW@%', $character) => 900,
                str_contains('frt()[]', $character) => 350,
                ctype_upper($character) => 722,
                default => 556,
            };
        }

        return $units / 1000 * $size * ($bold ? 1.04 : 1);
    }

    public function textAt(float $x, float $top, float $size, string $text, string $color = '#17352d', bool $bold = false): void
    {
        $this->pages[array_key_last($this->pages)][] = $this->textCommand($x, $top, $size, $text, $color, $bold);
    }

    private function textCommand(float $x, float $top, float $size, string $text, string $color, bool $bold = false): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', str_replace(["\r", "\n", "\t"], ' ', $text));
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded === false ? '' : $encoded);

        return 'q '.$this->color($color).' rg BT /'.($bold ? 'F2' : 'F1').' '.self::number($size).' Tf 1 0 0 1 '.self::number($x).' '.self::number(self::HEIGHT - $top - $size).' Tm ('.$escaped.') Tj ET Q';
    }

    public function rectangle(float $x, float $top, float $width, float $height, string $color): void
    {
        $this->pages[array_key_last($this->pages)][] = 'q '.$this->color($color).' rg '.self::number($x).' '.self::number(self::HEIGHT - $top - $height).' '.self::number($width).' '.self::number($height).' re f Q';
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width = 1): void
    {
        $this->pages[array_key_last($this->pages)][] = 'q '.$this->color($color).' RG '.self::number($width).' w '.self::number($x1).' '.self::number(self::HEIGHT - $y1).' m '.self::number($x2).' '.self::number(self::HEIGHT - $y2).' l S Q';
    }

    private function color(string $hex): string
    {
        return implode(' ', array_map(static fn (string $channel): string => self::number(hexdec($channel) / 255), str_split(ltrim($hex, '#'), 2)));
    }

    private static function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    public function render(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($this->pages as $index => $commands) {
            $commands[] = 'q 0.85 0.9 0.88 RG 0.5 w 42 43 m 553 43 l S Q';
            $commands[] = $this->textCommand(42, 811, 8, 'EnerDiag · Relatório de energia', '#64748b');
            $commands[] = $this->textCommand(470, 811, 8, 'Página '.($index + 1).' de '.count($this->pages), '#64748b');
            $stream = implode("\n", $commands)."\n";
            $pageId = count($objects) + 1;
            $streamId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[$streamId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";
    }
}
