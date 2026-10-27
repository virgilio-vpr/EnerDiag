<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ApproveTariffImportRequest;
use App\Models\TariffImport;
use App\Services\AutomaticAneelTariffImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: tarifas', 'Revisão de tarifas baixadas automaticamente da ANEEL')]
#[Authenticated]
#[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}')]
#[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}')]
class TariffImportReviewController extends Controller
{
    #[Endpoint('Listar importações pendentes', 'Nenhuma tarifa pendente é usada pelo motor de cálculo.')]
    #[Response(status: 200, content: '{"data":[],"meta":{"current_page":1,"last_page":1,"total":0}}')]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TariffImport::class);
        $imports = TariffImport::query()->where('status', 'pending')
            ->select(['id', 'source_reference', 'source_url', 'imported_at', 'status', 'rows_ignored'])
            ->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $imports->items(),
            'meta' => ['current_page' => $imports->currentPage(), 'last_page' => $imports->lastPage(), 'total' => $imports->total()],
        ]);
    }

    #[Endpoint('Revisar importação', 'Mostra os valores normalizados, em decimal e sem tributos, antes da aprovação.')]
    #[Response(status: 200, content: '{"data":{"id":1,"status":"pending","pending_tariffs":[]}}')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}')]
    public function show(Request $request, TariffImport $tariffImport): JsonResponse
    {
        $this->authorize('view', $tariffImport);

        return response()->json(['data' => $tariffImport]);
    }

    #[Endpoint('Aprovar importação', 'Ativa somente os valores revisados, em transação única, e registra o administrador responsável.')]
    #[BodyParam('confirmed', 'boolean', 'Confirmação explícita da revisão das tarifas.', required: true, example: true)]
    #[Response(status: 200, content: '{"message":"Importação aprovada. Tarifas ativadas.","data":{"id":1,"status":"completed"}}')]
    #[Response(status: 409, content: '{"message":"Esta importação não está pendente de revisão.","code":"HTTP_ERROR","errors":null}')]
    #[Response(status: 422, content: '{"message":"Confirme a revisão.","code":"VALIDATION_ERROR","errors":{"confirmed":["Confirme a revisão."]}}')]
    public function approve(ApproveTariffImportRequest $request, TariffImport $tariffImport, AutomaticAneelTariffImport $service): JsonResponse
    {
        return response()->json([
            'message' => 'Importação aprovada. Tarifas ativadas.',
            'data' => $service->approve($tariffImport, $request->user()),
        ]);
    }
}
