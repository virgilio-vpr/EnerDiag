<?php

namespace App\Services;

use App\Models\TariffImport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AutomaticAneelTariffImport
{
    public function __construct(private AneelTariffImporter $importer) {}

    public function fetch(): TariffImport
    {
        return Cache::lock('aneel:fetch-tariffs', 240)->block(1, function (): TariffImport {
            $url = config('aneel.csv_url');
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'dadosabertos.aneel.gov.br') {
                throw new RuntimeException('A fonte deve usar HTTPS no portal oficial da ANEEL.');
            }
            $directory = storage_path('app/private/aneel-downloads');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Não foi possível preparar o download.');
            }
            $path = tempnam($directory, 'tariffs-');
            if ($path === false) {
                throw new RuntimeException('Não foi possível preparar o download.');
            }
            try {
                $maximum = config('aneel.max_download_bytes');
                Http::connectTimeout(15)->timeout(180)->withOptions([
                    'sink' => $path,
                    'allow_redirects' => false,
                    'progress' => function (int|float $total, int|float $downloaded) use ($maximum): void {
                        if ($total > $maximum || $downloaded > $maximum) {
                            throw new RuntimeException('O arquivo da ANEEL excede o limite de download.');
                        }
                    },
                ])->get($url)->throw();
                if (filesize($path) > $maximum) {
                    throw new RuntimeException('O arquivo da ANEEL excede o limite de download.');
                }
                $preview = $this->importer->preview($path);
                $hash = hash('sha256', json_encode($preview['tariffs'], JSON_THROW_ON_ERROR));

                return TariffImport::query()->firstOrCreate(['source_hash' => $hash], [
                    'source_reference' => Str::limit(implode('; ', array_unique(array_column($preview['tariffs'], 'source_reference'))), 255, ''),
                    'file_name' => 'tarifas-homologadas-distribuidoras-energia-eletrica.csv',
                    'source_url' => $url,
                    'imported_at' => now(),
                    'rows_accepted' => 0,
                    'rows_ignored' => $preview['rows_ignored'],
                    'status' => 'pending',
                    'pending_tariffs' => $preview['tariffs'],
                ]);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }

    public function approve(TariffImport $import, User $admin): TariffImport
    {
        return DB::transaction(function () use ($import, $admin): TariffImport {
            $locked = TariffImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending' && $locked->pending_tariffs !== null, 409, 'Esta importação não está pendente de revisão.');
            $accepted = $this->importer->activate($locked->pending_tariffs);
            $locked->update([
                'status' => 'completed', 'rows_accepted' => $accepted,
                'reviewed_by' => $admin->id, 'reviewed_at' => now(),
            ]);

            return $locked;
        });
    }
}
