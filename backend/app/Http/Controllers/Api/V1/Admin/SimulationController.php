<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Calculation\Exceptions\ExpiredSimulationException;
use App\Calculation\Services\ReportRecalculationService;
use App\Http\Controllers\Controller;
use App\Models\Simulation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: simulações', 'Consulta paginada de simulações e dados extraídos')]
class SimulationController extends Controller
{
    /**
     * List simulations with pagination.
     */
    #[Authenticated]
    #[Endpoint('Listar simulações', 'Retorna simulações paginadas com identificação do cliente.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"user_id":1,"status":"completed","months_count":12,"total_monthly_savings":"100.00","total_yearly_savings":"1200.00","expires_at":null,"customer":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br"}}],"links":{},"meta":{"current_page":1,"per_page":15,"total":1}}', description: 'Simulações paginadas')]
    #[Response(status: 403, content: '{"message":"Esta ação não é autorizada.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $simulations = Simulation::query()
            ->with('user:id,name,email')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json($this->paginatedSimulations($simulations));
    }

    /**
     * Show a simulation and its extracted invoice data.
     */
    #[Authenticated]
    #[Endpoint('Consultar simulação', 'Retorna os dados extraídos das faturas e o relatório, sem documentos PDF.')]
    #[Response(status: 200, content: '{"data":{"id":1,"status":"completed","invoices":[],"report":null}}', description: 'Simulação encontrada')]
    #[Response(status: 404, content: '{"message":"Não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Simulação não encontrada')]
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $simulation = Simulation::query()
            ->with(['user:id,name,email', 'invoices.items', 'report'])
            ->findOrFail($id);

        return response()->json(['data' => $this->simulationData($simulation, true)]);
    }

    /** Recalculate a simulation report from its extracted invoice data. */
    #[Authenticated]
    #[Endpoint('Recalcular relatório', 'Recalcula os resultados a partir dos dados extraídos e registra uma nova versão.')]
    #[Response(status: 200, content: '{"data":{"id":1,"total_monthly_savings":"100.00","total_yearly_savings":"1200.00","algorithm_version":"2.0"}}', description: 'Relatório recalculado')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Simulação não encontrada')]
    #[Response(status: 422, content: '{"message":"Simulações expiradas não podem ser recalculadas.","code":"SIMULATION_EXPIRED","errors":null}', description: 'Simulação expirada')]
    public function recalculate(Request $request, int $id, ReportRecalculationService $recalculationService): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $report = $recalculationService->recalculate($id);
        } catch (ExpiredSimulationException) {
            return response()->json([
                'message' => 'Simulações expiradas não podem ser recalculadas.',
                'code' => 'SIMULATION_EXPIRED',
                'errors' => null,
            ], 422);
        }

        return response()->json(['data' => $report]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginatedSimulations(LengthAwarePaginator $simulations): array
    {
        return [
            'data' => $simulations->getCollection()->map(fn (Simulation $simulation): array => $this->simulationData($simulation))->all(),
            'links' => [
                'first' => $simulations->url(1),
                'last' => $simulations->url($simulations->lastPage()),
                'prev' => $simulations->previousPageUrl(),
                'next' => $simulations->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $simulations->currentPage(),
                'per_page' => $simulations->perPage(),
                'last_page' => $simulations->lastPage(),
                'total' => $simulations->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulationData(Simulation $simulation, bool $includeDetails = false): array
    {
        $data = [
            'id' => $simulation->id,
            'user_id' => $simulation->user_id,
            'status' => $simulation->status,
            'months_count' => $simulation->months_count,
            'total_monthly_savings' => $simulation->total_monthly_savings,
            'total_yearly_savings' => $simulation->total_yearly_savings,
            'expires_at' => $simulation->expires_at?->toIso8601String(),
            'customer' => $simulation->user ? [
                'id' => $simulation->user->id,
                'name' => $simulation->user->name,
                'email' => $simulation->user->email,
            ] : null,
        ];

        if ($includeDetails) {
            $data['invoices'] = $simulation->invoices->map(fn ($invoice): array => [
                ...$invoice->only(['id', 'reference_month', 'consumer_unit', 'subgroup', 'tariff_modality', 'total_paid']),
                'items' => $invoice->items->map(fn ($item): array => $item->only([
                    'id', 'item_type', 'tariff_component', 'time_slot', 'reactive_nature',
                    'quantity', 'unit', 'unit_price_taxed', 'tariff_rate', 'amount',
                    'pis_cofins_amount', 'icms_base', 'icms_rate', 'icms_amount', 'source_label',
                ]))->all(),
            ])->all();
            $data['report'] = $simulation->report?->only([
                'id', 'total_monthly_savings', 'total_yearly_savings', 'savings_percentage',
                'demand_savings_monthly', 'demand_savings_yearly', 'overrun_savings_monthly',
                'overrun_savings_yearly', 'reactive_savings_monthly', 'reactive_savings_yearly',
                'migration_savings_monthly', 'migration_savings_yearly', 'recommended_modality',
                'optimal_demand_peak', 'optimal_demand_off_peak', 'optimal_demand_single',
                'chart_series', 'warnings', 'explanatory_texts', 'recommendations', 'r_values',
                'k_values', 'algorithm_version', 'tariff_version_ids', 'regulatory_parameter_version_ids',
            ]);
        }

        return $data;
    }
}
