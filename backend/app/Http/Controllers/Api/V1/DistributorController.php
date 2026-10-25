<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Distributor;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Distribuidoras', 'Distribuidoras atendidas disponíveis para simulação')]
class DistributorController extends Controller
{
    #[Endpoint('Listar distribuidoras atendidas', 'Retorna as distribuidoras ativas para seleção no fluxo de envio das faturas.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"name":"ENEL SP","state":"SP"}]}', description: 'Distribuidoras ativas')]
    #[Response(status: 500, content: '{"message":"Ocorreu um erro inesperado.","code":"INTERNAL_SERVER_ERROR","errors":null}', description: 'Falha ao consultar as distribuidoras')]
    public function index(): JsonResponse
    {
        $distributors = Distributor::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'state']);

        return response()->json(['data' => $distributors]);
    }
}
