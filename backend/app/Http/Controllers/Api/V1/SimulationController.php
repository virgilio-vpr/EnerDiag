<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartInvoiceImportRequest;
use App\Http\Requests\Api\V1\StoreSimulationRequest;
use App\Invoices\Exceptions\UnsupportedDistributorException;
use App\Invoices\Exceptions\UnsupportedInvoiceReaderException;
use App\Invoices\Pdf\TemporaryPdfTextExtractor;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Models\CustomerProfile;
use App\Models\Distributor;
use App\Models\Simulation;
use App\Reports\PdfReportGenerator;
use App\Services\ImportInvoiceIntoSimulation;
use App\Services\RunSavedSimulation;
use App\Services\SimulationCapacity;
use App\Services\SubmitSimulation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\UrlParam;

#[Group('Simulações', 'Envio e processamento de faturas de energia')]
class SimulationController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar simulações', 'Retorna as simulações do cliente autenticado, com paginação.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"status":"completed","months_count":12,"total_monthly_savings":"1200.00","total_yearly_savings":"14400.00"}],"meta":{"current_page":1,"per_page":15,"total":1}}', description: 'Simulações do cliente')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    public function index(Request $request): JsonResponse
    {
        $simulations = $request->user()->simulations()
            ->select([
                'id',
                'status',
                'months_count',
                'total_monthly_savings',
                'total_yearly_savings',
                'created_at',
                'updated_at',
                'expires_at',
            ])
            ->withCount('invoices')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        $simulations->through(fn (Simulation $simulation): array => $this->simulationSummary($simulation));

        $pagination = $simulations->toArray();

        return response()->json([
            'data' => $pagination['data'],
            'links' => [
                'first' => $pagination['first_page_url'],
                'last' => $pagination['last_page_url'],
                'prev' => $pagination['prev_page_url'],
                'next' => $pagination['next_page_url'],
            ],
            'meta' => [
                'current_page' => $pagination['current_page'],
                'from' => $pagination['from'],
                'last_page' => $pagination['last_page'],
                'per_page' => $pagination['per_page'],
                'to' => $pagination['to'],
                'total' => $pagination['total'],
                'limit' => SimulationCapacity::MAX_SIMULATIONS,
                'can_create' => $pagination['total'] < SimulationCapacity::MAX_SIMULATIONS,
            ],
        ]);
    }

    #[Authenticated]
    #[Endpoint('Consultar status da simulação', 'Retorna o estado atual e erros por arquivo, quando houver.')]
    #[UrlParam('simulationId', 'integer', 'Identificador da simulação.', example: 1)]
    #[Response(status: 200, content: '{"data":{"id":1,"status":"failed","processing_errors":[{"file":"01-conta.pdf","code":"INVOICE_READ_FAILED","errors":{"message":"Não foi possível ler este arquivo."}}]}}', description: 'Status da simulação')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Simulação não encontrada para este cliente')]
    public function status(Request $request, int $simulationId): JsonResponse
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);

        return response()->json(['data' => [
            'id' => $simulation->id,
            'status' => $simulation->status,
            'processing_errors' => $simulation->status === 'failed' ? $simulation->processing_errors : null,
        ]]);
    }

    #[Authenticated]
    #[Endpoint('Consultar relatório da simulação', 'Retorna os indicadores, séries e avisos do relatório concluído.')]
    #[UrlParam('simulationId', 'integer', 'Identificador da simulação.', example: 1)]
    #[Response(status: 200, content: '{"data":{"id":1,"simulation_id":1,"months_count":12,"total_monthly_savings":"1200.00","total_yearly_savings":"14400.00","chart_series":{},"warnings":[]}}', description: 'Relatório da simulação')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Simulação não encontrada para este cliente')]
    #[Response(status: 409, content: '{"message":"O relatório ainda não está disponível.","code":"REPORT_NOT_READY","errors":null}', description: 'Simulação ainda não concluída')]
    public function report(Request $request, int $simulationId): JsonResponse
    {
        $simulation = $request->user()->simulations()->with('report')->findOrFail($simulationId);
        if ($simulation->status !== 'completed' || $simulation->report === null) {
            return response()->json([
                'message' => 'O relatório ainda não está disponível.',
                'code' => 'REPORT_NOT_READY',
                'errors' => null,
            ], 409);
        }

        return response()->json(['data' => array_merge($simulation->report->only([
            'id',
            'simulation_id',
            'total_monthly_savings',
            'total_yearly_savings',
            'savings_percentage',
            'demand_savings_monthly',
            'demand_savings_yearly',
            'overrun_savings_monthly',
            'overrun_savings_yearly',
            'reactive_savings_monthly',
            'reactive_savings_yearly',
            'migration_savings_monthly',
            'migration_savings_yearly',
            'recommended_modality',
            'optimal_demand_peak',
            'optimal_demand_off_peak',
            'optimal_demand_single',
            'chart_series',
            'warnings',
            'explanatory_texts',
            'recommendations',
            'r_values',
            'k_values',
        ]), ['months_count' => $simulation->months_count])]);
    }

    #[Authenticated]
    #[Endpoint('Baixar relatório em PDF', 'Retorna o relatório concluído em PDF para o cliente proprietário.')]
    #[UrlParam('simulationId', 'integer', 'Identificador da simulação.', example: 1)]
    #[Response(status: 200, content: 'PDF', description: 'Relatório PDF')]
    #[Response(status: 401, content: '{"message":"Não autenticado."}', description: 'Token ausente ou inválido')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado."}', description: 'Simulação não encontrada para este cliente')]
    #[Response(status: 409, content: '{"message":"O relatório ainda não está disponível."}', description: 'Simulação ainda não concluída')]
    public function pdf(Request $request, int $simulationId, PdfReportGenerator $generator): \Symfony\Component\HttpFoundation\Response
    {
        $simulation = $request->user()->simulations()->with('report')->findOrFail($simulationId);
        if ($simulation->status !== 'completed' || $simulation->report === null) {
            return response()->json([
                'message' => 'O relatório ainda não está disponível.',
                'code' => 'REPORT_NOT_READY',
                'errors' => null,
            ], 409);
        }

        return response($generator->generate($simulation->report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relatorio-enerdiag-'.$simulation->id.'.pdf"',
        ]);
    }

    #[Authenticated]
    #[Endpoint('Iniciar envio individual de faturas', 'Cria um rascunho para receber e salvar cada fatura separadamente.')]
    public function startInvoiceImport(StartInvoiceImportRequest $request, InvoiceReaderResolver $readerResolver, SimulationCapacity $capacity): JsonResponse
    {
        $data = $request->validated();
        $distributor = Distributor::query()->findOrFail($data['distributor_id']);
        if (! $distributor->active) {
            return response()->json(['message' => 'A distribuidora selecionada ainda não possui leitor disponível.', 'code' => 'DISTRIBUTOR_UNSUPPORTED', 'errors' => null], 422);
        }
        try {
            $readerResolver->resolve($distributor);
        } catch (UnsupportedInvoiceReaderException) {
            return response()->json(['message' => 'A distribuidora selecionada ainda não possui leitor disponível.', 'code' => 'DISTRIBUTOR_UNSUPPORTED', 'errors' => null], 422);
        }

        $simulation = DB::transaction(function () use ($request, $data, $capacity): Simulation {
            $capacity->assertCanCreate($request->user());
            CustomerProfile::query()->updateOrCreate(['user_id' => $request->user()->id], [
                'company_name' => $data['company_name'],
                'cnpj' => $data['cnpj'] ?? null,
                'distributor_id' => $data['distributor_id'],
                'transformer_power_kva' => $data['transformer_power_kva'] ?? null,
                'address_details' => $data['address_details'],
                'substation_same_address' => $data['substation_same_address'],
                'substation_address' => $data['substation_same_address'] ? null : ($data['substation_address'] ?? null),
            ]);

            return $request->user()->simulations()->create([
                'status' => 'draft',
                'months_count' => 0,
                'expires_at' => now()->addDays(7),
            ]);
        });

        return response()->json(['data' => ['id' => $simulation->id, 'status' => $simulation->status]], 201);
    }

    #[Authenticated]
    public function invoiceImportStatus(Request $request, int $simulationId, ImportInvoiceIntoSimulation $importer): JsonResponse
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);

        return response()->json(['data' => array_merge(['id' => $simulation->id, 'status' => $simulation->status], $importer->summary($simulation))]);
    }

    #[Authenticated]
    public function importInvoice(Request $request, int $simulationId, ImportInvoiceIntoSimulation $importer): JsonResponse
    {
        $validated = $request->validate(['invoice' => ['required', 'file', 'max:20480']]);
        $simulation = $request->user()->simulations()->with('user.profile.distributor')->findOrFail($simulationId);
        $summary = $importer->handle($simulation, $validated['invoice']);

        return response()->json(['data' => array_merge(['id' => $simulation->id, 'status' => 'draft'], $summary)], 201);
    }

    #[Authenticated]
    public function removeImportedInvoice(Request $request, int $simulationId, int $invoiceId, ImportInvoiceIntoSimulation $importer): JsonResponse
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);
        DB::transaction(function () use ($simulation, $invoiceId): void {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            abort_if($locked->status !== 'draft', 409, 'Este envio já foi finalizado.');
            $locked->invoices()->findOrFail($invoiceId)->delete();
        });

        return response()->json(['data' => array_merge(['id' => $simulation->id, 'status' => 'draft'], $importer->summary($simulation))]);
    }

    #[Authenticated]
    public function completeInvoiceImport(Request $request, int $simulationId, RunSavedSimulation $runner): JsonResponse
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);
        $started = microtime(true);
        $completed = $runner->handle($simulation);

        return response()->json(['data' => [
            'id' => $completed->id, 'status' => $completed->status,
            'processing_ms' => (int) round((microtime(true) - $started) * 1000),
        ]], $completed->status === 'completed' ? 200 : 202);
    }

    #[Authenticated]
    public function destroy(Request $request, int $simulationId): \Illuminate\Http\Response
    {
        $simulation = $request->user()->simulations()->findOrFail($simulationId);
        DB::transaction(function () use ($simulation): void {
            $locked = Simulation::query()->lockForUpdate()->findOrFail($simulation->id);
            abort_if($locked->status === 'processing' && $locked->updated_at->greaterThan(now()->subMinute()), 409, 'A simulação está sendo calculada. Aguarde a conclusão antes de excluir.');
            $locked->delete();
        });
        Storage::disk(TemporaryPdfTextExtractor::DISK)->deleteDirectory('simulation-'.$simulation->id);

        return response()->noContent();
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
    #[BodyParam('invoices[]', 'file', 'Faturas PDF (de 1 a 12 arquivos).')]
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

    /** @return array<string, mixed> */
    private function simulationSummary(Simulation $simulation): array
    {
        return [
            'id' => $simulation->id,
            'status' => $simulation->status,
            'months_count' => $simulation->months_count,
            'invoice_count' => $simulation->invoices_count ?? $simulation->months_count,
            'total_monthly_savings' => $simulation->total_monthly_savings,
            'total_yearly_savings' => $simulation->total_yearly_savings,
            'created_at' => $simulation->created_at?->toIso8601String(),
            'updated_at' => $simulation->updated_at?->toIso8601String(),
            'expires_at' => $simulation->expires_at?->toIso8601String(),
        ];
    }
}
