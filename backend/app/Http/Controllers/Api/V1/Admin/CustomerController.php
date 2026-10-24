<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: clientes', 'Consulta paginada de clientes e seus perfis')]
class CustomerController extends Controller
{
    /**
     * List customers with pagination.
     */
    #[Authenticated]
    #[Endpoint('Listar clientes', 'Retorna clientes em páginas, com dados de contato e perfil.')]
    #[Response(status: 200, content: '{"data":[{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","phone":"11999999999","role":"customer","email_verified_at":"2026-10-24T12:00:00Z","contact_requested_at":null,"profile":{"company_name":"Empresa Exemplo Ltda."}}],"links":{},"meta":{"current_page":1,"per_page":15,"total":1}}', description: 'Clientes paginados')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Esta ação não é autorizada.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $customers = User::query()
            ->where('role', 'customer')
            ->with('profile')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json($this->paginatedCustomers($customers));
    }

    /**
     * Show a customer's account and profile.
     */
    #[Authenticated]
    #[Endpoint('Consultar cliente', 'Retorna os dados de contato e perfil de um cliente.')]
    #[Response(status: 200, content: '{"data":{"id":1,"name":"Ana Souza","email":"ana@empresa.com.br","phone":"11999999999","role":"customer","profile":{"company_name":"Empresa Exemplo Ltda."}}}', description: 'Cliente encontrado')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    #[Response(status: 404, content: '{"message":"Não encontrado.","code":"NOT_FOUND","errors":null}', description: 'Cliente não encontrado')]
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $customer = User::query()->where('role', 'customer')->with('profile')->findOrFail($id);

        return response()->json(['data' => $this->customerData($customer)]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginatedCustomers(LengthAwarePaginator $customers): array
    {
        return [
            'data' => $customers->getCollection()->map(fn (User $customer): array => $this->customerData($customer))->all(),
            'links' => [
                'first' => $customers->url(1),
                'last' => $customers->url($customers->lastPage()),
                'prev' => $customers->previousPageUrl(),
                'next' => $customers->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerData(User $customer): array
    {
        $profile = $customer->profile;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'role' => $customer->role,
            'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
            'contact_requested_at' => $customer->contact_requested_at?->toIso8601String(),
            'profile' => $profile?->only([
                'company_name', 'cnpj', 'distributor_id', 'transformer_power_kva',
                'address_details', 'substation_same_address', 'substation_address',
            ]),
        ];
    }
}
