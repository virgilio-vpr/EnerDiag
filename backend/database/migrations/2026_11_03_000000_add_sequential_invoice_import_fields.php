<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedInteger('source_bytes')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->unique(['simulation_id', 'source_sha256'], 'invoices_simulation_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_simulation_hash_unique');
            $table->dropColumn(['source_sha256', 'source_bytes', 'page_count']);
        });

    }
};
