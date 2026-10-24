<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Sistema', 'Endpoints do sistema e integridade da API')]
class StatusController extends Controller
{
    /**
     * Status da API
     *
     * Retorna a disponibilidade e a versão da API EnerDiag.
     */
    #[Endpoint('Status da API', 'Retorna o status operacional da API EnerDiag v1.')]
    #[Response(
        status: 200,
        content: '{"status":"ok","version":"v1","environment":"local"}',
        description: 'API em funcionamento normal'
    )]
    #[Response(
        status: 500,
        content: '{"message":"Erro interno do servidor.","code":"INTERNAL_SERVER_ERROR","errors":null}',
        description: 'Erro interno'
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => 'v1',
            'environment' => config('app.env'),
        ]);
    }
}
