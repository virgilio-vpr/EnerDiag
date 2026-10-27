<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateInvoiceUploadBatchRequest;
use App\Http\Requests\Api\V1\StoreInvoiceUploadFileRequest;
use App\Invoices\Exceptions\UnsupportedDistributorException;
use App\Models\InvoiceUploadBatch;
use App\Services\InvoiceUploadBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Envio de faturas', 'Upload sequencial privado antes de iniciar uma simulação')]
#[Authenticated]
class InvoiceUploadBatchController extends Controller
{
    #[Endpoint('Iniciar envio', 'Registra os dados da empresa para receber as faturas uma por vez. Expira em uma hora.')]
    #[Response(status: 201, content: '{"data":{"id":"019c0000-0000-7000-8000-000000000001"}}')]
    #[Response(status: 422, content: '{"message":"Dados inválidos.","code":"VALIDATION_ERROR","errors":{}}')]
    public function store(CreateInvoiceUploadBatchRequest $request): JsonResponse
    {
        Gate::authorize('create', InvoiceUploadBatch::class);
        $batch = InvoiceUploadBatch::query()->create([
            'user_id' => $request->user()->id, 'company_data' => $request->validated(),
            'files' => [], 'expires_at' => now()->addHour(),
        ]);

        return response()->json(['data' => ['id' => $batch->id]], 201);
    }

    #[Endpoint('Enviar uma fatura', 'Valida assinatura, tamanho e páginas e recebe um único PDF. client_id permite repetir a requisição sem duplicar a fatura.')]
    #[Response(status: 201, content: '{"data":{"id":"019c0000-0000-7000-8000-000000000002","name":"fatura.pdf"}}')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}')]
    public function upload(StoreInvoiceUploadFileRequest $request, InvoiceUploadBatch $batch, InvoiceUploadBatchService $service): JsonResponse
    {
        Gate::authorize('update', $batch);
        $entry = $service->store($batch, $request->file('invoice'), $request->validated('client_id'));

        return response()->json(['data' => ['id' => $entry['id'], 'name' => $entry['name']]], 201);
    }

    #[Endpoint('Concluir envio', 'Inicia a simulação somente após validar o conjunto completo; repetir retorna a mesma simulação.')]
    #[Response(status: 202, content: '{"data":{"id":1,"status":"queued"}}')]
    #[Response(status: 422, content: '{"message":"Envio inválido.","code":"VALIDATION_ERROR","errors":{}}')]
    public function complete(Request $request, InvoiceUploadBatch $batch, InvoiceUploadBatchService $service): JsonResponse
    {
        Gate::authorize('update', $batch);
        try {
            $simulation = $service->complete($batch, $request->user());
        } catch (UnsupportedDistributorException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'DISTRIBUTOR_UNSUPPORTED', 'errors' => null], 422);
        }

        return response()->json(['data' => ['id' => $simulation->id, 'status' => $simulation->status]], 202);
    }

    #[Endpoint('Descartar envio', 'Remove os PDFs temporários de um envio do próprio usuário.')]
    #[Response(status: 200, content: '{"message":"Envio descartado."}')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}')]
    public function destroy(InvoiceUploadBatch $batch, InvoiceUploadBatchService $service): JsonResponse
    {
        Gate::authorize('update', $batch);
        $service->discard($batch);

        return response()->json(['message' => 'Envio descartado.']);
    }
}
