<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreQuotationItemRequest;
use App\Http\Requests\Api\V1\Admin\UpdateQuotationItemRequest;
use App\Models\QuotationBase;
use App\Services\AdminQuotationItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: orçamento', 'Consulta e manutenção dos itens de custo usados na preparação de orçamentos')]
class QuotationItemController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar itens de orçamento', 'Retorna os custos base cadastrados, ordenados pelo nome.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"item_key":"consulting_hour","name":"Hora técnica de consultoria","unit":"hour","unit_price":"250.00"}]}', description: 'Itens de orçamento')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => QuotationBase::query()->orderBy('name')->get()]);
    }

    #[Authenticated]
    #[Endpoint('Cadastrar item de orçamento', 'Cria um item de custo na tabela preparatória de orçamento.')]
    #[BodyParam('item_key', 'string', 'Identificador único do item.', example: 'consulting_hour')]
    #[BodyParam('name', 'string', 'Nome exibido do item.', example: 'Hora técnica de consultoria')]
    #[BodyParam('description', 'string', 'Descrição opcional.', required: false, example: 'Consultoria especializada')]
    #[BodyParam('unit', 'string', 'Unidade de cobrança.', example: 'hour')]
    #[BodyParam('unit_price', 'number', 'Preço unitário com duas casas decimais.', example: '250.00')]
    #[Response(status: 201, content: '{"data":{"id":1,"item_key":"consulting_hour","unit_price":"250.00"}}', description: 'Item criado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou chave duplicada')]
    public function store(StoreQuotationItemRequest $request, AdminQuotationItemService $service): JsonResponse
    {
        return response()->json(['data' => $service->create($request->validated())], 201);
    }

    #[Authenticated]
    #[Endpoint('Atualizar item de orçamento', 'Atualiza um item de custo existente.')]
    #[BodyParam('item_key', 'string', 'Identificador único do item.', example: 'consulting_hour')]
    #[BodyParam('name', 'string', 'Nome exibido do item.', example: 'Hora técnica de consultoria')]
    #[BodyParam('description', 'string', 'Descrição opcional.', required: false, example: 'Consultoria especializada')]
    #[BodyParam('unit', 'string', 'Unidade de cobrança.', example: 'hour')]
    #[BodyParam('unit_price', 'number', 'Preço unitário com duas casas decimais.', example: '275.00')]
    #[Response(status: 200, content: '{"data":{"id":1,"item_key":"consulting_hour","unit_price":"275.00"}}', description: 'Item atualizado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Recurso não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Item não encontrado')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos ou chave duplicada')]
    public function update(UpdateQuotationItemRequest $request, int $id, AdminQuotationItemService $service): JsonResponse
    {
        $item = QuotationBase::query()->findOrFail($id);

        return response()->json(['data' => $service->update($item, $request->validated())]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
