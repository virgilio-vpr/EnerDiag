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
        Schema::table('tariff_imports', function (Blueprint $table) {
            $table->text('source_url')->nullable();
            $table->string('source_hash', 64)->nullable()->unique();
            $table->json('pending_tariffs')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tariff_imports', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropUnique(['source_hash']);
            $table->dropColumn(['source_url', 'source_hash', 'pending_tariffs', 'reviewed_by', 'reviewed_at']);
        });
    }
};
