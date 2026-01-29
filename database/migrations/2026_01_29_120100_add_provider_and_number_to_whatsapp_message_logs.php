<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_message_logs', 'whatsapp_number_id')) {
                $table->foreignId('whatsapp_number_id')
                    ->nullable()
                    ->constrained('whatsapp_numbers')
                    ->nullOnDelete()
                    ->after('connection_id');
            }
            if (! Schema::hasColumn('whatsapp_message_logs', 'provider')) {
                $table->string('provider', 20)->nullable()->after('message_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_message_logs', 'whatsapp_number_id')) {
                $table->dropForeign(['whatsapp_number_id']);
                $table->dropColumn('whatsapp_number_id');
            }
            if (Schema::hasColumn('whatsapp_message_logs', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};

