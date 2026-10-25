<?php

namespace App\Services;

use App\Mail\QuotationReleased;
use App\Models\Quotation;
use App\Models\QuotationBase;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class AdminQuotationService
{
    /** @param list<array{quotation_base_id: int, quantity: string|int|float}> $items */
    public function create(int $customerId, array $items, int $adminId): Quotation
    {
        return DB::transaction(function () use ($customerId, $items, $adminId): Quotation {
            $customer = User::query()->with('profile')->where('role', 'customer')->findOrFail($customerId);
            $quotation = Quotation::query()->create([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'company_name' => $customer->profile?->company_name,
                'cnpj' => $customer->profile?->cnpj,
                'customer_address' => $customer->profile?->address_details,
                'status' => 'draft',
                'subtotal' => '0.00',
                'total' => '0.00',
                'created_by' => $adminId,
            ]);

            $subtotal = BigDecimal::zero();
            foreach ($items as $input) {
                $baseItem = QuotationBase::query()->findOrFail($input['quotation_base_id']);
                $quantity = BigDecimal::of((string) $input['quantity'])->toScale(3, RoundingMode::HalfUp);
                $unitPrice = BigDecimal::of((string) $baseItem->unit_price)->toScale(2, RoundingMode::HalfUp);
                $lineTotal = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
                $subtotal = $subtotal->plus($lineTotal);

                if ($lineTotal->isGreaterThan('999999999999.99') || $subtotal->isGreaterThan('999999999999.99')) {
                    throw ValidationException::withMessages(['items' => 'O total do orçamento ultrapassa o limite permitido.']);
                }

                $quotation->items()->create([
                    'item_key' => $baseItem->item_key,
                    'name' => $baseItem->name,
                    'unit' => $baseItem->unit,
                    'quantity' => (string) $quantity,
                    'unit_price' => (string) $unitPrice,
                    'total' => (string) $lineTotal,
                ]);
            }

            $quotation->update([
                'quote_number' => sprintf('ORC-%s-%06d', now()->format('Y'), $quotation->id),
                'subtotal' => (string) $subtotal->toScale(2, RoundingMode::HalfUp),
                'total' => (string) $subtotal->toScale(2, RoundingMode::HalfUp),
            ]);

            return $quotation->load('items');
        });
    }

    /** @param array{technical_responsible_name: string, crea_registration: string, art_number: string} $attributes */
    public function releaseAndSend(Quotation $quotation, User $admin, array $attributes): Quotation
    {
        $quotation->loadMissing(['customer', 'items']);
        if ($quotation->sent_at !== null) {
            throw ValidationException::withMessages(['quotation' => 'Este orçamento já foi enviado ao cliente.']);
        }

        if ($quotation->status === 'draft') {
            $issueDate = Carbon::today();
            $quotation->update([
                ...$attributes,
                'status' => 'released',
                'issue_date' => $issueDate->toDateString(),
                'valid_until' => $issueDate->copy()->addDays(30)->toDateString(),
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        } elseif ($quotation->status !== 'released') {
            throw ValidationException::withMessages(['quotation' => 'Somente orçamentos em rascunho podem ser liberados.']);
        }

        $quotation->refresh()->load(['customer', 'items']);
        Mail::to($quotation->customer_email)->send(new QuotationReleased($quotation));
        $quotation->update(['status' => 'sent', 'sent_at' => now()]);

        return $quotation->fresh(['customer', 'items']);
    }
}
