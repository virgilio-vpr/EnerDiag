<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_number', 'customer_id', 'customer_name', 'customer_email', 'company_name', 'cnpj',
        'customer_address', 'status', 'issue_date', 'valid_until', 'subtotal', 'total',
        'technical_responsible_name', 'crea_registration', 'art_number', 'created_by',
        'approved_by', 'approved_at', 'sent_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<QuotationLineItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationLineItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
