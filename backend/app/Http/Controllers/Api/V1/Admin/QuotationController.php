<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReleaseQuotationRequest;
use App\Http\Requests\Api\V1\Admin\StoreQuotationRequest;
use App\Models\Quotation;
use App\Quotations\QuotationPdfGenerator;
use App\Services\AdminQuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: orçamentos', 'Criação, revisão, liberação e envio de propostas comerciais')]
class QuotationController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar orçamentos', 'Retorna os orçamentos em ordem decrescente de criação.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"quote_number":"ORC-2026-000001","status":"draft","customer_name":"Ana Souza","total":"250.00","items":[]}],"links":{},"meta":{}}', description: 'Orçamentos paginados')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $quotations = Quotation::query()->with('items')->orderByDesc('id')->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => $quotations->getCollection()->map(fn (Quotation $quotation): array => $this->quotationData($quotation))->all(),
            'links' => [
                'first' => $quotations->url(1),
                'last' => $quotations->url($quotations->lastPage()),
                'prev' => $quotations->previousPageUrl(),
                'next' => $quotations->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $quotations->currentPage(),
                'per_page' => $quotations->perPage(),
                'last_page' => $quotations->lastPage(),
                'total' => $quotations->total(),
            ],
        ]);
    }

    #[Authenticated]
    #[Endpoint('Criar orçamento em rascunho', 'Cria uma proposta para revisão do admin, congelando os nomes e preços unitários atuais dos itens selecionados.')]
    #[BodyParam('customer_id', 'integer', 'ID do cliente destinatário.', example: 2)]
    #[BodyParam('items', 'array', 'Itens do orçamento com quantidade.', example: [['quotation_base_id' => 1, 'quantity' => '8.000']])]
    #[Response(status: 201, content: '{"data":{"id":1,"quote_number":"ORC-2026-000001","status":"draft","total":"2000.00"}}', description: 'Rascunho criado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Cliente, itens ou quantidades inválidas')]
    public function store(StoreQuotationRequest $request, AdminQuotationService $service): JsonResponse
    {
        $quotation = $service->create(
            (int) $request->validated('customer_id'),
            $request->validated('items'),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $this->quotationData($quotation)], 201);
    }

    #[Authenticated]
    #[Endpoint('Pré-visualizar PDF do orçamento', 'Gera o PDF A4 do rascunho ou orçamento liberado, sem enviar e-mail.')]
    #[Response(status: 200, content: '%PDF-1.4', description: 'Arquivo PDF A4')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Orçamento não encontrado')]
    public function pdf(Request $request, int $id, QuotationPdfGenerator $pdfGenerator)
    {
        $this->authorizeAdmin($request);
        $quotation = Quotation::query()->with('items')->findOrFail($id);
        $filename = 'orcamento-'.$quotation->quote_number.'.pdf';

        return response($pdfGenerator->generate($quotation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    #[Authenticated]
    #[Endpoint('Revisar, liberar e enviar orçamento', 'Registra o responsável técnico e a ART, libera a proposta e envia o PDF ao cliente por e-mail. O endpoint não envia rascunhos sem os dados técnicos obrigatórios.')]
    #[BodyParam('technical_responsible_name', 'string', 'Nome do Engenheiro Eletricista responsável.', example: 'Nome do responsável técnico')]
    #[BodyParam('crea_registration', 'string', 'Registro profissional no CREA-SP.', example: 'CREA-SP 0000000000')]
    #[BodyParam('art_number', 'string', 'Número da ART emitida para o serviço.', example: 'ART 00000000000000000')]
    #[Response(status: 200, content: '{"data":{"id":1,"status":"sent","sent_at":"2026-10-24T12:00:00Z"}}', description: 'Orçamento liberado e enviado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Orçamento não encontrado')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados técnicos ausentes ou orçamento já enviado')]
    public function release(ReleaseQuotationRequest $request, int $id, AdminQuotationService $service): JsonResponse
    {
        $quotation = Quotation::query()->findOrFail($id);
        $quotation = $service->releaseAndSend($quotation, $request->user(), $request->validated());

        return response()->json(['data' => $this->quotationData($quotation)]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    /** @return array<string, mixed> */
    private function quotationData(Quotation $quotation): array
    {
        return [
            ...$quotation->only([
                'id', 'quote_number', 'customer_id', 'customer_name', 'customer_email', 'company_name',
                'cnpj', 'status', 'issue_date', 'valid_until', 'subtotal', 'total',
                'technical_responsible_name', 'crea_registration', 'art_number', 'approved_at', 'sent_at',
            ]),
            'issue_date' => $quotation->issue_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'items' => $quotation->items->map(fn ($item): array => $item->only([
                'id', 'item_key', 'name', 'unit', 'quantity', 'unit_price', 'total',
            ]))->all(),
        ];
    }
}
