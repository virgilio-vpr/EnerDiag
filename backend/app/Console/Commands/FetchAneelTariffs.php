<?php

namespace App\Console\Commands;

use App\Services\AutomaticAneelTariffImport;
use Illuminate\Console\Command;
use Throwable;

class FetchAneelTariffs extends Command
{
    protected $signature = 'tariffs:fetch-aneel';

    protected $description = 'Baixa tarifas da ANEEL e prepara revisão sem ativar valores';

    public function handle(AutomaticAneelTariffImport $service): int
    {
        try {
            $import = $service->fetch();
            $this->info("Importação #{$import->id}: {$import->status}. Nenhuma tarifa foi ativada por esta rotina.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Não foi possível preparar a importação. As tarifas vigentes foram preservadas.');

            return self::FAILURE;
        }
    }
}
