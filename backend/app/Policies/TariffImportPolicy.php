<?php

namespace App\Policies;

use App\Models\TariffImport;
use App\Models\User;

class TariffImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function view(User $user, TariffImport $import): bool
    {
        return $user->role === 'admin';
    }

    public function approve(User $user, TariffImport $import): bool
    {
        return $user->role === 'admin';
    }
}
