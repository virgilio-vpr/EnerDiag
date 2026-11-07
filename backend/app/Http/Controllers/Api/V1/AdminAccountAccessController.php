<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmAdminEmailChangeRequest;
use App\Http\Requests\Api\V1\RequestAdminEmailChangeRequest;
use App\Http\Requests\Api\V1\UpdateAdminPasswordRequest;
use App\Services\AdminAccountAccessService;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAccountAccessController extends Controller
{
    public function updatePassword(UpdateAdminPasswordRequest $request, AdminAccountAccessService $service): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $service->updatePassword($request->user(), $request->validated('password'), $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->json(['message' => 'Senha atualizada. As outras sessões foram encerradas.']);
    }

    public function requestEmailChange(RequestAdminEmailChangeRequest $request, AdminAccountAccessService $service): JsonResponse
    {
        $service->requestEmailChange($request->user(), $request->validated('email'));

        return response()->json(['message' => 'Enviamos um código para o novo e-mail. Seu acesso atual permanece válido até a confirmação.']);
    }

    public function confirmEmailChange(ConfirmAdminEmailChangeRequest $request, AdminAccountAccessService $service): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $email = $service->confirmEmailChange($request->user(), $request->validated('code'), $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->json(['message' => 'E-mail de acesso atualizado. Use o novo endereço no próximo login.', 'email' => $email]);
    }
}
