<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Minha conta', 'Consulta, atualização e exclusão da conta autenticada')]
class MeController extends Controller
{
    /**
     * Return the authenticated account and customer profile.
     */
    #[Authenticated]
    #[Endpoint('Consultar minha conta', 'Retorna os dados públicos da conta autenticada e seu perfil de cliente.')]
    #[Response(status: 200, content: '{"data":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","phone":"11999999999","role":"customer","email_verified_at":"2026-10-24T12:00:00Z","contact_requested_at":null,"profile":{"company_name":"Empresa Exemplo Ltda.","cnpj":null,"distributor_id":1,"transformer_power_kva":500,"address_details":null,"substation_same_address":true,"substation_address":null}}}', description: 'Conta autenticada')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accountData($request->user())]);
    }

    /**
     * Update the authenticated account and its customer profile.
     */
    #[Authenticated]
    #[Endpoint('Atualizar minha conta', 'Atualiza nome, telefone e dados de perfil permitidos da conta autenticada.')]
    #[BodyParam('name', 'string', 'Nome do responsável.', example: 'Ana Souza')]
    #[BodyParam('phone', 'string', 'Telefone de contato.', example: '11999999999')]
    #[BodyParam('company_name', 'string', 'Nome da empresa.', example: 'Empresa Exemplo Ltda.')]
    #[Response(status: 200, content: '{"data":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","phone":"11999999999","role":"customer","profile":{"company_name":"Empresa Exemplo Ltda."}}}', description: 'Conta atualizada')]
    #[Response(status: 422, content: '{"message":"Os dados informados são inválidos.","code":"VALIDATION_ERROR","errors":{}}', description: 'Dados inválidos')]
    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $user->fill(array_intersect_key($validated, array_flip(['name', 'phone'])));

        if ($user->isDirty()) {
            $user->save();
        }

        $profileUpdates = array_intersect_key($validated, array_flip([
            'company_name',
            'cnpj',
            'distributor_id',
            'transformer_power_kva',
            'address_details',
            'substation_same_address',
            'substation_address',
        ]));

        if ($profileUpdates !== []) {
            $profile = $user->profile;

            if (! $profile) {
                abort(404, 'Perfil de cliente não encontrado.');
            }

            $this->authorize('update', $profile);
            $profile->fill($profileUpdates)->save();
        }

        return response()->json(['data' => $this->accountData($user->fresh())]);
    }

    /**
     * Delete the authenticated account and its associated personal data.
     */
    #[Authenticated]
    #[Endpoint('Excluir minha conta', 'Exclui a conta autenticada e os dados relacionados conforme a solicitação do titular.')]
    #[Response(status: 204, description: 'Conta e dados relacionados excluídos')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            Password::deleteToken($user);
            $user->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Record that the account holder requested commercial contact.
     */
    #[Authenticated]
    #[Endpoint('Solicitar contato', 'Registra a solicitação de contato comercial do titular da conta.')]
    #[Response(status: 200, content: '{"message":"Solicitação de contato registrada.","contact_requested_at":"2026-10-24T12:00:00Z"}', description: 'Solicitação registrada')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    public function requestContact(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->contact_requested_at) {
            $user->forceFill(['contact_requested_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Solicitação de contato registrada.',
            'contact_requested_at' => $user->fresh()->contact_requested_at->toIso8601String(),
        ]);
    }

    /**
     * Format the fields exposed for the authenticated account.
     *
     * @return array<string, mixed>
     */
    private function accountData(User $user): array
    {
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'contact_requested_at' => $user->contact_requested_at?->toIso8601String(),
            'profile' => $profile ? $profile->only([
                'company_name',
                'cnpj',
                'distributor_id',
                'transformer_power_kva',
                'address_details',
                'substation_same_address',
                'substation_address',
            ]) : null,
        ];
    }
}
