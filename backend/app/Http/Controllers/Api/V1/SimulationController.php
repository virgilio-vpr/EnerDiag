<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSimulationRequest;
use App\Invoices\Exceptions\UnsupportedDistributorException;
use App\Models\Simulation;
use App\Services\SubmitSimulation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Simulações', 'Envio e processamento de faturas de energia')]
class SimulationController extends Controller
{
    public function status(Request $request, int $simulationId): JsonResponse
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);

        return response()->json(['data' => [
            'id' => $simulation->id,
            'status' => $simulation->status,
            'processing_errors' => $simulation->processing_errors,
        ]]);
    }

    /**
     * Submit invoices for asynchronous processing.
     */
    #[Authenticated]
    #[Endpoint('Criar simulação', 'Valida os dados da empresa e as faturas PDF e registra uma simulação na fila.')]
    #[BodyParam('company_name', 'string', 'Nome da empresa.', example: 'Empresa Exemplo Ltda.')]
    #[BodyParam('distributor_id', 'integer', 'Identificador da distribuidora atendida.', example: 1)]
    #[BodyParam('distributor_name', 'string', 'Nome de uma distribuidora ainda não atendida.', example: 'Distribuidora Exemplo')]
    #[BodyParam('address_details', 'string', 'Endereço da empresa.', example: 'Rua Exemplo, 100, São Paulo/SP')]
    #[BodyParam('substation_same_address', 'boolean', 'Indica se o endereço da subestação é o mesmo da fatura.', example: true)]
    #[BodyParam('invoices[]', 'file', 'Faturas PDF (de 6 a 12 arquivos).')]
    #[Response(status: 202, content: '{"data":{"id":1,"status":"queued","created_at":"2026-10-24T12:00:00Z"}}', description: 'Simulação registrada')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 422, content: '{"message":"A distribuidora selecionada ainda não possui leitor disponível.","code":"DISTRIBUTOR_UNSUPPORTED","errors":null}', description: 'Distribuidora sem leitor')]
    public function store(StoreSimulationRequest $request, SubmitSimulation $submitSimulation): JsonResponse
    {
        try {
            /** @var Simulation $simulation */
            $simulation = $submitSimulation->handle(
                $request->user(),
                $request->validated(),
                array_values($request->file('invoices', [])),
            );
        } catch (UnsupportedDistributorException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'DISTRIBUTOR_UNSUPPORTED',
                'errors' => null,
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => $simulation->id,
                'status' => $simulation->status,
                'created_at' => $simulation->created_at?->toIso8601String(),
            ],
        ], 202);
    }
}
