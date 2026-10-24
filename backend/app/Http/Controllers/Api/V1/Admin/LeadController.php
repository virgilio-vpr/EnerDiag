<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Administração: leads', 'Lista solicitações de distribuidoras não atendidas e pedidos de contato')]
class LeadController extends Controller
{
    #[Authenticated]
    #[Endpoint('Listar leads e pedidos de contato', 'Retorna uma lista paginada com leads de distribuidoras e solicitações de contato de clientes.')]
    #[Response(status: 200, content: '{"data":[{"type":"unsupported_distributor","id":1,"email":"cliente@empresa.com.br","distributor_name":"Distribuidora X","reason":"Fora da área atendida","customer_name":null,"requested_at":"2026-10-24T12:00:00Z"}],"links":{},"meta":{"current_page":1,"per_page":15,"total":1}}', description: 'Leads paginados')]
    #[Response(status: 401, content: '{"message":"Não autenticado.","code":"UNAUTHENTICATED","errors":null}', description: 'Token ausente ou inválido')]
    #[Response(status: 403, content: '{"message":"Acesso não autorizado.","code":"FORBIDDEN","errors":null}', description: 'Acesso restrito ao admin')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $unsupportedDistributorLeads = DB::table('leads')
            ->selectRaw("'unsupported_distributor' as type, id, email, distributor_name, reason, NULL as customer_name, created_at as requested_at");
        $contactRequests = DB::table('users')
            ->whereNotNull('contact_requested_at')
            ->selectRaw("'contact_request' as type, id, email, NULL as distributor_name, NULL as reason, name as customer_name, contact_requested_at as requested_at");

        $leads = DB::query()
            ->fromSub($unsupportedDistributorLeads->unionAll($contactRequests), 'admin_leads')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $leads->items(),
            'links' => [
                'first' => $leads->url(1),
                'last' => $leads->url($leads->lastPage()),
                'prev' => $leads->previousPageUrl(),
                'next' => $leads->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $leads->currentPage(),
                'per_page' => $leads->perPage(),
                'last_page' => $leads->lastPage(),
                'total' => $leads->total(),
            ],
        ]);
    }
}
