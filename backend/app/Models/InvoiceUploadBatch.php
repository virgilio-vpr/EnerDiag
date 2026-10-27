<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InvoiceUploadBatch extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'company_data', 'files', 'expires_at', 'simulation_id'];

    protected function casts(): array
    {
        return ['company_data' => 'array', 'files' => 'array', 'expires_at' => 'datetime'];
    }
}
