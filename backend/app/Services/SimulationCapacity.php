<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

final class SimulationCapacity
{
    public const MAX_SIMULATIONS = 3;

    /** Call inside the transaction that creates the simulation, so concurrent requests share the same lock. */
    public function assertCanCreate(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($user->simulations()->count() >= self::MAX_SIMULATIONS) {
            throw new HttpResponseException(response()->json([
                'message' => 'Você pode manter até 3 simulações. Exclua uma simulação em Minhas simulações para iniciar outra.',
                'code' => 'SIMULATION_LIMIT_REACHED', 'errors' => null,
            ], 409));
        }
    }
}
