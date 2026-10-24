<?php

namespace App\Policies;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerProfilePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CustomerProfile $customerProfile): bool|Response
    {
        return $this->canAccessProfile($user, $customerProfile)
            ? Response::allow()
            : Response::denyAsNotFound('Perfil não encontrado.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CustomerProfile $customerProfile): bool|Response
    {
        return $this->canAccessProfile($user, $customerProfile)
            ? Response::allow()
            : Response::denyAsNotFound('Perfil não encontrado.');
    }

    private function canAccessProfile(User $user, CustomerProfile $customerProfile): bool
    {
        return $user->role === 'admin' || $user->id === $customerProfile->user_id;
    }
}
