<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ImportTariffRequest;
use App\Services\AneelTariffImporter;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: tarifas', 'Importação do conjunto de tarifas de aplicação da ANEEL')]
class TariffImportController extends Controller
{
    #[Authenticated]
    #[Endpoint('Importar tarifas da ANEEL', 'Importa as tarifas de aplicação em CSV, sem tributos, para os subgrupos A4 e AS de distribuidoras ativas.')]
    #[Response(status: 200, content: '{"message":"Importação concluída.","data":{"rows_accepted":3,"rows_ignored":1,"import_id":1}}', description: 'Resumo da importação')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Arquivo inválido')]
    public function store(ImportTariffRequest $request, AneelTariffImporter $importer): JsonResponse
    {
        $result = $importer->import($request->file('file'), $request->user());

        return response()->json([
            'message' => 'Importação concluída.',
            'data' => [
                'import_id' => $result['import']->id,
                'rows_accepted' => $result['rows_accepted'],
                'rows_ignored' => $result['rows_ignored'],
            ],
        ]);
    }
}
