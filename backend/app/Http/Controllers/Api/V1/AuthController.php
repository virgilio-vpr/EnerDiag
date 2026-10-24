<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Services\RegisterCustomer;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Autenticação', 'Cadastro e acesso de clientes')]
class AuthController extends Controller
{
    /**
     * Register a customer and send an email verification code.
     */
    #[Endpoint('Cadastrar cliente', 'Cria a conta de cliente e envia um código numérico para confirmação do e-mail.')]
    #[BodyParam('name', 'string', 'Nome do responsável.', required: true, example: 'Ana Souza')]
    #[BodyParam('email', 'string', 'E-mail de acesso.', required: true, example: 'ana@empresa.com.br')]
    #[BodyParam('company_name', 'string', 'Nome da empresa.', required: true, example: 'Empresa Exemplo Ltda.')]
    #[BodyParam('password', 'string', 'Senha com pelo menos 10 caracteres, letras e números.', required: true, example: 'EnergiaSegura2026')]
    #[BodyParam('password_confirmation', 'string', 'Confirmação da senha.', required: true, example: 'EnergiaSegura2026')]
    #[BodyParam('terms_accepted', 'boolean', 'Aceite dos Termos de Uso e da Política de Privacidade.', required: true, example: true)]
    #[Response(
        status: 201,
        content: '{"message":"Cadastro realizado. Verifique seu e-mail para confirmar a conta.","data":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","role":"customer"}}',
        description: 'Conta criada e código enviado'
    )]
    #[Response(
        status: 422,
        content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{"email":["Este e-mail já está cadastrado."]}}',
        description: 'Dados inválidos'
    )]
    public function register(RegisterRequest $request, RegisterCustomer $registration): JsonResponse
    {
        $user = $registration->handle($request->validated(), $request->ip());

        return response()->json([
            'message' => 'Cadastro realizado. Verifique seu e-mail para confirmar a conta.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }
}
