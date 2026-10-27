<?php

namespace App\Services;

use App\Models\Distributor;
use App\Models\Tariff;
use App\Models\TariffImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SplFileObject;

final class AneelTariffImporter
{
    /** @var Collection<int, Distributor>|null */
    private $distributors = null;

    /** Read current and future application tariffs without changing the active table.
     * @return array{tariffs: list<array<string, mixed>>, rows_ignored: int}
     */
    public function preview(string $path): array
    {
        $csv = new SplFileObject($path);
        $csv->setCsvControl($this->delimiter($path));
        $header = $csv->fgetcsv();
        $header = is_array($header) ? array_map(fn ($column): string => trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF"), $header) : [];
        if (array_diff(self::REQUIRED_COLUMNS, $header) !== []) {
            throw ValidationException::withMessages(['file' => 'O CSV não corresponde ao conjunto oficial de tarifas da ANEEL.']);
        }
        $tariffs = [];
        $ignored = 0;
        while (! $csv->eof()) {
            $row = $csv->fgetcsv();
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if (count($row) !== count($header)) {
                throw ValidationException::withMessages(['file' => 'O CSV contém uma linha incompleta.']);
            }
            $record = array_combine($header, array_map(fn ($value): string => trim((string) $value), $row));
            $mapped = $this->mapRow($record);
            if ($mapped === null) {
                $ignored++;

                continue;
            }
            foreach ($mapped['tariffs'] as $attributes) {
                if ($attributes['valid_to'] !== null && $attributes['valid_to'] < now()->toDateString()) {
                    $ignored++;

                    continue;
                }
                $tariffs[json_encode($attributes, JSON_THROW_ON_ERROR)] = $attributes;
                if (count($tariffs) > 10000) {
                    throw ValidationException::withMessages(['file' => 'O conjunto excede o limite de tarifas para revisão.']);
                }
            }
        }
        if ($tariffs === []) {
            throw ValidationException::withMessages(['file' => 'Nenhuma tarifa vigente ou futura foi encontrada no escopo.']);
        }
        ksort($tariffs);

        return ['tariffs' => array_values($tariffs), 'rows_ignored' => $ignored];
    }

    /** Activate only the reviewed snapshot, atomically.
     * @param  list<array<string, mixed>>  $tariffs
     */
    public function activate(array $tariffs): int
    {
        return DB::transaction(function () use ($tariffs): int {
            $activeDistributors = Distributor::query()->whereIn('id', array_unique(array_column($tariffs, 'distributor_id')))
                ->where('active', true)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $accepted = 0;
            foreach ($tariffs as $attributes) {
                if ($this->existingExactTariff($attributes)) {
                    continue;
                }
                if (! in_array($attributes['distributor_id'], $activeDistributors, true)
                    || ! $this->closePreviousTariffs($attributes)) {
                    throw ValidationException::withMessages(['import' => 'Há conflito de vigência ou distribuidora inativa. Revise as tarifas antes de aprovar.']);
                }
                Tariff::query()->create($attributes);
                $accepted++;
            }

            return $accepted;
        });
    }

    private const REQUIRED_COLUMNS = [
        'DscREH', 'SigAgente', 'DatInicioVigencia', 'DatFimVigencia', 'DscBaseTarifaria',
        'DscSubGrupo', 'DscModalidadeTarifaria', 'DscClasse', 'DscSubClasse', 'DscDetalhe', 'NomPostoTarifario', 'DscUnidadeTerciaria',
        'SigAgenteAcessante', 'VlrTUSD', 'VlrTE',
    ];

    /** @return array{import: TariffImport, rows_accepted: int, rows_ignored: int} */
    public function import(UploadedFile $file, User $user): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl($this->delimiter($file->getRealPath()));
        $header = $csv->fgetcsv();

        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => 'O CSV da ANEEL está vazio ou inválido.']);
        }

        $header = array_map(fn ($column): string => trim((string) $column, " \t\n\r\0\x0B\xEF\xBB\xBF"), $header);
        $positions = array_flip($header);
        if (array_diff(self::REQUIRED_COLUMNS, array_keys($positions)) !== []) {
            throw ValidationException::withMessages(['file' => 'O CSV não corresponde ao conjunto oficial de tarifas da ANEEL.']);
        }

        return DB::transaction(function () use ($csv, $positions, $file, $user): array {
            $accepted = 0;
            $ignored = 0;
            $references = [];

            while (! $csv->eof()) {
                $row = $csv->fgetcsv();
                if (! is_array($row) || count($row) < count($positions) || count(array_filter($row, fn ($value): bool => $value !== null && $value !== '')) === 0) {
                    continue;
                }

                $record = [];
                foreach ($positions as $column => $index) {
                    $record[$column] = trim((string) ($row[$index] ?? ''));
                }

                $mapped = $this->mapRow($record);
                if ($mapped === null) {
                    $ignored++;

                    continue;
                }

                $references[$mapped['source_reference']] = true;
                foreach ($mapped['tariffs'] as $attributes) {
                    if ($this->existingExactTariff($attributes)) {
                        continue;
                    }

                    if (! $this->closePreviousTariffs($attributes)) {
                        $ignored++;

                        continue;
                    }

                    Tariff::query()->create($attributes);
                    $accepted++;
                }
            }

            if ($accepted === 0 && $ignored === 0) {
                throw ValidationException::withMessages(['file' => 'O CSV da ANEEL não contém linhas de tarifa válidas.']);
            }

            $import = TariffImport::query()->create([
                'source_reference' => implode('; ', array_keys($references)) ?: null,
                'file_name' => $file->getClientOriginalName(),
                'imported_by' => $user->id,
                'imported_at' => now(),
                'rows_accepted' => $accepted,
                'rows_ignored' => $ignored,
                'status' => 'completed',
            ]);

            return ['import' => $import, 'rows_accepted' => $accepted, 'rows_ignored' => $ignored];
        });
    }

    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $firstLine = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /** @param array<string, string> $row
     * @return array{source_reference: string, tariffs: list<array<string, mixed>>}|null
     */
    private function mapRow(array $row): ?array
    {
        $subgroup = strtoupper($row['DscSubGrupo']);
        $modality = $this->normalized($row['DscModalidadeTarifaria']);
        $timeSlot = $this->mapTimeSlot($row['NomPostoTarifario']);
        $unit = $this->normalized($row['DscUnidadeTerciaria']);
        $base = $this->normalized($row['DscBaseTarifaria']);
        $agent = trim($row['SigAgenteAcessante']);
        if ($base !== $this->normalized('Tarifa de Aplicação')
            || ! in_array($this->normalized($row['DscDetalhe'] ?? ''), ['', 'nao se aplica'], true)
            || ! in_array($this->normalized($row['DscClasse'] ?? ''), ['', 'nao se aplica'], true)
            || ! in_array($this->normalized($row['DscSubClasse'] ?? ''), ['', 'nao se aplica'], true)
            || ! in_array($subgroup, ['A4', 'AS'], true)
            || ! in_array($modality, ['horaria azul', 'horaria verde', 'azul', 'verde'], true)
            || $timeSlot === null
            || ($agent !== '' && ! in_array($this->normalized($agent), ['', 'nao se aplica', 'na'], true))) {
            return null;
        }

        $distributor = $this->findDistributor($row['SigAgente']);
        if ($distributor === null) {
            return null;
        }

        $tariffModality = str_ends_with($modality, 'azul') ? 'blue' : 'green';
        $sourceReference = trim($row['DscREH']);
        $validFrom = $this->parseDate($row['DatInicioVigencia']);
        $validTo = $this->parseDate($row['DatFimVigencia'], true);
        if ($sourceReference === '' || $validFrom === null || ($row['DatFimVigencia'] !== '' && $validTo === null)) {
            return null;
        }

        $tariffs = [];
        if ($unit === 'kw') {
            if (! $this->isDecimal($row['VlrTUSD']) || $timeSlot === 'single' && $tariffModality !== 'green') {
                return null;
            }
            $tariffs[] = $this->attributes($distributor, $subgroup, $tariffModality, $timeSlot, 'demand', $row['VlrTUSD'], $sourceReference, $validFrom, $validTo);
        } elseif ($unit === 'mwh' && $timeSlot !== 'single') {
            if ($this->isDecimal($row['VlrTUSD'])) {
                $tariffs[] = $this->attributes($distributor, $subgroup, $tariffModality, $timeSlot, 'energy_tusd', $this->perKwh($row['VlrTUSD']), $sourceReference, $validFrom, $validTo);
            }
            if ($this->isDecimal($row['VlrTE'])) {
                $tariffs[] = $this->attributes($distributor, $subgroup, $tariffModality, $timeSlot, 'energy_te', $this->perKwh($row['VlrTE']), $sourceReference, $validFrom, $validTo);
            }
        }

        return $tariffs === [] ? null : ['source_reference' => $sourceReference, 'tariffs' => $tariffs];
    }

    private function findDistributor(string $agent): ?Distributor
    {
        $normalizedAgent = $this->normalized($agent);
        $distributors = $this->distributors ??= Distributor::query()->where('active', true)->get();
        foreach ($distributors as $distributor) {
            if ($normalizedAgent === $this->normalized($distributor->name)
                || $normalizedAgent === $this->normalized((string) $distributor->parser_key)
                || ($distributor->parser_key === 'enel' && in_array($normalizedAgent, ['eletropaulo', 'eletropaulo metropolitana', 'enel distribuicao sao paulo'], true))) {
                return $distributor;
            }
        }

        return null;
    }

    private function mapTimeSlot(string $value): ?string
    {
        return match ($this->normalized($value)) {
            'ponta' => 'peak',
            'fora ponta', 'fora de ponta' => 'off_peak',
            'nao se aplica' => 'single',
            default => null,
        };
    }

    private function attributes(Distributor $distributor, string $subgroup, string $modality, string $timeSlot, string $component, string $rawRate, string $reference, string $validFrom, ?string $validTo): array
    {
        return [
            'distributor_id' => $distributor->id,
            'subgroup' => $subgroup,
            'tariff_modality' => $modality,
            'time_slot' => $timeSlot,
            'component' => $component,
            'rate' => bcadd($this->normalizeDecimal($rawRate), '0', 5),
            'includes_taxes' => false,
            'source' => 'aneel_import',
            'source_reference' => $reference,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }

    private function existingExactTariff(array $attributes): bool
    {
        return Tariff::query()->where($attributes)->exists();
    }

    private function closePreviousTariffs(array $attributes): bool
    {
        $previousTariffs = Tariff::query()
            ->where('distributor_id', $attributes['distributor_id'])
            ->where('subgroup', $attributes['subgroup'])
            ->where('tariff_modality', $attributes['tariff_modality'])
            ->where('time_slot', $attributes['time_slot'])
            ->where('component', $attributes['component'])
            ->where('includes_taxes', false)
            ->whereDate('valid_from', '<', $attributes['valid_from'])
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $attributes['valid_from']))
            ->get();

        foreach ($previousTariffs as $previous) {
            $previous->update(['valid_to' => Carbon::parse($attributes['valid_from'])->subDay()->toDateString()]);
        }

        return ! Tariff::query()
            ->where('distributor_id', $attributes['distributor_id'])
            ->where('subgroup', $attributes['subgroup'])
            ->where('tariff_modality', $attributes['tariff_modality'])
            ->where('time_slot', $attributes['time_slot'])
            ->where('component', $attributes['component'])
            ->where('includes_taxes', false)
            ->whereDate('valid_from', '<=', $attributes['valid_to'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $attributes['valid_from']))
            ->exists();
    }

    private function parseDate(string $value, bool $nullable = false): ?string
    {
        if ($nullable && trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function perKwh(string $value): string
    {
        return bcdiv($this->normalizeDecimal($value), '1000', 5);
    }

    private function isDecimal(string $value): bool
    {
        $normalized = $this->normalizeDecimal($value);

        return preg_match('/\A(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)\z/', $normalized)
            && bccomp($normalized, '0', 8) > 0;
    }

    private function normalizeDecimal(string $value): string
    {
        $normalized = trim(str_replace(' ', '', $value));
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            return str_replace(',', '', $normalized);
        }

        return str_replace(',', '.', $normalized);
    }

    private function normalized(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
