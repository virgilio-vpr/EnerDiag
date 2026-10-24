<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_id')->unique()->constrained('simulations')->cascadeOnDelete();
            $table->decimal('total_monthly_savings', 14, 2)->default(0);
            $table->decimal('total_yearly_savings', 14, 2)->default(0);
            $table->decimal('savings_percentage', 6, 2)->default(0);
            $table->decimal('demand_savings_monthly', 14, 2)->default(0);
            $table->decimal('demand_savings_yearly', 14, 2)->default(0);
            $table->decimal('overrun_savings_monthly', 14, 2)->default(0);
            $table->decimal('overrun_savings_yearly', 14, 2)->default(0);
            $table->decimal('reactive_savings_monthly', 14, 2)->default(0);
            $table->decimal('reactive_savings_yearly', 14, 2)->default(0);
            $table->decimal('migration_savings_monthly', 14, 2)->default(0);
            $table->decimal('migration_savings_yearly', 14, 2)->default(0);
            $table->string('recommended_modality', 30)->nullable();
            $table->decimal('optimal_demand_peak', 14, 3)->nullable();
            $table->decimal('optimal_demand_off_peak', 14, 3)->nullable();
            $table->decimal('optimal_demand_single', 14, 3)->nullable();
            $table->json('chart_series')->nullable();
            $table->json('warnings')->nullable();
            $table->json('explanatory_texts')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('r_values')->nullable();
            $table->json('k_values')->nullable();
            $table->string('algorithm_version', 20)->default('1.0');
            $table->json('tariff_version_ids')->nullable();
            $table->json('regulatory_parameter_version_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
