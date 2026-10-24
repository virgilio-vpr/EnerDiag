<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeadRequest;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Leads', 'Interesse em distribuidoras ainda não atendidas')]
class LeadController extends Controller
{
    #[Endpoint('Registrar interesse em distribuidora', 'Registra um lead para uma distribuidora sem atendimento disponível.')]
    #[BodyParam('email', 'string', 'E-mail para contato.', example: 'cliente@empresa.com.br')]
    #[BodyParam('distributor_name', 'string', 'Nome da distribuidora de interesse.', example: 'Distribuidora Regional')]
    #[BodyParam('reason', 'string', 'Motivo do contato.', example: 'unsupported_distributor', required: false)]
    #[Response(status: 201, content: '{"data":{"id":1,"email":"cliente@empresa.com.br","distributor_name":"Distribuidora Regional","reason":"unsupported_distributor"}}', description: 'Lead registrado')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR"}', description: 'Dados inválidos')]
    #[Response(status: 429, content: '{"message":"Muitas requisições.","code":"TOO_MANY_REQUESTS"}', description: 'Limite de requisições excedido')]
    public function __invoke(StoreLeadRequest $request): JsonResponse
    {
        $lead = Lead::query()->create($request->validated());

        return response()->json(['data' => $lead->only(['id', 'email', 'distributor_name', 'reason'])], 201);
    }
}
