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
        Schema::table('message_crons', function (Blueprint $table) {
            $table->foreignId('connection_id')->nullable()->after('whatsapp_number_id')->constrained('conta_azul_connections')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropColumn('connection_id');
        });
    }
};
