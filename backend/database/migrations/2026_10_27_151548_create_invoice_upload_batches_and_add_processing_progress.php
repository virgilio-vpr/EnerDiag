<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_upload_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('company_data');
            $table->json('files');
            $table->foreignId('simulation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('simulations', function (Blueprint $table): void {
            $table->json('processing_progress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_upload_batches');
        Schema::table('simulations', function (Blueprint $table): void {
            $table->dropColumn('processing_progress');
        });
    }
};
