<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Exemplos', 'Endpoints de exemplo para validação da documentação')]
class ExampleController extends Controller
{
    /**
     * Validação de Exemplo
     *
     * Valida os dados enviados e demonstra o formato padronizado de sucesso e de erros (422).
     */
    #[Endpoint('Validação de Exemplo', 'Endpoint de demonstração de contratos de sucesso e erro da API.')]
    #[BodyParam('email', 'string', 'Endereço de e-mail corporativo válido.', required: true, example: 'contato@empresa.com.br')]
    #[Response(
        status: 200,
        content: '{"status":"ok","message":"Dados validados com sucesso.","data":{"email":"contato@empresa.com.br"}}',
        description: 'Requisição processada com sucesso'
    )]
    #[Response(
        status: 422,
        content: '{"message":"O campo e-mail é obrigatório.","code":"VALIDATION_ERROR","errors":{"email":["O campo e-mail é obrigatório."]}}',
        description: 'Falha de validação dos campos'
    )]
    public function validateExample(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'Informe um endereço de e-mail válido.',
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Dados validados com sucesso.',
            'data' => $validated,
        ]);
    }
}
