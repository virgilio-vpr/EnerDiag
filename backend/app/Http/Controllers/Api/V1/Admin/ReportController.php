<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateReportRequest;
use App\Mail\AdminReportResend;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: relatórios', 'Edição de textos e reenvio de relatórios')]
class ReportController extends Controller
{
    /** @var list<string> */
    private const CALCULATION_FIELDS = [
        'total_monthly_savings', 'total_yearly_savings', 'savings_percentage',
        'demand_savings_monthly', 'demand_savings_yearly', 'overrun_savings_monthly',
        'overrun_savings_yearly', 'reactive_savings_monthly', 'reactive_savings_yearly',
        'migration_savings_monthly', 'migration_savings_yearly', 'recommended_modality',
        'optimal_demand_peak', 'optimal_demand_off_peak', 'optimal_demand_single',
        'chart_series', 'warnings', 'r_values', 'k_values', 'algorithm_version',
        'tariff_version_ids', 'regulatory_parameter_version_ids',
    ];

    /**
     * Update editable report text and create an immutable revision snapshot.
     */
    #[Authenticated]
    #[Endpoint('Editar relatório', 'Atualiza textos explicativos e recomendações, preservando os resultados calculados e registrando uma revisão.')]
    #[BodyParam('explanatory_texts', 'array', 'Textos explicativos do relatório.', example: ['Ajuste recomendado da demanda.'])]
    #[BodyParam('recommendations', 'array', 'Recomendações do relatório.', example: ['Avalie a adequação da demanda contratada.'])]
    #[Response(status: 200, content: '{"data":{"id":1,"total_monthly_savings":"100.00","explanatory_texts":["Texto revisado"],"recommendations":["Recomendação revisada"]}}', description: 'Relatório atualizado e revisão registrada')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Campos inválidos ou tentativa de alterar resultados')]
    public function update(UpdateReportRequest $request, int $id): JsonResponse
    {
        $report = Report::query()->findOrFail($id);
        $editable = $request->validated();

        DB::transaction(function () use ($report, $editable, $request): void {
            $snapshot = [];
            foreach (self::CALCULATION_FIELDS as $field) {
                $snapshot[$field] = $report->getAttribute($field);
            }

            $report->fill($editable)->save();
            $report->revisions()->create([
                'user_id' => $request->user()->id,
                'explanatory_texts' => $report->explanatory_texts,
                'recommendations' => $report->recommendations,
                'calculation_snapshot' => $snapshot,
            ]);
        });

        return response()->json(['data' => $report->fresh()]);
    }

    /**
     * Resend the current report summary to its customer by email.
     */
    #[Authenticated]
    #[Endpoint('Reenviar relatório', 'Envia manualmente por e-mail o resumo atual do relatório ao cliente.')]
    #[Response(status: 200, content: '{"message":"Relatório reenviado por e-mail."}', description: 'E-mail enviado')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Relatório não encontrado')]
    public function resend(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $report = Report::query()->with('simulation.user')->findOrFail($id);

        Mail::to($report->simulation->user->email)->send(new AdminReportResend($report));

        return response()->json(['message' => 'Relatório reenviado por e-mail.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
