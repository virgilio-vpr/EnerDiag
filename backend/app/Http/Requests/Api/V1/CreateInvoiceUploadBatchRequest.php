<?php

namespace App\Http\Requests\Api\V1;

class CreateInvoiceUploadBatchRequest extends StoreSimulationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['invoices'], $rules['invoices.*']);

        return $rules;
    }
}
