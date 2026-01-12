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
            $table->foreignId('whatsapp_number_id')->nullable()->after('message_template_id')->constrained('whatsapp_numbers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_number_id']);
            $table->dropColumn('whatsapp_number_id');
        });
    }
};
