<?php

namespace App\Services;

use App\Models\QuotationBase;

final class AdminQuotationItemService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): QuotationBase
    {
        return QuotationBase::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(QuotationBase $item, array $attributes): QuotationBase
    {
        $item->update($attributes);

        return $item->refresh();
    }
}
