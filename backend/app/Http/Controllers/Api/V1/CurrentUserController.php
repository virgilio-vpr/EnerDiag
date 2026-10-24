<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Autenticação', 'Endpoints que exigem uma sessão de acesso autenticada')]
class CurrentUserController extends Controller
{
    /**
     * Dados do usuário autenticado
     *
     * Retorna os dados públicos do usuário associado ao token enviado.
     */
    #[Authenticated]
    #[Endpoint('Consultar usuário autenticado', 'Retorna os dados públicos do usuário do token de acesso.')]
    #[Response(
        status: 200,
        content: '{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","phone":"11999999999","role":"customer"}',
        description: 'Usuário autenticado'
    )]
    #[Response(
        status: 401,
        content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}',
        description: 'Token ausente ou inválido'
    )]
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
