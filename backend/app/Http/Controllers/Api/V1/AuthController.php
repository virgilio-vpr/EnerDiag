<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResendVerificationCodeRequest;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Services\EmailVerificationService;
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

    /**
     * Verify the email address using the current numeric code.
     */
    #[Endpoint('Confirmar e-mail', 'Confirma o endereço de e-mail usando o código numérico enviado.')]
    #[BodyParam('email', 'string', 'E-mail da conta.', required: true, example: 'ana@empresa.com.br')]
    #[BodyParam('code', 'string', 'Código numérico de seis dígitos.', required: true, example: '482913')]
    #[Response(status: 200, content: '{"message":"E-mail confirmado."}', description: 'E-mail confirmado')]
    #[Response(
        status: 422,
        content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{"code":["Código inválido, expirado ou com limite de tentativas excedido."]}}',
        description: 'Código inválido, expirado ou bloqueado'
    )]
    public function verifyEmail(VerifyEmailRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $verification->verify($request->string('email')->toString(), $request->string('code')->toString());

        return response()->json(['message' => 'E-mail confirmado.']);
    }

    /**
     * Resend a verification code without exposing whether an account exists.
     */
    #[Endpoint('Reenviar código de confirmação', 'Envia um novo código se o e-mail pertencer a uma conta ainda não confirmada.')]
    #[BodyParam('email', 'string', 'E-mail informado no cadastro.', required: true, example: 'ana@empresa.com.br')]
    #[Response(status: 200, content: '{"message":"Se a conta existir e ainda não estiver confirmada, enviaremos um novo código."}', description: 'Solicitação processada')]
    #[Response(status: 429, content: '{"message":"Muitas requisições. Tente novamente mais tarde.","code":"TOO_MANY_REQUESTS","errors":null}', description: 'Limite de reenvios atingido')]
    public function resendCode(ResendVerificationCodeRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $verification->resend($request->string('email')->toString());

        return response()->json([
            'message' => 'Se a conta existir e ainda não estiver confirmada, enviaremos um novo código.',
        ]);
    }
}
