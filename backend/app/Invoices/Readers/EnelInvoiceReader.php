<?php

namespace App\Invoices\Readers;

use App\Calculation\Data\ExtractedInvoiceData;
use InvalidArgumentException;

final class EnelInvoiceReader implements InvoiceReader
{
    private const IDLE_MATCH_TOLERANCE = '0.02';

    public function parserKey(): string
    {
        return 'enel';
    }

    public function recognizesIdleDemandLine(): bool
    {
        return true;
    }

    public function read(string $text): ExtractedInvoiceData
    {
        $modality = $this->modality($text);
        $data = [
            'reference_month' => $this->referenceMonth($text),
            'consumer_unit' => $this->consumerUnit($text),
            'subgroup' => $this->subgroup($text),
            'tariff_modality' => $modality,
            'total_paid' => $this->totalPaid($text),
            'items' => [],
            'warnings' => [],
        ];

        $contractedBySlot = $this->contractedDemand($text, $modality);
        foreach ($contractedBySlot as $slot => $quantity) {
            $data['items'][] = $this->item(
                itemType: 'contracted_demand',
                tariffComponent: 'demand',
                timeSlot: $slot,
                quantity: $quantity,
                unit: 'kW',
                unitPriceTaxed: null,
                tariffRate: null,
                amount: '0.00',
                pisCofins: '0.00',
                icmsBase: '0.00',
                icmsRate: '0.0000',
                icmsAmount: '0.00',
                sourceLabel: 'Demanda Contratada',
            );
        }

        $rows = $this->billingRows($text);
        $idleRows = [];
        $normalDemandBySlot = [];

        foreach ($rows as $row) {
            if ($row['kind'] === 'idle_demand') {
                $idleRows[] = $this->parseIdleRow($row['content']);

                continue;
            }

            $parsed = $this->parseRegularRow($row['content']);
            if ($parsed === null) {
                throw new InvalidArgumentException("A linha '{$row['source_label']}' do quadro de faturamento da ENEL está incompleta.");
            }

            $item = $this->item(
                itemType: $row['item_type'],
                tariffComponent: $row['tariff_component'],
                timeSlot: $row['time_slot'],
                quantity: $parsed['quantity'],
                unit: $row['unit'],
                unitPriceTaxed: $parsed['unit_price_taxed'],
                tariffRate: $parsed['tariff_rate'],
                amount: $parsed['amount'],
                pisCofins: $parsed['pis_cofins_amount'],
                icmsBase: $parsed['icms_base'],
                icmsRate: $parsed['icms_rate'],
                icmsAmount: $parsed['icms_amount'],
                sourceLabel: $row['source_label'],
                reactiveNature: $row['reactive_nature'],
            );
            $data['items'][] = $item;

            if ($row['item_type'] === 'measured_demand') {
                $normalDemandBySlot[$row['time_slot']] = $item;
            }
        }

        [$matchedIdleRows, $matchingWarnings] = $this->matchIdleDemandRows(
            $idleRows,
            $contractedBySlot,
            $normalDemandBySlot,
            $modality,
        );
        $data['items'] = array_merge($data['items'], $matchedIdleRows);
        $data['warnings'] = array_merge($data['warnings'], $matchingWarnings);

        return ExtractedInvoiceData::fromArray($data);
    }

    /** @return list<array{kind: string, item_type: string, tariff_component: string, time_slot: string, unit: string, reactive_nature: string, source_label: string, content: string}> */
    private function billingRows(string $text): array
    {
        $specifications = [
            ['kind' => 'idle_demand', 'pattern' => '~DEMANDA\s+LEI\s+ESTADUAL\s+16\.886/18\s+(?:KW\s*)?~iu', 'item_type' => 'idle_demand', 'tariff_component' => 'demand', 'time_slot' => 'unresolved', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'DEMANDA LEI ESTADUAL 16.886/18'],
            ['kind' => 'regular', 'pattern' => '~DEMANDA\s+(?:DE\s+)?ULTRAPASSAGEM\s+PONTA\s+KW\s*~iu', 'item_type' => 'demand_overrun', 'tariff_component' => 'demand', 'time_slot' => 'peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'Demanda de Ultrapassagem Ponta'],
            ['kind' => 'regular', 'pattern' => '~DEMANDA\s+(?:DE\s+)?ULTRAPASSAGEM\s+(?:FORA\s+(?:DE\s+)?PONTA|F\.?\s*PONTA)\s+KW\s*~iu', 'item_type' => 'demand_overrun', 'tariff_component' => 'demand', 'time_slot' => 'off_peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'Demanda de Ultrapassagem Fora de Ponta'],
            ['kind' => 'regular', 'pattern' => '~DEMANDA\s+PONTA\s+KW\s*~iu', 'item_type' => 'measured_demand', 'tariff_component' => 'demand', 'time_slot' => 'peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'DEMANDA PONTA'],
            ['kind' => 'regular', 'pattern' => '~DEMANDA\s+(?:FORA\s+DE\s+PONTA|F\.?\s*PONTA)\s+KW\s*~iu', 'item_type' => 'measured_demand', 'tariff_component' => 'demand', 'time_slot' => 'off_peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'DEMANDA FORA DE PONTA'],
            ['kind' => 'regular', 'pattern' => '~CONSUMO\s+ATIVO\s+PONTA\s+(TUSD|TE)\s+KWH\s*~iu', 'item_type' => 'active_consumption', 'tariff_component' => 'captured_energy_component', 'time_slot' => 'peak', 'unit' => 'kWh', 'reactive_nature' => 'not_applicable', 'source_label' => 'CONSUMO ATIVO PONTA'],
            ['kind' => 'regular', 'pattern' => '~CONSUMO\s+ATIVO\s+(?:FORA\s+DE\s+PONTA|F\.?\s*PONTA)\s+(TUSD|TE)\s+KWH\s*~iu', 'item_type' => 'active_consumption', 'tariff_component' => 'captured_energy_component', 'time_slot' => 'off_peak', 'unit' => 'kWh', 'reactive_nature' => 'not_applicable', 'source_label' => 'CONSUMO ATIVO FORA DE PONTA'],
            ['kind' => 'regular', 'pattern' => '~(?:UFDR|DUR|DEMANDA\s+REATIVA\s+(?:EXCEDENTE|INDUTIVA\s*(?:/|E)\s*CAPACITIVA))\s+(?:FORA\s+(?:DE\s+)?PONTA|F\.?\s*PONTA)\s+KW\s*~iu', 'item_type' => 'reactive_demand_excess', 'tariff_component' => 'demand', 'time_slot' => 'off_peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'UFDR FORA DE PONTA'],
            ['kind' => 'regular', 'pattern' => '~(?:UFDR|DUR|DEMANDA\s+REATIVA\s+(?:EXCEDENTE|INDUTIVA\s*(?:/|E)\s*CAPACITIVA))\s+PONTA\s+KW\s*~iu', 'item_type' => 'reactive_demand_excess', 'tariff_component' => 'demand', 'time_slot' => 'peak', 'unit' => 'kW', 'reactive_nature' => 'not_applicable', 'source_label' => 'UFDR PONTA'],
            ['kind' => 'regular', 'pattern' => '~UFER\s+PONTA\s+(?:INDU(?:TIVO)?\s+)?(?:TE\s+)?KWH\s*~iu', 'item_type' => 'reactive_energy_excess', 'tariff_component' => 'energy_te', 'time_slot' => 'peak', 'unit' => 'kWh', 'reactive_nature' => 'inductive', 'source_label' => 'UFER PONTA INDU'],
            ['kind' => 'regular', 'pattern' => '~UFER\s+(?:FORA\s+(?:DE\s+)?PONTA|F\.?\s*PONTA)\s+(?:INDU(?:TIVO)?\s+)?(?:TE\s+)?KWH\s*~iu', 'item_type' => 'reactive_energy_excess', 'tariff_component' => 'energy_te', 'time_slot' => 'off_peak', 'unit' => 'kWh', 'reactive_nature' => 'inductive', 'source_label' => 'UFER FORA DE PONTA INDU'],
            ['kind' => 'regular', 'pattern' => '~UFER\s+(?:MADRUGADA|FIM\s+DE\s+SEMANA|F\.?\s*PONTA)\s+CAP(?:ACITIVO)?\s+(?:TE\s+)?KWH\s*~iu', 'item_type' => 'reactive_energy_excess', 'tariff_component' => 'energy_te', 'time_slot' => 'late_night_weekend', 'unit' => 'kWh', 'reactive_nature' => 'capacitive', 'source_label' => 'UFER MADRUGADA/FIM DE SEMANA CAPACITIVO'],
        ];

        $matches = [];
        foreach ($specifications as $specification) {
            preg_match_all($specification['pattern'], $text, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as $matchIndex => [$label, $offset]) {
                $matches[] = [
                    ...$specification,
                    'offset' => $offset,
                    'content_offset' => $offset + strlen($label),
                    'captured_component' => $found[1][$matchIndex][0] ?? null,
                ];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $rows = [];
        foreach ($matches as $index => $match) {
            $end = $matches[$index + 1]['offset'] ?? strlen($text);
            $component = match ($match['tariff_component']) {
                'captured_energy_component' => strtolower($match['captured_component'] ?? '') === 'te' ? 'energy_te' : 'energy_tusd',
                default => $match['tariff_component'],
            };
            $rows[] = [
                'kind' => $match['kind'],
                'item_type' => $match['item_type'],
                'tariff_component' => $component,
                'time_slot' => $match['time_slot'],
                'unit' => $match['unit'],
                'reactive_nature' => $match['reactive_nature'],
                'source_label' => $match['source_label'],
                'content' => substr($text, $match['content_offset'], $end - $match['content_offset']),
            ];
        }

        return $rows;
    }

    /** @return array{quantity: string, unit_price_taxed: string, amount: string, pis_cofins_amount: string, icms_base: string, icms_rate: string, icms_amount: string, tariff_rate: string}|null */
    private function parseRegularRow(string $content): ?array
    {
        $offset = 0;
        $quantity = $this->takeDecimal($content, $offset, 3);
        $unitPrice = $this->takeDecimal($content, $offset, 5);
        $amount = $this->takeDecimal($content, $offset, 2);
        $pisCofins = $this->takeDecimal($content, $offset, 2);
        $icmsBase = $this->takeDecimal($content, $offset, 2);
        $icmsRate = $this->takeRate($content, $offset);
        $icmsAmount = $this->takeDecimal($content, $offset, 2);
        $tariffRate = $this->takeDecimal($content, $offset, 5);

        if (in_array(null, [$quantity, $unitPrice, $amount, $pisCofins, $icmsBase, $icmsRate, $icmsAmount, $tariffRate], true)) {
            return null;
        }

        return [
            'quantity' => $quantity,
            'unit_price_taxed' => $unitPrice,
            'amount' => $amount,
            'pis_cofins_amount' => $pisCofins,
            'icms_base' => $icmsBase,
            'icms_rate' => $icmsRate,
            'icms_amount' => $icmsAmount,
            'tariff_rate' => $tariffRate,
        ];
    }

    /** @return array{amount: string, pis_cofins_amount: string, icms_base: string, icms_rate: string, icms_amount: string} */
    private function parseIdleRow(string $content): array
    {
        $offset = 0;
        $amount = $this->takeDecimal($content, $offset, 2);
        $pisCofins = $this->takeDecimal($content, $offset, 2);
        $icmsBase = $this->takeDecimal($content, $offset, 2);
        $icmsRate = $this->takeRate($content, $offset);
        $icmsAmount = $this->takeDecimal($content, $offset, 2);

        if (in_array(null, [$amount, $pisCofins, $icmsBase, $icmsRate, $icmsAmount], true)) {
            throw new InvalidArgumentException('A linha de demanda ociosa da ENEL está incompleta.');
        }

        return [
            'amount' => $amount,
            'pis_cofins_amount' => $pisCofins,
            'icms_base' => $icmsBase,
            'icms_rate' => $icmsRate,
            'icms_amount' => $icmsAmount,
        ];
    }

    /** @return array<string, string> */
    private function contractedDemand(string $text, string $modality): array
    {
        $patterns = [
            'peak' => '~DEMANDA\s+PONTA\s*[-–]\s*KW\s*~iu',
            'off_peak' => '~DEMANDA\s+(?:FORA\s+(?:DE\s+)?PONTA|F\.?\s*PONTA)\s*[-–]\s*KW\s*~iu',
            'unique' => '~DEMANDA\s+(?:ÚNICA\s*[-–]\s*KW|[-–]\s*KW)\s*~iu',
        ];
        $values = [];
        foreach ($patterns as $slot => $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $offset = $match[0][1] + strlen($match[0][0]);
            $quantity = $this->takeDecimal($text, $offset, 3);
            if ($quantity !== null) {
                $values[$slot] = $quantity;
            }
        }

        if ($modality === 'green' && ! isset($values['unique'])) {
            $values['unique'] = $values['peak'] ?? $values['off_peak'] ?? '';
            unset($values['peak'], $values['off_peak']);
        }

        $requiredSlots = $modality === 'green' ? ['unique'] : ['peak', 'off_peak'];
        if (array_diff($requiredSlots, array_keys($values)) !== [] || in_array('', $values, true)) {
            throw new InvalidArgumentException('Não foi possível ler a demanda contratada da fatura ENEL.');
        }

        return $values;
    }

    /** @param list<array<string, string>> $idleRows
     * @param  array<string, string>  $contractedBySlot
     * @param  array<string, array<string, mixed>>  $normalDemandBySlot
     * @return array{list<array<string, mixed>>, list<string>}
     */
    private function matchIdleDemandRows(array $idleRows, array $contractedBySlot, array $normalDemandBySlot, string $modality): array
    {
        if ($idleRows === []) {
            return [[], []];
        }

        $slots = $modality === 'green' ? ['unique'] : ['peak', 'off_peak'];
        if (count($idleRows) === count($slots) && $this->hasExpectedDemandValues($slots, $contractedBySlot, $normalDemandBySlot)) {
            $direct = $this->assignmentCost($idleRows, $slots, $contractedBySlot, $normalDemandBySlot, false);
            $reverse = count($slots) === 2
                ? $this->assignmentCost($idleRows, $slots, $contractedBySlot, $normalDemandBySlot, true)
                : null;
            $reversed = $reverse !== null && bccomp($reverse, $direct, 4) < 0;
            $cost = $reversed ? $reverse : $direct;
            $assignment = $reversed ? array_reverse($slots) : $slots;
            $errors = $this->assignmentErrors($idleRows, $assignment, $contractedBySlot, $normalDemandBySlot);

            if (count(array_filter($errors, fn (string $error): bool => bccomp($error, self::IDLE_MATCH_TOLERANCE, 2) > 0)) === 0
                && ($reverse === null || bccomp($direct, $reverse, 4) !== 0)) {
                $items = [];
                foreach ($idleRows as $index => $row) {
                    $items[] = $this->item(
                        itemType: 'idle_demand',
                        tariffComponent: 'demand',
                        timeSlot: $assignment[$index],
                        quantity: null,
                        unit: 'kW',
                        unitPriceTaxed: null,
                        tariffRate: null,
                        amount: $row['amount'],
                        pisCofins: $row['pis_cofins_amount'],
                        icmsBase: $row['icms_base'],
                        icmsRate: $row['icms_rate'],
                        icmsAmount: $row['icms_amount'],
                        sourceLabel: 'DEMANDA LEI ESTADUAL 16.886/18',
                    );
                }

                return [$items, []];
            }
        }

        $items = [];
        foreach ($idleRows as $index => $row) {
            $items[] = $this->item(
                itemType: 'idle_demand',
                tariffComponent: 'demand',
                timeSlot: 'unresolved_'.$index,
                quantity: null,
                unit: 'kW',
                unitPriceTaxed: null,
                tariffRate: null,
                amount: $row['amount'],
                pisCofins: $row['pis_cofins_amount'],
                icmsBase: $row['icms_base'],
                icmsRate: $row['icms_rate'],
                icmsAmount: $row['icms_amount'],
                sourceLabel: 'DEMANDA LEI ESTADUAL 16.886/18',
            );
        }

        return [$items, ['idle_demand_post_matching_unresolved']];
    }

    /** @param list<string> $slots
     * @param  array<string, string>  $contractedBySlot
     * @param  array<string, array<string, mixed>>  $normalDemandBySlot
     */
    private function hasExpectedDemandValues(array $slots, array $contractedBySlot, array $normalDemandBySlot): bool
    {
        foreach ($slots as $slot) {
            if (! isset($contractedBySlot[$slot], $normalDemandBySlot[$slot])
                || $normalDemandBySlot[$slot]['unit_price_taxed'] === null) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, string>> $idleRows
     * @param  list<string>  $slots
     * @param  array<string, string>  $contractedBySlot
     * @param  array<string, array<string, mixed>>  $normalDemandBySlot
     */
    private function assignmentCost(array $idleRows, array $slots, array $contractedBySlot, array $normalDemandBySlot, bool $reverse): string
    {
        $assignment = $reverse ? array_reverse($slots) : $slots;
        $cost = '0.0000';
        foreach ($this->assignmentErrors($idleRows, $assignment, $contractedBySlot, $normalDemandBySlot) as $error) {
            $cost = bcadd($cost, bcmul($error, $error, 4), 4);
        }

        return $cost;
    }

    /** @param list<array<string, string>> $idleRows
     * @param  list<string>  $assignment
     * @param  array<string, string>  $contractedBySlot
     * @param  array<string, array<string, mixed>>  $normalDemandBySlot
     * @return list<string>
     */
    private function assignmentErrors(array $idleRows, array $assignment, array $contractedBySlot, array $normalDemandBySlot): array
    {
        $errors = [];
        foreach ($idleRows as $index => $row) {
            $slot = $assignment[$index];
            $normal = $normalDemandBySlot[$slot];
            $unusedDemand = bcsub($contractedBySlot[$slot], $normal['quantity'], 3);
            $taxRatio = bcsub('1.0000', $normal['icms_rate'], 4);
            $expected = bcmul(bcmul($unusedDemand, $normal['unit_price_taxed'], 10), $taxRatio, 10);
            $expected = bcadd($expected, '0.0050000000', 10);
            $expected = bcadd($expected, '0', 2);
            $difference = bcsub($row['amount'], $expected, 2);
            $errors[] = bccomp($difference, '0.00', 2) < 0 ? bcsub('0.00', $difference, 2) : $difference;
        }

        return $errors;
    }

    private function referenceMonth(string $text): string
    {
        if (preg_match('/(?:refer[eê]ncia|m[eê]s(?:\s*\/\s*ano)?)\s*[:\-]?\s*(0[1-9]|1[0-2])[\/.-](20\d{2})/iu', $text, $match) === 1) {
            return $match[2].'-'.$match[1];
        }

        if (preg_match('/(?:janeiro|fevereiro|mar[cç]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+(?:de\s+)?(20\d{2})/iu', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $month = mb_strtolower(substr($text, $match[0][1], strpos($match[0][0], ' ') ?: strlen($match[0][0])));
            $months = ['janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'marco' => '03', 'abril' => '04', 'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08', 'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12'];

            return $match[1][0].'-'.($months[$month] ?? '01');
        }

        preg_match_all('~\b(0[1-9]|1[0-2])/(20\d{2})\b~', $text, $dates, PREG_SET_ORDER);
        $dateCounts = [];
        foreach ($dates as $date) {
            $key = $date[2].'-'.$date[1];
            $dateCounts[$key] = ($dateCounts[$key] ?? 0) + 1;
        }
        if ($dateCounts !== []) {
            arsort($dateCounts);

            return (string) array_key_first($dateCounts);
        }

        throw new InvalidArgumentException('Não foi possível ler o mês de referência da fatura ENEL.');
    }

    private function consumerUnit(string $text): string
    {
        if (preg_match('/(?:unidade\s+consumidora|instala[cç][aã]o|\bUC)\s*[:#\-]?\s*(\d{5,})/iu', $text, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/^\s*(\d{8,})\s+(?:ENRG|DEMANDA|CONSUMO)\b/imu', $text, $match) === 1) {
            return $match[1];
        }

        throw new InvalidArgumentException('Não foi possível ler a unidade consumidora da fatura ENEL.');
    }

    private function subgroup(string $text): string
    {
        if (preg_match('/subgrupo\s*[:\-]?\s*(A4|AS)\b/iu', $text, $match) === 1
            || preg_match('/\b(A4|AS)\s*[-|]\s*(?:hor[aá]ria\s+)?(?:azul|verde)\b/iu', $text, $match) === 1) {
            return strtoupper($match[1]);
        }

        throw new InvalidArgumentException('Não foi possível ler o subgrupo da fatura ENEL.');
    }

    private function modality(string $text): string
    {
        if (preg_match('/modalidade(?:\s+tarif[aá]ria)?\s*[:\-]?\s*(?:hor[aá]ria\s+)?(azul|verde)\b/iu', $text, $match) === 1
            || preg_match('/hor[aá]ria\s+(azul|verde)\b/iu', $text, $match) === 1) {
            return strtolower($match[1]) === 'azul' ? 'blue' : 'green';
        }

        $hasPeak = preg_match('~DEMANDA\s+PONTA\s*[-–]\s*KW~iu', $text) === 1;
        $hasOffPeak = preg_match('~DEMANDA\s+(?:FORA\s+(?:DE\s+)?PONTA|F\.?\s*PONTA)\s*[-–]\s*KW~iu', $text) === 1;
        if ($hasPeak && $hasOffPeak) {
            return 'blue';
        }
        if (preg_match('~DEMANDA\s+(?:ÚNICA\s*[-–]\s*KW|[-–]\s*KW)~iu', $text) === 1) {
            return 'green';
        }

        throw new InvalidArgumentException('Não foi possível ler a modalidade tarifária da fatura ENEL.');
    }

    private function totalPaid(string $text): string
    {
        $number = '(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}';
        if (preg_match('/\bTOTAL(?:\s+A\s+PAGAR|\s+DA\s+FATURA|\s+GERAL)?\s*[:\t ]+('.$number.')/iu', $text, $match) === 1) {
            return $this->normalizeLocaleDecimal($match[1]);
        }

        throw new InvalidArgumentException('Não foi possível ler o valor total pago da fatura ENEL.');
    }

    /** @return array<string, mixed> */
    private function item(
        string $itemType,
        string $tariffComponent,
        string $timeSlot,
        ?string $quantity,
        ?string $unit,
        ?string $unitPriceTaxed,
        ?string $tariffRate,
        string $amount,
        string $pisCofins,
        string $icmsBase,
        string $icmsRate,
        string $icmsAmount,
        string $sourceLabel,
        string $reactiveNature = 'not_applicable',
    ): array {
        return [
            'item_type' => $itemType,
            'tariff_component' => $tariffComponent,
            'time_slot' => $timeSlot,
            'reactive_nature' => $reactiveNature,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price_taxed' => $unitPriceTaxed,
            'tariff_rate' => $tariffRate,
            'amount' => $amount,
            'pis_cofins_amount' => $pisCofins,
            'icms_base' => $icmsBase,
            'icms_rate' => $icmsRate,
            'icms_amount' => $icmsAmount,
            'source_label' => $sourceLabel,
        ];
    }

    private function takeDecimal(string $text, int &$offset, int $scale): ?string
    {
        $this->skipSeparators($text, $offset);
        $integer = '(?:\d{1,3}(?:\.\d{3})+|\d+)';
        $pattern = '/^('.$integer.',\d{'.$scale.'})/';

        if (preg_match($pattern, substr($text, $offset), $match) !== 1) {
            return null;
        }

        $offset += strlen($match[1]);

        return $this->normalizeLocaleDecimal($match[1]);
    }

    private function takeRate(string $text, int &$offset): ?string
    {
        $this->skipSeparators($text, $offset);
        if (preg_match('/^(\d{1,2})\s*%/u', substr($text, $offset), $match) !== 1) {
            return null;
        }

        $offset += strlen($match[0]);

        return bcdiv($match[1], '100', 4);
    }

    private function skipSeparators(string $text, int &$offset): void
    {
        $length = strlen($text);
        while ($offset < $length && (ctype_space($text[$offset]) || str_contains('|:;', $text[$offset]))) {
            $offset++;
        }
    }

    private function normalizeLocaleDecimal(string $value): string
    {
        return str_replace(',', '.', str_replace('.', '', $value));
    }
}
