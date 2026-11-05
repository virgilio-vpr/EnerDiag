<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('source_filename')->nullable();
            $table->json('extraction_warnings')->nullable();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropUnique('inv_items_unique_slot');
        });
        DB::statement("CREATE UNIQUE INDEX inv_items_unique_slot ON invoice_items (invoice_id, item_type, tariff_component, time_slot, reactive_nature) WHERE item_type <> 'other_charge'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX inv_items_unique_slot');
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unique(['invoice_id', 'item_type', 'tariff_component', 'time_slot', 'reactive_nature'], 'inv_items_unique_slot');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['source_filename', 'extraction_warnings']);
        });
    }
};
