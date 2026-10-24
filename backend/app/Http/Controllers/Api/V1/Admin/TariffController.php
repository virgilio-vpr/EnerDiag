<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreTariffRequest;
use App\Models\Tariff;
use App\Services\AdminTariffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: tarifas', 'Consulta e versionamento de tarifas por vigência')]
class TariffController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar tarifas', 'Retorna tarifas paginadas, incluindo sua base tributária e origem.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"distributor_id":1,"subgroup":"A4","tariff_modality":"blue","time_slot":"peak","component":"demand","rate":"37.80322","includes_taxes":true,"source":"manual","valid_from":"2026-01-01","valid_to":null}],"links":{},"meta":{}}', description: 'Tarifas paginadas')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $tariffs = Tariff::query()->with('distributor:id,name')->orderBy('id')->paginate($perPage);

        return response()->json($tariffs);
    }

    #[Authenticated]
    #[Endpoint('Cadastrar tarifa', 'Cria uma tarifa sem sobreposição de vigência.')]
    #[Response(status: 201, content: '{"data":{"id":1,"includes_taxes":false,"source":"manual"}}', description: 'Tarifa criada')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou vigência sobreposta')]
    public function store(StoreTariffRequest $request, AdminTariffService $tariffService): JsonResponse
    {
        $tariff = $tariffService->create($request->validated());

        return response()->json(['data' => $tariff], 201);
    }

    #[Authenticated]
    #[Endpoint('Criar nova versão de tarifa', 'Preserva os valores da versão existente e cria outra tarifa com nova vigência.')]
    #[Response(status: 201, content: '{"data":{"id":2,"includes_taxes":false,"source":"manual"}}', description: 'Nova versão criada')]
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
