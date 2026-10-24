<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'simulation_id',
        'total_monthly_savings',
        'total_yearly_savings',
        'savings_percentage',
        'demand_savings_monthly',
        'demand_savings_yearly',
        'overrun_savings_monthly',
        'overrun_savings_yearly',
        'reactive_savings_monthly',
        'reactive_savings_yearly',
        'migration_savings_monthly',
        'migration_savings_yearly',
        'recommended_modality',
        'optimal_demand_peak',
        'optimal_demand_off_peak',
        'optimal_demand_single',
        'chart_series',
        'warnings',
        'explanatory_texts',
        'recommendations',
        'r_values',
        'k_values',
        'algorithm_version',
        'tariff_version_ids',
        'regulatory_parameter_version_ids',
    ];

    /**
     * Simulation parent of this report.
     */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class);
    }

    /**
     * Revisions history for this report.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_monthly_savings' => 'decimal:2',
            'total_yearly_savings' => 'decimal:2',
            'savings_percentage' => 'decimal:2',
            'demand_savings_monthly' => 'decimal:2',
            'demand_savings_yearly' => 'decimal:2',
            'overrun_savings_monthly' => 'decimal:2',
            'overrun_savings_yearly' => 'decimal:2',
            'reactive_savings_monthly' => 'decimal:2',
            'reactive_savings_yearly' => 'decimal:2',
            'migration_savings_monthly' => 'decimal:2',
            'migration_savings_yearly' => 'decimal:2',
            'optimal_demand_peak' => 'decimal:3',
            'optimal_demand_off_peak' => 'decimal:3',
            'optimal_demand_single' => 'decimal:3',
            'chart_series' => 'array',
            'warnings' => 'array',
            'explanatory_texts' => 'array',
            'recommendations' => 'array',
            'r_values' => 'array',
            'k_values' => 'array',
            'tariff_version_ids' => 'array',
            'regulatory_parameter_version_ids' => 'array',
        ];
    }
}
