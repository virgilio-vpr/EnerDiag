<?php

namespace App\Policies;

use App\Models\InvoiceUploadBatch;
use App\Models\User;

class InvoiceUploadBatchPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, ['customer', 'admin'], true);
    }

    public function update(User $user, InvoiceUploadBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }
}
