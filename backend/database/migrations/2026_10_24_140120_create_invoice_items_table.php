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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('item_type', 50);
            $table->string('tariff_component', 30)->default('not_applicable');
            $table->string('time_slot', 30)->default('not_applicable');
            $table->string('reactive_nature', 30)->default('not_applicable');
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price_taxed', 14, 5)->nullable();
            $table->decimal('tariff_rate', 14, 5)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('pis_cofins_amount', 14, 2)->default(0);
            $table->decimal('icms_base', 14, 2)->default(0);
            $table->decimal('icms_rate', 6, 4)->default(0);
            $table->decimal('icms_amount', 14, 2)->default(0);
            $table->string('source_label')->nullable();
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'item_type', 'tariff_component', 'time_slot', 'reactive_nature'],
                'inv_items_unique_slot'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
