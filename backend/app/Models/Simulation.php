<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Simulation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'months_count',
        'total_monthly_savings',
        'total_yearly_savings',
        'expires_at',
    ];

    /**
     * User owner of the simulation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Invoices associated with this simulation.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Generated report for this simulation.
     */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'months_count' => 'integer',
            'total_monthly_savings' => 'decimal:2',
            'total_yearly_savings' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }
}
