<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'distributor_id',
        'subgroup',
        'tariff_modality',
        'time_slot',
        'component',
        'rate',
        'includes_taxes',
        'source',
        'source_reference',
        'valid_from',
        'valid_to',
    ];

    /**
     * Distributor associated with this tariff.
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:5',
            'includes_taxes' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }
}
