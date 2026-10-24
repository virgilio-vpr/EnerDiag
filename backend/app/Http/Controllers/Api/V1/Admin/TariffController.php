<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreTariffRequest;
use App\Models\Tariff;
use App\Services\AdminTariffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: tarifas', 'Consulta e versionamento de tarifas por vigência')]
class TariffController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar tarifas', 'Retorna tarifas paginadas, incluindo sua base tributária e origem.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"distributor_id":1,"subgroup":"A4","tariff_modality":"blue","time_slot":"peak","component":"demand","rate":"37.80322","includes_taxes":true,"source":"manual","valid_from":"2026-01-01","valid_to":null}],"links":{},"meta":{}}', description: 'Tarifas paginadas')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $tariffs = Tariff::query()->with('distributor:id,name')->orderBy('id')->paginate($perPage);

        return response()->json([
            'data' => $tariffs->items(),
            'links' => [
                'first' => $tariffs->url(1),
                'last' => $tariffs->url($tariffs->lastPage()),
                'prev' => $tariffs->previousPageUrl(),
                'next' => $tariffs->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $tariffs->currentPage(),
                'per_page' => $tariffs->perPage(),
                'last_page' => $tariffs->lastPage(),
                'total' => $tariffs->total(),
            ],
        ]);
    }

    #[Authenticated]
    #[Endpoint('Cadastrar tarifa', 'Cria uma tarifa sem sobreposição de vigência.')]
    #[BodyParam('distributor_id', 'integer', 'ID de uma distribuidora ativa.', example: 1)]
    #[BodyParam('subgroup', 'string', 'Subgrupo tarifário.', example: 'A4')]
    #[BodyParam('tariff_modality', 'string', 'Modalidade tarifária.', example: 'blue')]
    #[BodyParam('time_slot', 'string', 'Posto tarifário.', example: 'peak')]
    #[BodyParam('component', 'string', 'Componente da tarifa.', example: 'demand')]
    #[BodyParam('rate', 'number', 'Valor unitário com até cinco casas decimais.', example: '37.80322')]
    #[BodyParam('includes_taxes', 'boolean', 'Indica se o valor inclui tributos.', example: true)]
    #[BodyParam('valid_from', 'string', 'Início da vigência.', example: '2026-01-01')]
    #[BodyParam('valid_to', 'string', 'Fim opcional da vigência.', required: false, example: null)]
    #[BodyParam('source_reference', 'string', 'Referência regulatória de origem.', required: false, example: 'REH 1234/2026')]
    #[Response(status: 201, content: '{"data":{"id":1,"includes_taxes":false,"source":"manual"}}', description: 'Tarifa criada')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou vigência sobreposta')]
    public function store(StoreTariffRequest $request, AdminTariffService $tariffService): JsonResponse
    {
        $tariff = $tariffService->create($request->validated());

        return response()->json(['data' => $tariff], 201);
    }

    #[Authenticated]
    #[Endpoint('Criar nova versão de tarifa', 'Preserva os valores da versão existente e cria outra tarifa com nova vigência.')]
    #[BodyParam('distributor_id', 'integer', 'ID de uma distribuidora ativa.', example: 1)]
    #[BodyParam('subgroup', 'string', 'Subgrupo tarifário.', example: 'A4')]
    #[BodyParam('tariff_modality', 'string', 'Modalidade tarifária.', example: 'blue')]
    #[BodyParam('time_slot', 'string', 'Posto tarifário.', example: 'peak')]
    #[BodyParam('component', 'string', 'Componente da tarifa.', example: 'demand')]
    #[BodyParam('rate', 'number', 'Valor unitário com até cinco casas decimais.', example: '40.00000')]
    #[BodyParam('includes_taxes', 'boolean', 'Indica se o valor inclui tributos.', example: true)]
    #[BodyParam('valid_from', 'string', 'Início da nova vigência.', example: '2027-01-01')]
    #[BodyParam('valid_to', 'string', 'Fim opcional da vigência.', required: false, example: null)]
    #[BodyParam('source_reference', 'string', 'Referência regulatória de origem.', required: false, example: 'REH 5678/2027')]
    #[Response(status: 201, content: '{"data":{"id":2,"includes_taxes":false,"source":"manual"}}', description: 'Nova versão criada')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Tarifa não encontrada')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou vigência sobreposta')]
    public function update(StoreTariffRequest $request, int $id, AdminTariffService $tariffService): JsonResponse
    {
        $tariff = Tariff::query()->findOrFail($id);
        $newVersion = $tariffService->createVersion($tariff, $request->validated());

        return response()->json(['data' => $newVersion], 201);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
