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
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->string('subgroup', 10);
            $table->string('tariff_modality', 20);
            $table->string('time_slot', 20)->default('not_applicable');
            $table->string('component', 30);
            $table->decimal('rate', 14, 5);
            $table->boolean('includes_taxes')->default(true);
            $table->string('source', 30)->default('manual');
            $table->string('source_reference')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['distributor_id', 'subgroup', 'tariff_modality', 'component', 'valid_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
