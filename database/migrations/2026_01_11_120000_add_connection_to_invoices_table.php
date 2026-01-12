<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('connection_id')->nullable()->after('id')->constrained('conta_azul_connections')->onDelete('cascade');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_ca_id_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['connection_id', 'ca_id'], 'invoices_connection_ca_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_connection_ca_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('ca_id', 'invoices_ca_id_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connection_id');
        });
    }
};
