<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResendVerificationCodeRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\RegisterCustomer;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /**
     * Authenticate a verified account and issue a fixed two-hour Sanctum token.
     */
    #[Endpoint('Entrar', 'Autentica a conta confirmada e devolve um token Bearer válido por duas horas.')]
    #[BodyParam('email', 'string', 'E-mail da conta.', required: true, example: 'ana@empresa.com.br')]
    #[BodyParam('password', 'string', 'Senha da conta.', required: true, example: 'EnergiaSegura2026')]
    #[BodyParam('totp_code', 'string', 'Código do autenticador, obrigatório para admin com TOTP ativo.', required: false, example: '123456')]
    #[Response(status: 422, content: '{"message":"Informe o código do seu autenticador.","code":"VALIDATION_ERROR","errors":{"totp_code":["Informe o código do seu autenticador."]}}', description: 'Código TOTP ausente, inválido, expirado ou já utilizado')]
    #[Response(status: 200, content: '{"token":"1|token-em-texto-plano","token_type":"Bearer","expires_at":"2026-10-24T18:00:00Z","user":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","role":"customer"}}', description: 'Autenticação concluída')]
    #[Response(status: 401, content: '{"message":"E-mail ou senha inválidos.","code":"UNAUTHENTICATED","errors":null}', description: 'Credenciais inválidas')]
    #[Response(status: 403, content: '{"message":"Confirme seu e-mail antes de entrar.","code":"FORBIDDEN","errors":null}', description: 'E-mail ainda não confirmado')]
    public function login(LoginRequest $request, TotpService $totp): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password_hash)) {
            abort(401, 'E-mail ou senha inválidos.');
        }

        if (! $user->email_verified_at) {
            abort(403, 'Confirme seu e-mail antes de entrar.');
        }

        if ($user->role === 'admin' && $user->totp_secret !== null) {
            if (! $request->filled('totp_code')) {
                throw ValidationException::withMessages(['totp_code' => ['Informe o código do seu autenticador.']]);
            }

            if (! $totp->consume($user, $request->validated('totp_code'))) {
                throw ValidationException::withMessages(['totp_code' => ['Código inválido, expirado ou já utilizado.']]);
            }
        }

        $accessToken = $user->createToken('enerdiag-api', ['*'], now()->addHours(2));

        return response()->json([
            'token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $accessToken->accessToken->expires_at->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Revoke the current Sanctum token.
     */
    #[Endpoint('Sair', 'Revoga o token Bearer usado na requisição.')]
    #[Response(status: 200, content: '{"message":"Sessão encerrada."}', description: 'Token revogado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /**
     * Send a password reset link without disclosing whether the email exists.
     */
    #[Endpoint('Solicitar redefinição de senha', 'Envia um link de redefinição quando a conta existe.')]
    #[BodyParam('email', 'string', 'E-mail da conta.', required: true, example: 'ana@empresa.com.br')]
    #[Response(status: 200, content: '{"message":"Se o e-mail estiver cadastrado, enviaremos as instruções de redefinição."}', description: 'Resposta neutra')]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções de redefinição.',
        ]);
    }

    /**
     * Reset the account password using a valid single-use token.
     */
    #[Endpoint('Redefinir senha', 'Redefine a senha usando o link de uso único recebido por e-mail.')]
    #[BodyParam('email', 'string', 'E-mail da conta.', required: true, example: 'ana@empresa.com.br')]
    #[BodyParam('token', 'string', 'Token recebido no link de redefinição.', required: true, example: 'token-de-uso-unico')]
    #[BodyParam('password', 'string', 'Nova senha com pelo menos 10 caracteres, letras e números.', required: true, example: 'NovaSenhaSegura2026')]
    #[BodyParam('password_confirmation', 'string', 'Confirmação da nova senha.', required: true, example: 'NovaSenhaSegura2026')]
    #[Response(status: 200, content: '{"message":"Senha redefinida com sucesso."}', description: 'Senha atualizada')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{"token":["O link é inválido ou expirou."]}}', description: 'Link inválido, expirado ou já utilizado')]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset($request->validated(), function (User $user, string $password): void {
            $user->forceFill([
                'password_hash' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'O link é inválido ou expirou.',
            ]);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
