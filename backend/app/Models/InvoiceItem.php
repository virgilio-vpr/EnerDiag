<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'item_type',
        'tariff_component',
        'time_slot',
        'reactive_nature',
        'quantity',
        'unit',
        'unit_price_taxed',
        'tariff_rate',
        'amount',
        'pis_cofins_amount',
        'icms_base',
        'icms_rate',
        'icms_amount',
        'source_label',
    ];

    /**
     * Invoice parent of this item.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_taxed' => 'decimal:5',
            'tariff_rate' => 'decimal:5',
            'amount' => 'decimal:2',
            'pis_cofins_amount' => 'decimal:2',
            'icms_base' => 'decimal:2',
            'icms_rate' => 'decimal:4',
            'icms_amount' => 'decimal:2',
        ];
    }
}
