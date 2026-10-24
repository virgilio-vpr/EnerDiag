<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'explanatory_texts' => ['sometimes', 'required', 'array', 'max:50'],
            'explanatory_texts.*' => ['string', 'max:5000'],
            'recommendations' => ['sometimes', 'required', 'array', 'max:50'],
            'recommendations.*' => ['string', 'max:5000'],
            'simulation_id' => ['prohibited'],
            'total_monthly_savings' => ['prohibited'],
            'total_yearly_savings' => ['prohibited'],
            'savings_percentage' => ['prohibited'],
            'demand_savings_monthly' => ['prohibited'],
            'demand_savings_yearly' => ['prohibited'],
            'overrun_savings_monthly' => ['prohibited'],
            'overrun_savings_yearly' => ['prohibited'],
            'reactive_savings_monthly' => ['prohibited'],
            'reactive_savings_yearly' => ['prohibited'],
            'migration_savings_monthly' => ['prohibited'],
            'migration_savings_yearly' => ['prohibited'],
            'recommended_modality' => ['prohibited'],
            'optimal_demand_peak' => ['prohibited'],
            'optimal_demand_off_peak' => ['prohibited'],
            'optimal_demand_single' => ['prohibited'],
            'chart_series' => ['prohibited'],
            'warnings' => ['prohibited'],
            'r_values' => ['prohibited'],
            'k_values' => ['prohibited'],
            'algorithm_version' => ['prohibited'],
            'tariff_version_ids' => ['prohibited'],
            'regulatory_parameter_version_ids' => ['prohibited'],
        ];
    }
}
