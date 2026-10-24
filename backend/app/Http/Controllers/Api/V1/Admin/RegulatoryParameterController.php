<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreRegulatoryParameterRequest;
use App\Models\RegulatoryParameter;
use App\Services\AdminRegulatoryParameterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: parâmetros', 'Consulta e versionamento de parâmetros regulatórios nacionais')]
class RegulatoryParameterController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar parâmetros regulatórios', 'Retorna os parâmetros e suas vigências em ordem decrescente.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"key":"idle_demand_discount_fallback","value":"0.18000","state":null,"valid_from":"2021-01-01","valid_to":null}],"links":{},"meta":{}}', description: 'Parâmetros paginados')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $parameters = RegulatoryParameter::query()
            ->whereNull('state')
            ->orderBy('key')
            ->orderByDesc('valid_from')
            ->paginate(min(max((int) $request->query('per_page', 50), 1), 100));

        return response()->json($parameters);
    }

    #[Authenticated]
    #[Endpoint('Criar versão de parâmetro regulatório', 'Registra um novo valor nacional com vigência, preservando versões anteriores.')]
    #[BodyParam('key', 'string', 'Chave do parâmetro.', example: 'idle_demand_discount_fallback')]
    #[BodyParam('value', 'number', 'Novo valor decimal.', example: '0.18000')]
    #[BodyParam('valid_from', 'string', 'Início da nova vigência.', example: '2027-01-01')]
    #[BodyParam('valid_to', 'string', 'Fim opcional da vigência.', required: false, example: null)]
    #[Response(status: 201, content: '{"data":{"id":2,"key":"idle_demand_discount_fallback","value":"0.20000","state":null,"valid_from":"2027-01-01","valid_to":null}}', description: 'Nova versão criada')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou vigência sobreposta')]
    public function store(StoreRegulatoryParameterRequest $request, AdminRegulatoryParameterService $parameterService): JsonResponse
    {
        $parameter = $parameterService->createVersion($request->validated());

        return response()->json(['data' => $parameter], 201);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
