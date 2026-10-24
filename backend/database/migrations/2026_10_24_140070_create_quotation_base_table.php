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
        Schema::create('quotation_base', function (Blueprint $table) {
            $table->id();
            $table->string('item_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30);
            $table->decimal('unit_price', 14, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_base');
    }
};
